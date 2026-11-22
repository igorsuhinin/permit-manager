<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use App\Application\Port\MaintenanceLockInterface;

final class FileMaintenanceLock implements MaintenanceLockInterface
{
    /** @var resource|null */
    private $handle;

    public function __construct(private readonly string $path) {}

    #[\Override]
    public function acquire(): bool
    {
        if (null !== $this->handle) {
            return false;
        }
        $directory = dirname($this->path);
        if (
            !is_dir($directory) && !@mkdir(directory: $directory, permissions: 0700, recursive: true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException('Cannot create the maintenance lock directory.');
        }
        $handle = @fopen(filename: $this->path, mode: 'c');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the maintenance lock file.');
        }
        if (!flock(stream: $handle, operation: LOCK_EX | LOCK_NB, would_block: $blocked)) {
            fclose($handle);
            if (0 === $blocked) {
                throw new \RuntimeException('Cannot acquire the maintenance lock.');
            }

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    #[\Override]
    public function release(): void
    {
        if (null !== $this->handle) {
            $handle = $this->handle;
            $this->handle = null;
            fclose($handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
