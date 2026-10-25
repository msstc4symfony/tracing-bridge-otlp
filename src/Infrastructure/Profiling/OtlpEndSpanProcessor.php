<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling;

use Msstc4Symfony\ProfilingBundle\Application\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanMapper;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Buffers every ended span of a sampled trace as OTLP span data; a full buffer is exported early.
 *
 * @internal
 */
final class OtlpEndSpanProcessor implements EndSpanProcessorInterface, ResetInterface
{
    // A long-running command without kernel.reset must not grow the list without bound.
    public const MAX_REPORTED_NAMES = 256;

    /** @var array<string, true> span names already reported as skipped */
    private array $reported = [];

    public function __construct(
        private readonly SpanPlacer $placer,
        private readonly SpanMapper $mapper,
        private readonly SpanBuffer $buffer,
        private readonly SpanFlushInterface $flush,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function process(SpanInterface $span, array $context): void
    {
        $placement = $this->placer->place($span);
        if (!$placement instanceof SpanPlacement) {
            $this->reportSkipped($span->getMessage());

            return;
        }

        if (($placement->ids->flags & TraceParent::FLAG_SAMPLED) === 0) {
            return;
        }

        if ($this->buffer->add($this->mapper->map($span, $context, $placement->ids, $placement->kind, $placement->responseStatus))) {
            $this->flush->flush();
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->reported = [];
    }

    private function reportSkipped(string $name): void
    {
        if (isset($this->reported[$name])) {
            return;
        }

        if (count($this->reported) < self::MAX_REPORTED_NAMES) {
            $this->reported[$name] = true;
        }

        $this->logger->debug('Span "{span}" is not exported: it is not part of a trace.', ['span' => $name]);
    }
}
