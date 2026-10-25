<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\StatusDataInterface;
use Override;

/**
 * An ended profiling span as OTLP span data, with the ids of tracing-bundle rather than ids of the OpenTelemetry SDK.
 *
 * @internal
 */
final readonly class ExportedSpan implements SpanDataInterface
{
    /**
     * @param array<string, bool|int|float|string> $attributes
     */
    public function __construct(
        private string $name,
        private int $kind,
        private SpanIds $ids,
        private StatusDataInterface $status,
        private int $startEpochNanos,
        private int $endEpochNanos,
        private array $attributes,
        private InstrumentationScopeInterface $scope,
        private ResourceInfo $resource,
    ) {
    }

    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function getKind(): int
    {
        return $this->kind;
    }

    #[Override]
    public function getContext(): SpanContextInterface
    {
        return SpanContext::create($this->ids->traceId, $this->ids->spanId, $this->traceFlags());
    }

    #[Override]
    public function getParentContext(): SpanContextInterface
    {
        if ($this->ids->parentSpanId === null) {
            return SpanContext::getInvalid();
        }

        return $this->ids->remoteParent
            ? SpanContext::createFromRemoteParent($this->ids->traceId, $this->ids->parentSpanId, $this->traceFlags())
            : SpanContext::create($this->ids->traceId, $this->ids->parentSpanId, $this->traceFlags());
    }

    #[Override]
    public function getTraceId(): string
    {
        return $this->ids->traceId;
    }

    #[Override]
    public function getSpanId(): string
    {
        return $this->ids->spanId;
    }

    #[Override]
    public function getParentSpanId(): string
    {
        return $this->getParentContext()->getSpanId();
    }

    #[Override]
    public function getStatus(): StatusDataInterface
    {
        return $this->status;
    }

    #[Override]
    public function getStartEpochNanos(): int
    {
        return $this->startEpochNanos;
    }

    /**
     * Reason for the ignore: the OpenTelemetry SDK declares AttributesInterface without its iterable value type.
     */
    #[Override] // @phpstan-ignore missingType.iterableValue
    public function getAttributes(): AttributesInterface
    {
        return Attributes::create($this->attributes);
    }

    #[Override]
    public function getEvents(): array
    {
        return [];
    }

    #[Override]
    public function getLinks(): array
    {
        return [];
    }

    #[Override]
    public function getEndEpochNanos(): int
    {
        return $this->endEpochNanos;
    }

    #[Override]
    public function hasEnded(): bool
    {
        return true;
    }

    #[Override]
    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->scope;
    }

    #[Override]
    public function getResource(): ResourceInfo
    {
        return $this->resource;
    }

    #[Override]
    public function getTotalDroppedEvents(): int
    {
        return 0;
    }

    #[Override]
    public function getTotalDroppedLinks(): int
    {
        return 0;
    }

    private function traceFlags(): int
    {
        return $this->ids->flags & TraceFlags::SAMPLED;
    }
}
