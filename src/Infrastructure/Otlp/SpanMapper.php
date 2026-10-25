<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\TracingBridgeProfiling\Infrastructure\Tracing\TraceSpanKeys;
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
    // Keys of the bridges and profiling markers: ids and flags, not span data.
    private const SKIPPED_KEYS = [
        TraceSpanKeys::SPAN_ID => true,
        TraceSpanKeys::PARENT_SPAN_ID => true,
        ...DetachedSpanFactoryInterface::DETACHED,
        ...DetachedSpanFactoryInterface::AUTOMATIC,
    ];

    private const AUTOMATIC_MARKER = 'profiling_automatic';

    private const SERVER_ERROR = 500;

    private const MICROS_PER_SECOND = 1_000_000;

    private const NANOS_PER_MICRO = 1000;

    /**
     * @param positive-int $valueLengthLimit longer strings are cut to this many bytes
     */
    public function __construct(
        private InstrumentationScopeInterface $scope,
        private ResourceInfo $resource,
        private int $valueLengthLimit = AttributeLimit::DEFAULT_VALUE_LENGTH,
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
            $this->validName($span->getMessage()),
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
     * The SDK's converter throws on a name that is not UTF-8, which would lose the whole batch.
     */
    private function validName(string $name): string
    {
        return preg_match('//u', $name) === 1 ? $name : (string) preg_replace('/[\x80-\xFF]/', '?', $name);
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
            if (isset(self::SKIPPED_KEYS[$key]) || !is_scalar($value) || (is_float($value) && !is_finite($value))) {
                continue;
            }

            $attributes['profiling.' . $key] = is_string($value) ? substr($value, 0, $this->valueLengthLimit) : $value;
        }

        return [...$attributes, ...$this->semconv($context, $responseStatus)];
    }

    /**
     * OpenTelemetry semantic convention names, for values of the type the convention expects.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, int|string>
     */
    private function semconv(array $context, ?int $responseStatus): array
    {
        $semconv = [];
        $exception = $this->nonEmptyString($context['exception'] ?? null);
        if ($exception !== null) {
            $semconv['exception.type'] = substr($exception, 0, $this->valueLengthLimit);
        }

        $status = $responseStatus ?? $context['status'] ?? null;
        if (is_int($status)) {
            $semconv['http.response.status_code'] = $status;
        }

        $connection = $this->nonEmptyString($context['connection'] ?? null);
        if ($connection !== null && ($context[self::AUTOMATIC_MARKER] ?? false) === true) {
            $semconv['db.namespace'] = substr($connection, 0, $this->valueLengthLimit);
        }

        $transport = $this->nonEmptyString($context['transport'] ?? null);
        if ($transport !== null) {
            $semconv['messaging.destination.name'] = substr($transport, 0, $this->valueLengthLimit);
        }

        return $semconv;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function status(array $context, ?int $responseStatus): StatusDataInterface
    {
        $status = $responseStatus ?? (is_int($context['status'] ?? null) ? $context['status'] : null);
        $failed = $this->nonEmptyString($context['exception'] ?? null) !== null
            || ($context['message_failed'] ?? false) === true
            || ($status !== null && $status >= self::SERVER_ERROR);

        return $failed ? StatusData::error() : StatusData::unset();
    }
}
