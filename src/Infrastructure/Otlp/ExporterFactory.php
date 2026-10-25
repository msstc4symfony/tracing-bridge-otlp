<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the OTLP span exporter from the bundle options, falling back to the standard OTEL_EXPORTER_OTLP_* variables.
 * HTTP goes through its own Symfony HttpClient: the container's http_client is traced and profiled, so exporting
 * through it would create spans and trace headers about the export itself.
 *
 * @internal
 */
final class ExporterFactory
{
    public const GRPC_TRANSPORT_FACTORY = 'OpenTelemetry\Contrib\Grpc\GrpcTransportFactory';

    private const DEFAULT_COMPRESSION = 'none';

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
        $protocol ??= self::protocolFromEnvironment();
        $timeout = $timeoutMs / 1000;

        return new SpanExporter(self::transportFactory($protocol, $timeout)->create(
            self::endpoint($endpoint, $protocol),
            Protocols::contentType($protocol),
            self::headers(),
            self::compression(),
            $timeout,
            self::RETRY_DELAY_MS,
            self::MAX_RETRIES,
        ));
    }

    private static function protocolFromEnvironment(): string
    {
        return Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL)
            ? Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL)
            : Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, Defaults::OTEL_EXPORTER_OTLP_PROTOCOL);
    }

    private static function endpoint(?string $base, string $protocol): string
    {
        if ($base === null && Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT)) {
            return Configuration::getString(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT);
        }

        $base ??= Configuration::has(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            ? Configuration::getString(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            : Defaults::OTEL_EXPORTER_OTLP_ENDPOINT;

        return $protocol === Protocols::GRPC
            ? rtrim($base, '/') . OtlpUtil::method(Signals::TRACE)
            : HttpEndpointResolver::create()->resolveToString($base, Signals::TRACE);
    }

    private static function compression(): string
    {
        return Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION)
            ? Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION)
            : Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_COMPRESSION, self::DEFAULT_COMPRESSION);
    }

    /**
     * OTEL_EXPORTER_OTLP_TRACES_HEADERS / OTEL_EXPORTER_OTLP_HEADERS and the OTLP user agent.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        $headers = [];
        foreach (OtlpUtil::getHeaders(Signals::TRACE) as $name => $value) {
            if (is_string($value)) {
                $headers[(string) $name] = $value;
            }
        }

        return $headers;
    }

    private static function transportFactory(string $protocol, float $timeout): TransportFactoryInterface
    {
        if ($protocol !== Protocols::GRPC) {
            $psr17 = new Psr17Factory();

            return new PsrTransportFactory(new Psr18Client(HttpClient::create(['timeout' => $timeout, 'max_duration' => $timeout]), $psr17, $psr17), $psr17, $psr17);
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
