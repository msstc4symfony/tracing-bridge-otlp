<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Override;

/**
 * Keeps its records across kernel.reset, which a Messenger worker calls after every message and which empties
 * Monolog's TestHandler.
 */
final class CollectingHandler extends AbstractProcessingHandler
{
    /** @var list<LogRecord> */
    public array $records = [];

    #[Override]
    public function reset(): void
    {
    }

    #[Override]
    protected function write(LogRecord $record): void
    {
        $this->records[] = $record;
    }
}
