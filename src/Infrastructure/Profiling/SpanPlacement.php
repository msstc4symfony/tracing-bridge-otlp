<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * A profiling span's place in its trace.
 *
 * @internal
 */
final readonly class SpanPlacement
{
    /**
     * @param SpanKind::KIND_* $kind
     * @param int|null $responseStatus status code of the main response, for a request span
     */
    public function __construct(
        public SpanIds $ids,
        public int $kind,
        public ?int $responseStatus,
    ) {
    }
}
