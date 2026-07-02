<?php

declare(strict_types=1);

namespace PhpPico\Caching\Tests;

use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\NoopDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoopDriver::class)]
final class NoopDriverTest extends TestCase
{
    private function driver(): NoopDriver
    {
        return new NoopDriver();
    }

    #[Test]
    public function implements_driver_interface(): void
    {
        $this->assertInstanceOf(Driver::class, $this->driver());
    }

    #[Test]
    public function get_always_returns_null(): void
    {
        $this->assertNull($this->driver()->get('foo'));
    }

    #[Test]
    public function writes_and_deletes_always_report_success(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->set('foo', 'bar'));
        $this->assertTrue($driver->delete('foo'));
        $this->assertTrue($driver->clear());
    }

    #[Test]
    public function has_always_returns_false(): void
    {
        $this->assertFalse($this->driver()->has('foo'));
    }

    #[Test]
    public function multi_operations_report_success_but_never_store(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->setMultiple(['a' => '1', 'b' => '2']));
        $this->assertSame(['a' => null, 'b' => null], $driver->getMultiple(['a', 'b']));
        $this->assertTrue($driver->deleteMultiple(['a', 'b']));
    }
}
