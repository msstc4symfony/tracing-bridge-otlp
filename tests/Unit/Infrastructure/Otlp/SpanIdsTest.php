<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use InvalidArgumentException;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanIds::class)]
final class SpanIdsTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    public function testKeepsValidIds(): void
    {
        $ids = new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', true, 1);

        self::assertSame([self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', true, 1], [$ids->traceId, $ids->spanId, $ids->parentSpanId, $ids->remoteParent, $ids->flags]);
    }

    #[TestWith(['00000000000000000000000000000000', 'b7ad6b7169203331', null])]
    #[TestWith(['4BF92F3577B34DA6A3CE929D0E0E4736', 'b7ad6b7169203331', null])]
    #[TestWith(['4bf92f3577b34da6a3ce929d0e0e473', 'b7ad6b7169203331', null])]
    #[TestWith(["4bf92f3577b34da6a3ce929d0e0e4736\n", 'b7ad6b7169203331', null])]
    #[TestWith([self::TRACE_ID, '0000000000000000', null])]
    #[TestWith([self::TRACE_ID, 'zz', null])]
    #[TestWith([self::TRACE_ID, 'b7ad6b7169203331', 'zz'])]
    #[TestWith([self::TRACE_ID, 'b7ad6b7169203331', ''])]
    public function testRejectsInvalidIds(string $traceId, string $spanId, ?string $parentSpanId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpanIds($traceId, $spanId, $parentSpanId, false, 1);
    }
}
