<?php

declare(strict_types=1);

namespace App\Application\Port;

interface QrCodeGeneratorInterface
{
    public function dataUri(string $url): string;
}
