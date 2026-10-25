<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanMapper;
use Msstc4Symfony\TracingBridgeProfiling\Infrastructure\Tracing\TraceSpanKeys;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use OpenTelemetry\API\Trace\SpanKind;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Buffers every ended span of a sampled trace as OTLP span data:
 * - a unit span (request, command, message, action) is the unit's own trace span;
 * - a span tracing-bridge-profiling traced keeps its trace_span_id / trace_parent_span_id;
 * - a detached span (HTTP client) gets a new id under the span current when it was created.
 * Anything else has no place in the trace and is skipped.
 *
 * @internal
 */
final class OtlpEndSpanProcessor implements EndSpanProcessorInterface, ResetInterface
{
    private const MESSAGE_PREFIX = 'message ';

    private const REQUEST_PREFIX = 'request ';

    private const INVALID_SPAN_ID = '0000000000000000';

    /** @var array<string, true> span names already reported as skipped */
    private array $reported = [];

    /**
     * @param list<non-empty-string> $unitSpanPrefixes span names that open a unit of work
     */
    public function __construct(
        private readonly CreationRecorder $creations,
        private readonly UnitRecorder $units,
        private readonly TraceContextHolderInterface $holder,
        private readonly SpanMapper $mapper,
        private readonly SpanBuffer $buffer,
        private readonly SpanFlushInterface $flush,
        private readonly LoggerInterface $logger,
        private readonly array $unitSpanPrefixes,
    ) {
    }

    #[Override]
    public function process(SpanInterface $span, array $context): void
    {
        $prefix = $this->unitPrefix($span->getMessage());
        [$trace, $ids, $kind] = $prefix !== null ? $this->unit($prefix) : $this->traced($span);
        if (!$trace instanceof TraceContext || !$ids instanceof SpanIds) {
            $this->reportSkipped($span->getMessage());

            return;
        }

        if (($trace->traceParent->flags & TraceParent::FLAG_SAMPLED) === 0) {
            return;
        }

        $responseStatus = $prefix === self::REQUEST_PREFIX ? $this->units->responseStatus() : null;
        if ($this->buffer->add($this->mapper->map($span, $context, $ids, $kind, $responseStatus))) {
            $this->flush->flush();
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->reported = [];
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

    /**
     * @return array{TraceContext, SpanIds, int}
     */
    private function unit(string $prefix): array
    {
        $trace = $this->holder->current();
        if ($prefix === self::MESSAGE_PREFIX) {
            $trace = $this->units->messageTrace() ?? $trace;
            $this->units->forgetMessage();
        }

        $parent = $trace->parentSpanId;
        $ids = new SpanIds(
            $trace->traceParent->traceId,
            $trace->traceParent->spanId,
            $parent,
            $parent !== null && $trace->remoteTraceParent?->spanId === $parent,
            $trace->traceParent->flags,
        );

        return [$trace, $ids, SpanMapper::KIND_BY_UNIT_PREFIX[$prefix] ?? SpanKind::KIND_INTERNAL];
    }

    /**
     * @return array{TraceContext|null, SpanIds|null, int}
     */
    private function traced(SpanInterface $span): array
    {
        $trace = $this->creations->traceAt($span);
        if (!$trace instanceof TraceContext) {
            return [null, null, SpanKind::KIND_INTERNAL];
        }

        $context = $span->getContext();
        $traceId = $trace->traceParent->traceId;
        $spanId = $context[TraceSpanKeys::SPAN_ID] ?? null;
        if (is_string($spanId) && $spanId !== '' && TraceParent::isSpanId($spanId)) {
            $parent = $context[TraceSpanKeys::PARENT_SPAN_ID] ?? null;
            $ids = new SpanIds($traceId, $spanId, is_string($parent) && $parent !== '' && TraceParent::isSpanId($parent) ? $parent : null, false, $trace->traceParent->flags);

            return [$trace, $ids, isset($context['connection']) ? SpanKind::KIND_CLIENT : SpanKind::KIND_INTERNAL];
        }

        if ($this->isDetached($context)) {
            return [$trace, new SpanIds($traceId, $this->newSpanId(), $trace->traceParent->spanId, false, $trace->traceParent->flags), SpanKind::KIND_CLIENT];
        }

        return [null, null, SpanKind::KIND_INTERNAL];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isDetached(array $context): bool
    {
        foreach (DetachedSpanFactoryInterface::DETACHED as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function reportSkipped(string $name): void
    {
        if (isset($this->reported[$name])) {
            return;
        }

        $this->reported[$name] = true;
        $this->logger->debug('Span "{span}" is not exported: it is not part of a trace.', ['span' => $name]);
    }

    /**
     * @return non-empty-string
     */
    private function newSpanId(): string
    {
        do {
            $id = bin2hex(random_bytes(8));
        } while ($id === self::INVALID_SPAN_ID || $id === '');

        return $id;
    }
}
