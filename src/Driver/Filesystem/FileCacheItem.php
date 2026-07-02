<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Filesystem;

/**
 * A single on-disk cache item for FilesystemDriver.
 *
 * The item IS the file: it owns its absolute path and performs its own reads and
 * writes. The expiration is encoded in the filename as `{key}.{expiresAt}.cache`
 * (a unix timestamp, or 0 for "never"), so expiry can be decided from the name
 * alone without opening and parsing the file. The file body holds only the
 * serialized payload.
 *
 * Intentionally not shared with other drivers' item types: those may diverge.
 */
final readonly class FileCacheItem
{
    protected const string SUFFIX = '.cache';

    protected function __construct(
        public string $directory,
        public string $key,
        public ?int $expiresAt,
        public string $path,
    ) {}

    /**
     * Build an item for a value about to be written.
     */
    public static function create(string $directory, string $key, ?int $expiresAt): self
    {
        return new self($directory, $key, $expiresAt, self::pathFor($directory, $key, $expiresAt));
    }

    /**
     * Locate the item currently stored for a key, if any.
     */
    public static function find(string $directory, string $key): ?self
    {
        $matches = glob(self::normalize($directory . DIRECTORY_SEPARATOR . self::escape($key) . '.*' . self::SUFFIX));

        if ($matches === false || $matches === []) {
            return null;
        }

        $path = $matches[0];
        $name = basename($path);
        $stamp = substr($name, strlen($key . '.'), -strlen(self::SUFFIX));

        if (!ctype_digit($stamp)) {
            return null;
        }

        $expiresAt = (int) $stamp;

        return new self($directory, $key, $expiresAt === 0 ? null : $expiresAt, $path);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= time();
    }

    /**
     * Persist a value, replacing any previously stored file for this key.
     */
    public function write(string $value): bool
    {
        foreach (self::existingPaths($this->directory, $this->key) as $stale) {
            unlink($stale);
        }

        return file_put_contents($this->path, $value) !== false;
    }

    /**
     * Read the stored value, or null if the file has gone.
     */
    public function readValue(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }

        $value = file_get_contents($this->path);

        return $value === false ? null : $value;
    }

    public function delete(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        return unlink($this->path);
    }

    protected static function pathFor(string $directory, string $key, ?int $expiresAt): string
    {
        return self::normalize($directory . DIRECTORY_SEPARATOR . $key . '.' . ($expiresAt ?? 0) . self::SUFFIX);
    }

    /**
     * @return list<string>
     */
    protected static function existingPaths(string $directory, string $key): array
    {
        $matches = glob(self::normalize($directory . DIRECTORY_SEPARATOR . self::escape($key) . '.*' . self::SUFFIX));

        return $matches === false ? [] : $matches;
    }

    protected static function normalize(string $path): string
    {
        return (string) preg_replace('#/+#', '/', $path);
    }

    protected static function escape(string $key): string
    {
        return str_replace(['*', '?', '[', ']'], ['\\*', '\\?', '\\[', '\\]'], $key);
    }
}
