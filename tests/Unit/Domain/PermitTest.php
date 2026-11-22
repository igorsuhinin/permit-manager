<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(Permit::class)]
final class PermitTest extends TestCase
{
    #[DataProvider('storedFingerprints')]
    public function testFingerprintPreservesTheExistingStoredIdentity(
        PermitSignatureVersion $version,
        string $expected,
    ): void {
        $permit = new Permit(id: 'DEMO-SAME', term: '31.12.2099', plate: 'TEST', signatureVersion: $version);
        self::assertSame($expected, $permit->fingerprint());
    }

    /** @return iterable<string, array{PermitSignatureVersion, string}> */
    public static function storedFingerprints(): iterable
    {
        yield 'original unversioned record' => [
            PermitSignatureVersion::Legacy, '0478f9b3953771c94bb703c1334f8c40c6e550a5904e9fbdf7af757e79e3be98',
        ];
        yield 'version 2 record' => [
            PermitSignatureVersion::HmacSha256, '507167d6977143fa84b794bee442fba70f8e62de1ef6d584f84bad3621884020',
        ];
    }

    public function testUnencodableClaimsCannotProduceAnAmbiguousFingerprint(): void
    {
        $this->expectException(\JsonException::class);
        new Permit(id: "\xff", term: '31.12.2099', plate: 'TEST')->fingerprint();
    }
}
