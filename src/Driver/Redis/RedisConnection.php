<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Redis;

use PhpPico\Caching\Driver\Redis\Exceptions\RedisConnectionException;
use PhpPico\Caching\Exceptions\CacheException;

/**
 * RedisConnection.
 *
 * A transport facade in front of the stream a RedisDriver reads and writes.
 * Built either from connection details (dialed lazily on first use) or from an
 * already-open stream resource. It owns the socket and the target database:
 * a lazily-dialed connection selects its database automatically, while a caller
 * that supplies its own stream is trusted to have done so, or may call select().
 */
final class RedisConnection
{
    /** @var resource|null */
    protected $stream;

    protected const int RECONNECT_DELAY_MICROSECONDS = 50_000;

    /**
     * @param resource|null $stream
     */
    protected function __construct(
        $stream,
        protected readonly ?string $host,
        protected readonly ?int $port,
        protected readonly ?int $timeoutSeconds,
        protected int $database,
        protected readonly int $reconnectTries = 1,
    ) {
        $this->stream = $stream;
    }

    /**
     * The maximum number of reconnect attempts to make after a transport failure.
     */
    public function reconnectTries(): int
    {
        return $this->reconnectTries;
    }

    /**
     * Whether this connection is able to reconnect on its own. A connection built
     * from connection details can re-dial; one built from a caller-supplied stream
     * cannot, since it has no host to dial.
     */
    public function canReconnect(): bool
    {
        return $this->host !== null;
    }

    /**
     * The database index currently selected on this connection.
     *
     * Reflects the last database this connection successfully selected: the one
     * it was built with, or the argument of the most recent select() call that
     * Redis acknowledged. Useful for asserting which keyspace commands run against.
     */
    public function currentDatabase(): int
    {
        return $this->database;
    }

    /**
     * Build a connection that dials Redis lazily on first use.
     *
     * @throws CacheException If the host is empty or the port is not positive.
     */
    public static function build(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $timeoutSeconds = 3,
        int $database = 0,
        int $reconnectTries = 1,
    ): self {
        if ($host === '') {
            throw new CacheException('Cannot dial Redis without a host');
        }

        if ($port <= 0) {
            throw new CacheException('Cannot dial Redis without a valid port');
        }

        if ($reconnectTries < 0) {
            throw new CacheException('Reconnect tries must not be negative');
        }

        return new self(null, $host, $port, $timeoutSeconds, $database, $reconnectTries);
    }

    /**
     * Build a connection over an already-open stream resource.
     *
     * @param resource $stream
     */
    public static function fromStream($stream, int $database = 0): self
    {
        return new self($stream, null, null, null, $database);
    }

    /**
     * The live stream, dialing the TCP socket on first use if needed.
     *
     * @return resource
     * @throws RedisConnectionException If the connection failed.
     */
    public function stream()
    {
        if (!is_resource($this->stream)) {
            $this->dial();
        }

        return $this->stream;
    }

    /**
     * Discard the current (dead) stream and dial a fresh one, after a short delay
     * to avoid hammering an unavailable server. Re-runs the database selection.
     *
     * @throws RedisConnectionException If the connection cannot be re-dialed or the redial failed.
     */
    public function reconnect(): void
    {
        if (!$this->canReconnect()) {
            throw new RedisConnectionException('Cannot reconnect a connection built from a supplied stream');
        }

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;

        usleep(self::RECONNECT_DELAY_MICROSECONDS);

        $this->dial();
    }

    /**
     * Run a command, reconnecting and replaying it on a transport failure.
     *
     * The command is an opaque callable, so this method knows nothing about RESP
     * or what the command does; the caller alone decides, via $allowRetry, whether
     * replay is safe (i.e. whether the command is idempotent). A command that is
     * not retryable, or a connection that cannot re-dial (one built from a supplied
     * stream), surfaces the transport failure instead of replaying.
     *
     * @template T
     * @param callable(): T $command
     *
     * @return T
     * @throws RedisConnectionException If the command could not be completed after reconnecting.
     */
    public function retrying(bool $allowRetry, callable $command): mixed
    {
        try {
            return $command();
        } catch (RedisConnectionException $e) {
            if (!$allowRetry || !$this->canReconnect()) {
                throw $e;
            }
        }

        for ($attempt = 1; $attempt <= $this->reconnectTries; $attempt++) {
            try {
                $this->reconnect();

                return $command();
            } catch (RedisConnectionException $e) {
                if ($attempt === $this->reconnectTries) {
                    throw $e;
                }
            }
        }

        throw new RedisConnectionException('Redis connection lost and could not be recovered');
    }

    /**
     * Select a database on the current stream and record it as the current one.
     *
     * @throws CacheException If the database is negative or the SELECT failed.
     */
    public function select(int $database): void
    {
        if ($database < 0) {
            throw new CacheException(sprintf('Database must not be negative, got %s', $database));
        }

        $stream = $this->stream();
        fwrite($stream, sprintf("*2\r\n\$6\r\nSELECT\r\n\$%d\r\n%d\r\n", strlen((string) $database), $database));

        if (fgets($stream) !== "+OK\r\n") {
            throw new CacheException(sprintf('Failed to select database %s', $database));
        }

        $this->database = $database;
    }

    /**
     * @throws RedisConnectionException If the connection failed.
     */
    protected function dial(): void
    {
        assert($this->host !== null && $this->port !== null && $this->timeoutSeconds !== null);

        $errCode = null;
        $errMessage = null;

        $url = sprintf('tcp://%s:%s', $this->host, $this->port);
        $stream = @stream_socket_client($url, $errCode, $errMessage, $this->timeoutSeconds);

        if (!$stream) {
            throw new RedisConnectionException(sprintf('Failed to connect to Redis: %s %s', $errCode, $errMessage));
        }

        $this->stream = $stream;

        if ($this->database > 0) {
            $this->select($this->database);
        }
    }
}
