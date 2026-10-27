<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * The pause of one signal's export after a failure, kept in this process and, through the pool, shared with the other
 * processes using it (PHP-FPM, cron). The pool is an optimisation: when it fails, the pause holds for this process only.
 * Times are Unix times with microseconds.
 *
 * @internal
 */
final class ExportPause
{
    public const SECONDS = 30;

    // A long-running process (worker) sees a pause another process starts within this delay, without reading the cache
    // on every unit of work; a PHP-FPM request reads it once.
    public const SHARED_READ_SECONDS = 1;

    private ?float $pausedUntil = null;

    private ?float $sharedPause = null;

    private ?float $sharedPauseReadAt = null;

    /**
     * @param non-empty-string $key cache key of this signal's pause
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?CacheItemPoolInterface $pool,
        private readonly string $key,
    ) {
    }

    /**
     * The later pause holds: another process may have failed after this one.
     */
    public function isActive(): bool
    {
        return $this->now() < max($this->pausedUntil ?? 0.0, $this->sharedPause() ?? 0.0);
    }

    public function start(): void
    {
        $this->pausedUntil = $this->now() + self::SECONDS;
        try {
            $this->pool?->save($this->pool->getItem($this->key)->set($this->pausedUntil)->expiresAfter(self::SECONDS));
        } catch (Throwable) {
        }
    }

    /**
     * After an export went out: a clock set back would otherwise bring an old pause back, here or from the pool.
     */
    public function end(): void
    {
        if ($this->pausedUntil === null && $this->sharedPause === null) {
            return;
        }

        $this->pausedUntil = null;
        $this->sharedPause = null;
        try {
            $this->pool?->deleteItem($this->key);
        } catch (Throwable) {
        }
    }

    private function sharedPause(): ?float
    {
        if (!$this->pool instanceof CacheItemPoolInterface) {
            return null;
        }

        $now = $this->now();
        if ($this->sharedPauseReadIsFresh($now)) {
            return $this->sharedPause;
        }

        $this->sharedPauseReadAt = $now;
        try {
            $value = $this->pool->getItem($this->key)->get();
        } catch (Throwable) {
            $value = null;
        }

        return $this->sharedPause = is_float($value) ? $value : null;
    }

    // A clock set back makes the last read stale, not fresh.
    private function sharedPauseReadIsFresh(float $now): bool
    {
        return $this->sharedPauseReadAt !== null && $now >= $this->sharedPauseReadAt
            && $now - $this->sharedPauseReadAt < self::SHARED_READ_SECONDS;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
