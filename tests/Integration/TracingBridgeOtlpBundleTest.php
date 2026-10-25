<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Integration;

use LogicException;
use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class TracingBridgeOtlpBundleTest extends TestCase
{
    private const GRPC_TRANSPORT_FACTORY = 'OpenTelemetry\Contrib\Grpc\GrpcTransportFactory';

    public function testExtensionAlias(): void
    {
        self::assertSame('msstc4symfony_tracing_bridge_otlp', (new TracingBridgeOtlpBundle())->getContainerExtension()?->getAlias());
    }

    public function testThePauseLivesInTheShareDirWhenTheKernelHasOne(): void
    {
        self::assertSame('%kernel.share_dir%/msstc4symfony_tracing_bridge_otlp', $this->pauseDir(['kernel.share_dir' => '/srv/share', 'kernel.cache_dir' => '/srv/cache']));
        self::assertSame('%kernel.cache_dir%/msstc4symfony_tracing_bridge_otlp', $this->pauseDir(['kernel.cache_dir' => '/srv/cache']));
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

    /**
     * @param array<string, string> $kernelParameters
     */
    private function pauseDir(array $kernelParameters): mixed
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test', 'kernel.debug' => false, 'kernel.build_dir' => sys_get_temp_dir(), ...$kernelParameters]));
        $extension = (new TracingBridgeOtlpBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        $extension->load([[]], $container);

        return $container->getParameterBag()->all()['msstc4symfony_tracing_bridge_otlp.pause_dir'] ?? null;
    }
}
