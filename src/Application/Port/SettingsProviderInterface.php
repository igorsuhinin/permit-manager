<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Configuration\Settings;

interface SettingsProviderInterface
{
    public function load(): Settings;

    public function adminUsername(): string;
}
