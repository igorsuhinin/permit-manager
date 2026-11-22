<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Authentication\AdminCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(AdminCredentials::class)]
final class AdminCredentialsTest extends TestCase
{
    public function testStoredCredentialFingerprintRemainsCompatible(): void
    {
        self::assertSame(
            '156251991ef82d8da84920bb3760c5ee7bc45b24c1ff58d987cb4f5fe8700b7e',
            new AdminCredentials(username: 'admin', passwordHash: 'test-password-hash')->fingerprint(),
        );
    }
}
