<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use PhpPico\Caching\Driver\Filesystem\FileCacheItem;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileCacheItem::class)]
final class FileCacheItemTest extends TestCase
{
    private static function dir(): string
    {
        return __DIR__ . '/cache';
    }

    #[BeforeClass]
    #[AfterClass]
    public static function clearDir(): void
    {
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            unlink($file);
        }
    }

    #[Test]
    public function stores_the_file_at_a_predictable_path(): void
    {
        $item = FileCacheItem::create(self::dir(), 'plain', 1700000000);

        $this->assertSame(self::dir() . '/plain.cache', $item->path);
    }

    #[Test]
    public function writes_expiry_on_the_first_line_and_value_after(): void
    {
        FileCacheItem::create(self::dir(), 'key', 1700000000)->write('payload');

        $this->assertSame("1700000000\npayload", file_get_contents(self::dir() . '/key.cache'));
    }

    #[Test]
    public function never_expiring_item_writes_an_empty_header(): void
    {
        FileCacheItem::create(self::dir(), 'forever', null)->write('payload');

        $this->assertSame("\npayload", file_get_contents(self::dir() . '/forever.cache'));

        $found = FileCacheItem::find(self::dir(), 'forever');
        $this->assertNotNull($found);
        $this->assertNull($found->expiresAt);
        $this->assertFalse($found->isExpired());
    }

    #[Test]
    public function write_then_find_round_trips_value_and_expiry(): void
    {
        $expiresAt = time() + 60;
        FileCacheItem::create(self::dir(), 'live', $expiresAt)->write('payload');

        $found = FileCacheItem::find(self::dir(), 'live');
        $this->assertNotNull($found);
        $this->assertSame($expiresAt, $found->expiresAt);
        $this->assertFalse($found->isExpired());
        $this->assertSame('payload', $found->value());
    }

    #[Test]
    public function expired_item_reports_expired(): void
    {
        FileCacheItem::create(self::dir(), 'past', time() - 1)->write('gone');

        $this->assertTrue(FileCacheItem::find(self::dir(), 'past')?->isExpired());
    }

    #[Test]
    public function value_containing_newlines_round_trips(): void
    {
        $blob = serialize(['a' => "line1\nline2\nline3", 'b' => 42]);
        FileCacheItem::create(self::dir(), 'multiline', null)->write($blob);

        $this->assertSame($blob, FileCacheItem::find(self::dir(), 'multiline')?->value());
    }

    #[Test]
    public function rewriting_replaces_the_file_in_place(): void
    {
        FileCacheItem::create(self::dir(), 'key2', time() + 10)->write('v1');
        FileCacheItem::create(self::dir(), 'key2', time() + 999)->write('v2');

        $this->assertCount(1, glob(self::dir() . '/key2.cache') ?: []);
        $this->assertSame('v2', FileCacheItem::find(self::dir(), 'key2')?->value());
    }

    #[Test]
    public function dotted_keys_map_to_distinct_files(): void
    {
        FileCacheItem::create(self::dir(), 'user.42', null)->write('a');
        FileCacheItem::create(self::dir(), 'user.42.profile', null)->write('b');

        $this->assertSame('a', FileCacheItem::find(self::dir(), 'user.42')?->value());
        $this->assertSame('b', FileCacheItem::find(self::dir(), 'user.42.profile')?->value());
    }

    #[Test]
    public function find_returns_null_when_absent(): void
    {
        $this->assertNull(FileCacheItem::find(self::dir(), 'nope'));
    }

    #[Test]
    public function delete_removes_the_file(): void
    {
        FileCacheItem::create(self::dir(), 'temp', null)->write('x');

        $this->assertTrue(FileCacheItem::find(self::dir(), 'temp')?->delete());
        $this->assertNull(FileCacheItem::find(self::dir(), 'temp'));
    }

    #[Test]
    #[DataProvider('traversalKeys')]
    public function rejects_keys_that_could_escape_the_directory(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FileCacheItem::create(self::dir(), $key, null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalKeys(): array
    {
        return [
            'unix traversal' => ['../../etc/passwd'],
            'forward slash' => ['sub/dir'],
            'backslash' => ['sub\\dir'],
            'null byte' => ["foo\x00.png"],
        ];
    }
}
