<?php

declare(strict_types=1);

use Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExporterFactory;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\Flusher;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ResourceFactory;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanMapper;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\CreationRecorder;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\OtlpEndSpanProcessor;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\SpanPlacer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\UnitRecorder;
use Msstc4Symfony\TracingBridgeOtlp\Presentation\EventListener\FlushListener;
use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $prefix = TracingBridgeOtlpBundle::PARAMETER_PREFIX;
    $exporter = $prefix . 'exporter';
    $pausePool = $builder->getParameter($prefix . 'pause_pool');

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set($exporter, SpanExporterInterface::class)
        ->factory(ExporterFactory::create(...))
        ->args([param($prefix . 'endpoint'), param($prefix . 'protocol'), param($prefix . 'timeout_ms')])
    ;
    $services->set(SpanMapper::class)
        ->arg('$scope', inline_service(InstrumentationScopeInterface::class)->factory(ResourceFactory::scope(...)))
        ->arg('$resource', inline_service(ResourceInfo::class)
            ->factory(ResourceFactory::resource(...))
            ->args([param($prefix . 'service_name'), param('kernel.project_dir')]))
    ;
    $services->set(SpanBuffer::class)
        ->arg('$capacity', param($prefix . 'max_buffered_spans'))
    ;
    $services->set(Flusher::class)
        ->arg('$exporterFactory', service_closure($exporter))
        ->arg('$clock', inline_service(NativeClock::class))
        // Not cache.app by default: its lookups are application metrics, and every flush would count as one.
        ->arg('$pauses', is_string($pausePool) ? service($pausePool) : inline_service(FilesystemAdapter::class)
            ->args(['', 0, param($prefix . 'pause_dir')])
            // An unwritable directory leaves the pause per process; the adapter must not warn about it.
            ->call('setLogger', [inline_service(NullLogger::class)]))
        ->tag('monolog.logger', ['channel' => 'otlp'])
    ;
    // Last of the create processors: it must key the span object end processors receive, after every wrapper
    // (tracing-bridge-profiling's at priority 0, an application's at any priority above this one).
    $services->set(CreationRecorder::class)
        ->autoconfigure(false)
        ->tag(CreateSpanProcessorInterface::class, ['priority' => -65536])
    ;
    $services->set(UnitRecorder::class);
    $services->set(SpanPlacer::class)
        ->arg('$unitSpanPrefixes', param('msstc4symfony_tracing_bridge_profiling.unit_span_prefixes'))
        ->tag('monolog.logger', ['channel' => 'otlp'])
    ;
    $services->set(OtlpEndSpanProcessor::class)
        ->arg('$flush', service(Flusher::class))
        ->tag('monolog.logger', ['channel' => 'otlp'])
    ;
    $services->set(FlushListener::class)
        ->arg('$flush', service(Flusher::class))
    ;
};
