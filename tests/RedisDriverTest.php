<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use Override;
use PhpPico\Caching\CacheException;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\RedisConnection;
use PhpPico\Caching\Driver\RedisDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedisDriver::class)]
#[CoversClass(RedisConnection::class)]
final class RedisDriverTest extends TestCase
{
    /** @var list<resource> Kept alive so socket-pair peers are not closed mid-test. */
    private array $openStreams = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->openStreams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->openStreams = [];
    }

    /**
     * @return resource
     */
    private function streamOf(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function driverReplying(string $bytes): RedisDriver
    {
        return new RedisDriver(RedisConnection::fromStream($this->streamOf($bytes)));
    }

    /**
     * A driver wired to a socket pair, modelling a real Redis connection: the
     * returned resource is the "Redis" end, so a test can queue reply bytes on
     * it and read back the command the driver wrote.
     *
     * @return array{0: RedisDriver, 1: resource}
     */
    private function driverOverSocket(string $reply): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        assert(is_array($pair));
        [$clientEnd, $redisEnd] = $pair;

        fwrite($redisEnd, $reply);
        $this->openStreams[] = $redisEnd;

        return [new RedisDriver(RedisConnection::fromStream($clientEnd)), $redisEnd];
    }

    /**
     * A RedisConnection over a socket pair, with replies queued on the returned
     * "Redis" end so the command bytes it wrote can be read back.
     *
     * @return array{0: RedisConnection, 1: resource}
     */
    private function connectionOverSocket(int $database, string ...$replies): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        assert(is_array($pair));
        [$clientEnd, $redisEnd] = $pair;

        fwrite($redisEnd, implode('', $replies));
        $this->openStreams[] = $redisEnd;

        return [RedisConnection::fromStream($clientEnd, $database), $redisEnd];
    }

    #[Test]
    public function implements_driver_interface(): void
    {
        $this->assertInstanceOf(Driver::class, new RedisDriver(RedisConnection::build()));
    }

    #[Test]
    #[DataProvider('respReplies')]
    public function read_reply_decodes_resp2_types(string $bytes, mixed $expected): void
    {
        $this->assertSame($expected, $this->driverReplying($bytes)->readReply());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function respReplies(): array
    {
        return [
            'simple string' => ["+OK\r\n", 'OK'],
            'simple string empty' => ["+\r\n", ''],
            'integer' => [":42\r\n", 42],
            'integer zero' => [":0\r\n", 0],
            'integer negative' => [":-1\r\n", -1],
            'bulk string' => ["\$3\r\nfoo\r\n", 'foo'],
            'bulk string empty' => ["\$0\r\n\r\n", ''],
            'null bulk string' => ["\$-1\r\n", null],
            'array' => ["*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n", ['foo', 'bar']],
            'array mixed types' => ["*2\r\n:1\r\n\$3\r\nbar\r\n", [1, 'bar']],
            'array with null element' => ["*2\r\n\$-1\r\n\$3\r\nbar\r\n", [null, 'bar']],
            'array empty' => ["*0\r\n", []],
            'null array' => ["*-1\r\n", null],
            'nested array' => ["*2\r\n*2\r\n\$3\r\nfoo\r\n:1\r\n\$3\r\nbar\r\n", [['foo', 1], 'bar']],
            'deeply nested array' => [
                "*1\r\n*1\r\n*2\r\n:7\r\n\$2\r\nhi\r\n",
                [[[7, 'hi']]],
            ],
        ];
    }

    #[Test]
    public function read_reply_throws_on_simple_error(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('ERR unknown command');

        $this->driverReplying("-ERR unknown command\r\n")->readReply();
    }

    #[Test]
    public function read_reply_throws_on_unexpected_type(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Unexpected reply type: %');

        $this->driverReplying("%oops\r\n")->readReply();
    }

    #[Test]
    public function read_reply_throws_when_connection_closed(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('closed while reading reply');

        $this->driverReplying('')->readReply();
    }

    #[Test]
    public function read_bulk_string_throws_when_truncated(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('closed while reading');

        $this->driverReplying("\$5\r\nfo")->readReply();
    }

    #[Test]
    public function read_array_throws_on_error_element(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis error: BAD');

        $this->driverReplying("*1\r\n-BAD\r\n")->readReply();
    }

    #[Test]
    public function bulk_string_reads_binary_payload_with_embedded_crlf(): void
    {
        $blob = serialize(['a' => "line1\r\nline2", 'b' => 42]);
        $bytes = '$' . strlen($blob) . "\r\n" . $blob . "\r\n";

        $this->assertSame($blob, $this->driverReplying($bytes)->readReply());
        $this->assertEquals(['a' => "line1\r\nline2", 'b' => 42], unserialize($blob));
    }

    #[Test]
    public function build_resp_command_encodes_array_of_bulk_strings(): void
    {
        $this->assertSame("*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n", new RedisDriver(
            RedisConnection::build(),
        )->buildRespCommand('SET', 'foo', 'bar'));
    }

    #[Test]
    public function set_writes_command_and_returns_true_on_ok(): void
    {
        [$driver, $redisEnd] = $this->driverOverSocket("+OK\r\n");

        $this->assertTrue($driver->set('k', 'ser', 1234567890));

        $this->assertSame(
            $driver->buildRespCommand('SET', 'k', 'ser', 'EXAT', '1234567890'),
            (string) fread($redisEnd, 1024),
        );
    }

    #[Test]
    public function set_multiple_with_ttl_uses_msetex(): void
    {
        [$driver, $redisEnd] = $this->driverOverSocket(":1\r\n");

        $this->assertTrue($driver->setMultiple(['a' => '1', 'b' => '2'], 1700000000));

        $this->assertSame(
            $driver->buildRespCommand('MSETEX', '2', 'a', '1', 'b', '2', 'EXAT', '1700000000'),
            (string) fread($redisEnd, 1024),
        );
    }

    #[Test]
    public function get_multiple_maps_mget_reply_positionally(): void
    {
        [$driver] = $this->driverOverSocket("*3\r\n\$3\r\nfoo\r\n\$-1\r\n\$3\r\nbaz\r\n");

        $this->assertSame(['A' => 'foo', 'B' => null, 'C' => 'baz'], $driver->getMultiple(['A', 'B', 'C']));
    }

    #[Test]
    public function get_throws_on_non_string_reply(): void
    {
        [$driver] = $this->driverOverSocket(":1\r\n");

        $this->expectException(CacheException::class);
        $driver->get('k');
    }

    #[Test]
    public function get_multiple_throws_on_non_array_reply(): void
    {
        [$driver] = $this->driverOverSocket("+OK\r\n");

        $this->expectException(CacheException::class);
        $driver->getMultiple(['A']);
    }

    #[Test]
    public function has_returns_true_when_exists_replies_one(): void
    {
        [$driver] = $this->driverOverSocket(":1\r\n");

        $this->assertTrue($driver->has('k'));
    }

    #[Test]
    public function has_returns_false_when_exists_replies_zero(): void
    {
        [$driver] = $this->driverOverSocket(":0\r\n");

        $this->assertFalse($driver->has('k'));
    }

    #[Test]
    public function connection_select_issues_the_select_command(): void
    {
        [$connection, $redisEnd] = $this->connectionOverSocket(0, "+OK\r\n");

        $connection->select(2);

        $this->assertSame("*2\r\n\$6\r\nSELECT\r\n\$1\r\n2\r\n", (string) fread($redisEnd, 1024));
    }

    #[Test]
    public function connection_select_updates_the_current_database(): void
    {
        [$connection] = $this->connectionOverSocket(0, "+OK\r\n");

        $connection->select(2);

        $this->assertSame(2, $connection->database);
    }

    #[Test]
    public function connection_select_issues_select_for_database_zero(): void
    {
        [$connection, $redisEnd] = $this->connectionOverSocket(0, "+OK\r\n");

        $connection->select(0);

        $this->assertSame("*2\r\n\$6\r\nSELECT\r\n\$1\r\n0\r\n", (string) fread($redisEnd, 1024));
    }

    #[Test]
    public function connection_select_throws_when_redis_does_not_acknowledge(): void
    {
        [$connection] = $this->connectionOverSocket(0, "-ERR no such db\r\n");

        $this->expectException(CacheException::class);
        $connection->select(2);
    }

    #[Test]
    public function connection_select_throws_on_negative_database(): void
    {
        [$connection] = $this->connectionOverSocket(0);

        $this->expectException(CacheException::class);
        $connection->select(-1);
    }

    #[Test]
    public function driver_over_an_injected_stream_does_not_select(): void
    {
        [$connection, $redisEnd] = $this->connectionOverSocket(2, ":1\r\n");
        $driver = new RedisDriver($connection);

        $driver->has('k');

        $this->assertSame($driver->buildRespCommand('EXISTS', 'k'), (string) fread($redisEnd, 1024));
    }

    #[Test]
    public function build_throws_on_empty_host(): void
    {
        $this->expectException(CacheException::class);
        RedisConnection::build(host: '');
    }

    #[Test]
    public function build_throws_on_non_positive_port(): void
    {
        $this->expectException(CacheException::class);
        RedisConnection::build(port: 0);
    }
}
