<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver;

/**
 * Naive multi-operation defaults built on the single-key methods.
 *
 * Drivers that cannot batch requests reuse these; drivers that can (such as
 * RedisDriver with MGET/MSET) override them with a single round-trip.
 */
trait DriverTrait
{
    abstract public function get(string $key): ?string;

    abstract public function set(string $key, string $value, ?int $expiresAt = null): bool;

    abstract public function delete(string $key): bool;

    /**
     * @param list<string> $keys
     *
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * @param array<string, string> $values
     */
    public function setMultiple(array $values, ?int $expiresAt = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            $success = $this->set($key, $value, $expiresAt) && $success;
        }

        return $success;
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }
}
