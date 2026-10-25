<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\TracingBridgeProfiling\Infrastructure\Tracing\TraceSpanKeys;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\SDK\Trace\StatusDataInterface;

/**
 * Turns an ended profiling span into OTLP span data. Only scalar context values are exported, so nothing beyond what
 * profiling already sanitised (no SQL text, no query string) leaves the process.
 *
 * @internal
 */
final readonly class SpanMapper
{
    /** @var array<string, SpanKind::KIND_*> */
    public const KIND_BY_UNIT_PREFIX = [
        'request ' => SpanKind::KIND_SERVER,
        'message ' => SpanKind::KIND_CONSUMER,
    ];

    // Keys of the bridges and profiling markers: ids and flags, not span data.
    private const SKIPPED_KEYS = [
        TraceSpanKeys::SPAN_ID => true,
        TraceSpanKeys::PARENT_SPAN_ID => true,
        ...DetachedSpanFactoryInterface::DETACHED,
        ...DetachedSpanFactoryInterface::AUTOMATIC,
    ];

    // OpenTelemetry semantic convention names next to the profiling.* copy.
    private const SEMCONV = [
        'exception' => 'exception.type',
        'status' => 'http.response.status_code',
        'connection' => 'db.namespace',
        'transport' => 'messaging.destination.name',
    ];

    private const SERVER_ERROR = 500;

    private const MICROS_PER_SECOND = 1_000_000;

    private const NANOS_PER_MICRO = 1000;

    public function __construct(
        private InstrumentationScopeInterface $scope,
        private ResourceInfo $resource,
    ) {
    }

    /**
     * @param array<string, mixed> $endContext context passed to end(); overrides the creation context
     * @param int|null $responseStatus HTTP status of the response, for a request span
     */
    public function map(SpanInterface $span, array $endContext, SpanIds $ids, int $kind, ?int $responseStatus): ExportedSpan
    {
        $context = [...$span->getContext(), ...$endContext];
        $startNanos = $this->nanos($span->getStartTime());

        return new ExportedSpan(
            $span->getMessage(),
            $kind,
            $ids,
            $this->status($context, $responseStatus),
            $startNanos,
            $startNanos + $this->nanos($span->getDuration()),
            $this->attributes($context, $responseStatus),
            $this->scope,
            $this->resource,
        );
    }

    /**
     * Microsecond precision, as microtime() gives: a Unix time in nanoseconds is above 2^53, where a float loses digits.
     * Rounded by hand: round() of floats this large differs between PHP versions.
     */
    private function nanos(float $seconds): int
    {
        $micros = $seconds * self::MICROS_PER_SECOND;
        $whole = (int) $micros;

        return ($micros - $whole >= 0.5 ? $whole + 1 : $whole) * self::NANOS_PER_MICRO;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, bool|int|float|string>
     */
    private function attributes(array $context, ?int $responseStatus): array
    {
        $attributes = [];
        foreach ($context as $key => $value) {
            if (!is_scalar($value) || isset(self::SKIPPED_KEYS[$key])) {
                continue;
            }

            $attributes['profiling.' . $key] = $value;
            if (isset(self::SEMCONV[$key])) {
                $attributes[self::SEMCONV[$key]] = $value;
            }
        }

        if ($responseStatus !== null) {
            $attributes['http.response.status_code'] = $responseStatus;
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function status(array $context, ?int $responseStatus): StatusDataInterface
    {
        $status = $responseStatus ?? (is_int($context['status'] ?? null) ? $context['status'] : null);
        $failed = isset($context['exception'])
            || ($context['message_failed'] ?? false) === true
            || ($status !== null && $status >= self::SERVER_ERROR);

        return $failed ? StatusData::error() : StatusData::unset();
    }
}
