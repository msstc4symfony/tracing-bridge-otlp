<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Closure;
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
 * pauses exporting both, so a collector that is down costs the timeout once per pause rather than once per request or
 * message.
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

    private ?float $sharedPause = null;

    private ?float $sharedPauseReadAt = null;

    // Unix time with microseconds.
    private ?float $pausedUntil = null;

    /** Spans drained without export since the last failure. */
    private int $dropped = 0;

    /** The same for log records. */
    private int $droppedLogs = 0;

    private ?SpanExporterInterface $exporter = null;

    private ?LogRecordExporterInterface $logExporter = null;

    /**
     * @param Closure(): SpanExporterInterface $exporterFactory called on the first export: a misconfigured endpoint or
     *                                                          protocol then fails like an export, not the application
     * @param CacheItemPoolInterface|null $pauses shares the pause with the other processes using it (PHP-FPM, cron);
     *                                            null: the pause holds for this process only
     * @param Closure(): LogRecordExporterInterface|null $logExporterFactory like $exporterFactory, for $logs
     */
    public function __construct(
        private readonly SpanBuffer $buffer,
        private readonly Closure $exporterFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ?CacheItemPoolInterface $pauses = null,
        private readonly ?LogBuffer $logs = null,
        private readonly ?Closure $logExporterFactory = null,
    ) {
    }

    #[Override]
    public function flush(): void
    {
        $spans = $this->buffer->drain();
        $records = $this->logs?->drain() ?? [];
        if ($spans === [] && $records === []) {
            return;
        }

        // The later pause holds: another process may have failed after this one.
        if ($this->now() < max($this->pausedUntil ?? 0.0, $this->sharedPause() ?? 0.0)) {
            $this->dropped += count($spans);
            $this->droppedLogs += count($records);

            return;
        }

        if ($spans !== [] && !$this->exportSpans($spans)) {
            $this->droppedLogs += count($records);

            return;
        }

        if ($records !== []) {
            $this->exportLogs($records);
        }
    }

    /**
     * @param non-empty-list<SpanDataInterface> $spans
     */
    private function exportSpans(array $spans): bool
    {
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
            $this->pause();

            return false;
        }

        if ($this->dropped > 0) {
            $this->logger->info('OTLP span export resumed, {dropped} spans dropped while paused.', ['dropped' => $this->dropped]);
            $this->dropped = 0;
        }

        return true;
    }

    /**
     * @param non-empty-list<ReadableLogRecord> $records
     */
    private function exportLogs(array $records): void
    {
        try {
            $this->logExporter ??= ($this->logExporterFactory ?? throw new LogicException('Log records need a log exporter factory.'))();
            $exported = $this->logExporter->export($records)->await() === true;
            $exception = null;
        } catch (Throwable $exception) {
            $exported = false;
        }

        if (!$exported) {
            $this->logger->warning('OTLP log export failed, {count} log records dropped.', $this->failure(count($records) + $this->droppedLogs, $exception));
            $this->droppedLogs = 0;
            $this->pause();

            return;
        }

        if ($this->droppedLogs > 0) {
            $this->logger->info('OTLP log export resumed, {dropped} log records dropped while paused.', ['dropped' => $this->droppedLogs]);
            $this->droppedLogs = 0;
        }
    }

    /**
     * @return array{count: int, exception?: Throwable}
     */
    private function failure(int $count, ?Throwable $exception): array
    {
        return $exception instanceof Throwable ? ['count' => $count, 'exception' => $exception] : ['count' => $count];
    }

    private function pause(): void
    {
        $this->pausedUntil = $this->now() + self::PAUSE_SECONDS;
        $this->sharePause($this->pausedUntil);
    }

    // The cache is an optimisation: when it fails, the pause holds for this process only.
    private function sharedPause(): ?float
    {
        if (!$this->pauses instanceof CacheItemPoolInterface) {
            return null;
        }

        $now = $this->now();
        if ($this->sharedPauseReadIsFresh($now)) {
            return $this->sharedPause;
        }

        $this->sharedPauseReadAt = $now;
        try {
            $value = $this->pauses->getItem(self::PAUSE_KEY)->get();
        } catch (Throwable) {
            $value = null;
        }

        return $this->sharedPause = is_float($value) ? $value : null;
    }

    // A clock set back makes the last read stale, not fresh.
    private function sharedPauseReadIsFresh(float $now): bool
    {
        return $this->sharedPauseReadAt !== null && $now >= $this->sharedPauseReadAt
            && $now - $this->sharedPauseReadAt < self::SHARED_PAUSE_READ_SECONDS;
    }

    private function sharePause(float $until): void
    {
        try {
            if ($this->pauses instanceof CacheItemPoolInterface) {
                $this->pauses->save($this->pauses->getItem(self::PAUSE_KEY)->set($until)->expiresAfter(self::PAUSE_SECONDS));
            }
        } catch (Throwable) {
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
