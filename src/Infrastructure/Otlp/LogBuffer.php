<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use Override;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Log records of the current unit of work, waiting for its end. Emptied on kernel.reset, which runs after the unit's
 * export: records written there would otherwise belong to the next unit.
 *
 * @internal
 */
final class LogBuffer implements ResetInterface
{
    /** @var list<ReadableLogRecord> */
    private array $records = [];

    /**
     * @param positive-int $capacity
     */
    public function __construct(
        private readonly int $capacity,
    ) {
    }

    /**
     * @return bool true when the buffer is full and must be drained
     */
    public function add(ReadableLogRecord $record): bool
    {
        $this->records[] = $record;

        return count($this->records) >= $this->capacity;
    }

    /**
     * @return list<ReadableLogRecord>
     */
    public function drain(): array
    {
        $records = $this->records;
        $this->records = [];

        return $records;
    }

    #[Override]
    public function reset(): void
    {
        $this->records = [];
    }
}
