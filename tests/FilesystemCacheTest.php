<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use DateInterval;
use PhpPico\Caching\FilesystemCache;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as InvalidArgumentExceptionInterface;

#[CoversClass(CacheInterface::class)]
#[CoversClass(FilesystemCache::class)]
final class FilesystemCacheTest extends TestCase
{
    #[BeforeClass]
    #[AfterClass]
    public static function clearAllCache(): void
    {
        self::getCache()->clear();
    }

    protected static function getCache(string $dir = 'cache'): FilesystemCache
    {
        return new FilesystemCache(__DIR__ . "/$dir");
    }

    #[Test]
    public function implements_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, self::getCache());
    }

    #[Test]
    public function constructor_throws_exception_on_directory_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getCache('../parent');
    }

    #[Test]
    #[DataProvider('validKeys')]
    public function valid_keys_return_true(string $key): void
    {
        $cache = self::getCache();

        $this->expectNotToPerformAssertions();
        $cache->assertValidKey($key);
    }

    public static function validKeys(): array
    {
        return [
            'basic' => ['foo'],
            'underscore' => ['foo_bar'],
            'dot' => ['foo.bar'],
            'mixed case' => ['FooBar123'],
            'single zero' => ['0'],
            'single char' => ['a'],
            'max length (64)' => [str_repeat('a', 64)],
            'realistic dotted' => ['user.42.profile'],
        ];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalid_keys_return_false(string $key): void
    {
        $cache = self::getCache();

        $this->expectException(InvalidArgumentExceptionInterface::class);
        $cache->assertValidKey($key);
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

    #[Test]
    public function set_and_get_value(): void
    {
        $cache = self::getCache();

        $key = __FUNCTION__;
        $value = 'lorem ipsum';
        $ttl = null;

        $this->assertFalse($cache->has($key), 'StaticCache::has() must return FALSE before caching a value');

        $cache->set($key, $value, $ttl);
        $this->assertTrue($cache->has($key), 'StaticCache::has() must return TRUE after setting a value');

        $this->assertEquals($value, $cache->get($key), 'StaticCache::get() must return the same cached value');
    }

    #[Test]
    public function set_value_with_int_ttl(): void
    {
        $cache = self::getCache();

        $key = __FUNCTION__;
        $value = 'lorem ipsum';
        $ttl = 60;

        $this->assertFalse($cache->has($key), 'StaticCache::has() must return FALSE before caching a value');

        $cache->set($key, $value, $ttl);
        $this->assertTrue($cache->has($key), 'StaticCache::has() must return TRUE after setting a value');

        $this->assertEquals($value, $cache->get($key), 'StaticCache::get() must return the same cached value');
    }

    #[Test]
    public function set_value_with_dateinterval_ttl(): void
    {
        $cache = self::getCache();

        $key = __FUNCTION__;
        $value = 'lorem ipsum';
        $ttl = DateInterval::createFromDateString('1 minutes');

        $this->assertFalse($cache->has($key), 'StaticCache::has() must return FALSE before caching a value');

        $cache->set($key, $value, $ttl);
        $this->assertTrue($cache->has($key), 'StaticCache::has() must return TRUE after setting a value');

        $this->assertEquals($value, $cache->get($key), 'StaticCache::get() must return the same cached value');
    }

    #[Test]
    public function delete_returns_false_for_nonexistent_key(): void
    {
        $cache = self::getCache();

        $key = 'does.not.exist';

        $this->assertFalse(
            $cache->delete($key),
            'StaticCache::delete() must return FALSE when trying to delete a key which does not exist',
        );
    }

    #[Test]
    public function deletemultiple_returns_false_for_nonexistent_key(): void
    {
        $cache = self::getCache();

        $keys = [
            'does',
            'not',
            'exist',
        ];

        $this->assertFalse(
            $cache->deleteMultiple($keys),
            'StaticCache::deleteMultiple() must return FALSE when trying to delete a key which does not exist',
        );
    }

    #[Test]
    public function get_and_set_multiple(): void
    {
        $cache = self::getCache();

        $items = [
            'a' => 1,
            'b' => '2',
            'c' => null,
            'd' => false,
            'e' => true,
            'f' => -1,
            'lorem' => 'ipsum',
        ];

        $default = null;

        /** @var string[] $keys */
        $keys = array_keys($items);

        $this->assertContainsOnlyNull(
            $cache->getMultiple($keys),
            'StaticCache::getMultiple() must return the default value for non-existent items',
        );
        $this->assertFalse(
            $cache->deleteMultiple($keys),
            'StaticCache::deleteMultiple() must return FALSE  when trying to delete non-existent items',
        );

        $this->assertTrue(
            $cache->setMultiple($items),
            'StaticCache::setMultiple() must return TRUE after setting all values',
        );
        $this->assertEquals(
            $items,
            $cache->getMultiple($keys),
            'StaticCache::getMultiple() must return the same items when passed the original keys',
        );

        $this->assertTrue(
            $cache->deleteMultiple($keys),
            'StaticCache::deleteMultiple() must return TRUE when deleting all items successfully',
        );
        $this->assertContainsOnlyNull(
            $cache->getMultiple($keys),
            'StaticCache::getMultiple() must return the default value after deleting all the items',
        );
    }
}
