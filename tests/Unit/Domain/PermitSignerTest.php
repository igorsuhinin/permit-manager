<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Service\PermitSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(PermitSigner::class)]
#[UsesClass(Permit::class)]
final class PermitSignerTest extends TestCase
{
    #[DataProvider('hmacVectors')]
    public function testHmacMatchesIndependentCanonicalPayloadVectors(Permit $permit, string $expected): void
    {
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        self::assertSame($expected, $signer->sign($permit));
        self::assertTrue($signer->matches($permit, $expected));
        self::assertFalse($signer->matches($permit, str_repeat('0', 64)));
        self::assertFalse($signer->matches($permit, strtoupper($expected)));
        self::assertFalse($signer->matches($permit, ''));
    }

    /** @return iterable<string, array{Permit, string}> */
    public static function hmacVectors(): iterable
    {
        yield 'resident' => [new Permit(
            id: 'DEMO-1',
            term: '03.11.2026',
            plate: 'AB1234',
            signatureVersion: PermitSignatureVersion::HmacSha256,
        ), 'faa9475144b47737c7a975d198733d1662ff338bd53ae4a7cd5b5bb7ee215a6d'];
        yield 'guest' => [new Permit(
            id: 'DEMO-1',
            term: '03.11.2026',
            guest: true,
            apartment: '48',
            signatureVersion: PermitSignatureVersion::HmacSha256,
        ), '7b9390f20fa4bcbce44259a7e11bfeaa87b7ae107358892b57e178957ba420d8'];
        yield 'exact UTF-8 bytes and delimiters' => [new Permit(
            id: ' 0Ā:| ',
            term: '31.12.2099',
            plate: 'AB:|Ā',
            signatureVersion: PermitSignatureVersion::HmacSha256,
        ), 'd380da4937b7198b3c4845d14b6ae2fa13a332fb9bf7cf882d26b38d05ef49d1'];
    }

    public function testFieldBoundariesAndPermitTypeCannotBeSubstituted(): void
    {
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $legacy = new Permit(id: '23', term: '31.12.2099', plate: '1');
        $other = new Permit(id: '3', term: '31.12.2099', plate: '12');
        self::assertSame($signer->sign($legacy), $signer->sign($other));
        $original = new Permit(
            id: '23',
            term: '31.12.2099',
            plate: '1',
            signatureVersion: PermitSignatureVersion::HmacSha256,
        );
        $signature = $signer->sign($original);
        foreach (
            [
                new Permit(
                    id: '3',
                    term: '31.12.2099',
                    plate: '12',
                    signatureVersion: PermitSignatureVersion::HmacSha256,
                ),
                new Permit(
                    id: '23',
                    term: '30.12.2099',
                    plate: '1',
                    signatureVersion: PermitSignatureVersion::HmacSha256,
                ),
                new Permit(
                    id: '23',
                    term: '31.12.2099',
                    plate: '2',
                    signatureVersion: PermitSignatureVersion::HmacSha256,
                ),
                new Permit(
                    id: '23',
                    term: '31.12.2099',
                    guest: true,
                    apartment: '1',
                    signatureVersion: PermitSignatureVersion::HmacSha256,
                ),
                $legacy,
            ] as $tampered
        ) {
            self::assertFalse($signer->matches($tampered, $signature));
        }
        self::assertFalse($signer->matches($original, $signer->sign($legacy)));
    }

    public function testEachProtocolUsesOnlyItsOwnKey(): void
    {
        $original = new PermitSigner(legacySecret: 'legacy', hmacKey: str_repeat('h', 32));
        $newLegacy = new PermitSigner(legacySecret: 'changed', hmacKey: str_repeat('h', 32));
        $newHmac = new PermitSigner(legacySecret: 'legacy', hmacKey: str_repeat('k', 32));
        $legacy = new Permit(id: '1', term: '31.12.2099', plate: 'TEST');
        $modern = new Permit(
            id: '1',
            term: '31.12.2099',
            plate: 'TEST',
            signatureVersion: PermitSignatureVersion::HmacSha256,
        );
        self::assertSame($original->sign($modern), $newLegacy->sign($modern));
        self::assertNotSame($original->sign($legacy), $newLegacy->sign($legacy));
        self::assertSame($original->sign($legacy), $newHmac->sign($legacy));
        self::assertNotSame($original->sign($modern), $newHmac->sign($modern));
    }
}
