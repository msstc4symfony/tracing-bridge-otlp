<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Integration;

use LogicException;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\Flusher;
use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;

final class TracingBridgeOtlpBundleTest extends TestCase
{
    private const PAUSE_DIR = TracingBridgeOtlpBundle::PARAMETER_PREFIX . 'pause_dir';

    private const GRPC_TRANSPORT_FACTORY = 'OpenTelemetry\Contrib\Grpc\GrpcTransportFactory';

    public function testExtensionAlias(): void
    {
        self::assertSame('msstc4symfony_tracing_bridge_otlp', (new TracingBridgeOtlpBundle())->getContainerExtension()?->getAlias());
    }

    public function testThePauseLivesInTheShareDirWhenTheKernelHasOne(): void
    {
        $withShareDir = $this->load(['kernel.share_dir' => '/srv/share', 'kernel.cache_dir' => '/srv/cache']);
        $withoutShareDir = $this->load(['kernel.cache_dir' => '/srv/cache']);

        self::assertSame('%kernel.share_dir%/msstc4symfony_tracing_bridge_otlp', $withShareDir->getParameter(self::PAUSE_DIR));
        self::assertSame('%kernel.cache_dir%/msstc4symfony_tracing_bridge_otlp', $withoutShareDir->getParameter(self::PAUSE_DIR));
    }

    public function testThePauseAdapterLogsNothing(): void
    {
        $pauses = $this->load(['kernel.cache_dir' => '/srv/cache'])->getDefinition(Flusher::class)->getArgument('$pauses');
        self::assertInstanceOf(Definition::class, $pauses);

        // An unwritable directory would otherwise warn once per failed export.
        $call = $pauses->getMethodCalls()[0] ?? null;
        self::assertIsArray($call);
        self::assertSame('setLogger', $call[0] ?? null);
        $arguments = $call[1] ?? null;
        self::assertIsArray($arguments);
        $logger = $arguments[0] ?? null;
        self::assertInstanceOf(Definition::class, $logger);
        self::assertSame(NullLogger::class, $logger->getClass());
    }

    public function testAPausePoolReplacesThePauseDirectory(): void
    {
        $container = $this->load(['kernel.cache_dir' => '/srv/cache'], ['pause_pool' => 'app.pause_pool']);

        self::assertFalse($container->hasParameter(self::PAUSE_DIR));
        $pauses = $container->getDefinition(Flusher::class)->getArgument('$pauses');
        self::assertEquals(new Reference('app.pause_pool'), $pauses);
    }

    public function testAPausePoolThatIsNoServiceFailsTheBuild(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage('app.missing_pool');

        TestContainer::compile(['pause_pool' => 'app.missing_pool']);
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
     * The extension loaded alone: compiling would need profiling-bundle and tracing-bridge-profiling.
     *
     * @param array<string, string> $kernelParameters
     * @param array<string, string> $config
     */
    private function load(array $kernelParameters, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.build_dir' => sys_get_temp_dir(),
            ...$kernelParameters,
        ]));
        $extension = (new TracingBridgeOtlpBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        $extension->load([$config], $container);

        return $container;
    }
}
