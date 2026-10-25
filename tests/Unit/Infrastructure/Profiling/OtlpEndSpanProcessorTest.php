<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Profiling;

use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\AttributeLimit;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\DatabaseSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ExportedSpan;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanIds;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\SpanMapper;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\CreationRecorder;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\OtlpEndSpanProcessor;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\SpanPlacement;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\SpanPlacer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\UnitRecorder;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\RecordingLogger;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\StubSpan;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

#[CoversClass(OtlpEndSpanProcessor::class)]
#[UsesClass(CreationRecorder::class)]
#[UsesClass(UnitRecorder::class)]
#[UsesClass(SpanPlacer::class)]
#[UsesClass(SpanPlacement::class)]
#[UsesClass(DatabaseSpan::class)]
#[UsesClass(AttributeLimit::class)]
#[UsesClass(SpanMapper::class)]
#[UsesClass(SpanBuffer::class)]
#[UsesClass(SpanIds::class)]
#[UsesClass(ExportedSpan::class)]
final class OtlpEndSpanProcessorTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const CALLER_SPAN = '00f067aa0ba902b7';

    private InMemoryTraceContextHolder $holder;

    private CreationRecorder $creations;

    private UnitRecorder $units;

    private SpanBuffer $buffer;

    private int $flushes = 0;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        $this->holder = new InMemoryTraceContextHolder();
        $this->creations = new CreationRecorder($this->holder);
        $this->units = new UnitRecorder($this->holder);
        $this->buffer = new SpanBuffer(10);
        $this->logger = new RecordingLogger();
    }

    public function testABridgeSpanUsesItsTraceSpanIdsAndTheTraceIdAtCreation(): void
    {
        $this->holder->replace($this->continued('01'));
        $span = $this->created(new StubSpan('work', ['trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'a3ce929d0e0e4736']));
        $this->holder->reset();

        $this->processor()->process($span, []);

        $exported = $this->single();
        self::assertSame(self::TRACE_ID, $exported->getTraceId());
        self::assertSame('b7ad6b7169203331', $exported->getSpanId());
        self::assertSame('a3ce929d0e0e4736', $exported->getParentSpanId());
        self::assertFalse($exported->getParentContext()->isRemote());
        self::assertSame(SpanKind::KIND_INTERNAL, $exported->getKind());
    }

    public function testADbalSpanIsAClient(): void
    {
        $span = $this->created(new StubSpan('SELECT 1', ['connection' => 'default', 'profiling_automatic' => true, 'trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'a3ce929d0e0e4736']));

        $this->processor()->process($span, []);

        self::assertSame(SpanKind::KIND_CLIENT, $this->single()->getKind());
    }

    public function testAConnectionKeyWithoutTheAutomaticMarkerIsInternal(): void
    {
        $span = $this->created(new StubSpan('import', ['connection' => 'mine', 'trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'a3ce929d0e0e4736']));

        $this->processor()->process($span, []);

        self::assertSame(SpanKind::KIND_INTERNAL, $this->single()->getKind());
    }

    public function testAnEmptyConnectionIsNoDatabaseClient(): void
    {
        $span = $this->created(new StubSpan('SELECT 1', ['connection' => '', 'profiling_automatic' => true, 'trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'a3ce929d0e0e4736']));

        $this->processor()->process($span, []);

        self::assertSame(SpanKind::KIND_INTERNAL, $this->single()->getKind());
    }

    public function testAnInvalidTraceParentSpanIdIsReported(): void
    {
        $processor = $this->processor();

        $processor->process($this->created(new StubSpan('work', ['trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'xyz'])), []);
        $processor->process($this->created(new StubSpan('work', ['trace_span_id' => 'b7ad6b7169203332', 'trace_parent_span_id' => 'xyz'])), []);

        self::assertSame([['level' => 'debug', 'message' => 'Span "{span}" has an invalid trace_parent_span_id; it is exported as a root.', 'context' => ['span' => 'work']]], $this->logger->records);
    }

    public function testSkippedNamesAreRememberedUpToALimit(): void
    {
        $processor = $this->processor();
        for ($i = 0; $i <= OtlpEndSpanProcessor::MAX_REPORTED_NAMES; $i++) {
            $processor->process(new StubSpan('work ' . $i), []);
        }

        $processor->process(new StubSpan('work 0'), []);
        $processor->process(new StubSpan('work ' . OtlpEndSpanProcessor::MAX_REPORTED_NAMES), []);

        // The names past the limit are not remembered: the last one is reported again.
        self::assertCount(OtlpEndSpanProcessor::MAX_REPORTED_NAMES + 2, $this->logger->records);
    }

    public function testADetachedSpanGetsANewIdUnderTheSpanCurrentAtCreation(): void
    {
        $this->holder->replace($this->continued('01'));
        $current = $this->holder->current()->traceParent->spanId;
        $span = $this->created(new StubSpan('GET /remote', ['profiling_detached' => true, 'profiling_automatic' => true]));

        $this->processor()->process($span, ['status' => 200]);

        $exported = $this->single();
        self::assertSame(SpanKind::KIND_CLIENT, $exported->getKind());
        self::assertSame(self::TRACE_ID, $exported->getTraceId());
        self::assertSame($current, $exported->getParentSpanId());
        self::assertFalse($exported->getParentContext()->isRemote());
        self::assertTrue(TraceParent::isSpanId($exported->getSpanId()));
        self::assertNotSame($current, $exported->getSpanId());
    }

    public function testARequestSpanIsTheUnitSpanWithItsRemoteParentAndResponseStatus(): void
    {
        $this->holder->replace($this->continued('01'));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->units);
        $dispatcher->dispatch(new ResponseEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new Response('', Response::HTTP_SERVICE_UNAVAILABLE)), KernelEvents::RESPONSE);

        $this->processor()->process(new StubSpan('request orders_create'), []);

        $exported = $this->single();
        self::assertSame(SpanKind::KIND_SERVER, $exported->getKind());
        self::assertSame($this->holder->current()->traceParent->spanId, $exported->getSpanId());
        self::assertSame(self::CALLER_SPAN, $exported->getParentSpanId());
        self::assertTrue($exported->getParentContext()->isRemote());
        self::assertSame(503, $exported->getAttributes()->get('http.response.status_code'));
    }

    public function testAUnitSpanOfANewTraceIsARoot(): void
    {
        $this->processor()->process(new StubSpan('cli command app:job'), []);

        $exported = $this->single();
        self::assertSame(SpanKind::KIND_INTERNAL, $exported->getKind());
        self::assertFalse($exported->getParentContext()->isValid());
    }

    public function testAMessageSpanUsesTheRecordedMessageTrace(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->units);
        $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));

        $messageTrace = $this->continued('01');
        $this->holder->replace($messageTrace);
        $dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        $this->holder->reset();

        $this->processor()->process(new StubSpan('message App\Job'), []);

        $exported = $this->single();
        self::assertSame(SpanKind::KIND_CONSUMER, $exported->getKind());
        self::assertSame($messageTrace->traceParent->spanId, $exported->getSpanId());
        self::assertSame(self::TRACE_ID, $exported->getTraceId());
        self::assertNull($this->units->takeMessageTrace());
    }

    public function testAMessageSpanWithoutARecordedTraceUsesTheCurrentOne(): void
    {
        $this->processor()->process(new StubSpan('message App\Job'), []);

        self::assertSame($this->holder->current()->traceParent->spanId, $this->single()->getSpanId());
    }

    public function testAnActionSpanUsesTheTraceCurrentAtItsEnd(): void
    {
        $action = $this->continued('01')->withChildSpan();
        $this->holder->replace($action);

        $this->processor()->process(new StubSpan('action App\Pay'), []);

        $exported = $this->single();
        self::assertSame($action->traceParent->spanId, $exported->getSpanId());
        self::assertSame($action->parentSpanId, $exported->getParentSpanId());
        self::assertFalse($exported->getParentContext()->isRemote());
    }

    public function testAnActionSpanUnderALocalUnitHasALocalParent(): void
    {
        $action = $this->holder->current()->withChildSpan();
        $this->holder->replace($action);

        $this->processor()->process(new StubSpan('action App\Pay'), []);

        $exported = $this->single();
        self::assertSame($action->parentSpanId, $exported->getParentSpanId());
        self::assertFalse($exported->getParentContext()->isRemote());
    }

    public function testAnInvalidTraceParentSpanIdMakesARoot(): void
    {
        $span = $this->created(new StubSpan('work', ['trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'xyz']));

        $this->processor()->process($span, []);

        self::assertFalse($this->single()->getParentContext()->isValid());
    }

    public function testASkippedSpanIsReportedOncePerNameUntilReset(): void
    {
        $processor = $this->processor();

        $processor->process(new StubSpan('work'), []);
        $processor->process(new StubSpan('work'), []);
        $processor->process(new StubSpan('other'), []);
        $processor->reset();
        $processor->process(new StubSpan('work'), []);

        self::assertSame([
            ['level' => 'debug', 'message' => 'Span "{span}" is not exported: it is not part of a trace.', 'context' => ['span' => 'work']],
            ['level' => 'debug', 'message' => 'Span "{span}" is not exported: it is not part of a trace.', 'context' => ['span' => 'other']],
            ['level' => 'debug', 'message' => 'Span "{span}" is not exported: it is not part of a trace.', 'context' => ['span' => 'work']],
        ], $this->logger->records);
    }

    public function testAnUnsampledTraceIsNotExported(): void
    {
        $this->holder->replace($this->continued('00'));
        $span = $this->created(new StubSpan('work', ['trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'a3ce929d0e0e4736']));

        $this->processor()->process($span, []);
        $this->processor()->process(new StubSpan('request x'), []);

        self::assertSame([], $this->buffer->drain());
    }

    public function testASpanWithoutCreationRecordIsSkipped(): void
    {
        $this->processor()->process(new StubSpan('work', ['trace_span_id' => 'b7ad6b7169203331', 'trace_parent_span_id' => 'a3ce929d0e0e4736']), []);
        $this->processor()->process(new StubSpan('GET /remote', ['profiling_detached' => true]), []);

        self::assertSame([], $this->buffer->drain());
    }

    public function testASpanNeitherUnitNorTracedIsSkipped(): void
    {
        $this->processor()->process($this->created(new StubSpan('work')), []);
        $this->processor()->process($this->created(new StubSpan('work', ['trace_span_id' => 'not-a-span-id'])), []);

        self::assertSame([], $this->buffer->drain());
    }

    public function testAFullBufferIsFlushed(): void
    {
        $this->buffer = new SpanBuffer(2);
        $processor = $this->processor();

        $processor->process(new StubSpan('request x'), []);
        self::assertSame(0, $this->flushes);
        $processor->process(new StubSpan('request x'), []);

        self::assertSame(1, $this->flushes);
    }

    private function processor(): OtlpEndSpanProcessor
    {
        $flush = new class($this->flushes) implements SpanFlushInterface {
            public function __construct(
                private int &$flushes,
            ) {
            }

            #[Override]
            public function flush(): void
            {
                $this->flushes++;
            }
        };

        return new OtlpEndSpanProcessor(
            new SpanPlacer($this->creations, $this->units, $this->holder, ['request ', 'cli command ', 'message ', 'action '], $this->logger),
            new SpanMapper(new InstrumentationScope('test', null, null, Attributes::create([])), ResourceInfo::create(Attributes::create([]))),
            $this->buffer,
            $flush,
            $this->logger,
        );
    }

    private function created(StubSpan $span): StubSpan
    {
        $this->creations->process($span);

        return $span;
    }

    private function continued(string $flags): TraceContext
    {
        $received = TraceParent::fromHeader('00-' . self::TRACE_ID . '-' . self::CALLER_SPAN . '-' . $flags);
        self::assertNotNull($received);

        return TraceContext::start()->continuing($received, null, Baggage::empty());
    }

    private function single(): SpanDataInterface
    {
        $spans = $this->buffer->drain();
        self::assertCount(1, $spans);

        return $spans[0];
    }
}
