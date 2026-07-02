<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use DateTimeImmutable;
use PhpPico\Caching\CacheItem;
use PhpPico\Caching\CachePool;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\Testing\StaticDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as InvalidArgumentExceptionInterface;

#[CoversClass(CacheItemPoolInterface::class)]
#[CoversClass(CachePool::class)]
final class CachePoolTest extends TestCase
{
    private function pool(): CachePool
    {
        return new CachePool(new StaticDriver());
    }

    #[Test]
    public function implements_interface(): void
    {
        $this->assertInstanceOf(CacheItemPoolInterface::class, $this->pool());
    }

    #[Test]
    public function get_item_returns_a_miss_item_never_null(): void
    {
        $item = $this->pool()->getItem('nope');

        $this->assertInstanceOf(CacheItemInterface::class, $item);
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());
    }

    #[Test]
    public function save_then_get_item_round_trips_the_value(): void
    {
        $pool = $this->pool();

        $this->assertFalse($pool->hasItem('foo'));
        $this->assertTrue($pool->save($pool->getItem('foo')->set(['a' => 1])));

        $item = $pool->getItem('foo');
        $this->assertTrue($item->isHit());
        $this->assertSame(['a' => 1], $item->get());
        $this->assertTrue($pool->hasItem('foo'));
    }

    #[Test]
    public function get_items_returns_an_item_for_every_key_including_misses(): void
    {
        $pool = $this->pool();
        $pool->save($pool->getItem('FIRST')->set('1st'));
        $pool->save($pool->getItem('THIRD')->set('3rd'));

        $items = $pool->getItems(['FIRST', 'SECOND', 'THIRD']);

        $this->assertSame(['FIRST', 'SECOND', 'THIRD'], array_keys($items));
        $this->assertTrue($items['FIRST']->isHit());
        $this->assertSame('1st', $items['FIRST']->get());
        $this->assertFalse($items['SECOND']->isHit());
        $this->assertNull($items['SECOND']->get());
        $this->assertTrue($items['THIRD']->isHit());
    }

    #[Test]
    public function get_items_with_no_keys_returns_empty(): void
    {
        $this->assertSame([], $this->pool()->getItems([]));
    }

    #[Test]
    public function delete_item_removes_it(): void
    {
        $pool = $this->pool();
        $pool->save($pool->getItem('foo')->set('bar'));

        $this->assertTrue($pool->deleteItem('foo'));
        $this->assertFalse($pool->hasItem('foo'));
    }

    #[Test]
    public function delete_items_removes_all(): void
    {
        $pool = $this->pool();
        $pool->save($pool->getItem('a')->set('1'));
        $pool->save($pool->getItem('b')->set('2'));

        $this->assertTrue($pool->deleteItems(['a', 'b']));
        $this->assertFalse($pool->hasItem('a'));
        $this->assertFalse($pool->hasItem('b'));
    }

    #[Test]
    public function clear_empties_the_pool(): void
    {
        $pool = $this->pool();
        $pool->save($pool->getItem('foo')->set('bar'));

        $this->assertTrue($pool->clear());
        $this->assertFalse($pool->hasItem('foo'));
    }

    #[Test]
    public function deferred_item_is_visible_before_commit_and_persisted_after(): void
    {
        $pool = $this->pool();

        $this->assertTrue($pool->saveDeferred($pool->getItem('foo')->set('bar')));

        // Read-back: visible as a hit before commit.
        $this->assertTrue($pool->hasItem('foo'));
        $this->assertTrue($pool->getItem('foo')->isHit());
        $this->assertSame('bar', $pool->getItem('foo')->get());

        $this->assertTrue($pool->commit());
        $this->assertTrue($pool->hasItem('foo'));
        $this->assertSame('bar', $pool->getItem('foo')->get());
    }

    #[Test]
    public function commit_with_nothing_deferred_returns_true(): void
    {
        $this->assertTrue($this->pool()->commit());
    }

    #[Test]
    public function commit_clears_the_deferred_buffer(): void
    {
        $pool = $this->pool();
        $pool->saveDeferred($pool->getItem('foo')->set('bar'));
        $pool->commit();

        // With the buffer cleared, deleting then committing again must not resurrect it.
        $pool->deleteItem('foo');
        $this->assertTrue($pool->commit());
        $this->assertFalse($pool->hasItem('foo'));
    }

    #[Test]
    public function save_serializes_the_value_and_passes_no_expiry_by_default(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('get')->willReturn(null);
        $driver->expects($this->once())->method('set')->with('foo', serialize('bar'), null)->willReturn(true);

        $pool = new CachePool($driver);

        $this->assertTrue($pool->save($pool->getItem('foo')->set('bar')));
    }

    #[Test]
    public function save_converts_expires_after_int_to_an_absolute_timestamp(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('get')->willReturn(null);
        $driver
            ->expects($this->once())
            ->method('set')
            ->with('foo', serialize('bar'), time() + 60)
            ->willReturn(true);

        $pool = new CachePool($driver);

        $this->assertTrue($pool->save($pool->getItem('foo')->set('bar')->expiresAfter(60)));
    }

    #[Test]
    public function save_converts_expires_at_datetime_to_an_absolute_timestamp(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('get')->willReturn(null);
        $driver->expects($this->once())->method('set')->with('foo', serialize('bar'), 1700000000)->willReturn(true);

        $pool = new CachePool($driver);

        $item = $pool->getItem('foo')->set('bar')->expiresAt(new DateTimeImmutable('@1700000000'));
        $this->assertTrue($pool->save($item));
    }

    #[Test]
    public function get_item_unserializes_the_driver_payload(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver
            ->method('get')
            ->with('foo')
            ->willReturn(serialize(['a' => 1]));

        $item = new CachePool($driver)->getItem('foo');

        $this->assertTrue($item->isHit());
        $this->assertSame(['a' => 1], $item->get());
    }

    #[Test]
    public function invalid_key_exception_satisfies_both_psr_specs(): void
    {
        try {
            $this->pool()->getItem('');
            $this->fail('Expected an invalid-argument exception');
        } catch (InvalidArgumentExceptionInterface $e) {
            $this->assertInstanceOf(\Psr\SimpleCache\InvalidArgumentException::class, $e);
        }
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function get_item_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->pool()->getItem($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function has_item_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->pool()->hasItem($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function delete_item_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->pool()->deleteItem($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function get_items_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->pool()->getItems(['valid', $key]);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function delete_items_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->pool()->deleteItems(['valid', $key]);
    }

    #[Test]
    #[DataProvider('validKeys')]
    public function valid_keys_round_trip(string $key): void
    {
        $pool = $this->pool();

        $this->assertTrue($pool->save($pool->getItem($key)->set('value')));
        $this->assertSame('value', $pool->getItem($key)->get());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('a', 65)],
            'space' => ['has space'],
            'slash' => ['has/slash'],
            'brace' => ['has{brace}'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validKeys(): array
    {
        return [
            'simple' => ['foo'],
            'underscore' => ['foo_bar'],
            'dot' => ['foo.bar'],
            'alnum' => ['Foo123'],
            'max length' => [str_repeat('a', 64)],
        ];
    }
}
