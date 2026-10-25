<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use OpenTelemetry\SDK\Trace\SpanDataInterface;
use Override;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Spans of the current unit of work, waiting for its end.
 *
 * @internal
 */
final class SpanBuffer implements ResetInterface
{
    /** @var list<SpanDataInterface> */
    private array $spans = [];

    /**
     * @param positive-int $capacity
     */
    public function __construct(
        private readonly int $capacity,
    ) {
    }

    /**
     * @return bool true when the buffer is full and must be drained
     */
    public function add(SpanDataInterface $span): bool
    {
        $this->spans[] = $span;

        return count($this->spans) >= $this->capacity;
    }

    /**
     * @return list<SpanDataInterface>
     */
    public function drain(): array
    {
        $spans = $this->spans;
        $this->spans = [];

        return $spans;
    }

    #[Override]
    public function reset(): void
    {
        $this->spans = [];
    }
}
