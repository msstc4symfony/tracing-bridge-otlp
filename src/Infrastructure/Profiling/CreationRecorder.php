<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling;

use Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Override;
use WeakMap;

/**
 * Remembers the trace that was current when each span was created: by the time the span ends, the unit of work may
 * have moved on. Registered after tracing-bridge-profiling, so it keys the span object end processors receive. Not
 * reset on kernel.reset: profiling ends left-open spans there, in an order the resetter does not guarantee; the WeakMap
 * forgets spans as they are collected.
 *
 * @internal
 */
final class CreationRecorder implements CreateSpanProcessorInterface
{
    /** @var WeakMap<SpanInterface, TraceContext> */
    private WeakMap $traces;

    public function __construct(
        private readonly TraceContextHolderInterface $holder,
    ) {
        $this->traces = new WeakMap();
    }

    #[Override]
    public function process(SpanInterface $span): SpanInterface
    {
        $this->traces[$span] = $this->holder->current();

        return $span;
    }

    public function traceAt(SpanInterface $span): ?TraceContext
    {
        return $this->traces[$span] ?? null;
    }
}
