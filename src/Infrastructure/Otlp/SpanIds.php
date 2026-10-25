<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

/**
 * Where an exported span sits in its trace: the ids tracing-bundle logs and propagates.
 *
 * @internal
 */
final readonly class SpanIds
{
    /**
     * @param string $traceId 32 lowercase hex digits
     * @param string $spanId 16 lowercase hex digits
     * @param string|null $parentSpanId 16 lowercase hex digits; null at the root of the trace
     * @param bool $remoteParent the parent span belongs to another service
     * @param int<0, 3> $flags W3C trace flags
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public bool $remoteParent,
        public int $flags,
    ) {
    }
}
