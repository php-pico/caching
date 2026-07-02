<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver;

use Override;
use PhpPico\Caching\CacheException;

/**
 * RedisDriver.
 *
 * Talks to Redis over a RedisConnection, speaking the RESP2 protocol by hand.
 * The connection hides whether it wraps a live TCP socket or an injected stream
 * and owns database selection, so the driver only cares about RESP.
 */
final readonly class RedisDriver implements Driver
{
    use DriverTrait;

    public function __construct(
        public RedisConnection $connection,
    ) {}

    /**
     * Write a command and read its reply.
     *
     * @return string|int|array<int, mixed>|null
     * @throws CacheException If the Redis connection failed or replied with an error.
     */
    public function execute(string ...$args): string|int|array|null
    {
        fwrite($this->connection->stream(), $this->buildRespCommand(...$args));

        return $this->readReply();
    }

    /**
     * Encode a command as a RESP array of bulk strings.
     */
    public function buildRespCommand(string ...$args): string
    {
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $cmd;
    }

    /**
     * Read one RESP reply from the stream and decode it to a PHP value.
     *
     * @return string|int|array<int, mixed>|null
     * @throws CacheException On a Redis error reply, an unexpected type or a closed connection.
     */
    public function readReply(): string|int|array|null
    {
        $line = fgets($this->connection->stream());
        if ($line === false) {
            throw new CacheException('Redis connection closed while reading reply');
        }

        $type = $line[0];
        $payload = substr($line, 1, -2);

        return match ($type) {
            '+' => $payload,
            ':' => (int) $payload,
            '$' => $this->readBulkString((int) $payload),
            '*' => $this->readArray((int) $payload),
            '-' => throw new CacheException(sprintf('Redis error: %s', $payload)),
            default => throw new CacheException(sprintf('Unexpected reply type: %s', $type)),
        };
    }

    /**
     * Read a RESP bulk string of the given length.
     *
     * @return string|null Null for the RESP null bulk string ($-1).
     * @throws CacheException If the connection closes mid-read.
     */
    public function readBulkString(int $len): ?string
    {
        if ($len === -1) {
            return null;
        }

        $stream = $this->connection->stream();

        $result = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = fread($stream, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new CacheException('Redis connection closed while reading');
            }

            $result .= $chunk;
            $remaining -= strlen($chunk);
        }

        fgets($stream);

        return $result;
    }

    /**
     * Read a RESP array of the given length.
     *
     * Each element is decoded through readReply(), so arrays may nest arbitrarily.
     *
     * @return array<int, mixed>|null Null for the RESP null array (*-1).
     * @throws CacheException On a closed connection or an error element.
     */
    public function readArray(int $len): ?array
    {
        if ($len === -1) {
            return null;
        }

        $result = [];
        for ($i = 0; $i < $len; $i++) {
            $result[] = $this->readReply();
        }

        return $result;
    }

    #[Override]
    public function get(string $key): ?string
    {
        $value = $this->execute('GET', $key);

        if (is_null($value)) {
            return null;
        }

        if (!is_string($value)) {
            throw new CacheException('Redis replied with invalid type for GET.');
        }

        return $value;
    }

    #[Override]
    public function set(string $key, string $value, ?int $expiresAt = null): bool
    {
        $args = ['SET', $key, $value];

        if (!is_null($expiresAt)) {
            $args[] = 'EXAT';
            $args[] = (string) $expiresAt;
        }

        return $this->execute(...$args) === 'OK';
    }

    #[Override]
    public function delete(string $key): bool
    {
        $this->execute('DEL', $key);

        return true;
    }

    #[Override]
    public function clear(): bool
    {
        return $this->execute('FLUSHDB') === 'OK';
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string|null>
     */
    #[Override]
    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $values = $this->execute('MGET', ...$keys);
        if (!is_array($values)) {
            throw new CacheException('Redis replied with an invalid type for MGET.');
        }

        $result = [];
        foreach ($keys as $i => $key) {
            // @mago-expect analysis:mixed-assignment
            $raw = $values[$i] ?? null;
            $result[$key] = is_string($raw) ? $raw : null;
        }

        return $result;
    }

    /**
     * @param array<string, string> $values
     */
    #[Override]
    public function setMultiple(array $values, ?int $expiresAt = null): bool
    {
        if ($values === []) {
            return true;
        }

        $args = [];
        foreach ($values as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }

        if (is_null($expiresAt)) {
            return $this->execute('MSET', ...$args) === 'OK';
        }

        $args = [
            (string) count($values),
            ...$args,
            'EXAT',
            (string) $expiresAt,
        ];

        return $this->execute('MSETEX', ...$args) === 1;
    }

    /**
     * @param list<string> $keys
     */
    #[Override]
    public function deleteMultiple(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $this->execute('DEL', ...$keys);

        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        return $this->execute('EXISTS', $key) === 1;
    }
}
