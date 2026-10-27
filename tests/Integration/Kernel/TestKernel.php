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
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as LogsInMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const EXPORTER = 'msstc4symfony_tracing_bridge_otlp.exporter';

    public const ENV_DISABLED = 'disabled';

    public const COUNTING_CACHE_APP = 'test.counting_cache_app';

    public const ENV_FAILING_EXPORT = 'failing_export';

    // A failing export with the pause in a pool of the application's choice.
    public const ENV_PAUSE_POOL = 'pause_pool';

    public const PAUSE_POOL = 'test.pause_pool';

    // The bridge before profiling-bundle: services are reset in registration order.
    public const ENV_BRIDGE_FIRST = 'bridge_first';

    // Logs over OTLP through the bridge's Monolog handler.
    public const ENV_LOGS = 'logs';

    public const LOG_EXPORTER = 'msstc4symfony_tracing_bridge_otlp.log_exporter';

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
        $handlers = ['collecting' => ['type' => 'service', 'id' => CollectingHandler::class]];
        if ($this->environment === self::ENV_LOGS) {
            $handlers['otlp'] = ['type' => 'service', 'id' => 'msstc4symfony_tracing_bridge_otlp.logs_handler'];
            $container->extension('msstc4symfony_tracing_bridge_otlp', ['logs' => ['enabled' => true]]);
        }
        $container->extension('monolog', ['handlers' => $handlers]);
        $container->extension('msstc4symfony_profiling', [
            'routes' => ['traced'],
            'commands' => ['app:job'],
            'messages' => [Job::class],
            'auto_spans' => ['http_client' => true],
        ]);
        if ($this->environment === self::ENV_DISABLED) {
            $container->extension('msstc4symfony_tracing_bridge_otlp', ['enabled' => false]);
        }
        if ($this->environment === self::ENV_PAUSE_POOL) {
            $container->extension('msstc4symfony_tracing_bridge_otlp', ['pause_pool' => self::PAUSE_POOL]);
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
        if ($this->environment === self::ENV_PAUSE_POOL) {
            $services->set(self::PAUSE_POOL, CountingPool::class)->args([inline_service(ArrayAdapter::class)])->public();
        }
        if (in_array($this->environment, [self::ENV_FAILING_EXPORT, self::ENV_PAUSE_POOL], true)) {
            $services->set(self::EXPORTER, RecordingExporter::class)->args([false])->public();
        } elseif ($this->environment !== self::ENV_DISABLED) {
            $services->set(self::EXPORTER, InMemoryExporter::class)->public();
        }
        if ($this->environment === self::ENV_LOGS) {
            $services->set(self::LOG_EXPORTER, LogsInMemoryExporter::class)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('traced', '/traced')->controller(TracedController::class);
    }
}
