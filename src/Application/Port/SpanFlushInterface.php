<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Application\Port;

/**
 * Hands the spans, and the log records with logs.enabled, buffered so far to the exporters. Named after the spans it
 * started with; a rename waits for 2.0.
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
