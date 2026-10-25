<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use InvalidArgumentException;

/**
 * Where an exported span sits in its trace: the ids tracing-bundle logs and propagates.
 *
 * @internal
 */
final readonly class SpanIds
{
    private const TRACE_ID = '/^(?!0{32})[0-9a-f]{32}$/D';

    private const SPAN_ID = '/^(?!0{16})[0-9a-f]{16}$/D';

    /**
     * @param string $traceId 32 lowercase hex digits, not all zero
     * @param string $spanId 16 lowercase hex digits, not all zero
     * @param string|null $parentSpanId like $spanId; null at the root of the trace
     * @param bool $remoteParent the parent span belongs to another service
     * @param int<0, 3> $flags W3C trace flags
     *
     * @throws InvalidArgumentException for a malformed id
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public bool $remoteParent,
        public int $flags,
    ) {
        if (preg_match(self::TRACE_ID, $traceId) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid trace id "%s".', $traceId));
        }

        if (preg_match(self::SPAN_ID, $spanId) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid span id "%s".', $spanId));
        }

        if ($parentSpanId !== null && preg_match(self::SPAN_ID, $parentSpanId) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid parent span id "%s".', $parentSpanId));
        }
    }
}
