<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\Filesystem\FileCacheItem;
use PhpPico\Caching\Driver\Filesystem\FilesystemDriver;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemDriver::class)]
#[CoversClass(FileCacheItem::class)]
final class FilesystemDriverTest extends TestCase
{
    #[BeforeClass]
    #[AfterClass]
    public static function clearAllCache(): void
    {
        self::driver()->clear();
    }

    private static function driver(string $dir = 'cache'): FilesystemDriver
    {
        return new FilesystemDriver(__DIR__ . "/$dir");
    }

    #[Test]
    public function implements_driver_interface(): void
    {
        $this->assertInstanceOf(Driver::class, self::driver());
    }

    #[Test]
    #[DataProvider('traversalDirs')]
    public function constructor_throws_on_directory_traversal(string $dir): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FilesystemDriver($dir);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalDirs(): array
    {
        return [
            'relative parent' => [__DIR__ . '/../parent'],
            'leading traversal' => ['../escape'],
            'null byte' => ["/tmp/cache\x00/x"],
        ];
    }

    #[Test]
    public function constructor_does_not_create_a_traversal_directory(): void
    {
        $escaped = __DIR__ . '/../pico-traversal-should-not-exist';

        try {
            new FilesystemDriver($escaped);
            $this->fail('Expected traversal to be rejected');
        } catch (\InvalidArgumentException) {
            $this->assertDirectoryDoesNotExist($escaped);
        }
    }

    #[Test]
    public function constructor_accepts_a_path_that_merely_contains_dots(): void
    {
        $dir = __DIR__ . '/cache..data';

        $this->assertInstanceOf(FilesystemDriver::class, new FilesystemDriver($dir));

        rmdir($dir);
    }

    #[Test]
    public function stores_and_returns_the_payload(): void
    {
        $driver = self::driver();

        $this->assertTrue($driver->set('foo', 'serialized'));
        $this->assertSame('serialized', $driver->get('foo'));
        $this->assertTrue($driver->has('foo'));
    }

    #[Test]
    public function get_returns_null_on_miss(): void
    {
        $this->assertNull(self::driver()->get('missing'));
    }

    #[Test]
    public function delete_returns_false_when_key_absent_and_true_when_present(): void
    {
        $driver = self::driver();

        $this->assertFalse($driver->delete('missing'));

        $driver->set('foo', 'bar');
        $this->assertTrue($driver->delete('foo'));
        $this->assertNull($driver->get('foo'));
    }

    #[Test]
    public function clear_removes_every_item(): void
    {
        $driver = self::driver();
        $driver->set('foo', 'bar');
        $driver->set('baz', 'qux');

        $this->assertTrue($driver->clear());
        $this->assertFalse($driver->has('foo'));
        $this->assertFalse($driver->has('baz'));
    }

    #[Test]
    public function clear_only_removes_cache_files(): void
    {
        $driver = self::driver();
        $driver->set('foo', 'bar');

        $keep = self::driver()->dir . '/keep.txt';
        file_put_contents($keep, 'not a cache file');

        $this->assertTrue($driver->clear());
        $this->assertFalse($driver->has('foo'));
        $this->assertFileExists($keep);

        unlink($keep);
    }

    #[Test]
    public function expired_entry_is_pruned_on_get(): void
    {
        $driver = self::driver();
        $driver->set('foo', 'bar', time() - 1);

        $this->assertNull($driver->get('foo'));
        $this->assertFalse($driver->has('foo'));
    }

    #[Test]
    public function future_expiration_is_a_hit(): void
    {
        $driver = self::driver();
        $driver->set('foo', 'bar', time() + 60);

        $this->assertSame('bar', $driver->get('foo'));
    }
}
