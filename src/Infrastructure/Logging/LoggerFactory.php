<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    public function create(?string $path = null, Level $level = Level::Info, int $retention = 14): Logger
    {
        $handler = null === $path
            ? new ErrorLogHandler(level: $level)
            : new RotatingFileHandler(filename: $path, maxFiles: $retention, level: $level);
        $handler->setFormatter(new JsonFormatter(includeStacktraces: true));

        return new Logger(name: 'application', handlers: [$handler]);
    }
}
