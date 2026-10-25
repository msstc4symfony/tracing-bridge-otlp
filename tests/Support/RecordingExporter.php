<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Support;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Override;
use RuntimeException;

/**
 * Records every batch; answers with the configured outcome: true, false, or a thrown exception.
 */
final class RecordingExporter implements SpanExporterInterface
{
    /** @var list<list<SpanDataInterface>> */
    public array $batches = [];

    public function __construct(
        public bool|RuntimeException $outcome = true,
    ) {
    }

    /**
     * @param iterable<SpanDataInterface> $batch
     *
     * @return FutureInterface<bool>
     */
    #[Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $this->batches[] = array_values([...$batch]);
        if ($this->outcome instanceof RuntimeException) {
            throw $this->outcome;
        }

        return new CompletedFuture($this->outcome);
    }

    #[Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
