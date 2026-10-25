<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use LogicException;
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

    private const PROTOCOLS = [Protocols::HTTP_PROTOBUF, Protocols::HTTP_JSON, Protocols::GRPC];

    private const COMPRESSIONS = ['none', 'gzip'];

    // The SDK declares only the CA variables; the client ones come from the OTLP exporter specification. All are read
    // through the SDK's resolvers ($_SERVER, environment, php.ini) like the other OTEL_* variables.
    private const CA_FILE = ['OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE', 'OTEL_EXPORTER_OTLP_CERTIFICATE'];

    private const CLIENT_CERTIFICATE = ['OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE', 'OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE'];

    private const CLIENT_KEY = ['OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY', 'OTEL_EXPORTER_OTLP_CLIENT_KEY'];

    private const MILLIS_PER_SECOND = 1000;

    /**
     * @param 'http/protobuf'|'http/json'|'grpc' $protocol
     * @param string $endpoint absolute URL
     * @param ContentTypes::* $contentType
     * @param array<string, string> $headers
     * @param 'none'|'gzip' $compression
     * @param float $timeout seconds, above zero
     * @param non-empty-string|null $caFile PEM file of the CA that signed the receiver's certificate
     * @param non-empty-string|null $clientCertificate PEM file for mutual TLS
     * @param non-empty-string|null $clientKey PEM file for mutual TLS
     */
    private function __construct(
        public string $protocol,
        public string $endpoint,
        public string $contentType,
        public array $headers,
        public string $compression,
        public float $timeout,
        public ?string $caFile,
        public ?string $clientCertificate,
        public ?string $clientKey,
    ) {
    }

    /**
     * @param non-empty-string|null $endpoint base URL; null: the OTEL_EXPORTER_OTLP_* variables
     * @param 'http/protobuf'|'http/json'|'grpc'|null $protocol null: the OTEL_EXPORTER_OTLP_* variables
     * @param positive-int $timeoutMs
     *
     * @throws LogicException for a protocol or compression from the variables this exporter cannot use
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
            self::firstVariable(self::CA_FILE),
            self::firstVariable(self::CLIENT_CERTIFICATE),
            self::firstVariable(self::CLIENT_KEY),
        );
    }

    /**
     * Options of the exporter's own HTTP client: the timeout bounds the whole request, not only the idle time.
     *
     * @return array{timeout: float, max_duration: float, cafile?: non-empty-string, local_cert?: non-empty-string, local_pk?: non-empty-string}
     */
    public function httpClientOptions(): array
    {
        $options = ['timeout' => $this->timeout, 'max_duration' => $this->timeout];
        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }

        if ($this->clientCertificate !== null) {
            $options['local_cert'] = $this->clientCertificate;
        }

        if ($this->clientKey !== null) {
            $options['local_pk'] = $this->clientKey;
        }

        return $options;
    }

    /**
     * @return 'http/protobuf'|'http/json'|'grpc'
     */
    private static function protocolFromEnvironment(): string
    {
        $protocol = Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL)
            ? Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL)
            : Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, Defaults::OTEL_EXPORTER_OTLP_PROTOCOL);

        return in_array($protocol, self::PROTOCOLS, true)
            ? $protocol
            : throw new LogicException(sprintf('The OTLP protocol "%s" is not supported; use one of %s.', $protocol, implode(', ', self::PROTOCOLS)));
    }

    private static function endpoint(?string $base, string $protocol): string
    {
        if ($base === null && Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT)) {
            return Configuration::getString(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT);
        }

        return self::signalEndpoint($base ?? (Configuration::has(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            ? Configuration::getString(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            : Defaults::OTEL_EXPORTER_OTLP_ENDPOINT), $protocol);
    }

    private static function signalEndpoint(string $base, string $protocol): string
    {
        return $protocol === Protocols::GRPC
            ? rtrim($base, '/') . OtlpUtil::method(Signals::TRACE)
            : HttpEndpointResolver::create()->resolveToString($base, Signals::TRACE);
    }

    /**
     * @param list<string> $names
     *
     * @return non-empty-string|null
     */
    private static function firstVariable(array $names): ?string
    {
        foreach ($names as $name) {
            $value = Configuration::has($name) ? Configuration::getString($name) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return null;
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

    /**
     * @return 'none'|'gzip'
     */
    private static function compression(): string
    {
        $compression = Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION)
            ? Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION)
            : Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_COMPRESSION, self::DEFAULT_COMPRESSION);

        return in_array($compression, self::COMPRESSIONS, true)
            ? $compression
            : throw new LogicException(sprintf('The OTLP compression "%s" is not supported; use none or gzip.', $compression));
    }
}
