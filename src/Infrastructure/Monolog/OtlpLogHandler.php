<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBridgeOtlp\Application\Port\SpanFlushInterface;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\LogBuffer;
use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\LogRecordMapper;
use Override;
use Throwable;

/**
 * Buffers Monolog records for the OTLP log export at the end of the unit of work. Records of the export itself (its
 * "otlp" channel) stay out: a failed export would otherwise log about itself into the next batch.
 *
 * @internal
 */
final class OtlpLogHandler extends AbstractProcessingHandler
{
    public const OWN_CHANNEL = 'otlp';

    public function __construct(
        private readonly LogRecordMapper $mapper,
        private readonly LogBuffer $buffer,
        private readonly SpanFlushInterface $flush,
        int|string|Level $level = Level::Info,
    ) {
        parent::__construct($level);
    }

    #[Override]
    public function isHandling(LogRecord $record): bool
    {
        return $record->channel !== self::OWN_CHANNEL && parent::isHandling($record);
    }

    #[Override]
    protected function write(LogRecord $record): void
    {
        // Logging must not fail the application: a record that cannot be mapped is lost alone.
        try {
            $full = $this->buffer->add($this->mapper->map($record));
        } catch (Throwable) {
            return;
        }

        if ($full) {
            $this->flush->flush();
        }
    }
}
