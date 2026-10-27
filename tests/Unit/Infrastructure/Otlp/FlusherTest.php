<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use InvalidArgumentException as InvalidFlusherArgument;
use LogicException;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExportedSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\Flusher;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\LogBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\RecordingExporter;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\RecordingLogExporter;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\RecordingLogger;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Clock\MockClock;

#[CoversClass(Flusher::class)]
#[UsesClass(SpanBuffer::class)]
#[UsesClass(LogBuffer::class)]
#[UsesClass(ExportedSpan::class)]
#[UsesClass(SpanIds::class)]
final class FlusherTest extends TestCase
{
    private SpanBuffer $buffer;

    private RecordingExporter $exporter;

    private MockClock $clock;

    private RecordingLogger $logger;

    private Flusher $flusher;

    private LogBuffer $logs;

    private RecordingLogExporter $logExporter;

    #[Override]
    protected function setUp(): void
    {
        $this->buffer = new SpanBuffer(100);
        $this->exporter = new RecordingExporter();
        $this->clock = new MockClock('2026-10-25 12:00:00 UTC');
        $this->logger = new RecordingLogger();
        $this->flusher = new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger);
    }

    public function testFlushExportsTheBufferOnce(): void
    {
        $this->fill(3);

        $this->flusher->flush();
        $this->flusher->flush();

        self::assertCount(1, $this->exporter->batches);
        self::assertCount(3, $this->exporter->batches[0]);
        self::assertSame([], $this->buffer->drain());
        self::assertSame([], $this->logger->records);
    }

    public function testAnEmptyBufferExportsNothing(): void
    {
        $this->flusher->flush();

        self::assertSame([], $this->exporter->batches);
    }

    public function testAThrowingExporterLogsOneWarningAndNeverThrows(): void
    {
        $this->exporter->outcome = new RuntimeException('connection refused');
        $exception = $this->exporter->outcome;
        $this->fill(2);

        $this->flusher->flush();

        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame('OTLP span export failed, {count} spans dropped.', $this->logger->records[0]['message']);
        self::assertSame(['count' => 2, 'exception' => $exception], $this->logger->records[0]['context']);
    }

    public function testAnExporterThatCannotBeBuiltLogsOneWarningAndPauses(): void
    {
        $exception = new LogicException('The OTLP protocol "grpc" needs open-telemetry/transport-grpc (and ext-grpc).');
        $flusher = new Flusher($this->buffer, static fn (): never => throw $exception, $this->clock, $this->logger);
        $this->fill(2);

        $flusher->flush();
        $this->fill(1);
        $flusher->flush();

        self::assertSame([['level' => 'warning', 'message' => 'OTLP span export failed, {count} spans dropped.', 'context' => ['count' => 2, 'exception' => $exception]]], $this->logger->records);
    }

    public function testTheExporterIsBuiltOnce(): void
    {
        $builds = 0;
        $flusher = new Flusher($this->buffer, function () use (&$builds): RecordingExporter {
            $builds++;

            return $this->exporter;
        }, $this->clock, $this->logger);

        $this->fill(1);
        $flusher->flush();
        $this->fill(1);
        $flusher->flush();

        self::assertSame(1, $builds);
        self::assertCount(2, $this->exporter->batches);
    }

    public function testAPauseIsSharedThroughTheCache(): void
    {
        $pauses = new ArrayAdapter();
        $first = new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, $pauses);
        $other = new RecordingExporter();
        $second = new Flusher($this->buffer, static fn (): RecordingExporter => $other, $this->clock, $this->logger, $pauses);
        $this->exporter->outcome = false;
        $this->fill(1);
        $first->flush();

        $this->fill(2);
        $second->flush();
        self::assertSame([], $other->batches);

        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->fill(1);
        $second->flush();
        self::assertCount(1, $other->batches);
    }

    public function testTheLaterOfTheOwnAndTheSharedPauseHolds(): void
    {
        $pauses = new ArrayAdapter();
        $first = new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, $pauses);
        $other = new RecordingExporter(false);
        $second = new Flusher($this->buffer, static fn (): RecordingExporter => $other, $this->clock, $this->logger, $pauses);
        $this->exporter->outcome = false;
        $this->fill(1);
        $first->flush();
        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->fill(1);
        $second->flush();
        $this->exporter->outcome = true;

        $this->clock->sleep(Flusher::PAUSE_SECONDS - 1);
        $this->fill(1);
        $first->flush();

        self::assertCount(1, $this->exporter->batches);
    }

    public function testTheSharedPauseIsReadAtMostOncePerSecond(): void
    {
        $pauses = new class extends ArrayAdapter {
            public int $reads = 0;

            #[Override]
            public function getItem(mixed $key): CacheItem
            {
                $this->reads++;

                return parent::getItem($key);
            }
        };
        $flusher = new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, $pauses);

        for ($i = 0; $i < 3; $i++) {
            $this->fill(1);
            $flusher->flush();
        }

        self::assertSame(1, $pauses->reads);
        $this->clock->sleep(Flusher::SHARED_PAUSE_READ_SECONDS);
        $this->fill(1);
        $flusher->flush();
        self::assertSame(2, $pauses->reads);

        // A clock set back must not stop the reads until it catches up.
        $this->clock->modify('-1 hour');
        $this->fill(1);
        $flusher->flush();
        self::assertSame(3, $pauses->reads);
    }

    public function testABrokenCacheLeavesThePauseToTheProcess(): void
    {
        $pauses = new class extends ArrayAdapter {
            #[Override]
            public function getItem(mixed $key): CacheItem
            {
                throw new InvalidArgumentException('broken');
            }
        };
        $flusher = new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, $pauses);
        $this->exporter->outcome = false;
        $this->fill(1);
        $flusher->flush();

        $this->fill(1);
        $flusher->flush();

        self::assertCount(1, $this->exporter->batches);
        self::assertCount(1, $this->logger->records);
    }

    public function testARejectedExportLogsOneWarning(): void
    {
        $this->exporter->outcome = false;
        $this->fill(2);

        $this->flusher->flush();

        self::assertSame([['level' => 'warning', 'message' => 'OTLP span export failed, {count} spans dropped.', 'context' => ['count' => 2]]], $this->logger->records);
    }

    public function testAFailedExportPausesExportingAndCountsDroppedSpans(): void
    {
        $this->exporter->outcome = false;
        $this->fill(2);
        $this->flusher->flush();
        $this->exporter->outcome = true;

        $this->clock->sleep(Flusher::PAUSE_SECONDS - 1);
        $this->fill(3);
        $this->flusher->flush();
        self::assertCount(1, $this->exporter->batches);
        self::assertSame([], $this->buffer->drain());

        $this->clock->sleep(1);
        $this->fill(1);
        $this->flusher->flush();

        self::assertCount(2, $this->exporter->batches);
        self::assertCount(1, $this->exporter->batches[1]);
        self::assertSame(['level' => 'info', 'message' => 'OTLP span export resumed, {dropped} spans dropped while paused.', 'context' => ['dropped' => 3]], $this->logger->records[1] ?? null);
    }

    public function testEveryFlushDuringThePauseAddsToTheDroppedCount(): void
    {
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->flusher->flush();
        $this->exporter->outcome = true;
        $this->fill(2);
        $this->flusher->flush();
        $this->fill(3);
        $this->flusher->flush();

        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->fill(1);
        $this->flusher->flush();

        self::assertSame(['dropped' => 5], $this->logger->records[1]['context'] ?? null);
    }

    public function testAResumedExportStartsCountingAfresh(): void
    {
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->flusher->flush();
        $this->fill(3);
        $this->flusher->flush();
        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->exporter->outcome = true;
        $this->fill(1);
        $this->flusher->flush();

        $this->exporter->outcome = false;
        $this->fill(2);
        $this->flusher->flush();

        self::assertSame(['count' => 2], $this->logger->records[2]['context'] ?? null);
    }

    public function testThePauseLastsExactlyThePauseSeconds(): void
    {
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->flusher->flush();

        $this->clock->sleep(Flusher::PAUSE_SECONDS - 0.001);
        $this->fill(1);
        $this->flusher->flush();
        self::assertCount(1, $this->exporter->batches);

        $this->clock->sleep(0.001);
        $this->fill(1);
        $this->flusher->flush();
        self::assertCount(2, $this->exporter->batches);
    }

    public function testAFailureAfterThePauseCountsThePausedSpansToo(): void
    {
        $this->exporter->outcome = false;
        $this->fill(2);
        $this->flusher->flush();
        $this->fill(3);
        $this->flusher->flush();

        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->fill(1);
        $this->flusher->flush();

        self::assertCount(2, $this->logger->records);
        self::assertSame(['count' => 4], $this->logger->records[1]['context']);
        $this->clock->sleep(Flusher::PAUSE_SECONDS - 1);
        $this->fill(1);
        $this->flusher->flush();
        self::assertCount(2, $this->exporter->batches);
    }

    public function testASuccessfulExportAfterThePauseWithoutDropsLogsNothing(): void
    {
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->flusher->flush();
        $this->exporter->outcome = true;

        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->fill(1);
        $this->flusher->flush();

        self::assertCount(1, $this->logger->records);
    }

    public function testLogRecordsGoInTheSameFlushThroughTheirOwnExporter(): void
    {
        $flusher = $this->withLogs();
        $this->fill(1);
        $this->logs->add($this->logRecord());
        $this->logs->add($this->logRecord());

        $flusher->flush();
        $flusher->flush();

        self::assertCount(1, $this->exporter->batches);
        self::assertCount(1, $this->logExporter->batches);
        self::assertCount(2, $this->logExporter->batches[0]);
        self::assertSame([], $this->logger->records);
    }

    public function testLogRecordsAloneAreExported(): void
    {
        $flusher = $this->withLogs();
        $this->logs->add($this->logRecord());

        $flusher->flush();

        self::assertSame([], $this->exporter->batches);
        self::assertCount(1, $this->logExporter->batches);
    }

    public function testAFailedLogExportLogsOneWarningAndPausesBothSignals(): void
    {
        $flusher = $this->withLogs();
        $this->logExporter->outcome = new RuntimeException('down');
        $this->logs->add($this->logRecord());
        $flusher->flush();

        $this->fill(1);
        $this->logs->add($this->logRecord());
        $flusher->flush();

        self::assertSame([], $this->exporter->batches);
        self::assertCount(1, $this->logExporter->batches);
        self::assertCount(1, $this->logger->records);
        self::assertSame('OTLP log export failed, {count} log records dropped.', $this->logger->records[0]['message']);
        self::assertSame(1, $this->logger->records[0]['context']['count'] ?? null);

        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->logExporter->outcome = true;
        $this->logs->add($this->logRecord());
        $flusher->flush();

        self::assertSame(['level' => 'info', 'message' => 'OTLP log export resumed, {dropped} log records dropped since the last export.', 'context' => ['dropped' => 1]], $this->logger->records[1] ?? null);
    }

    public function testAFailedSpanExportDropsTheLogRecordsOfTheFlushToo(): void
    {
        $flusher = $this->withLogs();
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->logs->add($this->logRecord());

        $flusher->flush();

        self::assertSame([], $this->logExporter->batches);
        self::assertSame([], $this->logs->drain());
        self::assertSame('OTLP span export failed, {count} spans dropped.', $this->logger->records[0]['message'] ?? null);
    }

    public function testAnEmptyFlushDuringThePauseKeepsThePause(): void
    {
        $flusher = $this->withLogs();
        $this->exporter->outcome = false;
        $this->fill(1);
        $flusher->flush();
        $this->exporter->outcome = true;

        $flusher->flush();
        $this->fill(1);
        $flusher->flush();

        self::assertCount(1, $this->exporter->batches);
    }

    public function testDroppedLogRecordsAddUpUntilTheyAreReported(): void
    {
        $flusher = $this->withLogs();
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->logs->add($this->logRecord());
        $flusher->flush();
        $this->logs->add($this->logRecord());
        $flusher->flush();

        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->fill(1);
        $this->logs->add($this->logRecord());
        $flusher->flush();

        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->logExporter->outcome = false;
        $this->logs->add($this->logRecord());
        $flusher->flush();

        self::assertSame('OTLP log export failed, {count} log records dropped.', $this->logger->records[2]['message'] ?? null);
        self::assertSame(4, $this->logger->records[2]['context']['count'] ?? null);
    }

    public function testTheResumeCountStartsAfreshForLogRecords(): void
    {
        $flusher = $this->withLogs();
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->logs->add($this->logRecord());
        $flusher->flush();
        $this->exporter->outcome = true;
        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->logs->add($this->logRecord());
        $flusher->flush();

        $this->logExporter->outcome = false;
        $this->logs->add($this->logRecord());
        $flusher->flush();

        self::assertSame(['dropped' => 1], $this->logger->records[1]['context'] ?? null);
        self::assertSame(1, $this->logger->records[2]['context']['count'] ?? null);
    }

    public function testTheLogExporterIsBuiltOnce(): void
    {
        $built = 0;
        $this->logs = new LogBuffer(100);
        $this->logExporter = new RecordingLogExporter();
        $flusher = new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, null, $this->logs, function () use (&$built): RecordingLogExporter {
            $built++;

            return $this->logExporter;
        });

        $this->logs->add($this->logRecord());
        $flusher->flush();
        $this->logs->add($this->logRecord());
        $flusher->flush();

        self::assertSame(1, $built);
        self::assertCount(2, $this->logExporter->batches);
    }

    public function testLogRecordsNeedALogExporterAndTheOtherWayRound(): void
    {
        try {
            new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, null, new LogBuffer(1));
            self::fail('A log buffer without a log exporter factory must be refused.');
        } catch (InvalidFlusherArgument) {
        }

        $this->expectException(InvalidFlusherArgument::class);

        new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, null, null, fn (): RecordingLogExporter => new RecordingLogExporter());
    }

    public function testAFlushStartedWhileExportingWaitsForTheNextOne(): void
    {
        $this->logs = new LogBuffer(100);
        $nested = new RecordingLogExporter();
        $flusher = new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, null, $this->logs, static fn (): RecordingLogExporter => $nested);
        $nested->onExport = function () use ($flusher): void {
            $this->logs->add(LogBufferTest::record());
            $flusher->flush();
        };
        $this->logs->add($this->logRecord());

        $flusher->flush();

        self::assertCount(1, $nested->batches);
        self::assertCount(1, $this->logs->drain(), 'the record logged while exporting waits for the next flush');
    }

    public function testAClockSetBackAfterAResumedExportDoesNotPauseAgain(): void
    {
        $this->exporter->outcome = false;
        $this->fill(1);
        $this->flusher->flush();
        $this->exporter->outcome = true;
        $this->clock->sleep(Flusher::PAUSE_SECONDS);
        $this->fill(1);
        $this->flusher->flush();

        $this->clock->modify('-1 hour');
        $this->fill(1);
        $this->flusher->flush();

        self::assertCount(3, $this->exporter->batches);
    }

    private function withLogs(): Flusher
    {
        $this->logs = new LogBuffer(100);
        $this->logExporter = new RecordingLogExporter();

        return new Flusher($this->buffer, fn (): RecordingExporter => $this->exporter, $this->clock, $this->logger, null, $this->logs, fn (): RecordingLogExporter => $this->logExporter);
    }

    private function logRecord(): ReadableLogRecord
    {
        return LogBufferTest::record();
    }

    private function fill(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->buffer->add(SpanBufferTest::span());
        }
    }
}
