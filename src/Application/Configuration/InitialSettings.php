<?php

declare(strict_types=1);

namespace App\Application\Configuration;

final readonly class InitialSettings
{
    public function __construct(
        public string $adminUsername,
        #[\SensitiveParameter]
        public string $passwordHash,
        #[\SensitiveParameter]
        public string $signingSecret,
        #[\SensitiveParameter]
        public string $hmacSigningKey,
        public string $baseUrl,
        public string $timezone,
    ) {}

    /** @return array<string, string> */
    public function values(): array
    {
        return [
            'admin_username' => $this->adminUsername, 'password_hash' => $this->passwordHash,
            'signing_secret' => $this->signingSecret, 'hmac_signing_key' => $this->hmacSigningKey,
            'base_url' => $this->baseUrl, 'timezone' => $this->timezone,
        ];
    }
}
