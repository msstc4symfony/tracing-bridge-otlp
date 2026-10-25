<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Presentation\EventListener;

use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Exports the spans of a unit of work when it ends: after profiling ended its spans, before kernel.reset drops them.
 *
 * @internal
 */
final readonly class FlushListener implements EventSubscriberInterface
{
    // ConsoleEvents::TERMINATE; symfony/console is optional.
    private const CONSOLE_TERMINATE = 'console.terminate';

    // After profiling-bundle's terminate listeners at -4096.
    private const TERMINATE_PRIORITY = -8192;

    // After profiling closes the message span (0), before ResetServicesListener (-1024).
    private const WORKER_PRIORITY = -512;

    public function __construct(
        private SpanFlushInterface $flush,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['flush', self::TERMINATE_PRIORITY],
            self::CONSOLE_TERMINATE => ['flush', self::TERMINATE_PRIORITY],
            WorkerRunningEvent::class => ['flush', self::WORKER_PRIORITY],
            WorkerStoppedEvent::class => ['flush', self::WORKER_PRIORITY],
        ];
    }

    public function flush(): void
    {
        $this->flush->flush();
    }
}
