<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use DateInterval;
use PhpPico\Caching\NoopCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as InvalidArgumentExceptionInterface;

#[CoversClass(CacheInterface::class)]
#[CoversClass(NoopCache::class)]
final class NoopCacheTest extends TestCase
{
    #[Test]
    public function implements_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, new NoopCache());
    }

    #[Test]
    public function get_returns_null(): void
    {
        $cache = new NoopCache();

        $this->assertNull($cache->get('foo'), 'NoopCache::get() must always return NULL');
    }

    #[Test]
    public function get_returns_default_when_provided(): void
    {
        $cache = new NoopCache();

        $this->assertEquals(
            'default',
            $cache->get('foo', 'default'),
            'NoopCache::get() must return the provided default value',
        );
    }

    #[Test]
    #[DataProvider('ttls')]
    public function set_returns_true(int|DateInterval|null $ttl): void
    {
        $cache = new NoopCache();

        $this->assertTrue($cache->set('foo', 'bar', $ttl), 'NoopCache::set() must always return TRUE');
    }

    public static function ttls(): array
    {
        return [
            'null ttl' => [null],
            'int ttl' => [60],
            'dateinterval ttl' => [DateInterval::createFromDateString('1 minutes')],
        ];
    }

    #[Test]
    public function delete_returns_true(): void
    {
        $cache = new NoopCache();

        $this->assertTrue($cache->delete('foo'), 'NoopCache::delete() must always return TRUE');
    }

    #[Test]
    public function clear_returns_true(): void
    {
        $cache = new NoopCache();

        $this->assertTrue($cache->clear(), 'NoopCache::clear() must always return TRUE');
    }

    #[Test]
    public function has_returns_false(): void
    {
        $cache = new NoopCache();

        $this->assertFalse($cache->has('foo'), 'NoopCache::has() must always return FALSE');
    }

    #[Test]
    public function getmultiple_returns_default_for_every_key(): void
    {
        $cache = new NoopCache();

        $keys = ['a', 'b', 'c'];

        $this->assertEquals(
            ['a' => null, 'b' => null, 'c' => null],
            $cache->getMultiple($keys),
            'NoopCache::getMultiple() must return NULL for every key by default',
        );

        $this->assertEquals(
            ['a' => 'default', 'b' => 'default', 'c' => 'default'],
            $cache->getMultiple($keys, 'default'),
            'NoopCache::getMultiple() must return the provided default for every key',
        );
    }

    #[Test]
    public function setmultiple_returns_true(): void
    {
        $cache = new NoopCache();

        $this->assertTrue(
            $cache->setMultiple(['a' => 1, 'b' => 2]),
            'NoopCache::setMultiple() must always return TRUE',
        );
    }

    #[Test]
    public function setmultiple_throws_on_non_string_key(): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->setMultiple(['valid' => 1, 2 => 'invalid']);
    }

    #[Test]
    public function deletemultiple_returns_true(): void
    {
        $cache = new NoopCache();

        $this->assertTrue(
            $cache->deleteMultiple(['a', 'b', 'c']),
            'NoopCache::deleteMultiple() must always return TRUE',
        );
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_key_throws_on_get(string $key): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->get($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_key_throws_on_set(string $key): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->set($key, 'bar');
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_key_throws_on_delete(string $key): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->delete($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_key_throws_on_has(string $key): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->has($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_key_throws_on_getmultiple(string $key): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->getMultiple([$key]);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_key_throws_on_setmultiple(string $key): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->setMultiple([$key => 'value']);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_key_throws_on_deletemultiple(string $key): void
    {
        $cache = new NoopCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->deleteMultiple([$key]);
    }

    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'open brace' => ['{'],
            'close brace' => ['}'],
            'open paren' => ['('],
            'close paren' => [')'],
            'forward slash' => ['/'],
            'backslash' => ['\\'],
            'at sign' => ['@'],
            'colon' => [':'],
            'reserved mid-key' => ['foo:bar'],
            'braces embedded' => ['foo{bar}'],
            'at embedded' => ['cache@home'],
        ];
    }
}
