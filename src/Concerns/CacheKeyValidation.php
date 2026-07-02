<?php

declare(strict_types=1);

namespace PhpPico\Caching\Concerns;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use PhpPico\Caching\Exceptions\InvalidArgumentException;

/**
 * Shared key validation and expiration handling for the PSR-16 Cache and the
 * PSR-6 CachePool, so both apply identical key rules and TTL conversion.
 */
trait CacheKeyValidation
{
    protected const int MAX_KEY_LENGTH = 64;

    protected const string KEY_PATTERN = '/^[A-Za-z0-9_.]+$/';

    /**
     * @throws InvalidArgumentException If the cache key is invalid.
     */
    protected function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty');
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Cache key must not be longer than %s characters',
                self::MAX_KEY_LENGTH,
            ));
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('Cache key contains invalid characters');
        }
    }

    /**
     * @return int|null NULL for no expiration, otherwise an absolute unix timestamp.
     */
    protected function calculateExpiration(int|DateInterval|DateTimeInterface|null $ttl): ?int
    {
        return match (true) {
            $ttl === null => null,
            is_int($ttl) => time() + $ttl,
            $ttl instanceof DateTimeInterface => $ttl->getTimestamp(),
            default => new DateTimeImmutable()->add($ttl)->getTimestamp(),
        };
    }
}
