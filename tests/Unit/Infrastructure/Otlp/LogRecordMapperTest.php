<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\AttributeLimit;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\LogRecordMapper;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\Utf8;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LogRecordMapper::class)]
#[UsesClass(Utf8::class)]
#[UsesClass(AttributeLimit::class)]
final class LogRecordMapperTest extends TestCase
{
    private const TRACE = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private InMemoryTraceContextHolder $holder;

    #[Override]
    protected function setUp(): void
    {
        $this->holder = new InMemoryTraceContextHolder();
        $traceParent = TraceParent::fromHeader(self::TRACE);
        self::assertInstanceOf(TraceParent::class, $traceParent);
        $this->holder->replace(TraceContext::start()->continuing($traceParent, null, Baggage::empty()));
    }

    public function testMapsTimeSeverityBodyAndTheCurrentSpan(): void
    {
        $record = $this->mapper()->map($this->record(Level::Warning, 'Payment declined', datetime: new DateTimeImmutable('2026-10-27 10:00:00.123456', new DateTimeZone('UTC'))));

        self::assertSame(1_793_095_200_123_456_000, $record->getTimestamp());
        self::assertSame(13, $record->getSeverityNumber());
        self::assertSame('WARNING', $record->getSeverityText());
        self::assertSame('Payment declined', $record->getBody());
        $span = $record->getSpanContext();
        self::assertNotNull($span);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span->getTraceId());
        self::assertSame($this->holder->current()->traceParent->spanId, $span->getSpanId());
        self::assertTrue($span->isSampled());
    }

    #[TestWith([Level::Debug, 5])]
    #[TestWith([Level::Info, 9])]
    #[TestWith([Level::Notice, 10])]
    #[TestWith([Level::Warning, 13])]
    #[TestWith([Level::Error, 17])]
    #[TestWith([Level::Critical, 18])]
    #[TestWith([Level::Alert, 19])]
    #[TestWith([Level::Emergency, 21])]
    public function testMapsEveryLevelToItsSeverity(Level $level, int $severity): void
    {
        self::assertSame($severity, $this->mapper()->map($this->record($level))->getSeverityNumber());
    }

    public function testExportsScalarContextAndExtraWithTheChannel(): void
    {
        $record = $this->mapper()->map($this->record(Level::Info, context: [
            'items' => ['a'],
            0 => 'positional',
            'order_id' => 42,
            'paid' => true,
            'none' => null,
            'ratio' => NAN,
        ], extra: [
            'trace_id' => 'x',
            'span_id' => 'y',
            'runtime_id' => 'r1',
            'order_id' => 7,
        ]));

        self::assertSame([
            'runtime_id' => 'r1',
            'order_id' => 42,
            'paid' => true,
            'monolog.channel' => 'app',
        ], $record->getAttributes()->toArray());
    }

    #[TestWith(['00', false])]
    #[TestWith(['02', false])]
    #[TestWith(['01', true])]
    #[TestWith(['03', true])]
    public function testTheRecordIsSampledLikeItsTrace(string $flags, bool $sampled): void
    {
        $traceParent = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-' . $flags);
        self::assertInstanceOf(TraceParent::class, $traceParent);
        $this->holder->replace(TraceContext::start()->continuing($traceParent, null, Baggage::empty()));

        self::assertSame($sampled, $this->mapper()->map($this->record(Level::Info))->getSpanContext()?->isSampled());
    }

    public function testTheIdsTheRecordWasLoggedWithWinOverTheCurrentSpan(): void
    {
        $logged = $this->record(Level::Info, extra: ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => 'b7ad6b7169203331']);
        $this->holder->replace($this->holder->current()->withChildSpan());

        $span = $this->mapper()->map($logged)->getSpanContext();

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span?->getTraceId());
        self::assertSame('b7ad6b7169203331', $span->getSpanId());
        self::assertTrue($span->isSampled(), 'the flags of its trace, still current');
    }

    public function testARecordOfAnotherTraceIsNotMarkedSampled(): void
    {
        $span = $this->mapper()->map($this->record(Level::Info, extra: ['trace_id' => '0af7651916cd43dd8448eb211c80319c', 'span_id' => 'b7ad6b7169203331']))->getSpanContext();

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $span?->getTraceId());
        self::assertFalse($span->isSampled());
    }

    /**
     * @param array<string, string> $extra
     */
    #[TestWith([['trace_id' => 'nope', 'span_id' => 'b7ad6b7169203331']])]
    #[TestWith([['trace_id' => '00000000000000000000000000000000', 'span_id' => 'b7ad6b7169203331']])]
    #[TestWith([['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => 'nope']])]
    #[TestWith([['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736']])]
    #[TestWith([['span_id' => 'b7ad6b7169203331']])]
    public function testInvalidLoggedIdsFallBackToTheCurrentSpan(array $extra): void
    {
        $span = $this->mapper()->map($this->record(Level::Info, extra: $extra))->getSpanContext();

        self::assertSame($this->holder->current()->traceParent->spanId, $span?->getSpanId());
    }

    public function testTheMappersNamesWinOverContextKeys(): void
    {
        $record = $this->mapper()->map($this->record(Level::Error, context: [
            'exception' => new RuntimeException('real'),
            'exception.type' => 'Forged',
            'monolog.channel' => 'forged',
        ]));

        $attributes = $record->getAttributes()->toArray();
        self::assertSame(RuntimeException::class, $attributes['exception.type'] ?? null);
        self::assertSame('app', $attributes['monolog.channel'] ?? null);
    }

    public function testAnExceptionThatIsNoThrowableStaysAPlainAttribute(): void
    {
        self::assertSame('timeout', $this->mapper()->map($this->record(Level::Error, context: ['exception' => 'timeout']))->getAttributes()->get('exception'));
    }

    public function testAnExceptionBecomesTheSemanticAttributes(): void
    {
        $record = $this->mapper()->map($this->record(Level::Error, context: ['exception' => new RuntimeException('card expired')]));

        $attributes = $record->getAttributes()->toArray();
        self::assertSame(RuntimeException::class, $attributes['exception.type'] ?? null);
        self::assertSame('card expired', $attributes['exception.message'] ?? null);
        self::assertArrayNotHasKey('exception', $attributes);
    }

    public function testLongAndBrokenStringsAreMadeSafe(): void
    {
        $record = $this->mapper(4)->map($this->record(Level::Info, "caf\xE9 au lait", context: ['note' => 'abcdefgh', "k\xFF" => 'dropped']));

        self::assertSame('caf? au lait', $record->getBody(), 'the body is not an attribute: no length limit');
        self::assertSame(['note' => 'abcd', 'monolog.channel' => 'app'], $record->getAttributes()->toArray());
    }

    /**
     * @param positive-int|null $limit
     */
    private function mapper(?int $limit = null): LogRecordMapper
    {
        return new LogRecordMapper(
            new InstrumentationScope('test', null, null, Attributes::create([])),
            ResourceInfo::create(Attributes::create([])),
            $this->holder,
            $limit,
        );
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    private function record(Level $level, string $message = 'm', array $context = [], array $extra = [], ?DateTimeImmutable $datetime = null): LogRecord
    {
        return new LogRecord($datetime ?? new DateTimeImmutable(), 'app', $level, $message, $context, $extra);
    }
}
