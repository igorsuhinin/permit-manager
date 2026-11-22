<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Configuration\InitialSettings;

interface InitialSettingsStoreInterface
{
    public function create(InitialSettings $settings): string;
}
