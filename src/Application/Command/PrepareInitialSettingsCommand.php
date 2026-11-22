<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class PrepareInitialSettingsCommand
{
    public function __construct(
        public string $adminUsername = 'admin',
        public string $baseUrl = 'http://localhost:8080',
        public string $timezone = 'Europe/Riga',
        #[\SensitiveParameter]
        public ?string $password = null,
        #[\SensitiveParameter]
        public ?string $signingSecret = null,
        #[\SensitiveParameter]
        public ?string $hmacSigningKey = null,
    ) {}
}
