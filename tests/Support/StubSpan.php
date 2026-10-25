<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Support;

use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;

/**
 * A profiling span with a fixed start time and duration.
 */
final readonly class StubSpan implements SpanInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $message,
        private array $context = [],
        private float $startTime = 1761350400.0,
        private float $duration = 0.001,
    ) {
    }

    #[Override]
    public function end(array $context = []): void
    {
    }

    #[Override]
    public function isEnded(): bool
    {
        return true;
    }

    #[Override]
    public function getDuration(): float
    {
        return $this->duration;
    }

    #[Override]
    public function getMessage(): string
    {
        return $this->message;
    }

    #[Override]
    public function getContext(): array
    {
        return $this->context;
    }

    #[Override]
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    #[Override]
    public function getParentSpan(): ?SpanInterface
    {
        return null;
    }

    #[Override]
    public function setParentSpan(?SpanInterface $parentSpan): static
    {
        throw new LogicException('Not used.');
    }

    #[Override]
    public function getEndHandlers(): array
    {
        return [];
    }

    #[Override]
    public function addEndHandler(callable $endHandler): static
    {
        throw new LogicException('Not used.');
    }

    #[Override]
    public function removeEndHandler(callable|int $endHandler): static
    {
        throw new LogicException('Not used.');
    }

    #[Override]
    public function isRecorded(): bool
    {
        return true;
    }
}
