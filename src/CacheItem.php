<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use DateInterval;
use DateTimeInterface;
use Override;
use Psr\Cache\CacheItemInterface;

/**
 * CacheItem.
 *
 * A PSR-6 cache item minted by {@see CachePool}. Per the spec, calling libraries
 * must not instantiate items directly — they are obtained from the pool via
 * getItem()/getItems(). The item stores the raw expiration argument; the pool
 * converts it to an absolute timestamp when persisting.
 */
final class CacheItem implements CacheItemInterface
{
    protected mixed $value;

    protected int|DateInterval|DateTimeInterface|null $expiry = null;

    protected function __construct(
        protected readonly string $key,
        protected readonly bool $isHit,
        mixed $value = null,
    ) {
        $this->value = $value;
    }

    /**
     * Mint an item for a cache hit.
     */
    public static function hit(string $key, mixed $value): self
    {
        return new self($key, true, $value);
    }

    /**
     * Mint an item for a cache miss.
     */
    public static function miss(string $key): self
    {
        return new self($key, false);
    }

    #[Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[Override]
    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    #[Override]
    public function isHit(): bool
    {
        return $this->isHit;
    }

    #[Override]
    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    #[Override]
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiry = $expiration;

        return $this;
    }

    #[Override]
    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->expiry = $time;

        return $this;
    }

    /**
     * The value last set on this item, regardless of hit status. Used by the pool
     * when persisting: unlike get(), it does not null out on a miss.
     */
    public function rawValue(): mixed
    {
        return $this->value;
    }

    /**
     * The raw expiration argument, for the pool to convert to an absolute timestamp.
     */
    public function rawExpiry(): int|DateInterval|DateTimeInterface|null
    {
        return $this->expiry;
    }
}
