<?php

declare(strict_types=1);

namespace App\Application\View;

use App\Domain\Permit\Model\Permit;

final readonly class PrintablePermit
{
    public function __construct(public Permit $permit, public string $url, public string $image) {}
}
