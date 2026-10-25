<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\AttributeLimit;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeLimit::class)]
final class AttributeLimitTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        putenv('OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT');
        putenv('OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT');
    }

    public function testDefaultsTo4096(): void
    {
        self::assertSame(4096, AttributeLimit::valueLength());
    }

    public function testTheSpanVariableWinsOverTheGeneralOne(): void
    {
        putenv('OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT=100');
        self::assertSame(100, AttributeLimit::valueLength());

        putenv('OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT=50');
        self::assertSame(50, AttributeLimit::valueLength());
    }

    public function testANonPositiveValueKeepsTheDefault(): void
    {
        putenv('OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT=0');

        self::assertSame(4096, AttributeLimit::valueLength());
    }
}
