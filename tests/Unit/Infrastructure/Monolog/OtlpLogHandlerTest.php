<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Monolog;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Monolog\OtlpLogHandler;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\LogBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\LogRecordMapper;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\Utf8;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtlpLogHandler::class)]
#[UsesClass(LogBuffer::class)]
#[UsesClass(LogRecordMapper::class)]
#[UsesClass(Utf8::class)]
final class OtlpLogHandlerTest extends TestCase
{
    private LogBuffer $buffer;

    private int $flushes = 0;

    #[Override]
    protected function setUp(): void
    {
        $this->buffer = new LogBuffer(2);
    }

    public function testBuffersRecordsAtOrAboveItsLevel(): void
    {
        $handler = $this->handler(Level::Info);

        $handler->handle($this->record(Level::Debug));
        $handler->handle($this->record(Level::Info, 'kept'));

        $records = $this->buffer->drain();
        self::assertCount(1, $records);
        self::assertSame('kept', $records[0]->getBody());
    }

    public function testAFullBufferFlushesEarly(): void
    {
        $handler = $this->handler(Level::Debug);

        $handler->handle($this->record(Level::Info));
        self::assertSame(0, $this->flushes);
        $handler->handle($this->record(Level::Info));

        self::assertSame(1, $this->flushes);
    }

    public function testSkipsTheRecordsOfTheExportItself(): void
    {
        $this->handler(Level::Debug)->handle($this->record(Level::Warning, channel: OtlpLogHandler::OWN_CHANNEL));

        self::assertSame([], $this->buffer->drain());
    }

    public function testLetsOtherHandlersSeeTheRecord(): void
    {
        self::assertFalse($this->handler(Level::Debug)->handle($this->record(Level::Info)));
    }

    private function handler(Level $level): OtlpLogHandler
    {
        $mapper = new LogRecordMapper(new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])), new InMemoryTraceContextHolder());
        $flush = new class($this->flushes) implements SpanFlushInterface {
            public function __construct(private int &$flushes)
            {
            }

            #[Override]
            public function flush(): void
            {
                $this->flushes++;
            }
        };

        return new OtlpLogHandler($mapper, $this->buffer, $flush, $level);
    }

    private function record(Level $level, string $message = 'm', string $channel = 'app'): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), $channel, $level, $message);
    }
}
