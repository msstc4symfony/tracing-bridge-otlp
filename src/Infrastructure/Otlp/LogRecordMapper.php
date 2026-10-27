<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use OpenTelemetry\API\Logs\LogRecord as OtelLogRecord;
use OpenTelemetry\API\Signals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Logs\LoggerSharedState;
use OpenTelemetry\SDK\Logs\LogRecordLimitsBuilder;
use OpenTelemetry\SDK\Logs\Processor\NoopLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Throwable;

/**
 * Turns a Monolog record into an OTLP log record of the current span. Scalar context and extra values become
 * attributes (context first); tracing-bundle's trace keys are the record's trace context instead.
 *
 * @internal
 */
final readonly class LogRecordMapper
{
    // Keys TraceLogProcessor adds that the record's trace context already carries.
    private const TRACE_KEYS = ['trace_id' => true, 'span_id' => true];

    private const TRACE_ID = '/^(?!0{32})[0-9a-f]{32}$/D';

    // OpenTelemetry severity numbers (logs data model) of the Monolog levels.
    private const SEVERITIES = [
        Level::Debug->value => 5,
        Level::Info->value => 9,
        Level::Notice->value => 10,
        Level::Warning->value => 13,
        Level::Error->value => 17,
        Level::Critical->value => 18,
        Level::Alert->value => 19,
        Level::Emergency->value => 21,
    ];

    private const NANOS_PER_SECOND = 1_000_000_000;

    private const NANOS_PER_MICRO = 1000;

    private LoggerSharedState $shared;

    /** @var positive-int */
    private int $valueLengthLimit;

    /**
     * @param positive-int|null $valueLengthLimit null: the OTEL_*ATTRIBUTE_VALUE_LENGTH_LIMIT variables at runtime
     */
    public function __construct(
        private InstrumentationScopeInterface $scope,
        ResourceInfo $resource,
        private TraceContextHolderInterface $holder,
        ?int $valueLengthLimit = null,
    ) {
        $this->valueLengthLimit = $valueLengthLimit ?? AttributeLimit::valueLength(Signals::LOGS);
        $limits = (new LogRecordLimitsBuilder())->setAttributeValueLengthLimit($this->valueLengthLimit)->build();
        $this->shared = new LoggerSharedState($resource, $limits, new NoopLogRecordProcessor());
    }

    public function map(LogRecord $record): ReadableLogRecord
    {
        $otel = (new OtelLogRecord(Utf8::valid($record->message)))
            ->setTimestamp($this->nanos($record))
            ->setSeverityNumber(self::SEVERITIES[$record->level->value])
            ->setSeverityText($record->level->getName())
            ->setAttributes($this->attributes($record))
            ->setContext(Context::getRoot()->withContextValue(Span::wrap($this->spanContext($record))))
        ;

        return new ReadableLogRecord($this->scope, $this->shared, $otel);
    }

    private function nanos(LogRecord $record): int
    {
        return (int) $record->datetime->format('U') * self::NANOS_PER_SECOND + (int) $record->datetime->format('u') * self::NANOS_PER_MICRO;
    }

    /**
     * The ids TraceLogProcessor wrote into the record when it was logged: a buffering handler (fingers_crossed, buffer)
     * passes records on later, when another span or unit of work is current. Without them, the current span.
     */
    private function spanContext(LogRecord $record): SpanContextInterface
    {
        $current = $this->holder->current()->traceParent;
        $traceId = $record->extra['trace_id'] ?? null;
        $spanId = $record->extra['span_id'] ?? null;
        if (!is_string($traceId) || preg_match(self::TRACE_ID, $traceId) !== 1 || !is_string($spanId) || !TraceParent::isSpanId($spanId)) {
            return SpanContext::create($current->traceId, $current->spanId, $this->traceFlags($current));
        }

        // The record does not carry the flags: those of its trace if it is still current, else not sampled.
        return SpanContext::create($traceId, $spanId, $traceId === $current->traceId ? $this->traceFlags($current) : TraceFlags::DEFAULT);
    }

    private function traceFlags(TraceParent $traceParent): int
    {
        return ($traceParent->flags & TraceParent::FLAG_SAMPLED) !== 0 ? TraceFlags::SAMPLED : TraceFlags::DEFAULT;
    }

    /**
     * @return array<string, bool|int|float|string>
     */
    private function attributes(LogRecord $record): array
    {
        $attributes = ['monolog.channel' => $this->cut(Utf8::valid($record->channel))];
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $attributes['exception.type'] = $exception::class;
            $attributes['exception.message'] = $this->cut(Utf8::valid($exception->getMessage()));
        }

        $values = array_diff_key([...$this->scalars($record->extra), ...$this->scalars($record->context)], self::TRACE_KEYS);

        // The mapper's own names win over context and extra keys spelled the same.
        return [...$values, ...$attributes];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, bool|int|float|string>
     */
    private function scalars(array $values): array
    {
        $scalars = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !Utf8::isValid($key) || !is_scalar($value) || (is_float($value) && !is_finite($value))) {
                continue;
            }

            $scalars[$key] = is_string($value) ? $this->cut($value) : $value;
        }

        return $scalars;
    }

    private function cut(string $value): string
    {
        return Utf8::cut($value, $this->valueLengthLimit);
    }
}
