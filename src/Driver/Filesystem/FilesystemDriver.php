<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Filesystem;

use Override;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\DriverTrait;

/**
 * FilesystemDriver.
 *
 * Stores each cache item as a file on disk, represented by a FileCacheItem. Each
 * item lives at `{key}.cache`, so it is found and deleted by exact path; the file
 * itself holds the expiration on its first line and the serialized payload after.
 */
final readonly class FilesystemDriver implements Driver
{
    use DriverTrait;

    /**
     * @throws \InvalidArgumentException If directory traversal is detected.
     */
    public function __construct(
        public string $dir,
    ) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (str_contains($dir, '..')) {
            throw new \InvalidArgumentException('Directory traversal detected.');
        }
    }

    #[Override]
    public function get(string $key): ?string
    {
        $item = FileCacheItem::find($this->dir, $key);

        if ($item === null) {
            return null;
        }

        if ($item->isExpired()) {
            $item->delete();

            return null;
        }

        return $item->value();
    }

    #[Override]
    public function set(string $key, string $value, ?int $expiresAt = null): bool
    {
        return FileCacheItem::create($this->dir, $key, $expiresAt)->write($value);
    }

    #[Override]
    public function delete(string $key): bool
    {
        $item = FileCacheItem::find($this->dir, $key);

        return $item !== null && $item->delete();
    }

    #[Override]
    public function clear(): bool
    {
        $success = true;

        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*' . FileCacheItem::SUFFIX) ?: [] as $file) {
            $success = unlink($file) && $success;
        }

        return $success;
    }

    #[Override]
    public function has(string $key): bool
    {
        $item = FileCacheItem::find($this->dir, $key);

        return $item !== null && !$item->isExpired();
    }
}
