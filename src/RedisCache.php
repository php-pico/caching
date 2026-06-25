<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use Override;
use Psr\SimpleCache\CacheInterface;

class RedisCache implements CacheInterface
{
    use CacheTrait;

    /** @var resource|null|false $socket */
    protected $socket;

    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 6379,
        public readonly int $timeoutSeconds = 3,
        public readonly int $database = 0,
    ) {}

    /**
     * Execute a Redis command.
     *
     * @param string[] $args
     *
     * @return string|int|array|null
     * @throws CacheException If the Redis connection failed.
     */
    protected function execute(string ...$args): string|int|array|null
    {
        if (!is_resource($this->socket)) {
            $this->connect();
        }

        $cmd = $this->buildRespCommand(...$args);

        assert(is_resource($this->socket));
        fwrite($this->socket, $cmd);

        $line = fgets($this->socket);
        if ($line === false) {
            throw new CacheException('Redis connection closed while reading reply');
        }

        $type = $line[0];
        $payload = substr($line, 1, -2);

        return match ($type) {
            '+' => $payload,
            ':' => (int) $payload,
            '$' => $this->readBulkStringReply((int) $payload),
            '*' => $this->readArrayReply((int) $payload),
            '-' => throw new CacheException(sprintf('Redis error: %s', $payload)),
            default => throw new CacheException(sprintf('Unexpected reply type: %s', $type)),
        };
    }

    /**
     * Connect to Redis.
     *
     * @return void
     * @throws CacheException If the Redis connection failed.
     */
    protected function connect(): void
    {
        $errCode = null;
        $errMessage = null;

        $url = sprintf('tcp://%s:%s', $this->host, $this->port);
        $this->socket = stream_socket_client($url, $errCode, $errMessage, $this->timeoutSeconds);

        if (!$this->socket) {
            throw new CacheException(sprintf('Failed to connect to Redis: %s %s', $errCode, $errMessage));
        }

        if ($this->database !== 0) {
            if ($this->execute('SELECT', (string) $this->database) !== 'OK') {
                throw new CacheException(sprintf('Failed to select database %s', $this->database));
            }
        }
    }

    /**
     * Build RESP command.
     *
     * @param string[] $args
     *
     * @return string
     */
    protected function buildRespCommand(string ...$args): string
    {
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $cmd;
    }

    /**
     * Read Redis bulk string reply.
     *
     * @param int $len
     *
     * @return string|null
     */
    protected function readBulkStringReply(int $len): ?string
    {
        assert(is_resource($this->socket));

        if ($len === -1) {
            return null;
        }

        $result = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new CacheException('Redis connection closed while reading');
            }

            $result .= $chunk;
            $remaining -= strlen($chunk);
        }

        fgets($this->socket);

        return $result;
    }

    /**
     * Read Redis array reply.
     *
     * @param int $len
     *
     * @return string[]|int[]|array<array-key, string>|null
     * @throws CacheException If redis connection closes while reading the reply
     */
    protected function readArrayReply(int $len): ?array
    {
        assert(is_resource($this->socket));

        if ($len === -1) {
            return null;
        }

        /** @var string[]|int[]|array<array-key, string>|null $result */
        $result = [];
        for ($i = 0; $i < $len; $i++) {
            $line = fgets($this->socket);
            if ($line === false) {
                throw new CacheException('Redis connection closed while reading array');
            }

            $type = $line[0];
            $payload = substr($line, 1, -2);

            $result[] = match ($type) {
                '+' => $payload,
                ':' => (int) $payload,
                '$' => $this->readBulkStringReply((int) $payload),
                '-' => throw new CacheException(sprintf('Redis error: %s', $payload)),
                default => throw new CacheException(sprintf('Unexpected reply type: %s', $type)),
            };
        }

        /** @var string[]|int[]|array<array-key, string>|null $result */
        return $result;
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
     * @throws CacheException If Redis replies with an invalid type for GET
     */
    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertValidKey($key);

        $value = $this->execute('GET', $key);

        if (is_null($value)) {
            return $default;
        }

        if (!is_string($value)) {
            throw new CacheException('Redis replied with invalid type for GET.');
        }

        return unserialize($value);
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
     * @return bool True on success and false on failure.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    #[Override]
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->assertValidKey($key);

        $args = ['SET', $key, serialize($value)];

        if (!is_null($ttl)) {
            $args[] = 'EXAT';
            $args[] = (string) $this->calculateExpiration($ttl);
        }

        return $this->execute(...$args) === 'OK';
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
        $this->execute('DEL', $key);

        return true;
    }

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    #[Override]
    public function clear(): bool
    {
        return $this->execute('FLUSHDB') === 'OK';
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
     * @throws CacheException If Redis replies with an invalid type for MGET.
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

        $values = $this->execute('MGET', ...$keys);
        if (!is_array($values)) {
            throw new CacheException('Redis replied with an invalid type for MGET.');
        }

        $result = [];
        /** @var int $i */
        foreach ($keys as $i => $key) {
            // @mago-expect analysis:mixed-assignment
            $raw = $values[$i] ?? null;
            $result[$key] = is_string($raw) ? unserialize($raw) : $default;
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
     * @return bool True on success and false on failure.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $values is neither an array nor a Traversable,
     *   or if any of the $values are not a legal value.
     */
    #[Override]
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $values = is_array($values) ? $values : iterator_to_array($values);

        if ($values === []) {
            return true;
        }

        $args = [];
        $count = 0;

        // @mago-expect analysis:mixed-assignment
        foreach ($values as $key => $value) {
            $this->assertValidKey((string) $key);
            $args[] = (string) $key;
            $args[] = serialize($value);
            $count++;
        }

        if (is_null($ttl)) {
            return $this->execute('MSET', ...$args) === 'OK';
        }

        $args = [
            (string) $count,
            ...$args,
            'EXAT',
            (string) $this->calculateExpiration($ttl),
        ];

        return $this->execute('MSETEX', ...$args) === 1;
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

        $this->execute('DEL', ...$keys);

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

        return $this->execute('EXISTS', $key) === 1;
    }
}
