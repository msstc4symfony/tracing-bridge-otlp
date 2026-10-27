<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExportedSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\StatusData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportedSpan::class)]
#[UsesClass(SpanIds::class)]
final class ExportedSpanTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    public function testHasNoEventsNorLinksAndNothingDropped(): void
    {
        $span = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', null, false, 1));

        self::assertSame([], $span->getEvents());
        self::assertSame([], $span->getLinks());
        self::assertSame(0, $span->getTotalDroppedEvents());
        self::assertSame(0, $span->getTotalDroppedLinks());
        self::assertTrue($span->hasEnded());
    }

    public function testAFailedSpanRecordsAnExceptionEventAtItsEnd(): void
    {
        $span = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', null, false, 1), exceptionType: 'RuntimeException');

        $events = $span->getEvents();

        self::assertCount(1, $events);
        self::assertSame('exception', $events[0]->getName());
        self::assertSame(2, $events[0]->getEpochNanos());
        self::assertSame(['exception.type' => 'RuntimeException'], $events[0]->getAttributes()->toArray());
    }

    public function testAConsumerLinksToTheRemoteProducer(): void
    {
        $span = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', true, 1), SpanKind::KIND_CONSUMER);

        $links = $span->getLinks();

        self::assertCount(1, $links);
        self::assertSame(self::TRACE_ID, $links[0]->getSpanContext()->getTraceId());
        self::assertSame('00f067aa0ba902b7', $links[0]->getSpanContext()->getSpanId());
        self::assertTrue($links[0]->getSpanContext()->isRemote());
        self::assertTrue($links[0]->getSpanContext()->isSampled());
    }

    /**
     * @param SpanKind::KIND_* $kind
     */
    #[TestWith([SpanKind::KIND_CONSUMER, null, true])]
    #[TestWith([SpanKind::KIND_CONSUMER, '00f067aa0ba902b7', false])]
    #[TestWith([SpanKind::KIND_SERVER, '00f067aa0ba902b7', true])]
    public function testOnlyAConsumerWithARemoteParentHasALink(int $kind, ?string $parent, bool $remote): void
    {
        self::assertSame([], $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', $parent, $remote, 1), $kind)->getLinks());
    }

    public function testARootHasAnInvalidParent(): void
    {
        $span = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', null, false, 1));

        self::assertFalse($span->getParentContext()->isValid());
        self::assertSame('0000000000000000', $span->getParentSpanId());
    }

    public function testAParentIsRemoteOnlyWhenSaidSo(): void
    {
        $remote = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', true, 1));
        $local = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', false, 1));

        self::assertTrue($remote->getParentContext()->isRemote());
        self::assertFalse($local->getParentContext()->isRemote());
        self::assertSame('00f067aa0ba902b7', $remote->getParentSpanId());
        self::assertSame('00f067aa0ba902b7', $local->getParentSpanId());
        self::assertSame(self::TRACE_ID, $local->getParentContext()->getTraceId());
    }

    public function testAnUnsampledSpanSaysSo(): void
    {
        $span = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', true, 0));

        self::assertFalse($span->getContext()->isSampled());
        self::assertFalse($span->getParentContext()->isSampled());
    }

    public function testTheRandomFlagIsNotTheSampledFlag(): void
    {
        $span = $this->span(new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', false, 2));

        self::assertSame(0, $span->getContext()->getTraceFlags());
        self::assertSame(0, $span->getParentContext()->getTraceFlags());
    }

    /**
     * @param SpanKind::KIND_* $kind
     */
    private function span(SpanIds $ids, int $kind = SpanKind::KIND_INTERNAL, ?string $exceptionType = null): ExportedSpan
    {
        return new ExportedSpan('x', $kind, $ids, StatusData::unset(), 1, 2, [], new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])), $exceptionType);
    }
}
