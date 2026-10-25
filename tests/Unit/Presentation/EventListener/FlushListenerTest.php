<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Presentation\EventListener;

use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use Msstc4Symfony\TracingBridgeOtlp\Presentation\EventListener\FlushListener;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

#[CoversClass(FlushListener::class)]
final class FlushListenerTest extends TestCase
{
    // profiling-bundle ends its terminate spans at -4096; Symfony resets services on WorkerRunningEvent at -1024.
    private const PROFILING_TERMINATE_END = -4096;

    private const KERNEL_RESET = -1024;

    public function testTheListenerFlushesAtEveryUnitEnd(): void
    {
        $flush = new class implements SpanFlushInterface {
            public int $flushes = 0;

            #[Override]
            public function flush(): void
            {
                $this->flushes++;
            }
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new FlushListener($flush));

        $worker = new Worker([], new MessageBus());

        $dispatcher->dispatch(new TerminateEvent(self::createStub(HttpKernelInterface::class), new Request(), new Response()), KernelEvents::TERMINATE);
        $dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:job'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
        $dispatcher->dispatch(new WorkerRunningEvent($worker, false));
        $dispatcher->dispatch(new WorkerStoppedEvent($worker));

        self::assertSame(4, $flush->flushes);
    }

    public function testTheListenerRunsAfterProfilingEndsItsSpansAndBeforeKernelReset(): void
    {
        $events = FlushListener::getSubscribedEvents();

        self::assertLessThan(self::PROFILING_TERMINATE_END, $events[KernelEvents::TERMINATE][1]);
        self::assertLessThan(self::PROFILING_TERMINATE_END, $events[ConsoleEvents::TERMINATE][1]);
        self::assertLessThan(0, $events[WorkerRunningEvent::class][1]);
        self::assertGreaterThan(self::KERNEL_RESET, $events[WorkerRunningEvent::class][1]);
        self::assertGreaterThan(self::KERNEL_RESET, $events[WorkerStoppedEvent::class][1]);
    }
}
