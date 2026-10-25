<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Application\Port;

/**
 * Hands the spans buffered so far to the exporter.
 *
 * @internal
 */
interface SpanFlushInterface
{
    /**
     * Never throws: a failed export is logged.
     */
    public function flush(): void;
}
