<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;

/**
 * profiling-bundle's automatic DBAL spans: a CLIENT span with db.namespace. A "connection" key alone is no database.
 *
 * @internal
 */
final class DatabaseSpan
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return non-empty-string|null the connection name of a DBAL span
     */
    public static function connection(array $context): ?string
    {
        foreach (DetachedSpanFactoryInterface::AUTOMATIC as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                return null;
            }
        }

        $connection = $context['connection'] ?? null;

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
