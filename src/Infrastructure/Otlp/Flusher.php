<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Closure;
use InvalidArgumentException;
use LogicException;
use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exports the buffered spans, then the buffered log records, in one batch each. A failed export is logged once and
 * pauses that signal's export, so a collector that is down costs the timeout once per pause rather than once per
 * request or message; a log pipeline the collector lacks does not stop the spans.
 *
 * @internal
 */
final class Flusher implements SpanFlushInterface
{
    public const PAUSE_SECONDS = 30;

    // A long-running process (worker) sees a pause another process starts within this delay, without reading the cache
    // on every unit of work; a PHP-FPM request reads it once.
    public const SHARED_PAUSE_READ_SECONDS = 1;

    private const PAUSE_KEY = 'msstc4symfony_tracing_bridge_otlp.paused_until';

    private const LOG_PAUSE_KEY = 'msstc4symfony_tracing_bridge_otlp.logs_paused_until';

    private readonly ExportPause $spanPause;

    private readonly ExportPause $logPause;

    /** @var non-negative-int spans drained without export since the last failure */
    private int $dropped = 0;

    /** @var non-negative-int the same for log records */
    private int $droppedLogs = 0;

    // A record logged while exporting (an SDK logger writing to the application's) may fill the log buffer again.
    private bool $flushing = false;

    private ?SpanExporterInterface $exporter = null;

    private ?LogRecordExporterInterface $logExporter = null;

    /**
     * @param Closure(): SpanExporterInterface $exporterFactory called on the first export: a misconfigured endpoint or
     *                                                          protocol then fails like an export, not the application
     * @param CacheItemPoolInterface|null $pauses shares the pauses with the other processes using it (PHP-FPM, cron);
     *                                            null: a pause holds for this process only
     * @param (Closure(): LogRecordExporterInterface)|null $logExporterFactory like $exporterFactory, for $logs; given
     *                                                                         together with $logs or not at all
     *
     * @throws InvalidArgumentException for log records without a log exporter, or the other way round
     */
    public function __construct(
        private readonly SpanBuffer $buffer,
        private readonly Closure $exporterFactory,
        ClockInterface $clock,
        private readonly LoggerInterface $logger,
        ?CacheItemPoolInterface $pauses = null,
        private readonly ?LogBuffer $logs = null,
        private readonly ?Closure $logExporterFactory = null,
    ) {
        $exportsLogs = $logs instanceof LogBuffer;
        $buildsLogExporter = $logExporterFactory instanceof Closure;
        if ($exportsLogs !== $buildsLogExporter) {
            throw new InvalidArgumentException('Log records need a log exporter factory, and the factory needs the log buffer.');
        }

        $this->spanPause = new ExportPause($clock, $pauses, self::PAUSE_KEY);
        $this->logPause = new ExportPause($clock, $pauses, self::LOG_PAUSE_KEY);
    }

    #[Override]
    public function flush(): void
    {
        if ($this->flushing) {
            return;
        }

        $this->flushing = true;
        try {
            $this->exportSpans($this->buffer->drain());
            $this->exportLogs($this->logs?->drain() ?? []);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * @param list<SpanDataInterface> $spans
     */
    private function exportSpans(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        if ($this->spanPause->isActive()) {
            $this->dropped += count($spans);

            return;
        }

        try {
            $this->exporter ??= ($this->exporterFactory)();
            $exported = $this->exporter->export($spans)->await() === true;
            $exception = null;
        } catch (Throwable $exception) {
            $exported = false;
        }

        if (!$exported) {
            $this->logger->warning('OTLP span export failed, {count} spans dropped.', $this->failure(count($spans) + $this->dropped, $exception));
            $this->dropped = 0;
            $this->spanPause->start();

            return;
        }

        if ($this->dropped > 0) {
            $this->logger->info('OTLP span export resumed, {dropped} spans dropped while paused.', ['dropped' => $this->dropped]);
            $this->dropped = 0;
        }

        $this->spanPause->end();
    }

    /**
     * @param list<ReadableLogRecord> $records
     */
    private function exportLogs(array $records): void
    {
        if ($records === []) {
            return;
        }

        if ($this->logPause->isActive()) {
            $this->droppedLogs += count($records);

            return;
        }

        try {
            $this->logExporter ??= ($this->logExporterFactory ?? throw new LogicException('No log exporter factory.'))();
            $exported = $this->logExporter->export($records)->await() === true;
            $exception = null;
        } catch (Throwable $exception) {
            $exported = false;
        }

        if (!$exported) {
            $this->logger->warning('OTLP log export failed, {count} log records dropped.', $this->failure(count($records) + $this->droppedLogs, $exception));
            $this->droppedLogs = 0;
            $this->logPause->start();

            return;
        }

        if ($this->droppedLogs > 0) {
            $this->logger->info('OTLP log export resumed, {dropped} log records dropped while paused.', ['dropped' => $this->droppedLogs]);
            $this->droppedLogs = 0;
        }

        $this->logPause->end();
    }

    /**
     * @param non-negative-int $count
     *
     * @return array{count: non-negative-int, exception?: Throwable}
     */
    private function failure(int $count, ?Throwable $exception): array
    {
        return $exception instanceof Throwable ? ['count' => $count, 'exception' => $exception] : ['count' => $count];
    }
}
