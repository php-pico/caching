<?php

namespace PhpPico\Caching;

use DateInterval;
use DateTimeImmutable;

trait CacheTrait
{
    /**
     * Check if a key is a legal value.
     *
     * @param string $key
     *
     * @return void
     * @throws InvalidArgumentException If the cache key is invalid
     */
    public function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty');
        }

        if (preg_match('/^[A-Za-z0-9_.]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Cache key contains invalid characters');
        }
    }

    /**
     * Calculate a cache item's expiration based on "now".
     *
     * @param null|int|DateInterval $ttl
     *
     * @return null|int NULL if no expiration or unix timestamp as int
     */
    public function calculateExpiration(int|DateInterval|null $ttl): ?int
    {
        if (is_null($ttl)) {
            return null;
        }

        if (is_int($ttl)) {
            return time() + $ttl;
        }

        return new DateTimeImmutable()->add($ttl)->getTimestamp();
    }
}
