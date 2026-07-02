<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use DateInterval;
use DateTimeImmutable;
use PhpPico\Caching\CacheItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;

#[CoversClass(CacheItem::class)]
final class CacheItemTest extends TestCase
{
    #[Test]
    public function implements_interface(): void
    {
        $this->assertInstanceOf(CacheItemInterface::class, CacheItem::miss('k'));
    }

    #[Test]
    public function hit_reports_hit_and_returns_value(): void
    {
        $item = CacheItem::hit('foo', ['a' => 1]);

        $this->assertSame('foo', $item->getKey());
        $this->assertTrue($item->isHit());
        $this->assertSame(['a' => 1], $item->get());
    }

    #[Test]
    public function miss_reports_miss_and_get_returns_null(): void
    {
        $item = CacheItem::miss('foo');

        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());
    }

    #[Test]
    public function get_returns_null_on_a_miss_even_after_set_but_raw_value_is_kept(): void
    {
        $item = CacheItem::miss('foo')->set('value');

        $this->assertNull($item->get(), 'get() must return null while isHit() is false');
        $this->assertSame('value', $item->rawValue(), 'the set value is retained for saving');
    }

    #[Test]
    public function set_is_fluent_and_updates_the_value(): void
    {
        $item = CacheItem::hit('foo', 'old');

        $this->assertSame($item, $item->set('new'));
        $this->assertSame('new', $item->get());
    }

    #[Test]
    public function expires_at_stores_the_datetime_and_is_fluent(): void
    {
        $item = CacheItem::hit('foo', 'v');
        $when = new DateTimeImmutable('@1700000000');

        $this->assertSame($item, $item->expiresAt($when));
        $this->assertSame($when, $item->rawExpiry());
    }

    #[Test]
    public function expires_at_null_clears_expiry(): void
    {
        $item = CacheItem::hit('foo', 'v')->expiresAt(new DateTimeImmutable('@1700000000'));

        $item->expiresAt(null);

        $this->assertNull($item->rawExpiry());
    }

    #[Test]
    public function expires_after_stores_the_raw_argument_and_is_fluent(): void
    {
        $item = CacheItem::hit('foo', 'v');
        $ttl = DateInterval::createFromDateString('1 minute');

        $this->assertSame($item, $item->expiresAfter($ttl));
        $this->assertSame($ttl, $item->rawExpiry());

        $item->expiresAfter(60);
        $this->assertSame(60, $item->rawExpiry());

        $item->expiresAfter(null);
        $this->assertNull($item->rawExpiry());
    }
}
