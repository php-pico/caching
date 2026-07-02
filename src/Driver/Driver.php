<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver;

/**
 * A cache backend.
 *
 * A driver only moves already-serialized string payloads to and from a backend.
 * All key validation, value (de)serialization and TTL-to-timestamp conversion is
 * performed by the Cache facade before a driver is ever called, so drivers stay
 * thin and independently testable.
 */
interface Driver
{
    /**
     * @param string $key A key already validated by the Cache facade.
     *
     * @return string|null The stored serialized payload, or null on a miss or expired entry.
     */
    public function get(string $key): ?string;

    /**
     * @param string   $key       A key already validated by the Cache facade.
     * @param string   $value     The serialized payload to store.
     * @param int|null $expiresAt Absolute unix timestamp, or null for no expiration.
     *
     * @return bool True on success and false on failure.
     */
    public function set(string $key, string $value, ?int $expiresAt = null): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    /**
     * @param list<string> $keys Keys already validated by the Cache facade.
     *
     * @return array<string, string|null> key => serialized payload (null on miss)
     */
    public function getMultiple(array $keys): array;

    /**
     * @param array<string, string> $values    key => serialized payload
     * @param int|null               $expiresAt Absolute unix timestamp, or null for no expiration.
     *
     * @return bool True on success and false on failure.
     */
    public function setMultiple(array $values, ?int $expiresAt = null): bool;

    /**
     * @param list<string> $keys Keys already validated by the Cache facade.
     *
     * @return bool True on success and false on failure.
     */
    public function deleteMultiple(array $keys): bool;

    public function has(string $key): bool;
}
