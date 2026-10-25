<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling;

use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Facts about the current unit of work that are gone when its profiling span ends: the trace of a consumed message
 * (tracing-bundle closes it on WorkerRunningEvent) and the status code of the main response. The message trace is
 * taken on whichever of handled, failed or running comes first after the handler. With an asynchronous message
 * execution strategy running may come before the handler ran: the message span then gets the worker's idle trace.
 *
 * @internal
 */
final class UnitRecorder implements EventSubscriberInterface, ResetInterface
{
    // Before tracing-bundle's closeUnit() and profiling's span listeners, all at 0 or below.
    private const CAPTURE_PRIORITY = 4096;

    private const FORGET_PRIORITY = 8192;

    private bool $messageInFlight = false;

    private ?TraceContext $messageTrace = null;

    private ?int $responseStatus = null;

    public function __construct(
        private readonly TraceContextHolderInterface $holder,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onMessageReceived', self::FORGET_PRIORITY],
            WorkerMessageHandledEvent::class => ['captureMessageTrace', self::CAPTURE_PRIORITY],
            WorkerMessageFailedEvent::class => ['captureMessageTrace', self::CAPTURE_PRIORITY],
            WorkerRunningEvent::class => ['captureMessageTrace', self::CAPTURE_PRIORITY],
            KernelEvents::REQUEST => ['onRequest', self::FORGET_PRIORITY],
            KernelEvents::RESPONSE => ['onResponse', -self::CAPTURE_PRIORITY],
        ];
    }

    public function onMessageReceived(): void
    {
        $this->messageTrace = null;
        $this->messageInFlight = true;
    }

    /**
     * The first worker event after the handler ran sees the message's trace, whatever order Symfony dispatches in.
     */
    public function captureMessageTrace(): void
    {
        if (!$this->messageInFlight) {
            return;
        }

        $this->messageInFlight = false;
        $this->messageTrace = $this->holder->current();
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->responseStatus = null;
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->responseStatus = $event->getResponse()->getStatusCode();
        }
    }

    /**
     * The trace of the message just handled, once: the next message span must not reuse it.
     */
    public function takeMessageTrace(): ?TraceContext
    {
        $trace = $this->messageTrace;
        $this->messageTrace = null;

        return $trace;
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    #[Override]
    public function reset(): void
    {
        $this->messageInFlight = false;
        $this->messageTrace = null;
        $this->responseStatus = null;
    }
}
