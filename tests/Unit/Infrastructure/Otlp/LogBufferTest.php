<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\LogBuffer;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Logs\LoggerSharedState;
use OpenTelemetry\SDK\Logs\LogRecordLimitsBuilder;
use OpenTelemetry\SDK\Logs\Processor\NoopLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogBuffer::class)]
final class LogBufferTest extends TestCase
{
    public static function record(): ReadableLogRecord
    {
        return new ReadableLogRecord(
            new InstrumentationScope('test', null, null, Attributes::create([])),
            new LoggerSharedState(ResourceInfo::create(Attributes::create([])), (new LogRecordLimitsBuilder())->build(), new NoopLogRecordProcessor()),
            new LogRecord('m'),
        );
    }

    public function testSaysWhenItIsFull(): void
    {
        $buffer = new LogBuffer(2);

        self::assertFalse($buffer->add(self::record()));
        self::assertTrue($buffer->add(self::record()));
    }

    public function testDrainEmptiesIt(): void
    {
        $buffer = new LogBuffer(5);
        $record = self::record();
        $buffer->add($record);

        self::assertSame([$record], $buffer->drain());
        self::assertSame([], $buffer->drain());
    }

    public function testResetForgetsTheRecordsOfTheUnit(): void
    {
        $buffer = new LogBuffer(5);
        $buffer->add(self::record());

        $buffer->reset();

        self::assertSame([], $buffer->drain());
    }
}
