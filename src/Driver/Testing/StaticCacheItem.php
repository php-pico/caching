<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Testing;

/**
 * A single in-memory cache item held by StaticDriver.
 *
 * Holds the serialized payload and its absolute expiration, and answers whether
 * it has expired. Intentionally not shared with other drivers' item types: those
 * may diverge independently.
 */
final readonly class StaticCacheItem
{
    public function __construct(
        public string $value,
        public ?int $expiresAt = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= time();
    }
}
