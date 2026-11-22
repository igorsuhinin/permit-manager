<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Infrastructure\Maintenance\FileMaintenanceLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

#[Medium]
#[CoversClass(FileMaintenanceLock::class)]
final class MaintenanceLockTest extends TestCase
{
    public function testUnwritableDirectoryFailsClosed(): void
    {
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'permit-lock-');
        self::assertNotFalse($path);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageIs('Cannot create the maintenance lock directory.');
            new FileMaintenanceLock($path . '/nested/history.lock')->acquire();
        } finally {
            unlink($path);
        }
    }

    public function testInvalidLockFileFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('Cannot open the maintenance lock file.');
        new FileMaintenanceLock(sys_get_temp_dir())->acquire();
    }

    public function testUnsupportedLockingFailsClosed(): void
    {
        $directory = sys_get_temp_dir() . '/permit-lock-' . bin2hex(random_bytes(8));
        mkdir(directory: $directory, permissions: 0700);
        $previous = getcwd();
        self::assertNotFalse($previous);
        try {
            chdir($directory);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageIs('Cannot acquire the maintenance lock.');
            new FileMaintenanceLock('php://memory')->acquire();
        } finally {
            chdir($previous);
            rmdir($directory . '/php:');
            rmdir($directory);
        }
    }
}
