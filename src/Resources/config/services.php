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
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\UnitRecorder;
use Msstc4Symfony\TracingBridgeOtlp\Presentation\EventListener\FlushListener;
use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container): void {
    $prefix = TracingBridgeOtlpBundle::PARAMETER_PREFIX;
    $exporter = $prefix . 'exporter';

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
    ;
    // After tracing-bridge-profiling's processor (priority 0): records the span object end processors receive.
    $services->set(CreationRecorder::class)
        ->autoconfigure(false)
        ->tag(CreateSpanProcessorInterface::class, ['priority' => -1024])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->set(UnitRecorder::class);
    $services->set(OtlpEndSpanProcessor::class)
        ->arg('$flush', service(Flusher::class))
        ->arg('$unitSpanPrefixes', param('msstc4symfony_tracing_bridge_profiling.unit_span_prefixes'))
    ;
    $services->set(FlushListener::class)
        ->arg('$flush', service(Flusher::class))
    ;
};
