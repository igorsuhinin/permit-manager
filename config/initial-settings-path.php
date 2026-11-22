<?php

declare(strict_types=1);

return (static function (): string {
    $path = getenv('INITIAL_SETTINGS_CONFIG');
    $path = false === $path || '' === $path ? 'protected/initial-settings.php' : $path;

    return str_starts_with(haystack: $path, needle: '/') ? $path : dirname(__DIR__) . '/' . $path;
})();
