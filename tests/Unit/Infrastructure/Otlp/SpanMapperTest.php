<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\AttributeLimit;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\DatabaseSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExportedSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanMapper;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\StubSpan;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\Suit;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SpanMapper::class)]
#[UsesClass(ExportedSpan::class)]
#[UsesClass(SpanIds::class)]
#[UsesClass(AttributeLimit::class)]
#[UsesClass(DatabaseSpan::class)]
final class SpanMapperTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    public function testMapsIdsTimesNameAndKind(): void
    {
        $span = new StubSpan('SELECT orders', ['connection' => 'default'], startTime: 1761350400.25, duration: 0.5);
        $ids = new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', false, 1);

        $data = $this->mapper()->map($span, [], $ids, SpanKind::KIND_CLIENT, null);

        self::assertSame('SELECT orders', $data->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $data->getKind());
        self::assertSame(self::TRACE_ID, $data->getTraceId());
        self::assertSame('b7ad6b7169203331', $data->getSpanId());
        self::assertSame('00f067aa0ba902b7', $data->getParentSpanId());
        self::assertFalse($data->getParentContext()->isRemote());
        self::assertSame(1761350400250000000, $data->getStartEpochNanos());
        self::assertSame(1761350400750000000, $data->getEndEpochNanos());
        self::assertTrue($data->hasEnded());
        self::assertTrue($data->getContext()->isSampled());
    }

    // Floats near 1.7e9 are 2.4e-7 apart: these are .25000075, .2500005 and .25000025.
    #[TestWith([1761350400.2500007, 1761350400250001000])]
    #[TestWith([1761350400.2500005, 1761350400250001000])]
    #[TestWith([1761350400.2500002, 1761350400250000000])]
    public function testTimesAreRoundedToTheMicrosecond(float $start, int $nanos): void
    {
        self::assertSame($nanos, $this->mapper()->map(new StubSpan('x', startTime: $start, duration: 0.0), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getStartEpochNanos());
    }

    public function testARootSpanHasAnInvalidParentAndARemoteParentIsMarkedRemote(): void
    {
        $root = $this->mapper()->map(new StubSpan('request x'), [], new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', null, false, 1), SpanKind::KIND_SERVER, 200);
        $remote = $this->mapper()->map(new StubSpan('request x'), [], new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', '00f067aa0ba902b7', true, 1), SpanKind::KIND_SERVER, 200);

        self::assertFalse($root->getParentContext()->isValid());
        self::assertSame('0000000000000000', $root->getParentSpanId());
        self::assertTrue($remote->getParentContext()->isRemote());
        self::assertSame('00f067aa0ba902b7', $remote->getParentSpanId());
    }

    public function testAnExceptionBecomesAnEventAtTheEndOfTheSpan(): void
    {
        $failed = $this->mapper()->map(new StubSpan('x'), ['exception' => 'RuntimeException'], $this->ids(), SpanKind::KIND_INTERNAL, null);
        $fine = $this->mapper()->map(new StubSpan('x'), [], $this->ids(), SpanKind::KIND_INTERNAL, null);

        self::assertCount(1, $failed->getEvents());
        self::assertSame($failed->getEndEpochNanos(), $failed->getEvents()[0]->getEpochNanos());
        self::assertSame(['exception.type' => 'RuntimeException'], $failed->getEvents()[0]->getAttributes()->toArray());
        self::assertSame([], $fine->getEvents());
    }

    public function testCopiesScalarContextAsProfilingAttributesWithSemconvDuplicates(): void
    {
        $span = new StubSpan('GET /pay', ['url_path' => '/pay', 'profiling_detached' => true, 'profiling_automatic' => true, 'profiling_outgoing_request' => true, 'trace_span_id' => 'x', 'trace_parent_span_id' => 'y']);

        $attributes = $this->mapper()->map($span, ['status' => 502, 'exception' => 'RuntimeException'], $this->ids(), SpanKind::KIND_CLIENT, null)->getAttributes()->toArray();

        self::assertSame([
            'profiling.url_path' => '/pay',
            'profiling.status' => 502,
            'profiling.exception' => 'RuntimeException',
            'exception.type' => 'RuntimeException',
            'http.response.status_code' => 502,
        ], $attributes);
    }

    public function testTheEndContextOverridesTheCreationContext(): void
    {
        $attributes = $this->mapper()->map(new StubSpan('x', ['step' => 'start']), ['step' => 'end'], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame(['profiling.step' => 'end'], $attributes);
    }

    public function testNonScalarValuesAreDropped(): void
    {
        $span = new StubSpan('x', ['list' => [1], 'object' => new stdClass(), 'null' => null, 'enum' => Suit::Hearts, 'ok' => 1.5]);

        self::assertSame(['profiling.ok' => 1.5], $this->mapper()->map($span, [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray());
    }

    /**
     * @param array<string, bool|int|string> $endContext
     */
    #[TestWith([['exception' => 'RuntimeException'], null, StatusCode::STATUS_ERROR])]
    #[TestWith([['message_failed' => true], null, StatusCode::STATUS_ERROR])]
    #[TestWith([['status' => 503], null, StatusCode::STATUS_ERROR])]
    #[TestWith([['status' => 500], null, StatusCode::STATUS_ERROR])]
    #[TestWith([['status' => 499], null, StatusCode::STATUS_UNSET])]
    #[TestWith([['status' => '503'], null, StatusCode::STATUS_UNSET])]
    #[TestWith([[], 500, StatusCode::STATUS_ERROR])]
    #[TestWith([['status' => 503], 200, StatusCode::STATUS_UNSET])]
    #[TestWith([[], 200, StatusCode::STATUS_UNSET])]
    #[TestWith([['message_failed' => false], null, StatusCode::STATUS_UNSET])]
    #[TestWith([['message_failed' => 1], null, StatusCode::STATUS_UNSET])]
    #[TestWith([['exception' => ''], null, StatusCode::STATUS_UNSET])]
    #[TestWith([['exception' => false], null, StatusCode::STATUS_UNSET])]
    public function testStatus(array $endContext, ?int $responseStatus, string $code): void
    {
        self::assertSame($code, $this->mapper()->map(new StubSpan('x'), $endContext, $this->ids(), SpanKind::KIND_INTERNAL, $responseStatus)->getStatus()->getCode());
    }

    public function testTheServerResponseCodeBecomesAnAttribute(): void
    {
        $attributes = $this->mapper()->map(new StubSpan('request x'), [], $this->ids(), SpanKind::KIND_SERVER, 201)->getAttributes()->toArray();

        self::assertSame(201, $attributes['http.response.status_code'] ?? null);
    }

    public function testConnectionAndTransportGetTheirSemconvNames(): void
    {
        $attributes = $this->mapper()->map(new StubSpan('x', ['connection' => 'default', 'profiling_automatic' => true, 'transport' => 'async']), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame('default', $attributes['db.namespace'] ?? null);
        self::assertSame('async', $attributes['messaging.destination.name'] ?? null);
    }

    public function testSemconvNamesNeedTheirType(): void
    {
        $span = new StubSpan('x', ['status' => 'pending', 'exception' => '', 'connection' => 'mine', 'transport' => 5]);

        $attributes = $this->mapper()->map($span, [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame(['profiling.status' => 'pending', 'profiling.exception' => '', 'profiling.connection' => 'mine', 'profiling.transport' => 5], $attributes);
    }

    public function testLongStringsAreTruncatedToTheLimit(): void
    {
        $mapper = new SpanMapper(new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])), 8);

        $attributes = $mapper->map(new StubSpan('x', ['payload' => str_repeat('a', 1_048_576), 'short' => 'abcdefgh']), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame(['profiling.payload' => 'aaaaaaaa', 'profiling.short' => 'abcdefgh'], $attributes);
    }

    public function testSemconvValuesAreTruncatedToo(): void
    {
        $mapper = new SpanMapper(new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])), 4);
        $span = new StubSpan('x', ['exception' => 'RuntimeException', 'connection' => 'default', 'profiling_automatic' => true, 'transport' => 'async_priority']);

        $attributes = $mapper->map($span, [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame(['Runt', 'defa', 'asyn'], [$attributes['exception.type'] ?? null, $attributes['db.namespace'] ?? null, $attributes['messaging.destination.name'] ?? null]);
    }

    public function testTheResponseCodeWinsOverAStatusInTheContext(): void
    {
        $attributes = $this->mapper()->map(new StubSpan('request x', ['status' => 503]), [], $this->ids(), SpanKind::KIND_SERVER, 200)->getAttributes()->toArray();

        self::assertSame(200, $attributes['http.response.status_code'] ?? null);
    }

    public function testTheDefaultLimitIs4096Characters(): void
    {
        $attributes = $this->mapper()->map(new StubSpan('x', ['payload' => str_repeat('é', 5000)]), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame(str_repeat('é', 4096), $attributes['profiling.payload'] ?? null);
    }

    public function testTheLimitComesFromTheEnvironmentWhenNotGiven(): void
    {
        putenv('OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT=3');

        try {
            $attributes = $this->mapper()->map(new StubSpan('x', ['payload' => 'abcdef']), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();
        } finally {
            putenv('OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT');
        }

        self::assertSame('abc', $attributes['profiling.payload'] ?? null);
    }

    public function testMultibyteStringsAreCutBetweenCharacters(): void
    {
        $mapper = new SpanMapper(new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])), 3);

        $attributes = $mapper->map(new StubSpan('x', ['city' => 'Zürich', 'bytes' => "\xff\xfe\xfd\xfc"]), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame(['profiling.city' => 'Zür', 'profiling.bytes' => "\xff\xfe\xfd"], $attributes);
    }

    public function testAKeyThatIsNotUtf8IsDropped(): void
    {
        $attributes = $this->mapper()->map(new StubSpan('x', ["bad\xff" => 1, 'ok' => 2]), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertSame(['profiling.ok' => 2], $attributes);
    }

    public function testAConnectionThatIsNotANonEmptyStringIsNoDatabase(): void
    {
        $attributes = $this->mapper()->map(new StubSpan('x', ['connection' => '', 'profiling_automatic' => true]), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray();

        self::assertArrayNotHasKey('db.namespace', $attributes);
    }

    #[TestWith([NAN])]
    #[TestWith([INF])]
    #[TestWith([-INF])]
    public function testNonFiniteFloatsAreDropped(float $value): void
    {
        self::assertSame([], $this->mapper()->map(new StubSpan('x', ['ratio' => $value]), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getAttributes()->toArray());
    }

    public function testANameThatIsNotUtf8IsMadeAscii(): void
    {
        self::assertSame('bad ? name', $this->mapper()->map(new StubSpan("bad \xff name"), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getName());
        self::assertSame('café', $this->mapper()->map(new StubSpan('café'), [], $this->ids(), SpanKind::KIND_INTERNAL, null)->getName());
    }

    public function testCarriesTheScopeAndResource(): void
    {
        $scope = new InstrumentationScope('msstc4symfony/tracing-bridge-otlp', '1.0.0', null, Attributes::create([]));
        $resource = ResourceInfo::create(Attributes::create(['service.name' => 'orders']));

        $data = (new SpanMapper($scope, $resource))->map(new StubSpan('x'), [], $this->ids(), SpanKind::KIND_INTERNAL, null);

        self::assertSame($scope, $data->getInstrumentationScope());
        self::assertSame($resource, $data->getResource());
    }

    private function mapper(): SpanMapper
    {
        return new SpanMapper(new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([])));
    }

    private function ids(): SpanIds
    {
        return new SpanIds(self::TRACE_ID, 'b7ad6b7169203331', null, false, 1);
    }
}
