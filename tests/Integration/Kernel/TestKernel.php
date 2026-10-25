<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Integration\Kernel;

use Msstc4Symfony\ProfilingBundle\ProfilingBundle;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\CollectingHandler;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\CountingPool;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\Job;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\JobCommand;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\JobHandler;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\TracedController;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\RecordingExporter;
use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use Msstc4Symfony\TracingBridgeProfiling\TracingBridgeProfilingBundle;
use Msstc4Symfony\TracingBundle\Framework\Messenger\IncomingTraceMiddleware;
use Msstc4Symfony\TracingBundle\Framework\Messenger\OutgoingTraceMiddleware;
use Msstc4Symfony\TracingBundle\TracingBundle;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const EXPORTER = 'msstc4symfony_tracing_bridge_otlp.exporter';

    public const ENV_DISABLED = 'disabled';

    public const COUNTING_CACHE_APP = 'test.counting_cache_app';

    public const ENV_FAILING_EXPORT = 'failing_export';

    // The bridge before profiling-bundle: services are reset in registration order.
    public const ENV_BRIDGE_FIRST = 'bridge_first';

    // Per process: parallel PHPUnit runs must not wipe each other's container.
    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-tracing-bridge-otlp-test-' . getmypid();
    }

    #[Override]
    public function registerBundles(): iterable
    {
        if ($this->environment === self::ENV_BRIDGE_FIRST) {
            return [new FrameworkBundle(), new MonologBundle(), new TracingBridgeOtlpBundle(), new TracingBridgeProfilingBundle(), new TracingBundle(), new ProfilingBundle()];
        }

        return [new FrameworkBundle(), new MonologBundle(), new TracingBundle(), new ProfilingBundle(), new TracingBridgeProfilingBundle(), new TracingBridgeOtlpBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            // The php_errors logger installs a global handler that outlives the kernel and trips failOnRisky.
            'php_errors' => ['log' => false],
            'http_client' => ['mock_response_factory' => 'test.mock_response'],
            'messenger' => [
                'default_bus' => 'bus',
                'buses' => ['bus' => ['middleware' => [OutgoingTraceMiddleware::class, IncomingTraceMiddleware::class]]],
                'transports' => ['async' => 'in-memory://?serialize=true'],
                'routing' => [Job::class => 'async'],
            ],
        ]);
        $container->extension('monolog', ['handlers' => [
            'collecting' => ['type' => 'service', 'id' => CollectingHandler::class],
        ]]);
        $container->extension('msstc4symfony_profiling', [
            'routes' => ['traced'],
            'commands' => ['app:job'],
            'messages' => [Job::class],
            'auto_spans' => ['http_client' => true],
        ]);
        if ($this->environment === self::ENV_DISABLED) {
            $container->extension('msstc4symfony_tracing_bridge_otlp', ['enabled' => false]);
        }

        $services = $container->services();
        $services->defaults()->autowire()->autoconfigure();
        $services->set(CollectingHandler::class)->public();
        $services->set(TracedController::class)->public()->tag('controller.service_arguments');
        $services->set(JobHandler::class);
        $services->set(JobCommand::class);
        $services->set('test.mock_response', MockResponse::class)->args(['{}']);
        $services->set(self::COUNTING_CACHE_APP, CountingPool::class)
            ->decorate('cache.app')
            ->args([service('.inner')])
            ->public()
        ;
        if ($this->environment === self::ENV_FAILING_EXPORT) {
            $services->set(self::EXPORTER, RecordingExporter::class)->args([false])->public();
        } elseif ($this->environment !== self::ENV_DISABLED) {
            $services->set(self::EXPORTER, InMemoryExporter::class)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('traced', '/traced')->controller(TracedController::class);
    }
}
