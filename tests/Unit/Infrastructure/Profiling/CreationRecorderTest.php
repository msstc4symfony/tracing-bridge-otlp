<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Profiling;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\CreationRecorder;
use Msstc4Symfony\TracingBridgeOtlp\Test\Support\StubSpan;
use Msstc4Symfony\TracingBundle\Infrastructure\Context\InMemoryTraceContextHolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreationRecorder::class)]
final class CreationRecorderTest extends TestCase
{
    public function testRecordsTheTraceCurrentWhenTheSpanIsCreated(): void
    {
        $holder = new InMemoryTraceContextHolder();
        $recorder = new CreationRecorder($holder);
        $span = new StubSpan('x');

        self::assertSame($span, $recorder->process($span));
        $at = $holder->current();
        $holder->reset();

        self::assertSame($at, $recorder->traceAt($span));
    }

    public function testAnUnknownSpanHasNoRecord(): void
    {
        self::assertNull((new CreationRecorder(new InMemoryTraceContextHolder()))->traceAt(new StubSpan('x')));
    }

    public function testResetForgetsEveryRecord(): void
    {
        $recorder = new CreationRecorder(new InMemoryTraceContextHolder());
        $span = new StubSpan('x');
        $recorder->process($span);

        $recorder->reset();

        self::assertNull($recorder->traceAt($span));
    }
}
