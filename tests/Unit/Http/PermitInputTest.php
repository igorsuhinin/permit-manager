<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Domain\Permit\Model\Permit;
use App\Http\Request\PermitInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(PermitInput::class)]
#[UsesClass(Permit::class)]
#[UsesClass(InvalidPermitInputException::class)]
final class PermitInputTest extends TestCase
{
    /** @param array<string, mixed> $data */
    #[DataProvider('publicVersions')]
    public function testOnlyExplicitSupportedVersionsAreSelected(array $data, PermitSignatureVersion $version): void
    {
        self::assertSame($version, new PermitInput()->permit($data)->signatureVersion);
    }

    /** @return iterable<string, array{array<string, mixed>, PermitSignatureVersion}> */
    public static function publicVersions(): iterable
    {
        yield 'absent' => [[], PermitSignatureVersion::Legacy];
        yield 'empty' => [['v' => ''], PermitSignatureVersion::Legacy];
        yield 'explicit legacy' => [['v' => '1'], PermitSignatureVersion::Legacy];
        yield 'HMAC' => [['v' => '2'], PermitSignatureVersion::HmacSha256];
    }

    #[DataProvider('invalidVersions')]
    public function testUnknownOrMalformedVersionsAreRejected(mixed $version): void
    {
        $this->expectException(InvalidPermitInputException::class);
        $this->expectExceptionMessageIs('request');
        new PermitInput()->permit(['v' => $version]);
    }

    #[DataProvider('invalidVersions')]
    public function testSubmittedVersionsCannotChooseTheIssuanceAlgorithm(mixed $version): void
    {
        self::assertSame(
            PermitSignatureVersion::HmacSha256,
            new PermitInput()->permit(data: ['v' => $version], generation: true)->signatureVersion,
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidVersions(): iterable
    {
        yield 'future' => ['3'];
        yield 'negative' => ['-1'];
        yield 'padded' => ['02'];
        yield 'space' => [' 2'];
        yield 'array' => [['2']];
        yield 'integer' => [2];
        yield 'boolean' => [true];
    }
}
