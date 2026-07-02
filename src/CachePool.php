<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use Override;
use PhpPico\Caching\Concerns\CacheKeyValidation;
use PhpPico\Caching\Driver\Driver;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * CachePool.
 *
 * A PSR-6 cache item pool that reuses the same {@see Driver} backends, key rules,
 * (de)serialization and TTL handling as the PSR-16 {@see Cache}. Deferred items
 * are visible to getItem()/getItems()/hasItem() before commit().
 */
final class CachePool implements CacheItemPoolInterface
{
    use CacheKeyValidation;

    /** @var array<string, CacheItem> Items awaiting commit(). */
    protected array $deferred = [];

    public function __construct(
        protected readonly Driver $driver,
    ) {}

    /**
     * Flush any items still deferred when the pool is destroyed, so PSR-6's
     * guarantee that deferred items are not lost holds even without an explicit
     * commit(). Best-effort: a failure at shutdown is swallowed so it cannot
     * fatal during garbage collection.
     */
    public function __destruct()
    {
        if ($this->deferred === []) {
            return;
        }

        try {
            $this->commit();
        } catch (\Throwable) {
            // The backend is unreachable at shutdown; nothing more we can do.
        }
    }

    #[Override]
    public function getItem(string $key): CacheItemInterface
    {
        $this->assertValidKey($key);

        if (isset($this->deferred[$key])) {
            return CacheItem::hit($key, $this->deferred[$key]->rawValue());
        }

        $raw = $this->driver->get($key);

        return $raw === null ? CacheItem::miss($key) : CacheItem::hit($key, unserialize($raw));
    }

    /**
     * @param array<array-key, string> $keys
     *
     * @return array<string, CacheItem>
     */
    #[Override]
    public function getItems(array $keys = []): iterable
    {
        if ($keys === []) {
            return [];
        }

        foreach ($keys as $key) {
            $this->assertValidKey($key);
        }

        $fromDriver = array_values(array_filter($keys, fn(string $key): bool => !isset($this->deferred[$key])));
        $raws = $fromDriver === [] ? [] : $this->driver->getMultiple($fromDriver);

        $items = [];
        foreach ($keys as $key) {
            if (isset($this->deferred[$key])) {
                $items[$key] = CacheItem::hit($key, $this->deferred[$key]->rawValue());
                continue;
            }

            $raw = $raws[$key] ?? null;
            $items[$key] = is_string($raw) ? CacheItem::hit($key, unserialize($raw)) : CacheItem::miss($key);
        }

        return $items;
    }

    #[Override]
    public function hasItem(string $key): bool
    {
        $this->assertValidKey($key);

        return isset($this->deferred[$key]) || $this->driver->has($key);
    }

    #[Override]
    public function clear(): bool
    {
        $this->deferred = [];

        return $this->driver->clear();
    }

    #[Override]
    public function deleteItem(string $key): bool
    {
        $this->assertValidKey($key);

        unset($this->deferred[$key]);

        return $this->driver->delete($key);
    }

    /**
     * @param array<array-key, string> $keys
     */
    #[Override]
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->assertValidKey($key);
        }

        foreach ($keys as $key) {
            unset($this->deferred[$key]);
        }

        return $this->driver->deleteMultiple(array_values($keys));
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->persist($this->asOwnItem($item));
    }

    #[Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $item = $this->asOwnItem($item);
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    #[Override]
    public function commit(): bool
    {
        $success = true;

        foreach ($this->deferred as $item) {
            $success = $this->persist($item) && $success;
        }

        $this->deferred = [];

        return $success;
    }

    protected function persist(CacheItem $item): bool
    {
        return $this->driver->set(
            $item->getKey(),
            serialize($item->rawValue()),
            $this->calculateExpiration($item->rawExpiry()),
        );
    }

    /**
     * PSR-6 items are always minted by this pool; coerce defensively in case a
     * foreign CacheItemInterface is passed (only its key and value are recoverable).
     */
    protected function asOwnItem(CacheItemInterface $item): CacheItem
    {
        return $item instanceof CacheItem ? $item : CacheItem::hit($item->getKey(), $item->get());
    }
}
