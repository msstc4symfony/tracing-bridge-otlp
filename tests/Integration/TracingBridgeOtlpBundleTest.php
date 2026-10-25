<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Integration;

use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use PHPUnit\Framework\TestCase;

final class TracingBridgeOtlpBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        self::assertSame('msstc4symfony_tracing_bridge_otlp', (new TracingBridgeOtlpBundle())->getContainerExtension()?->getAlias());
    }
}
