<?php

declare(strict_types=1);

namespace App\Application\View;

final readonly class PreparedInitialSettings
{
    public function __construct(
        public string $path,
        public string $adminUsername,
        #[\SensitiveParameter]
        public ?string $generatedPassword,
    ) {}
}
