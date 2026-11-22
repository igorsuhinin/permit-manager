<?php

declare(strict_types=1);

namespace App\Application\Configuration;

final readonly class Settings
{
    /** @param non-empty-string $timezone */
    public function __construct(
        #[\SensitiveParameter]
        public string $secret,
        #[\SensitiveParameter]
        public string $hmacKey,
        #[\SensitiveParameter]
        public string $passwordHash,
        public string $baseUrl = 'https://example.com',
        public string $timezone = 'Europe/Riga',
    ) {}
}
