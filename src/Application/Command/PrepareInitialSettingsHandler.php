<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Configuration\InitialSettings;
use App\Application\Port\InitialSettingsStoreInterface;
use App\Application\Validator\InitialSettingsValidator;
use App\Application\View\PreparedInitialSettings;

final readonly class PrepareInitialSettingsHandler
{
    public function __construct(
        private InitialSettingsStoreInterface $store,
        private InitialSettingsValidator $validator,
    ) {}

    public function handle(PrepareInitialSettingsCommand $command): PreparedInitialSettings
    {
        $password = $command->password ?? bin2hex(random_bytes(16));
        $settings = new InitialSettings(
            adminUsername: $command->adminUsername,
            passwordHash: password_hash($password, PASSWORD_DEFAULT),
            signingSecret: $command->signingSecret ?? bin2hex(random_bytes(32)),
            hmacSigningKey: strtolower($command->hmacSigningKey ?? bin2hex(random_bytes(32))),
            baseUrl: $command->baseUrl,
            timezone: $command->timezone,
        );
        $this->validator->validate($settings);

        return new PreparedInitialSettings(
            path: $this->store->create($settings),
            adminUsername: $settings->adminUsername,
            generatedPassword: null === $command->password ? $password : null,
        );
    }
}
