<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures;

use Override;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * Counts the lookups of the pool it decorates, as a metrics bundle would.
 */
final class CountingPool implements AdapterInterface
{
    public int $lookups = 0;

    public function __construct(
        private readonly AdapterInterface $inner,
    ) {
    }

    #[Override]
    public function getItem(mixed $key): CacheItem
    {
        $this->lookups++;

        return $this->inner->getItem($key);
    }

    #[Override]
    public function getItems(array $keys = []): iterable
    {
        $this->lookups++;

        return $this->inner->getItems($keys);
    }

    #[Override]
    public function hasItem(string $key): bool
    {
        $this->lookups++;

        return $this->inner->hasItem($key);
    }

    #[Override]
    public function clear(string $prefix = ''): bool
    {
        return $this->inner->clear($prefix);
    }

    #[Override]
    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    #[Override]
    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item);
    }

    #[Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    #[Override]
    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
