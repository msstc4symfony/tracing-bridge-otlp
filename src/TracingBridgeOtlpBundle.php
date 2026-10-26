<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp;

use LogicException;
use Override;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @phpstan-type BundleConfig array{
 *     enabled: bool,
 *     service_name: non-empty-string|null,
 *     endpoint: non-empty-string|null,
 *     protocol: 'http/protobuf'|'http/json'|'grpc'|null,
 *     timeout_ms: positive-int,
 *     max_buffered_spans: positive-int,
 *     pause_pool: non-empty-string|null,
 * }
 */
final class TracingBridgeOtlpBundle extends AbstractBundle
{
    public const PARAMETER_PREFIX = 'msstc4symfony_tracing_bridge_otlp.';

    // Also ExporterFactory::GRPC_TRANSPORT_FACTORY: deptrac keeps the bundle class off Infrastructure.
    private const GRPC_TRANSPORT_FACTORY = 'OpenTelemetry\Contrib\Grpc\GrpcTransportFactory';

    protected string $extensionAlias = 'msstc4symfony_tracing_bridge_otlp';

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('false: no export service is registered.')
                    ->defaultTrue()
                ->end()
                ->stringNode('service_name')
                    ->info('service.name of the exported spans; ~: OTEL_SERVICE_NAME / OTEL_RESOURCE_ATTRIBUTES, else the basename of kernel.project_dir.')
                    ->defaultNull()
                    ->cannotBeEmpty()
                ->end()
                ->stringNode('endpoint')
                    ->info('Base URL like OTEL_EXPORTER_OTLP_ENDPOINT (/v1/traces is appended for HTTP); ~: OTEL_EXPORTER_OTLP_TRACES_ENDPOINT, OTEL_EXPORTER_OTLP_ENDPOINT, http://localhost:4318.')
                    ->defaultNull()
                    ->cannotBeEmpty()
                ->end()
                ->enumNode('protocol')
                    ->info('~: OTEL_EXPORTER_OTLP_TRACES_PROTOCOL, OTEL_EXPORTER_OTLP_PROTOCOL, http/protobuf. grpc needs open-telemetry/transport-grpc.')
                    ->values(['http/protobuf', 'http/json', 'grpc'])
                    ->defaultNull()
                ->end()
                ->integerNode('timeout_ms')
                    ->info('Export timeout; OTEL_EXPORTER_OTLP_TIMEOUT is ignored.')
                    ->min(1)
                    ->defaultValue(1000)
                ->end()
                ->integerNode('max_buffered_spans')
                    ->info('Spans kept per unit of work; a full buffer is exported early.')
                    ->min(1)
                    ->defaultValue(2048)
                ->end()
                ->stringNode('pause_pool')
                    ->info('PSR-6 pool (service id) sharing the pause after a failed export between hosts; ~: files in kernel.share_dir (else kernel.cache_dir), shared by the processes on one host.')
                    ->defaultNull()
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (string $pool): bool => str_starts_with($pool, '@'))
                        ->thenInvalid('pause_pool is a service id without "@", %s given.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * Reason for the ignore: Symfony declares $config as a plain array; configure() guarantees the shape.
     * The identifier is listed twice because the method has two parent declarations
     * (AbstractBundle and ConfigurableExtensionInterface), each reporting it.
     *
     * @param BundleConfig $config
     */
    #[Override] // @phpstan-ignore method.childParameterType, method.childParameterType
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        // A protocol from OTEL_EXPORTER_OTLP_PROTOCOL is only known at runtime: the first export then fails and logs.
        if ($config['protocol'] === 'grpc' && !class_exists(self::GRPC_TRANSPORT_FACTORY)) {
            throw new LogicException('msstc4symfony_tracing_bridge_otlp.protocol "grpc" needs open-telemetry/transport-grpc (and ext-grpc).');
        }

        foreach ($config as $key => $value) {
            $builder->setParameter(self::PARAMETER_PREFIX . $key, $value);
        }

        if ($config['pause_pool'] === null) {
            // kernel.share_dir (Symfony 7.4+, unless APP_SHARE_DIR=false) is meant for data shared by the front servers.
            $baseDir = $builder->hasParameter('kernel.share_dir') ? '%kernel.share_dir%' : '%kernel.cache_dir%';
            $builder->setParameter(self::PARAMETER_PREFIX . 'pause_dir', $baseDir . '/' . $this->extensionAlias);
        }

        $container->import(__DIR__ . '/Resources/config/services.php');
    }
}
