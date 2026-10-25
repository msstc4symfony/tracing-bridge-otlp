<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Integration;

use LogicException;
use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use PHPUnit\Framework\TestCase;

final class TracingBridgeOtlpBundleTest extends TestCase
{
    private const GRPC_TRANSPORT_FACTORY = 'OpenTelemetry\Contrib\Grpc\GrpcTransportFactory';

    public function testExtensionAlias(): void
    {
        self::assertSame('msstc4symfony_tracing_bridge_otlp', (new TracingBridgeOtlpBundle())->getContainerExtension()?->getAlias());
    }

    public function testAConfiguredGrpcProtocolNeedsItsTransportAtBuildTime(): void
    {
        if (class_exists(self::GRPC_TRANSPORT_FACTORY)) {
            self::markTestSkipped('open-telemetry/transport-grpc is installed');
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('open-telemetry/transport-grpc');

        TestContainer::compile(['protocol' => 'grpc']);
    }
}
