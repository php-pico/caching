<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Filesystem;

/**
 * A single on-disk cache item for FilesystemDriver.
 *
 * The item IS the file: it owns its absolute path and performs its own reads and
 * writes. The file is stored at `{key}.cache`, so it can be looked up, checked
 * and deleted by exact path (a single stat), with no directory scan. The first
 * line holds the expiration — a unix timestamp, or empty for "never" — and the
 * remainder of the file is the serialized payload.
 *
 * Intentionally not shared with other drivers' item types: those may diverge.
 */
final readonly class FileCacheItem
{
    public const string SUFFIX = '.cache';

    protected function __construct(
        public string $path,
        public ?int $expiresAt,
        protected ?string $value = null,
    ) {}

    /**
     * Build an item for a value about to be written.
     */
    public static function create(string $directory, string $key, ?int $expiresAt): self
    {
        return new self(self::pathFor($directory, $key), $expiresAt);
    }

    /**
     * Load the item stored for a key, reading its expiry and value, if present.
     */
    public static function find(string $directory, string $key): ?self
    {
        $path = self::pathFor($directory, $key);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $newline = strpos($contents, "\n");

        if ($newline === false) {
            return null;
        }

        $header = substr($contents, 0, $newline);
        $value = substr($contents, $newline + 1);

        return new self($path, $header === '' ? null : (int) $header, $value);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= time();
    }

    /**
     * The stored value, available once the item has been loaded via find().
     */
    public function value(): ?string
    {
        return $this->value;
    }

    /**
     * Persist a value as `{expiry}\n{value}`.
     */
    public function write(string $value): bool
    {
        return file_put_contents($this->path, ($this->expiresAt ?? '') . "\n" . $value) !== false;
    }

    public function delete(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        return unlink($this->path);
    }

    protected static function pathFor(string $directory, string $key): string
    {
        return (string) preg_replace('#/+#', '/', $directory . DIRECTORY_SEPARATOR . $key . self::SUFFIX);
    }
}
