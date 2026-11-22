<?php

declare(strict_types=1);

namespace App\Domain\Permit\Service;

use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;

final readonly class PermitSigner
{
    public function __construct(
        #[\SensitiveParameter]
        private string $legacySecret,
        #[\SensitiveParameter]
        private string $hmacKey,
    ) {}

    public function sign(Permit $permit): string
    {
        return match ($permit->signatureVersion) {
            PermitSignatureVersion::Legacy => $this->signLegacy($permit),
            PermitSignatureVersion::HmacSha256 => hash_hmac(
                algo: 'sha256',
                data: $this->canonicalPayload($permit),
                key: $this->hmacKey,
            ),
        };
    }

    /** @psalm-suppress WeakHash The original digest is required to verify and reprint issued v1 permits. */
    private function signLegacy(Permit $permit): string
    {
        return md5(($permit->guest ? $permit->apartment : $permit->plate)
            . $permit->id . $permit->term . $this->legacySecret . ($permit->guest ? 'guest' : ''));
    }

    private function canonicalPayload(Permit $permit): string
    {
        $fields = [
            'parking-permit', '2', $permit->guest ? 'guest' : 'resident', $permit->id, $permit->term,
            $permit->guest ? $permit->apartment : $permit->plate,
        ];

        // Byte lengths preserve exact values and make field boundaries unambiguous.
        return implode('', array_map(static fn(string $field): string => strlen($field) . ':' . $field, $fields));
    }

    public function matches(Permit $permit, string $signature): bool
    {
        return hash_equals(known_string: $this->sign($permit), user_string: $signature);
    }
}
