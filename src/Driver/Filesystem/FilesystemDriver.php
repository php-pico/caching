<?php

declare(strict_types=1);

namespace PhpPico\Caching\Driver\Filesystem;

use FilesystemIterator;
use JsonException;
use Override;
use PhpPico\Caching\Driver\Driver;
use PhpPico\Caching\Driver\DriverTrait;

/**
 * FilesystemDriver.
 *
 * Stores each cache item as a JSON file on disk holding its expiration and the
 * serialized payload handed down by the Cache facade.
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

    protected function getAbsolutePath(string $key): string
    {
        return (string) preg_replace('/([\/]+)/', '/', $this->dir . DIRECTORY_SEPARATOR . $key);
    }

    /**
     * @return array{expires: int|null, value: string}|null
     */
    protected function readItem(string $key): ?array
    {
        $filePath = $this->getAbsolutePath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        try {
            // @mago-expect analysis:mixed-assignment
            $item = json_decode((string) file_get_contents($filePath), true);
        } catch (JsonException) {
            return null;
        }

        if (
            is_array($item)
            && array_key_exists('expires', $item)
            && array_key_exists('value', $item)
            && is_string($item['value'])
        ) {
            return [
                'expires' => is_int($item['expires']) ? $item['expires'] : null,
                'value' => $item['value'],
            ];
        }

        return null;
    }

    #[Override]
    public function get(string $key): ?string
    {
        if ($this->has($key)) {
            $item = $this->readItem($key);

            if ($item && (is_null($item['expires']) || $item['expires'] > time())) {
                return $item['value'];
            }
        }

        $this->delete($key);

        return null;
    }

    #[Override]
    public function set(string $key, string $value, ?int $expiresAt = null): bool
    {
        return (bool) file_put_contents(
            $this->getAbsolutePath($key),
            json_encode([
                'expires' => $expiresAt,
                'value' => $value,
            ]),
        );
    }

    #[Override]
    public function delete(string $key): bool
    {
        $filePath = $this->getAbsolutePath($key);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return false;
    }

    #[Override]
    public function clear(): bool
    {
        /** @var \SplFileInfo $file */
        foreach (new FilesystemIterator($this->dir) as $file) {
            if (!$this->delete($file->getFilename())) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        return file_exists($this->getAbsolutePath($key));
    }
}
