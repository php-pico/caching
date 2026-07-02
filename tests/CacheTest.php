<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use DateInterval;
use DateTimeImmutable;
use PhpPico\Caching\Cache;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\StaticDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as InvalidArgumentExceptionInterface;

#[CoversClass(CacheInterface::class)]
#[CoversClass(Cache::class)]
final class CacheTest extends TestCase
{
    private function cache(): Cache
    {
        return new Cache(new StaticDriver());
    }

    #[Test]
    public function implements_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cache());
    }

    #[Test]
    public function set_and_get_round_trips_the_value(): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->set('foo', ['a' => 1, 'b' => 'two']));
        $this->assertEquals(['a' => 1, 'b' => 'two'], $cache->get('foo'));
    }

    #[Test]
    public function get_returns_default_on_miss(): void
    {
        $this->assertSame('fallback', $this->cache()->get('missing', 'fallback'));
    }

    #[Test]
    public function set_serializes_value_before_handing_it_to_the_driver(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->expects($this->once())->method('set')->with('foo', serialize('bar'), null)->willReturn(true);

        $this->assertTrue(new Cache($driver)->set('foo', 'bar'));
    }

    #[Test]
    public function set_converts_int_ttl_to_absolute_timestamp(): void
    {
        $ttl = 60;

        $driver = $this->createMock(Driver::class);
        $driver
            ->expects($this->once())
            ->method('set')
            ->with('foo', serialize('bar'), time() + $ttl)
            ->willReturn(true);

        $this->assertTrue(new Cache($driver)->set('foo', 'bar', $ttl));
    }

    #[Test]
    public function set_converts_dateinterval_ttl_to_absolute_timestamp(): void
    {
        $ttl = DateInterval::createFromDateString('1 minute');

        $driver = $this->createMock(Driver::class);
        $driver
            ->expects($this->once())
            ->method('set')
            ->with('foo', serialize('bar'), new DateTimeImmutable()->add($ttl)->getTimestamp())
            ->willReturn(true);

        $this->assertTrue(new Cache($driver)->set('foo', 'bar', $ttl));
    }

    #[Test]
    public function get_unserializes_the_driver_payload(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('get')->with('foo')->willReturn(serialize('bar'));

        $this->assertSame('bar', new Cache($driver)->get('foo'));
    }

    #[Test]
    public function delete_and_clear_delegate_to_the_driver(): void
    {
        $cache = $this->cache();

        $cache->set('foo', 'bar');
        $this->assertTrue($cache->delete('foo'));
        $this->assertFalse($cache->has('foo'));

        $cache->set('foo', 'bar');
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('foo'));
    }

    #[Test]
    public function has_returns_false_before_set_and_true_after_set(): void
    {
        $cache = $this->cache();

        $this->assertFalse($cache->has('foo'));
        $cache->set('foo', 'bar');
        $this->assertTrue($cache->has('foo'));
    }

    #[Test]
    public function get_multiple_returns_values_and_defaults(): void
    {
        $cache = $this->cache();
        $cache->set('FIRST', '1st');
        $cache->set('THIRD', '3rd');

        $this->assertEquals(
            ['FIRST' => '1st', 'SECOND' => 'x', 'THIRD' => '3rd'],
            $cache->getMultiple(['FIRST', 'SECOND', 'THIRD'], 'x'),
        );
    }

    #[Test]
    public function get_multiple_returns_empty_for_no_keys(): void
    {
        $this->assertSame([], $this->cache()->getMultiple([]));
    }

    #[Test]
    public function set_multiple_serializes_each_value(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver
            ->expects($this->once())
            ->method('setMultiple')
            ->with(['FIRST' => serialize('1st'), 'SECOND' => serialize('2nd')], null)
            ->willReturn(true);

        $this->assertTrue(new Cache($driver)->setMultiple(['FIRST' => '1st', 'SECOND' => '2nd']));
    }

    #[Test]
    public function set_multiple_returns_true_for_no_values(): void
    {
        $this->assertTrue($this->cache()->setMultiple([]));
    }

    #[Test]
    public function set_multiple_throws_on_non_string_key(): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->cache()->setMultiple([0 => 'value']);
    }

    #[Test]
    public function delete_multiple_round_trips(): void
    {
        $cache = $this->cache();
        $cache->setMultiple(['FIRST' => '1st', 'SECOND' => '2nd']);

        $this->assertTrue($cache->deleteMultiple(['FIRST', 'SECOND']));
        $this->assertFalse($cache->has('FIRST'));
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function get_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->cache()->get($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function set_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->cache()->set($key, 'value');
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function delete_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->cache()->delete($key);
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function has_throws_on_invalid_key(string $key): void
    {
        $this->expectException(InvalidArgumentExceptionInterface::class);
        $this->cache()->has($key);
    }

    #[Test]
    #[DataProvider('validKeys')]
    public function valid_keys_are_accepted(string $key): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->set($key, 'value'));
        $this->assertSame('value', $cache->get($key));
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
