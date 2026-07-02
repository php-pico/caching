<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use DateInterval;
use Override;
use PhpPico\Caching\Concerns\CacheKeyValidation;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Exceptions\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Cache.
 *
 * A PSR-16 cache that owns key validation, value (de)serialization and
 * TTL-to-timestamp conversion, delegating storage to a swappable {@see Driver}.
 */
final readonly class Cache implements CacheInterface
{
    use CacheKeyValidation;

    public function __construct(
        public Driver $driver,
    ) {}

    /**
     * Fetches a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key does not exist.
     *
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertValidKey($key);

        $raw = $this->driver->get($key);

        return $raw === null ? $default : unserialize($raw);
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string                 $key   The key of the item to store.
     * @param mixed                  $value The value of the item to store, must be serializable.
     * @param null|int|DateInterval $ttl   Optional. The TTL value of this item. If no value is sent and
     *                                      the driver supports TTL then the library may set a default value
     *                                      for it or let the driver take care of that.
     *
     * @return bool True on success and false on failure.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $this->assertValidKey($key);

        return $this->driver->set($key, serialize($value), $this->calculateExpiration($ttl));
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function delete(string $key): bool
    {
        $this->assertValidKey($key);

        return $this->driver->delete($key);
    }

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    #[Override]
    public function clear(): bool
    {
        return $this->driver->clear();
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys    A list of keys that can be obtained in a single operation.
     * @param mixed            $default Default value to return for keys that do not exist.
     *
     * @return iterable<string, mixed> A list of key => value pairs. Cache keys that do not exist or are stale will have $default as value.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $keys is neither an array nor a Traversable,
     *   or if any of the $keys are not a legal value.
     */
    #[Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys, false);

        if ($keys === []) {
            return [];
        }

        foreach ($keys as $key) {
            $this->assertValidKey($key);
        }

        $raws = $this->driver->getMultiple(array_values($keys));

        $result = [];
        foreach ($keys as $key) {
            $raw = $raws[$key] ?? null;
            $result[$key] = is_string($raw) ? unserialize($raw) : $default;
        }

        return $result;
    }

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable               $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|DateInterval $ttl    Optional. The TTL value of this item. If no value is sent and
     *                                       the driver supports TTL then the library may set a default value
     *                                       for it or let the driver take care of that.
     *
     * @return bool True on success and false on failure.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $values is neither an array nor a Traversable,
     *   or if any of the $values are not a legal value.
     */
    #[Override]
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        $values = is_array($values) ? $values : iterator_to_array($values);

        if ($values === []) {
            return true;
        }

        $serialized = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Provided key is not a string');
            }

            $this->assertValidKey($key);
            $serialized[$key] = serialize($value);
        }

        return $this->driver->setMultiple($serialized, $this->calculateExpiration($ttl));
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     *
     * @return bool True if the items were successfully removed. False if there was an error.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $keys is neither an array nor a Traversable,
     *   or if any of the $keys are not a legal value.
     */
    #[Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys, false);

        foreach ($keys as $key) {
            $this->assertValidKey($key);
        }

        return $this->driver->deleteMultiple(array_values($keys));
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * NOTE: It is recommended that has() is only to be used for cache warming type purposes
     * and not to be used within your live applications operations for get/set, as this method
     * is subject to a race condition where your has() will return true and immediately after,
     * another script can remove it making the state of your app out of date.
     *
     * @param string $key The cache item key.
     *
     * @return bool
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function has(string $key): bool
    {
        $this->assertValidKey($key);

        return $this->driver->has($key);
    }
}
