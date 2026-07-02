<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver;

use PhpPico\Caching\CacheException;

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

    /**
     * @param resource|null $stream
     */
    protected function __construct(
        $stream,
        protected readonly ?string $host,
        protected readonly ?int $port,
        protected readonly ?int $timeoutSeconds,
        public int $database,
    ) {
        $this->stream = $stream;
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
    ): self {
        if ($host === '') {
            throw new CacheException('Cannot dial Redis without a host');
        }

        if ($port <= 0) {
            throw new CacheException('Cannot dial Redis without a valid port');
        }

        return new self(null, $host, $port, $timeoutSeconds, $database);
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
     * @throws CacheException If the connection failed.
     */
    public function stream()
    {
        if (!is_resource($this->stream)) {
            $this->dial();
        }

        return $this->stream;
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
     * @throws CacheException If the connection failed.
     */
    protected function dial(): void
    {
        assert($this->host !== null && $this->port !== null && $this->timeoutSeconds !== null);

        $errCode = null;
        $errMessage = null;

        $url = sprintf('tcp://%s:%s', $this->host, $this->port);
        $stream = stream_socket_client($url, $errCode, $errMessage, $this->timeoutSeconds);

        if (!$stream) {
            throw new CacheException(sprintf('Failed to connect to Redis: %s %s', $errCode, $errMessage));
        }

        $this->stream = $stream;

        if ($this->database > 0) {
            $this->select($this->database);
        }
    }
}
