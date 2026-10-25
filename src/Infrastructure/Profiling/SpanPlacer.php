<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use Msstc4Symfony\TracingBridgeProfiling\Infrastructure\Tracing\TraceSpanKeys;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * Finds the ids of an ended profiling span:
 * - a unit span (request, command, message, action) is the unit's own trace span;
 * - a span tracing-bridge-profiling traced keeps its trace_span_id / trace_parent_span_id;
 * - a detached span (HTTP client) gets a new id under the span current when it was created.
 * Anything else has no place in the trace.
 *
 * @internal
 */
final readonly class SpanPlacer
{
    private const REQUEST_PREFIX = 'request ';

    private const MESSAGE_PREFIX = 'message ';

    /** @var array<string, SpanKind::KIND_*> */
    private const KIND_BY_UNIT_PREFIX = [
        self::REQUEST_PREFIX => SpanKind::KIND_SERVER,
        self::MESSAGE_PREFIX => SpanKind::KIND_CONSUMER,
    ];

    /**
     * @param list<non-empty-string> $unitSpanPrefixes span names that open a unit of work
     */
    public function __construct(
        private CreationRecorder $creations,
        private UnitRecorder $units,
        private TraceContextHolderInterface $holder,
        private array $unitSpanPrefixes,
    ) {
    }

    /**
     * @return SpanPlacement|null null for a span outside every trace
     */
    public function place(SpanInterface $span): ?SpanPlacement
    {
        $prefix = $this->unitPrefix($span->getMessage());

        return $prefix !== null ? $this->unit($prefix) : $this->traced($span);
    }

    private function unit(string $prefix): SpanPlacement
    {
        $trace = $this->holder->current();
        if ($prefix === self::MESSAGE_PREFIX) {
            $trace = $this->units->takeMessageTrace() ?? $trace;
        }

        $parent = $trace->parentSpanId;
        $remote = $parent !== null && $trace->remoteTraceParent?->spanId === $parent;
        $responseStatus = $prefix === self::REQUEST_PREFIX ? $this->units->responseStatus() : null;

        return new SpanPlacement(
            $this->ids($trace, $trace->traceParent->spanId, $parent, $remote),
            self::KIND_BY_UNIT_PREFIX[$prefix] ?? SpanKind::KIND_INTERNAL,
            $responseStatus,
        );
    }

    private function traced(SpanInterface $span): ?SpanPlacement
    {
        $trace = $this->creations->traceAt($span);
        if (!$trace instanceof TraceContext) {
            return null;
        }

        $context = $span->getContext();
        $spanId = $this->spanId($context[TraceSpanKeys::SPAN_ID] ?? null);
        if ($spanId !== null) {
            $ids = $this->ids($trace, $spanId, $this->spanId($context[TraceSpanKeys::PARENT_SPAN_ID] ?? null), false);

            return new SpanPlacement($ids, $this->matches($context, DetachedSpanFactoryInterface::AUTOMATIC) && isset($context['connection']) ? SpanKind::KIND_CLIENT : SpanKind::KIND_INTERNAL, null);
        }

        if ($this->matches($context, DetachedSpanFactoryInterface::DETACHED)) {
            return new SpanPlacement($this->ids($trace, $trace->traceParent->child()->spanId, $trace->traceParent->spanId, false), SpanKind::KIND_CLIENT, null);
        }

        return null;
    }

    private function unitPrefix(string $name): ?string
    {
        foreach ($this->unitSpanPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    private function ids(TraceContext $trace, string $spanId, ?string $parentSpanId, bool $remoteParent): SpanIds
    {
        return new SpanIds($trace->traceParent->traceId, $spanId, $parentSpanId, $remoteParent, $trace->traceParent->flags);
    }

    private function spanId(mixed $value): ?string
    {
        return is_string($value) && TraceParent::isSpanId($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, true> $marker
     */
    private function matches(array $context, array $marker): bool
    {
        foreach ($marker as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
