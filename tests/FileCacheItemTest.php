<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use PhpPico\Caching\Driver\Filesystem\FileCacheItem;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\CoversClass;
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
    public function encodes_expiry_in_the_filename(): void
    {
        $item = FileCacheItem::create(self::dir(), 'plain', 1700000000);

        $this->assertSame(self::dir() . '/plain.1700000000.cache', $item->path);
    }

    #[Test]
    public function never_expiring_item_uses_zero_in_the_filename(): void
    {
        $item = FileCacheItem::create(self::dir(), 'plain', null);

        $this->assertSame(self::dir() . '/plain.0.cache', $item->path);
        $this->assertFalse($item->isExpired());
    }

    #[Test]
    public function is_expired_is_read_from_the_filename_without_reading_the_file(): void
    {
        FileCacheItem::create(self::dir(), 'past', time() - 1)->write('gone');

        $found = FileCacheItem::find(self::dir(), 'past');
        $this->assertNotNull($found);
        $this->assertTrue($found->isExpired());
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
        $this->assertSame('payload', $found->readValue());
    }

    #[Test]
    public function rewriting_with_a_new_ttl_leaves_only_one_file(): void
    {
        FileCacheItem::create(self::dir(), 'key', time() + 10)->write('v1');
        FileCacheItem::create(self::dir(), 'key', time() + 999)->write('v2');

        $matches = glob(self::dir() . '/key.*.cache') ?: [];
        $this->assertCount(1, $matches, 'a re-set must not leave the stale-expiry file behind');
        $this->assertSame('v2', FileCacheItem::find(self::dir(), 'key')?->readValue());
    }

    #[Test]
    public function keys_containing_dots_parse_back_correctly(): void
    {
        $expiresAt = time() + 60;
        FileCacheItem::create(self::dir(), 'user.42.profile', $expiresAt)->write('dotted');

        $found = FileCacheItem::find(self::dir(), 'user.42.profile');
        $this->assertNotNull($found);
        $this->assertSame($expiresAt, $found->expiresAt);
        $this->assertSame('dotted', $found->readValue());
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

        $found = FileCacheItem::find(self::dir(), 'temp');
        $this->assertNotNull($found);
        $this->assertTrue($found->delete());
        $this->assertNull(FileCacheItem::find(self::dir(), 'temp'));
    }
}
