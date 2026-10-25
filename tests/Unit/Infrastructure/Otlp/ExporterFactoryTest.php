<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use LogicException;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExporterFactory;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExporterSettings;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExporterFactory::class)]
#[UsesClass(ExporterSettings::class)]
final class ExporterFactoryTest extends TestCase
{
    public function testBuildsAnHttpExporter(): void
    {
        self::assertInstanceOf(SpanExporter::class, ExporterFactory::create('http://collector:4318', 'http/json', 1000));
    }

    public function testGrpcNeedsItsTransport(): void
    {
        if (class_exists(ExporterFactory::GRPC_TRANSPORT_FACTORY)) {
            self::markTestSkipped('open-telemetry/transport-grpc is installed');
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('open-telemetry/transport-grpc');

        ExporterFactory::create('http://collector:4317', 'grpc', 1000);
    }
}
