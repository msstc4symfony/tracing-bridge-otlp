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

    private function span(SpanIds $ids): ExportedSpan
    {
        return new ExportedSpan('x', SpanKind::KIND_INTERNAL, $ids, StatusData::unset(), 1, 2, [], new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])));
    }
}
