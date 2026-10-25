<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling;

use Override;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Span names already reported in this unit of work, so a debug record is written once per name.
 *
 * @internal
 */
final class ReportedNames implements ResetInterface
{
    // A long-running command without kernel.reset must not grow the list without bound.
    public const MAX = 256;

    /** @var array<string, true> */
    private array $names = [];

    /**
     * @return bool true the first time a name is seen; names past the limit are not remembered
     */
    public function first(string $name): bool
    {
        if (isset($this->names[$name])) {
            return false;
        }

        if (count($this->names) < self::MAX) {
            $this->names[$name] = true;
        }

        return true;
    }

    #[Override]
    public function reset(): void
    {
        $this->names = [];
    }
}
