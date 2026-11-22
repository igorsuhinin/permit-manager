<?php

declare(strict_types=1);

/** @return array{messages: array<string, string>, parameters: array<string, string>} */

return (static function (): array {
    /** @var array<string, string> $messages */
    $messages = require __DIR__ . '/../translations/public.lv.php';
    $defaults = [
        'messages' => $messages,
        'parameters' => [
            '%location%' => 'autostāvvietas teritorijā',
            '%check_location%' => 'autostāvvietas teritorijā',
            '%guest_location%' => 'autostāvvietas teritorijā',
            '%basis%' => 'apstiprinātajiem noteikumiem',
        ],
    ];
    $path = getenv('PUBLIC_UI_CONFIG');
    $explicit = false !== $path && '' !== $path;
    $path = $explicit ? $path : 'protected/public-ui.php';
    $path = str_starts_with(haystack: $path, needle: '/') ? $path : __DIR__ . '/../' . $path;
    if (!is_file($path)) {
        if ($explicit) {
            throw new RuntimeException('Public UI configuration file does not exist.');
        }

        return $defaults;
    }
    $overrides = require $path;
    if (!is_array($overrides) || [] !== array_diff(array_keys($overrides), ['messages', 'parameters'])) {
        throw new RuntimeException('Invalid public UI configuration.');
    }
    foreach ($overrides as $section => $values) {
        if (!is_array($values)) {
            throw new RuntimeException('Public UI values must be string maps.');
        }
        foreach ($values as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('Public UI values must be string maps.');
            }
            $defaults[$section][$key] = $value;
        }
    }

    return $defaults;
})();
