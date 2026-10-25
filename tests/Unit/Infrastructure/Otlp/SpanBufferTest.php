<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExportedSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\StatusData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanBuffer::class)]
#[UsesClass(ExportedSpan::class)]
#[UsesClass(SpanIds::class)]
final class SpanBufferTest extends TestCase
{
    public function testAFullBufferFlushesEarly(): void
    {
        $buffer = new SpanBuffer(2);

        self::assertFalse($buffer->add(self::span()));
        self::assertTrue($buffer->add(self::span()));
    }

    public function testDrainEmptiesTheBuffer(): void
    {
        $buffer = new SpanBuffer(5);
        $first = self::span();
        $second = self::span();
        $buffer->add($first);
        $buffer->add($second);

        self::assertSame([$first, $second], $buffer->drain());
        self::assertSame([], $buffer->drain());
        self::assertFalse($buffer->add(self::span()));
    }

    public function testResetDropsBufferedSpans(): void
    {
        $buffer = new SpanBuffer(5);
        $buffer->add(self::span());

        $buffer->reset();

        self::assertSame([], $buffer->drain());
    }

    public static function span(): ExportedSpan
    {
        return new ExportedSpan('x', SpanKind::KIND_INTERNAL, new SpanIds('4bf92f3577b34da6a3ce929d0e0e4736', 'b7ad6b7169203331', null, false, 1), StatusData::unset(), 1, 2, [], new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])));
    }
}
