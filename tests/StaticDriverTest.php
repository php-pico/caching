<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\Testing\StaticDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticDriver::class)]
final class StaticDriverTest extends TestCase
{
    private function driver(): StaticDriver
    {
        return new StaticDriver();
    }

    #[Test]
    public function implements_driver_interface(): void
    {
        $this->assertInstanceOf(Driver::class, $this->driver());
    }

    #[Test]
    public function stores_and_returns_the_payload(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->set('foo', 'serialized'));
        $this->assertSame('serialized', $driver->get('foo'));
        $this->assertTrue($driver->has('foo'));
    }

    #[Test]
    public function get_returns_null_on_miss(): void
    {
        $this->assertNull($this->driver()->get('missing'));
    }

    #[Test]
    public function delete_returns_false_when_key_absent_and_true_when_present(): void
    {
        $driver = $this->driver();

        $this->assertFalse($driver->delete('missing'));

        $driver->set('foo', 'bar');
        $this->assertTrue($driver->delete('foo'));
        $this->assertNull($driver->get('foo'));
    }

    #[Test]
    public function clear_empties_the_cache(): void
    {
        $driver = $this->driver();
        $driver->set('foo', 'bar');

        $this->assertTrue($driver->clear());
        $this->assertFalse($driver->has('foo'));
    }

    #[Test]
    public function expired_entry_is_a_miss(): void
    {
        $driver = $this->driver();
        $driver->set('foo', 'bar', time() - 1);

        $this->assertFalse($driver->has('foo'));
        $this->assertNull($driver->get('foo'));
    }

    #[Test]
    public function future_expiration_is_a_hit(): void
    {
        $driver = $this->driver();
        $driver->set('foo', 'bar', time() + 60);

        $this->assertTrue($driver->has('foo'));
        $this->assertSame('bar', $driver->get('foo'));
    }

    #[Test]
    public function entries_are_isolated_per_instance(): void
    {
        $a = $this->driver();
        $a->set('foo', 'bar');

        $this->assertNull($this->driver()->get('foo'));
    }
}
