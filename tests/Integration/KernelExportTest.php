<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Integration;

use Monolog\LogRecord;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\CollectingHandler;
use Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures\Job;
use Msstc4Symfony\TracingBridgeOtlp\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

final class KernelExportTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const CALLER_SPAN = '00f067aa0ba902b7';

    private ?TestKernel $kernel = null;

    #[Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testARequestExportsItsTreeUnderTheCallersSpan(): void
    {
        $kernel = $this->boot();
        $request = Request::create('/traced', server: ['HTTP_TRACEPARENT' => '00-' . self::TRACE_ID . '-' . self::CALLER_SPAN . '-01']);

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $byName = $this->exportedByName();
        $unit = $byName['request traced'] ?? null;
        $work = $byName['work'] ?? null;
        $call = $byName['http GET remote.example'] ?? null;
        self::assertNotNull($unit);
        self::assertNotNull($work);
        self::assertNotNull($call);
        self::assertSame(SpanKind::KIND_SERVER, $unit->getKind());
        self::assertSame(self::TRACE_ID, $unit->getTraceId());
        self::assertSame(self::CALLER_SPAN, $unit->getParentSpanId());
        self::assertTrue($unit->getParentContext()->isRemote());
        self::assertSame(201, $unit->getAttributes()->get('http.response.status_code'));
        self::assertSame($unit->getSpanId(), $work->getParentSpanId());
        self::assertSame($work->getSpanId(), $call->getParentSpanId());
        self::assertSame(SpanKind::KIND_CLIENT, $call->getKind());
        self::assertSame(self::TRACE_ID, $call->getTraceId());
        self::assertSame($work->getSpanId(), $this->logExtra('inside work')['span_id'] ?? null);
        self::assertSame($unit->getSpanId(), $response->headers->has('traceresponse') ? TraceParent::fromHeader((string) $response->headers->get('traceresponse'))?->spanId : null);
    }

    public function testTheExportGoesOutOncePerRequestAfterTerminate(): void
    {
        $kernel = $this->boot();
        $request = Request::create('/traced');

        $response = $kernel->handle($request);
        self::assertSame([], $this->exporter()->getSpans());

        $kernel->terminate($request, $response);
        self::assertCount(3, $this->exporter()->getSpans());
    }

    public function testAnUnsampledCallerExportsNothing(): void
    {
        $kernel = $this->boot();
        $request = Request::create('/traced', server: ['HTTP_TRACEPARENT' => '00-' . self::TRACE_ID . '-' . self::CALLER_SPAN . '-00']);

        $kernel->terminate($request, $kernel->handle($request));

        self::assertSame([], $this->exporter()->getSpans());
    }

    public function testAConsumedMessageExportsAConsumerSpanUnderTheSendersSpan(): void
    {
        $kernel = $this->boot();
        $holder = $kernel->getContainer()->get(TraceContextHolderInterface::class);
        self::assertInstanceOf(TraceContextHolderInterface::class, $holder);
        $sender = TraceParent::fromHeader('00-' . self::TRACE_ID . '-' . self::CALLER_SPAN . '-01');
        self::assertNotNull($sender);
        $holder->replace(TraceContext::start()->continuing($sender, null, Baggage::empty()));
        $senderSpan = $holder->current()->traceParent->spanId;
        $testContainer = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $testContainer);
        $bus = $testContainer->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new Job());
        $application = new Application($kernel);
        $application->setAutoExit(false);

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'messenger:consume', 'receivers' => ['async'], '--limit' => 1]), new NullOutput()));

        $byName = $this->exportedByName();
        $unit = $byName['message ' . Job::class] ?? null;
        $work = $byName['job work'] ?? null;
        self::assertNotNull($unit);
        self::assertNotNull($work);
        self::assertSame(SpanKind::KIND_CONSUMER, $unit->getKind());
        self::assertSame(self::TRACE_ID, $unit->getTraceId());
        self::assertSame($senderSpan, $unit->getParentSpanId());
        self::assertTrue($unit->getParentContext()->isRemote());
        self::assertSame($unit->getSpanId(), $work->getParentSpanId());
        self::assertSame($work->getSpanId(), $this->logExtra('inside job')['span_id'] ?? null);
    }

    public function testAConsoleCommandExportsItsSpans(): void
    {
        $kernel = $this->boot();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:job']), new NullOutput()));

        $byName = $this->exportedByName();
        $unit = $byName['cli command app:job'] ?? null;
        $work = $byName['command work'] ?? null;
        self::assertNotNull($unit);
        self::assertNotNull($work);
        self::assertSame(SpanKind::KIND_INTERNAL, $unit->getKind());
        self::assertFalse($unit->getParentContext()->isValid());
        self::assertSame($unit->getSpanId(), $work->getParentSpanId());
    }

    #[TestWith(['test'])]
    #[TestWith([TestKernel::ENV_BRIDGE_FIRST])]
    public function testSpansEndedOnKernelResetGoWithTheNextExport(string $environment): void
    {
        $kernel = $this->boot($environment);
        $profiling = $this->testContainer()->get(ProfilingFactoryInterface::class);
        self::assertInstanceOf(ProfilingFactoryInterface::class, $profiling);
        $request = Request::create('/traced');
        $kernel->terminate($request, $kernel->handle($request));
        $firstTrace = $this->exportedByName()['request traced']->getTraceId();
        $profiling->createSpan('left open');

        $resetter = $this->testContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        $request = Request::create('/traced');
        $kernel->terminate($request, $kernel->handle($request));

        self::assertSame($firstTrace, ($this->exportedByName()['left open'] ?? null)?->getTraceId());
    }

    public function testDisabledRegistersNoService(): void
    {
        $kernel = $this->boot(TestKernel::ENV_DISABLED);

        $request = Request::create('/traced');
        $kernel->terminate($request, $kernel->handle($request));

        self::assertFalse($kernel->getContainer()->has(TestKernel::EXPORTER));
    }

    private function boot(string $environment = 'test'): TestKernel
    {
        $this->kernel = new TestKernel($environment, false);
        $this->kernel->boot();

        return $this->kernel;
    }

    private function testContainer(): ContainerInterface
    {
        $container = $this->kernel?->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function exporter(): InMemoryExporter
    {
        $exporter = $this->kernel?->getContainer()->get(TestKernel::EXPORTER);
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        return $exporter;
    }

    /**
     * @return array<string, SpanDataInterface>
     */
    private function exportedByName(): array
    {
        $byName = [];
        foreach ($this->exporter()->getSpans() as $span) {
            self::assertInstanceOf(SpanDataInterface::class, $span);
            $byName[$span->getName()] = $span;
        }

        return $byName;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function logExtra(string $message): array
    {
        $handler = $this->kernel?->getContainer()->get(CollectingHandler::class);
        self::assertInstanceOf(CollectingHandler::class, $handler);
        $records = array_values(array_filter($handler->records, static fn (LogRecord $record): bool => $record->message === $message));
        self::assertCount(1, $records);

        return $records[0]->extra;
    }
}
