<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use DateInterval;
use FilesystemIterator;
use JsonException;
use Override;
use Psr\SimpleCache\CacheInterface;

/**
 * FilesystemCache.
 *
 * Writes cache as files on disk.
 */
final readonly class FilesystemCache implements CacheInterface
{
    use CacheTrait;

    /**
     * @throws \InvalidArgumentException If directory traversal is detected.
     */
    public function __construct(
        public string $dir,
    ) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (str_contains($dir, '..')) {
            throw new \InvalidArgumentException('Directory traversal detected.');
        }
    }

    /**
     * Get absolute file path for a cache key.
     *
     * @param string $key
     *
     * @return string
     */
    protected function getAbsolutePath(string $key): string
    {
        return (string) preg_replace('/([\/]+)/', '/', $this->dir . DIRECTORY_SEPARATOR . $key);
    }

    /**
     * Read a cache item from the filesystem by key.
     *
     * @param string $key
     *
     * @return array{expires: null|int, value: mixed}|null
     */
    protected function readCacheItem(string $key): ?array
    {
        $filePath = $this->getAbsolutePath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        try {
            // @mago-expect analysis:mixed-assignment
            $item = json_decode((string) file_get_contents($filePath), true);
        } catch (JsonException) {
            return null;
        }

        if (
            is_array($item)
            && array_key_exists('expires', $item)
            && array_key_exists('value', $item)
            && is_string($item['value'])
        ) {
            return [
                'expires' => is_int($item['expires']) ? $item['expires'] : null,
                'value' => unserialize($item['value']),
            ];
        }

        return null;
    }

    /**
     * Get a FilesystemIterator for the cache directory.
     *
     * @return FilesystemIterator
     */
    protected function getFilesystemIterator(): FilesystemIterator
    {
        return new FilesystemIterator($this->dir);
    }

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

        if ($this->has($key)) {
            $item = $this->readCacheItem($key);

            if ($item && (is_null($item['expires']) || $item['expires'] > time())) {
                return $item['value'];
            }
        }

        $this->delete($key);

        return $default;
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

        $filePath = $this->getAbsolutePath($key);

        return (bool) file_put_contents($filePath, json_encode([
            'expires' => $this->calculateExpiration($ttl),
            'value' => serialize($value),
        ]));
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

        $filePath = $this->getAbsolutePath($key);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return false;
    }

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    #[Override]
    public function clear(): bool
    {
        $files = $this->getFilesystemIterator();

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $fileName = $file->getFilename();

            if (!$this->delete($fileName)) {
                return false;
            }
        }

        return true;
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
        $result = [];

        foreach ($keys as $key) {
            $this->assertValidKey($key);
            $result[$key] = $this->get($key, $default);
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
        // @mago-expect analysis:mixed-assignment
        // @mago-expect analysis:mixed-assignment
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Provided key is not a string');
            }

            $this->assertValidKey($key);
            $this->set($key, $value, $ttl);
        }

        return true;
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
        foreach ($keys as $key) {
            $this->assertValidKey($key);

            if (!$this->delete($key)) {
                return false;
            }
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
     * @return bool
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function has(string $key): bool
    {
        $this->assertValidKey($key);

        return file_exists($this->getAbsolutePath($key));
    }
}
