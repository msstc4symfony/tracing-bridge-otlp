<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use DateTimeImmutable;
use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Override;
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

    private ?DateTimeImmutable $pausedUntil = null;

    /** Spans drained without export since the last failure. */
    private int $dropped = 0;

    public function __construct(
        private readonly SpanBuffer $buffer,
        private readonly SpanExporterInterface $exporter,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function flush(): void
    {
        $spans = $this->buffer->drain();
        if ($spans === []) {
            return;
        }

        if ($this->pausedUntil instanceof DateTimeImmutable && $this->clock->now() < $this->pausedUntil) {
            $this->dropped += count($spans);

            return;
        }

        try {
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
        $this->pausedUntil = $this->clock->now()->modify('+' . self::PAUSE_SECONDS . ' seconds');
    }
}
