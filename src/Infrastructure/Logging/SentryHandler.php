<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\Monolog\LogsHandler;
use Sentry\State\HubInterface;

final class SentryHandler extends WhatFailureGroupHandler
{
    public function __construct(HubInterface $hub)
    {
        parent::__construct([
            new ExceptionToSentryIssueHandler(hub: $hub, level: Level::Error),
            new LogsHandler(Level::Info),
        ]);
    }
}
