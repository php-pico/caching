<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Testing;

use Override;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\DriverTrait;

/**
 * NoopDriver.
 *
 * Stores nothing. Reads always report a miss; writes and deletions always report
 * success because discarding the data is exactly the operation this driver
 * performs. Useful for testing or for transparently disabling caching.
 */
final readonly class NoopDriver implements Driver
{
    use DriverTrait;

    #[Override]
    public function get(string $key): ?string
    {
        return null;
    }

    #[Override]
    public function set(string $key, string $value, ?int $expiresAt = null): bool
    {
        return true;
    }

    #[Override]
    public function delete(string $key): bool
    {
        return true;
    }

    #[Override]
    public function clear(): bool
    {
        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        return false;
    }
}
