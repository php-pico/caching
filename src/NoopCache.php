<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use Override;
use Psr\SimpleCache\CacheInterface;

/**
 * NoopCache.
 *
 * This cache driver stores nothing. Reads always report a miss, returning the supplied default,
 * while writes and deletions always report success because discarding the data is exactly the
 * operation this driver performs. Useful for testing or for transparently disabling caching
 * without tripping a caller's failure handling.
 */
final readonly class NoopCache implements CacheInterface
{
    use CacheTrait;

    /**
     * Fetches a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key does not exist.
     *
     * @return mixed Always $default, since this driver stores nothing and every read is a miss.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertValidKey($key);

        return $default;
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string                 $key   The key of the item to store.
     * @param mixed                  $value The value of the item to store, must be serializable.
     * @param null|int|\DateInterval $ttl   Optional. The TTL value of this item. If no value is sent and
     *                                      the driver supports TTL then the library may set a default value
     *                                      for it or let the driver take care of that.
     *
     * @return bool Always true, since discarding the value is the operation this driver performs and it cannot fail.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->assertValidKey($key);

        return true;
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     *
     * @return bool Always true, since there is nothing to remove and the operation cannot fail.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function delete(string $key): bool
    {
        $this->assertValidKey($key);

        return true;
    }

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool Always true, since there is nothing to wipe and the operation cannot fail.
     */
    #[Override]
    public function clear(): bool
    {
        return true;
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys    A list of keys that can be obtained in a single operation.
     * @param mixed            $default Default value to return for keys that do not exist.
     *
     * @return iterable<string, mixed> A list of key => $default pairs; every key is a miss, since this driver stores nothing.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $keys is neither an array nor a Traversable,
     *   or if any of the $keys are not a legal value.
     */
    #[Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $this->assertValidKey($key);
            $result[$key] = $default;
        }

        return $result;
    }

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable               $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|\DateInterval $ttl    Optional. The TTL value of this item. If no value is sent and
     *                                       the driver supports TTL then the library may set a default value
     *                                       for it or let the driver take care of that.
     *
     * @return bool Always true, since discarding the values is the operation this driver performs and it cannot fail.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $values is neither an array nor a Traversable,
     *   or if any of the $values are not a legal value.
     */
    #[Override]
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        // @mago-expect analysis:mixed-assignment
        // @mago-expect analysis:mixed-assignment
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Provided key is not a string');
            }

            $this->assertValidKey($key);
        }

        return true;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     *
     * @return bool Always true, since there is nothing to remove and the operation cannot fail.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $keys is neither an array nor a Traversable,
     *   or if any of the $keys are not a legal value.
     */
    #[Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->assertValidKey($key);
        }

        return true;
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
     * @return bool Always false, since this driver stores nothing and never holds an item.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function has(string $key): bool
    {
        $this->assertValidKey($key);

        return false;
    }
}
