<?php

declare(strict_types=1);

namespace App\Application\Authentication;

final readonly class AdminSession
{
    public function __construct(
        public string $token,
        public string $csrfToken,
        public bool $authenticated,
        public int $expiresAt,
        public ?string $credentialHash,
    ) {}
}
