<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Testing;

use ArrayObject;
use Override;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\DriverTrait;

/**
 * StaticDriver.
 *
 * Keeps entries in memory for the request, so everything is cleared once the
 * response is sent. Useful for testing.
 */
final readonly class StaticDriver implements Driver
{
    use DriverTrait;

    /** @var ArrayObject<string, array{expires: int|null, value: string}> */
    protected ArrayObject $cache;

    public function __construct()
    {
        $this->cache = new ArrayObject([]);
    }

    #[Override]
    public function get(string $key): ?string
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->cache->offsetGet($key)['value'];
    }

    #[Override]
    public function set(string $key, string $value, ?int $expiresAt = null): bool
    {
        $this->cache->offsetSet($key, [
            'expires' => $expiresAt,
            'value' => $value,
        ]);

        return true;
    }

    #[Override]
    public function delete(string $key): bool
    {
        if (!$this->has($key)) {
            return false;
        }

        $this->cache->offsetUnset($key);

        return true;
    }

    #[Override]
    public function clear(): bool
    {
        $this->cache->exchangeArray([]);

        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        if (!$this->cache->offsetExists($key)) {
            return false;
        }

        $item = $this->cache->offsetGet($key);

        if (is_null($item['expires'])) {
            return true;
        }

        return $item['expires'] > time();
    }
}
