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
final readonly class OtlpEndSpanProcessor implements EndSpanProcessorInterface, ResetInterface
{
    public function __construct(
        private SpanPlacer $placer,
        private SpanMapper $mapper,
        private SpanBuffer $buffer,
        private SpanFlushInterface $flush,
        private LoggerInterface $logger,
        private ReportedNames $skipped = new ReportedNames(),
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
        $this->skipped->reset();
    }

    private function reportSkipped(string $name): void
    {
        if ($this->skipped->first($name)) {
            $this->logger->debug('Span "{span}" is not exported: it is not part of a trace.', ['span' => $name]);
        }
    }
}
