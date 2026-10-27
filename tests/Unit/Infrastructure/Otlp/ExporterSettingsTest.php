<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use LogicException;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExporterSettings;
use OpenTelemetry\API\Signals;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExporterSettings::class)]
final class ExporterSettingsTest extends TestCase
{
    private const VARIABLES = [
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
        'OTEL_EXPORTER_OTLP_PROTOCOL',
        'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL',
        'OTEL_EXPORTER_OTLP_HEADERS',
        'OTEL_EXPORTER_OTLP_TRACES_HEADERS',
        'OTEL_EXPORTER_OTLP_COMPRESSION',
        'OTEL_EXPORTER_OTLP_TRACES_COMPRESSION',
        'OTEL_EXPORTER_OTLP_TIMEOUT',
        'OTEL_EXPORTER_OTLP_CERTIFICATE',
        'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE',
        'OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE',
        'OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE',
        'OTEL_EXPORTER_OTLP_CLIENT_KEY',
        'OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY',
        'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT',
        'OTEL_EXPORTER_OTLP_LOGS_PROTOCOL',
        'OTEL_EXPORTER_OTLP_LOGS_HEADERS',
        'OTEL_EXPORTER_OTLP_LOGS_COMPRESSION',
        'OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE',
    ];

    #[Override]
    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    public function testDefaultsToProtobufOnLocalhost(): void
    {
        $settings = ExporterSettings::resolve(null, null, 1000);

        self::assertSame('http/protobuf', $settings->protocol);
        self::assertSame('application/x-protobuf', $settings->contentType);
        self::assertSame('http://localhost:4318/v1/traces', $settings->endpoint);
        self::assertSame('none', $settings->compression);
    }

    public function testLogsGoToTheLogsPathAndTheirOwnVariables(): void
    {
        self::assertSame('http://alloy:4318/v1/logs', ExporterSettings::resolve('http://alloy:4318', 'http/json', 1000, Signals::LOGS)->endpoint);
        self::assertSame('http://alloy:4317/opentelemetry.proto.collector.logs.v1.LogsService/Export', ExporterSettings::resolve('http://alloy:4317', 'grpc', 1000, Signals::LOGS)->endpoint);

        putenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://traces:4318/t');
        putenv('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=grpc');
        putenv('OTEL_EXPORTER_OTLP_TRACES_COMPRESSION=gzip');
        putenv('OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=/etc/ssl/traces.pem');
        putenv('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://logs:4318/l');
        putenv('OTEL_EXPORTER_OTLP_LOGS_PROTOCOL=http/json');
        putenv('OTEL_EXPORTER_OTLP_LOGS_HEADERS=x-signal=logs');
        putenv('OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE=/etc/ssl/logs.pem');

        $logs = ExporterSettings::resolve(null, null, 1000, Signals::LOGS);

        self::assertSame('http://logs:4318/l', $logs->endpoint);
        self::assertSame('http/json', $logs->protocol);
        self::assertSame('none', $logs->compression);
        self::assertSame('logs', $logs->headers['x-signal'] ?? null);
        self::assertSame('/etc/ssl/logs.pem', $logs->caFile);
        self::assertSame('http://traces:4318/t', ExporterSettings::resolve(null, null, 1000)->endpoint);
    }

    public function testAnEmptyEndpointIsUnset(): void
    {
        // An env placeholder resolves to "" when its variable is empty, as OpenTelemetry reads an empty variable.
        self::assertSame('http://localhost:4318/v1/traces', ExporterSettings::resolve('', null, 1000)->endpoint);
    }

    public function testTheConfiguredEndpointIsABaseUrl(): void
    {
        self::assertSame('http://alloy:4318/v1/traces', ExporterSettings::resolve('http://alloy:4318', 'http/json', 1000)->endpoint);
        self::assertSame('http://alloy:4318/v1/traces', ExporterSettings::resolve('http://alloy:4318/', 'http/json', 1000)->endpoint);
    }

    public function testAGrpcEndpointGetsTheServiceMethod(): void
    {
        $settings = ExporterSettings::resolve('http://alloy:4317/', 'grpc', 1000);

        self::assertSame('http://alloy:4317/opentelemetry.proto.collector.trace.v1.TraceService/Export', $settings->endpoint);
        self::assertSame('grpc', $settings->protocol);
    }

    public function testTheTracesEndpointVariableIsUsedAsIs(): void
    {
        putenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://collector:4318/custom');
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://other:4318');

        self::assertSame('http://collector:4318/custom', ExporterSettings::resolve(null, 'http/json', 1000)->endpoint);
    }

    public function testTheConfiguredEndpointWinsOverTheVariables(): void
    {
        putenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://collector:4318/custom');
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://other:4318');

        self::assertSame('http://alloy:4318/v1/traces', ExporterSettings::resolve('http://alloy:4318', 'http/json', 1000)->endpoint);
    }

    public function testTheEndpointVariableIsABaseUrl(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://other:4318');

        self::assertSame('http://other:4318/v1/traces', ExporterSettings::resolve(null, 'http/json', 1000)->endpoint);
    }

    public function testTheProtocolComesFromTheVariablesWhenNotConfigured(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/json');
        self::assertSame('http/json', ExporterSettings::resolve(null, null, 1000)->protocol);

        putenv('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=http/protobuf');
        self::assertSame('http/protobuf', ExporterSettings::resolve(null, null, 1000)->protocol);
        self::assertSame('http/json', ExporterSettings::resolve(null, 'http/json', 1000)->protocol);
    }

    public function testHeadersAndCompressionComeFromTheVariables(): void
    {
        putenv('OTEL_EXPORTER_OTLP_HEADERS=authorization=Bearer%20abc,x-tenant=shop');
        putenv('OTEL_EXPORTER_OTLP_TRACES_COMPRESSION=gzip');

        $settings = ExporterSettings::resolve(null, null, 1000);

        self::assertSame('Bearer abc', $settings->headers['authorization'] ?? null);
        self::assertSame('shop', $settings->headers['x-tenant'] ?? null);
        self::assertArrayHasKey('User-Agent', $settings->headers);
        self::assertSame('gzip', $settings->compression);
    }

    public function testCertificatesComeFromTheVariables(): void
    {
        putenv('OTEL_EXPORTER_OTLP_CERTIFICATE=/etc/ssl/ca.pem');
        putenv('OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE=/etc/ssl/traces-client.pem');
        putenv('OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE=/etc/ssl/client.pem');
        putenv('OTEL_EXPORTER_OTLP_CLIENT_KEY=/etc/ssl/client.key');

        $settings = ExporterSettings::resolve(null, null, 1000);

        self::assertSame(['/etc/ssl/ca.pem', '/etc/ssl/traces-client.pem', '/etc/ssl/client.key'], [$settings->caFile, $settings->clientCertificate, $settings->clientKey]);
        self::assertSame(['timeout' => 1.0, 'max_duration' => 1.0, 'cafile' => '/etc/ssl/ca.pem', 'local_cert' => '/etc/ssl/traces-client.pem', 'local_pk' => '/etc/ssl/client.key'], $settings->httpClientOptions());
    }

    public function testCertificatesAreReadThroughTheSdkResolversToo(): void
    {
        $_SERVER['OTEL_EXPORTER_OTLP_CERTIFICATE'] = '/etc/ssl/server.pem';

        try {
            self::assertSame('/etc/ssl/server.pem', ExporterSettings::resolve(null, null, 1000)->caFile);
        } finally {
            unset($_SERVER['OTEL_EXPORTER_OTLP_CERTIFICATE']);
        }
    }

    public function testAnUnsupportedCompressionIsRejected(): void
    {
        putenv('OTEL_EXPORTER_OTLP_COMPRESSION=brotli');

        $this->expectException(LogicException::class);

        ExporterSettings::resolve(null, null, 1000);
    }

    public function testNoCertificateByDefault(): void
    {
        $settings = ExporterSettings::resolve(null, null, 1000);

        self::assertSame([null, null, null], [$settings->caFile, $settings->clientCertificate, $settings->clientKey]);
    }

    public function testAnUnsupportedProtocolFromTheVariablesIsRejected(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/ndjson');

        $this->expectException(LogicException::class);

        ExporterSettings::resolve(null, null, 1000);
    }

    public function testTheTimeoutIsTheConfiguredOneWhateverTheVariable(): void
    {
        putenv('OTEL_EXPORTER_OTLP_TIMEOUT=10000');

        $settings = ExporterSettings::resolve(null, null, 250);

        self::assertSame(0.25, $settings->timeout);
        self::assertSame(['timeout' => 0.25, 'max_duration' => 0.25], $settings->httpClientOptions());
    }
}
