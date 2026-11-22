<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Application\Authentication\AdminSession;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminSessionAccessor
{
    public function get(ServerRequestInterface $request): AdminSession
    {
        $session = $request->getAttribute(AdminSession::class);
        if (!$session instanceof AdminSession) {
            throw new \LogicException('Admin session middleware is missing.');
        }

        return $session;
    }
}
