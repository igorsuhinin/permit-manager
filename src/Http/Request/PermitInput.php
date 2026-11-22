<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Domain\Permit\Model\Permit;

final class PermitInput
{
    /** @param array<array-key, mixed> $data */
    public function permit(array $data, bool $generation = false): Permit
    {
        $guest = $generation ? isset($data['guest']) : (bool) $this->text($data, 'guest');
        $plate = $this->text($data, 'plate');
        $version = $generation ? PermitSignatureVersion::HmacSha256 : match ($this->text($data, 'v')) {
            '', '1' => PermitSignatureVersion::Legacy,
            '2' => PermitSignatureVersion::HmacSha256,
            default => throw new InvalidPermitInputException(PermitFailureReason::InvalidRequest),
        };

        return new Permit(
            id: $this->text($data, 'id'),
            term: $this->text($data, 'term'),
            plate: $plate,
            guest: $guest,
            apartment: $this->text($data, 'apt'),
            signatureVersion: $version,
        );
    }

    /** @param array<array-key, mixed> $data */
    public function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value)) {
            throw new InvalidPermitInputException(PermitFailureReason::InvalidRequest);
        }

        return $value;
    }
}
