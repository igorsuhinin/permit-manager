<?php

declare(strict_types=1);

namespace App\Domain\Permit\Model;

use App\Domain\Permit\Enum\PermitSignatureVersion;

final readonly class Permit
{
    public function __construct(
        public string $id,
        public string $term,
        public string $plate = '',
        public bool $guest = false,
        public string $apartment = '',
        public PermitSignatureVersion $signatureVersion = PermitSignatureVersion::Legacy,
    ) {}

    public function fingerprint(): string
    {
        $claims = [$this->id, $this->term, $this->plate, $this->guest, $this->apartment];
        if (PermitSignatureVersion::Legacy !== $this->signatureVersion) {
            $claims[] = $this->signatureVersion->value;
        }

        return hash(algo: 'sha256', data: json_encode(value: $claims, flags: JSON_THROW_ON_ERROR));
    }
}
