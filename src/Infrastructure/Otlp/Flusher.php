<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Closure;
use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exports the buffered spans in one batch. A failed export is logged once and pauses exporting, so a collector that
 * is down costs the timeout once per pause rather than once per request or message.
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

    private ?SpanExporterInterface $exporter = null;

    /**
     * @param Closure(): SpanExporterInterface $exporterFactory called on the first export: a misconfigured endpoint or
     *                                                          protocol then fails like an export, not the application
     * @param CacheItemPoolInterface|null $pauses shares the pause with the other processes (PHP-FPM, cron commands);
     *                                            null: the pause holds for this process only
     */
    public function __construct(
        private readonly SpanBuffer $buffer,
        private readonly Closure $exporterFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ?CacheItemPoolInterface $pauses = null,
    ) {
    }

    #[Override]
    public function flush(): void
    {
        $spans = $this->buffer->drain();
        if ($spans === []) {
            return;
        }

        // The later pause holds: another process may have failed after this one.
        if ($this->now() < max($this->pausedUntil ?? 0.0, $this->sharedPause() ?? 0.0)) {
            $this->dropped += count($spans);

            return;
        }

        try {
            $this->exporter ??= ($this->exporterFactory)();
            $exported = $this->exporter->export($spans)->await();
        } catch (Throwable $exception) {
            $this->fail(count($spans), $exception);

            return;
        }

        if ($exported !== true) {
            $this->fail(count($spans), null);

            return;
        }

        if ($this->dropped > 0) {
            $this->logger->info('OTLP span export resumed, {dropped} spans dropped while paused.', ['dropped' => $this->dropped]);
        }

        $this->pausedUntil = null;
        $this->dropped = 0;
    }

    private function fail(int $count, ?Throwable $exception): void
    {
        $context = ['count' => $count + $this->dropped];
        if ($exception instanceof Throwable) {
            $context['exception'] = $exception;
        }

        $this->logger->warning('OTLP span export failed, {count} spans dropped.', $context);
        $this->dropped = 0;
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
        if ($this->sharedPauseReadAt !== null && $now >= $this->sharedPauseReadAt && $now - $this->sharedPauseReadAt < self::SHARED_PAUSE_READ_SECONDS) {
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
