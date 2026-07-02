<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver;

use Override;
use PhpPico\Caching\CacheException;
use PhpPico\Caching\RedisConnectionException;

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
     * On a transport failure (a dropped connection), an idempotent command may be
     * replayed after reconnecting. Callers MUST state whether replay is safe.
     *
     * Safe to pass $allowRetry = true (every command this driver issues): GET,
     * SET/MSET/MSETEX (which use an absolute EXAT, so a delayed replay does not
     * drift the TTL), DEL, EXISTS, FLUSHDB — running any of these twice yields the
     * same final state.
     *
     * NOT safe (do not add with $allowRetry = true): read-modify-write commands
     * whose repeat changes the result — INCR/DECR, APPEND, SETRANGE, GETDEL,
     * LPUSH/RPUSH, HINCRBY, or SET with a relative EX/PX TTL. A protocol error (a
     * Redis "-ERR" reply) is never retried regardless of $allowRetry.
     *
     * @param bool $allowRetry Whether this command is safe to replay after a reconnect.
     *
     * @return string|int|array<int, mixed>|null
     * @throws CacheException On a protocol error, or a transport failure that could not be recovered.
     */
    protected function execute(bool $allowRetry, string ...$args): string|int|array|null
    {
        return $this->connection->retrying($allowRetry, fn(): string|int|array|null => $this->runCommand(...$args));
    }

    /**
     * @return string|int|array<int, mixed>|null
     * @throws CacheException If the Redis connection failed or replied with an error.
     */
    private function runCommand(string ...$args): string|int|array|null
    {
        $command = $this->buildRespCommand(...$args);

        if (@fwrite($this->connection->stream(), $command) !== strlen($command)) {
            throw new RedisConnectionException('Redis connection closed while writing command');
        }

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
            throw new RedisConnectionException('Redis connection closed while reading reply');
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
                throw new RedisConnectionException('Redis connection closed while reading');
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
        $value = $this->execute(true, 'GET', $key);

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

        return $this->execute(true, ...$args) === 'OK';
    }

    /**
     * Returns true unconditionally, ignoring the DEL reply count. Do not switch to
     * returning whether the count was non-zero: DEL is only replay-safe after a
     * reconnect because we discard the count (a replayed DEL returns 0 for a key
     * the first, lost attempt already removed).
     */
    #[Override]
    public function delete(string $key): bool
    {
        $this->execute(true, 'DEL', $key);

        return true;
    }

    #[Override]
    public function clear(): bool
    {
        return $this->execute(true, 'FLUSHDB') === 'OK';
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

        $values = $this->execute(true, 'MGET', ...$keys);
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
            return $this->execute(true, 'MSET', ...$args) === 'OK';
        }

        $args = [
            (string) count($values),
            ...$args,
            'EXAT',
            (string) $expiresAt,
        ];

        return $this->execute(true, 'MSETEX', ...$args) === 1;
    }

    /**
     * Returns true unconditionally, ignoring the DEL reply count — see delete().
     *
     * @param list<string> $keys
     */
    #[Override]
    public function deleteMultiple(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $this->execute(true, 'DEL', ...$keys);

        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        return $this->execute(true, 'EXISTS', $key) === 1;
    }
}
