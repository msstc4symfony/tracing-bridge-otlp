<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Profiling;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\UnitRecorder;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

#[CoversClass(UnitRecorder::class)]
final class UnitRecorderTest extends TestCase
{
    private InMemoryTraceContextHolder $holder;

    private UnitRecorder $recorder;

    private EventDispatcher $dispatcher;

    #[Override]
    protected function setUp(): void
    {
        $this->holder = new InMemoryTraceContextHolder();
        $this->recorder = new UnitRecorder($this->holder);
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber($this->recorder);
        // As tracing-bundle's WorkerTraceSubscriber::closeUnit() at priority 0.
        $reset = function (): void {
            $this->holder->reset();
        };
        $this->dispatcher->addListener(WorkerMessageHandledEvent::class, $reset);
        $this->dispatcher->addListener(WorkerRunningEvent::class, $reset);
    }

    public function testCapturesTheMessageTraceBeforeTheWorkerClosesTheUnitWhenHandledComesFirst(): void
    {
        $messageTrace = $this->consume();

        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        $this->dispatcher->dispatch(new WorkerRunningEvent($this->worker(), false));

        self::assertSame($messageTrace, $this->recorder->takeMessageTrace());
    }

    public function testCapturesTheMessageTraceBeforeTheWorkerClosesTheUnitWhenRunningComesFirst(): void
    {
        $messageTrace = $this->consume();

        $this->dispatcher->dispatch(new WorkerRunningEvent($this->worker(), false));
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));

        self::assertSame($messageTrace, $this->recorder->takeMessageTrace());
    }

    public function testAFailedMessageIsCapturedToo(): void
    {
        $messageTrace = $this->consume();

        $this->dispatcher->dispatch(new WorkerMessageFailedEvent($this->envelope(), 'async', new RuntimeException('boom')));
        $this->holder->reset();
        $this->dispatcher->dispatch(new WorkerRunningEvent($this->worker(), false));

        self::assertSame($messageTrace, $this->recorder->takeMessageTrace());
    }

    public function testAnIdleTickCapturesNothing(): void
    {
        $this->dispatcher->dispatch(new WorkerRunningEvent($this->worker(), true));

        self::assertNull($this->recorder->takeMessageTrace());
    }

    public function testTheNextMessageForgetsThePreviousTrace(): void
    {
        $this->consume();
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));

        self::assertNull($this->recorder->takeMessageTrace());
    }

    public function testTakingTheMessageTraceForgetsIt(): void
    {
        $messageTrace = $this->consume();
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));

        self::assertSame($messageTrace, $this->recorder->takeMessageTrace());
        self::assertNull($this->recorder->takeMessageTrace());
    }

    public function testRemembersTheMainResponseStatusOnly(): void
    {
        $this->respond(HttpKernelInterface::MAIN_REQUEST, 201);
        $this->respond(HttpKernelInterface::SUB_REQUEST, 404);

        self::assertSame(201, $this->recorder->responseStatus());
    }

    public function testANewMainRequestForgetsTheStatus(): void
    {
        $this->respond(HttpKernelInterface::MAIN_REQUEST, 201);

        $this->dispatcher->dispatch(new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::SUB_REQUEST), KernelEvents::REQUEST);
        self::assertSame(201, $this->recorder->responseStatus());

        $this->dispatcher->dispatch(new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        self::assertNull($this->recorder->responseStatus());
    }

    public function testResetForgetsEverything(): void
    {
        $this->consume();
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        $this->respond(HttpKernelInterface::MAIN_REQUEST, 201);

        $this->recorder->reset();

        self::assertNull($this->recorder->takeMessageTrace());
        self::assertNull($this->recorder->responseStatus());
    }

    public function testResetEndsTheMessageInFlight(): void
    {
        $this->consume();

        $this->recorder->reset();
        $this->dispatcher->dispatch(new WorkerRunningEvent($this->worker(), false));

        self::assertNull($this->recorder->takeMessageTrace());
    }

    /**
     * Received, then the handler continues the sender's trace as IncomingTraceMiddleware does.
     */
    private function consume(): TraceContext
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $messageTrace = TraceContext::start()->withChildSpan();
        $this->holder->replace($messageTrace);

        return $messageTrace;
    }

    private function respond(int $type, int $status): void
    {
        $this->dispatcher->dispatch(new ResponseEvent($this->kernel(), new Request(), $type, new Response('', $status)), KernelEvents::RESPONSE);
    }

    private function envelope(): Envelope
    {
        return new Envelope(new stdClass());
    }

    private function worker(): Worker
    {
        return new Worker([], new MessageBus());
    }

    private function kernel(): HttpKernelInterface
    {
        return self::createStub(HttpKernelInterface::class);
    }
}
