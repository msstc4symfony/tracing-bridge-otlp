<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Integration;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExportedSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExporterFactory;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\StatusData;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class OtlpTransportTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const ENDPOINT_VARIABLE = 'OTEL_EXPORTER_OTLP_ENDPOINT';

    private ?Process $receiver = null;

    private string $out = '';

    #[Override]
    protected function setUp(): void
    {
        $this->out = (string) tempnam(sys_get_temp_dir(), 'otlp-receiver-');
        unlink($this->out);
        // The SDK reports a failed export through its own global writer (OTEL_PHP_LOG_DESTINATION), not through us.
        Logging::disable();
    }

    #[Override]
    protected function tearDown(): void
    {
        Logging::reset();
        $this->receiver?->stop(0);
        if (is_file($this->out)) {
            unlink($this->out);
        }

        putenv(self::ENDPOINT_VARIABLE);
        unset($_SERVER[self::ENDPOINT_VARIABLE], $_ENV[self::ENDPOINT_VARIABLE]);
    }

    public function testSendsValidOtlpJson(): void
    {
        $exporter = ExporterFactory::create($this->startReceiver(), 'http/json', 2000);

        self::assertTrue($exporter->export([$this->span()])->await());

        $request = $this->received();
        self::assertSame('/v1/traces', $request['path']);
        self::assertStringStartsWith('application/json', $request['contentType']);
        $body = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $span = $this->first($this->first($this->first($body, 'resourceSpans'), 'scopeSpans'), 'spans');
        self::assertSame(self::TRACE_ID, $span['traceId'] ?? null);
        self::assertSame('b7ad6b7169203331', $span['spanId'] ?? null);
        self::assertSame('00f067aa0ba902b7', $span['parentSpanId'] ?? null);
        self::assertSame('work', $span['name'] ?? null);
        // OTLP numbers kinds from 1: SPAN_KIND_SERVER is 2.
        self::assertSame(2, $span['kind'] ?? null);
    }

    public function testSendsProtobufByDefault(): void
    {
        $exporter = ExporterFactory::create($this->startReceiver(), null, 2000);

        self::assertTrue($exporter->export([$this->span()])->await());

        self::assertSame('application/x-protobuf', $this->received()['contentType']);
    }

    public function testAHangingReceiverCostsAtMostTheTimeout(): void
    {
        $exporter = ExporterFactory::create($this->startReceiver(sleep: 5), 'http/json', 300);

        $started = microtime(true);
        $exported = $exporter->export([$this->span()])->await();

        self::assertFalse($exported);
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testUsesTheEndpointFromTheEnvironmentWhenNotConfigured(): void
    {
        $endpoint = $this->startReceiver();
        putenv(self::ENDPOINT_VARIABLE . '=' . $endpoint);

        self::assertTrue(ExporterFactory::create(null, 'http/json', 2000)->export([$this->span()])->await());

        self::assertSame('/v1/traces', $this->received()['path']);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function first(mixed $parent, string $key): array
    {
        self::assertIsArray($parent);
        $list = $parent[$key] ?? null;
        self::assertIsArray($list);
        $first = $list[0] ?? null;
        self::assertIsArray($first);

        return $first;
    }

    /**
     * @return non-empty-string base URL
     */
    private function startReceiver(int $sleep = 0): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->receiver = new Process([PHP_BINARY, '-S', $address, __DIR__ . '/../Support/OtlpReceiver/receiver.php'], env: ['RECEIVER_OUT' => $this->out, 'RECEIVER_SLEEP' => (string) $sleep]);
        $this->receiver->start();

        $deadline = microtime(true) + 5;
        while (@fsockopen('tcp://' . $address) === false) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The OTLP receiver did not start: ' . $this->receiver->getErrorOutput());
            }

            usleep(20_000);
        }

        return 'http://' . $address;
    }

    /**
     * @return array{path: string, contentType: string, body: string}
     */
    private function received(): array
    {
        self::assertFileExists($this->out);
        $request = json_decode((string) file_get_contents($this->out), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($request);
        self::assertIsString($request['path'] ?? null);
        self::assertIsString($request['contentType'] ?? null);
        self::assertIsString($request['body'] ?? null);

        return ['path' => $request['path'], 'contentType' => $request['contentType'], 'body' => (string) base64_decode($request['body'], true)];
    }

    private function span(): ExportedSpan
    {
        return new ExportedSpan(
            'work',
            SpanKind::KIND_SERVER,
            new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', true, 1),
            StatusData::unset(),
            1_761_350_400_000_000_000,
            1_761_350_400_500_000_000,
            ['profiling.connection' => 'default'],
            new InstrumentationScope('test', null, null, Attributes::create([])),
            ResourceInfo::create(Attributes::create(['service.name' => 'orders'])),
        );
    }
}
