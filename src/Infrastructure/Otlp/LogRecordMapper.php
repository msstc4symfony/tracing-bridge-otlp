<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use OpenTelemetry\API\Logs\LogRecord as OtelLogRecord;
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
        $this->valueLengthLimit = $valueLengthLimit ?? AttributeLimit::valueLength();
        $limits = (new LogRecordLimitsBuilder())->setAttributeValueLengthLimit($this->valueLengthLimit)->build();
        $this->shared = new LoggerSharedState($resource, $limits, new NoopLogRecordProcessor());
    }

    public function map(LogRecord $record): ReadableLogRecord
    {
        $traceParent = $this->holder->current()->traceParent;
        $otel = (new OtelLogRecord(Utf8::valid($record->message)))
            ->setTimestamp($this->nanos($record))
            ->setSeverityNumber(self::SEVERITIES[$record->level->value])
            ->setSeverityText($record->level->getName())
            ->setAttributes($this->attributes($record))
            ->setContext(Context::getRoot()->withContextValue(Span::wrap($this->spanContext($traceParent))))
        ;

        return new ReadableLogRecord($this->scope, $this->shared, $otel);
    }

    private function nanos(LogRecord $record): int
    {
        return (int) $record->datetime->format('U') * self::NANOS_PER_SECOND + (int) $record->datetime->format('u') * self::NANOS_PER_MICRO;
    }

    private function spanContext(TraceParent $traceParent): SpanContextInterface
    {
        return SpanContext::create($traceParent->traceId, $traceParent->spanId, ($traceParent->flags & TraceParent::FLAG_SAMPLED) !== 0 ? TraceFlags::SAMPLED : TraceFlags::DEFAULT);
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

        $values = [...$this->scalars($record->extra), ...$this->scalars($record->context)];
        unset($values['exception']);
        foreach (self::TRACE_KEYS as $key => $_) {
            unset($values[$key]);
        }

        return [...$attributes, ...$values];
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
