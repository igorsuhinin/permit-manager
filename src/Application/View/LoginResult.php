<?php

declare(strict_types=1);

namespace App\Application\View;

use App\Application\Authentication\AdminSession;

final readonly class LoginResult
{
    public function __construct(public ?AdminSession $session = null, public int $retryAfter = 0) {}
}
