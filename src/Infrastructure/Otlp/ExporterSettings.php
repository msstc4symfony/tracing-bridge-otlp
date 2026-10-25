<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;

/**
 * Where and how spans are exported: the bundle options first, then the standard OTEL_EXPORTER_OTLP_* variables.
 * OTEL_EXPORTER_OTLP_TIMEOUT is ignored: the SDK default of 10 s is too long for the end of every unit of work.
 *
 * @internal
 */
final readonly class ExporterSettings
{
    private const DEFAULT_COMPRESSION = 'none';

    private const MILLIS_PER_SECOND = 1000;

    /**
     * @param ContentTypes::* $contentType
     * @param array<string, string> $headers
     */
    private function __construct(
        public string $protocol,
        public string $endpoint,
        public string $contentType,
        public array $headers,
        public string $compression,
        public float $timeout,
    ) {
    }

    /**
     * @param non-empty-string|null $endpoint base URL; null: the OTEL_EXPORTER_OTLP_* variables
     * @param 'http/protobuf'|'http/json'|'grpc'|null $protocol null: the OTEL_EXPORTER_OTLP_* variables
     * @param positive-int $timeoutMs
     */
    public static function resolve(?string $endpoint, ?string $protocol, int $timeoutMs): self
    {
        $protocol ??= self::protocolFromEnvironment();

        return new self(
            $protocol,
            self::endpoint($endpoint, $protocol),
            Protocols::contentType($protocol),
            self::headers(),
            self::compression(),
            $timeoutMs / self::MILLIS_PER_SECOND,
        );
    }

    /**
     * Options of the exporter's own HTTP client: the timeout bounds the whole request, not only the idle time.
     *
     * @return array{timeout: float, max_duration: float}
     */
    public function httpClientOptions(): array
    {
        return ['timeout' => $this->timeout, 'max_duration' => $this->timeout];
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

    private static function compression(): string
    {
        return Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION)
            ? Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION)
            : Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_COMPRESSION, self::DEFAULT_COMPRESSION);
    }
}
