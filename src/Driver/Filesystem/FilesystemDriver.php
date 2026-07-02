<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Filesystem;

use FilesystemIterator;
use Override;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\DriverTrait;

/**
 * FilesystemDriver.
 *
 * Stores each cache item as a file on disk, represented by a FileCacheItem. The
 * expiration lives in the filename, so existence and freshness are decided
 * without opening the file; the body holds only the serialized payload.
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

        return $item->readValue();
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
        /** @var \SplFileInfo $file */
        foreach (new FilesystemIterator($this->dir) as $file) {
            if (!unlink($file->getPathname())) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        $item = FileCacheItem::find($this->dir, $key);

        return $item !== null && !$item->isExpired();
    }
}
