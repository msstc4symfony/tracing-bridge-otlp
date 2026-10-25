<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExporterSettings;
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

    public function testTheTimeoutIsTheConfiguredOneWhateverTheVariable(): void
    {
        putenv('OTEL_EXPORTER_OTLP_TIMEOUT=10000');

        $settings = ExporterSettings::resolve(null, null, 250);

        self::assertSame(0.25, $settings->timeout);
        self::assertSame(['timeout' => 0.25, 'max_duration' => 0.25], $settings->httpClientOptions());
    }
}
