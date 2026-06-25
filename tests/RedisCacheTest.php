<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use DateInterval;
use DateTimeImmutable;
use PhpPico\Caching\RedisCache;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheException as CacheExceptionInterface;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(CacheInterface::class)]
#[CoversClass(RedisCache::class)]
final class RedisCacheTest extends TestCase
{
    #[Test]
    public function implements_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, new RedisCache());
    }

    #[Test]
    public function get_returns_unserialized_value(): void
    {
        $key = 'foo';
        $value = 'bar';

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('GET', $key)->willReturn(serialize($value));

        $this->assertEquals($value, $cache->get($key), 'RedisCache::get() must unserialize the value');
    }

    #[Test]
    public function get_returns_default_on_miss(): void
    {
        $key = 'missing';
        $default = 'fallback';

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('GET', $key)->willReturn(null);

        $this->assertEquals(
            $default,
            $cache->get($key, $default),
            'RedisCache::get() must return the default value on cache miss',
        );
    }

    #[Test]
    #[DataProvider('invalidGetReplies')]
    public function get_throws_exception_if_redis_replies_with_invalid_type(mixed $reply): void
    {
        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('GET', 'test')->willReturn($reply);

        $this->expectException(CacheExceptionInterface::class);
        $cache->get('test');
    }

    public static function invalidGetReplies(): array
    {
        return [
            [array()],
            [1],
        ];
    }

    #[Test]
    public function set_without_ttl(): void
    {
        $key = 'lorem';
        $value = 'ipsum';
        $ttl = null;

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('SET', $key, serialize($value))->willReturn('OK');

        $this->assertEquals(
            $cache->set($key, $value, $ttl),
            'RedisCache::set() must return TRUE when write was a success',
        );
    }

    #[Test]
    public function set_with_int_ttl(): void
    {
        $key = 'lorem';
        $value = 'ipsum';
        $ttl = 60;

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('execute')
            ->with('SET', $key, serialize($value), 'EXAT', time() + $ttl)
            ->willReturn('OK');

        $this->assertEquals(
            $cache->set($key, $value, $ttl),
            'RedisCache::set() must return TRUE when write was a success',
        );
    }

    #[Test]
    public function set_with_dateinterval_ttl(): void
    {
        $key = 'lorem';
        $value = 'ipsum';
        $ttl = DateInterval::createFromDateString('1 minute');

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('execute')
            ->with('SET', $key, serialize($value), 'EXAT', new DateTimeImmutable()->add($ttl)->getTimestamp())
            ->willReturn('OK');

        $this->assertEquals(
            $cache->set($key, $value, $ttl),
            'RedisCache::set() must return TRUE when write was a success',
        );
    }

    #[Test]
    public function delete_returns_true_if_key_does_not_exist(): void
    {
        $key = 'does_not_exist';

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->exactly(2))->method('execute');

        $this->assertFalse($cache->has($key), 'RedisCache::has() must return FALSE if the key does not exist');
        $this->assertTrue(
            $cache->delete($key),
            'RedisCache::delete() must return TRUE even though the key did not exist',
        );
    }

    #[Test]
    public function clear_returns_true_on_flushdb_ok(): void
    {
        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('FLUSHDB')->willReturn('OK');

        $this->assertTrue($cache->clear(), 'RedisCache::clear() must return TRUE when flushing Redis database');
    }

    #[Test]
    public function get_multiple(): void
    {
        $expected = [
            'FIRST' => '1st',
            'SECOND' => '2nd',
            'THIRD' => '3rd',
        ];

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('execute')
            ->with('MGET', ...array_keys($expected))
            ->willReturn(array_map('serialize', array_values($expected)));

        $this->assertEquals($expected, $cache->getMultiple(array_keys($expected)));
    }

    #[Test]
    #[DataProvider('invalidGetMultipleReplies')]
    public function get_multiple_throws_exception_on_invalid_reply(mixed $reply): void
    {
        $keys = ['FIRST', 'SECOND', 'THIRD'];

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('MGET', ...$keys)->willReturn($reply);

        $this->expectException(CacheExceptionInterface::class);
        $cache->getMultiple($keys);
    }

    public static function invalidGetMultipleReplies(): array
    {
        return [
            [0],
            ['a'],
            [null],
        ];
    }

    #[Test]
    public function set_multiple_with_null_ttl(): void
    {
        $values = [
            'FIRST' => '1st',
            'SECOND' => '2nd',
            'THIRD' => '3rd',
        ];
        $ttl = null;

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('execute')
            ->with('MSET', 'FIRST', 's:3:"1st";', 'SECOND', 's:3:"2nd";', 'THIRD', 's:3:"3rd";')
            ->willReturn('OK');

        $this->assertTrue($cache->setMultiple($values, $ttl));
    }

    #[Test]
    public function set_multiple_with_int_ttl(): void
    {
        $values = [
            'FIRST' => '1st',
            'SECOND' => '2nd',
            'THIRD' => '3rd',
        ];
        $ttl = 60;

        $expectedArgs = [];
        foreach ($values as $key => $value) {
            $expectedArgs[] = $key;
            $expectedArgs[] = serialize($value);
        }

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('execute')
            ->with(
                'MSETEX',
                3,
                'FIRST',
                's:3:"1st";',
                'SECOND',
                's:3:"2nd";',
                'THIRD',
                's:3:"3rd";',
                'EXAT',
                time() + $ttl,
            )
            ->willReturn(1);

        $this->assertTrue($cache->setMultiple($values, $ttl));
    }

    #[Test]
    public function set_multiple_with_dateinterval_ttl(): void
    {
        $values = [
            'FIRST' => '1st',
            'SECOND' => '2nd',
            'THIRD' => '3rd',
        ];
        $ttl = DateInterval::createFromDateString('30 seconds');

        $expectedArgs = [];
        foreach ($values as $key => $value) {
            $expectedArgs[] = $key;
            $expectedArgs[] = serialize($value);
        }

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('execute')
            ->with(
                'MSETEX',
                3,
                'FIRST',
                's:3:"1st";',
                'SECOND',
                's:3:"2nd";',
                'THIRD',
                's:3:"3rd";',
                'EXAT',
                new DateTimeImmutable()->add($ttl)->getTimestamp(),
            )
            ->willReturn(1);

        $this->assertTrue($cache->setMultiple($values, $ttl));
    }

    #[Test]
    public function delete_multiple_returns_true_if_key_does_not_exist(): void
    {
        $keys = [
            'FIRST',
            'SECOND',
            'THIRD',
        ];

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('DEL', ...$keys)->willReturn(0);

        $this->assertTrue(
            $cache->deleteMultiple($keys),
            'RedisCache::deleteMultiple() must return TRUE even if no keys were deleted.',
        );
    }

    #[Test]
    public function delete_multiple_returns_true_if_some_keys_exist(): void
    {
        $keys = [
            'FIRST',
            'SECOND',
            'THIRD',
        ];

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('DEL', ...$keys)->willReturn(1);

        $this->assertTrue(
            $cache->deleteMultiple($keys),
            'RedisCache::deleteMultiple() must return TRUE if some of the keys were deleted.',
        );
    }

    #[Test]
    public function delete_multiple_returns_true_if_all_keys_exist(): void
    {
        $keys = [
            'FIRST',
            'SECOND',
            'THIRD',
        ];

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('DEL', ...$keys)->willReturn(count($keys));

        $this->assertTrue(
            $cache->deleteMultiple($keys),
            'RedisCache::deleteMultiple() must return TRUE if all the keys were deleted.',
        );
    }

    #[Test]
    public function has_returns_false_if_key_does_not_exist(): void
    {
        $key = 'does_not_exist';

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('EXISTS', $key)->willReturn(0);

        $this->assertFalse($cache->has($key), 'RedisCache::has() must return FALSE if the key is not cached.');
    }

    #[Test]
    public function has_returns_true_if_key_exists(): void
    {
        $key = 'exists';

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $cache->expects($this->once())->method('execute')->with('EXISTS', $key)->willReturn(1);

        $this->assertTrue($cache->has($key), 'RedisCache::has() must return TRUE when the key is cached.');
    }

    #[Test]
    public function has_returns_false_before_set_and_returns_true_after_set(): void
    {
        $key = 'lorem';
        $value = 'ipsum';

        $cache = $this
            ->getMockBuilder(RedisCache::class)
            ->onlyMethods([
                'execute',
                'connect',
                'buildRespCommand',
                'readBulkStringReply',
                'readArrayReply',
            ])
            ->getMock();

        $this->assertFalse($cache->has($key), 'RedisCache::has() must return FALSE before caching a key.');
        $cache->set($key, $value);

        $cache->expects($this->once())->method('execute')->with('EXISTS', $key)->willReturn(1);
        $this->assertTrue($cache->has($key), 'RedisCache::has() must return TRUE after caching a key.');
    }
}
