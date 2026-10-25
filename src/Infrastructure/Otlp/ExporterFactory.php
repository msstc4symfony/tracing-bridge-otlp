<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the OTLP span exporter. HTTP goes through its own Symfony HttpClient: the container's http_client is traced
 * and profiled, so exporting through it would create spans and trace headers about the export itself.
 *
 * @internal
 */
final class ExporterFactory
{
    public const GRPC_TRANSPORT_FACTORY = 'OpenTelemetry\Contrib\Grpc\GrpcTransportFactory';

    // The exporter gives up at the timeout; a retry would multiply what a failed export costs the unit of work.
    private const MAX_RETRIES = 0;

    private const RETRY_DELAY_MS = 100;

    private function __construct()
    {
    }

    /**
     * @param non-empty-string|null $endpoint base URL; null: the OTEL_EXPORTER_OTLP_* variables
     * @param 'http/protobuf'|'http/json'|'grpc'|null $protocol null: the OTEL_EXPORTER_OTLP_* variables
     * @param positive-int $timeoutMs
     */
    public static function create(?string $endpoint, ?string $protocol, int $timeoutMs): SpanExporterInterface
    {
        $settings = ExporterSettings::resolve($endpoint, $protocol, $timeoutMs);

        return new SpanExporter(self::transportFactory($settings)->create(
            $settings->endpoint,
            $settings->contentType,
            $settings->headers,
            $settings->compression,
            $settings->timeout,
            self::RETRY_DELAY_MS,
            self::MAX_RETRIES,
        ));
    }

    private static function transportFactory(ExporterSettings $settings): TransportFactoryInterface
    {
        if ($settings->protocol !== Protocols::GRPC) {
            $psr17 = new Psr17Factory();

            return new PsrTransportFactory(new Psr18Client(HttpClient::create($settings->httpClientOptions()), $psr17, $psr17), $psr17, $psr17);
        }

        $class = self::GRPC_TRANSPORT_FACTORY;
        if (!class_exists($class)) {
            throw new LogicException('The OTLP protocol "grpc" needs open-telemetry/transport-grpc (and ext-grpc).');
        }

        $factory = new $class();
        if (!$factory instanceof TransportFactoryInterface) {
            throw new LogicException(sprintf('%s is not a %s.', $class, TransportFactoryInterface::class));
        }

        return $factory;
    }
}
