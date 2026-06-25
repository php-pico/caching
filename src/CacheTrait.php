<?php

namespace PhpPico\Caching;

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
}
