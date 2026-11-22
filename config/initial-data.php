<?php

declare(strict_types=1);

use App\Application\Validator\InitialSettingsValidator;
use App\Infrastructure\Configuration\InitialSettingsFile;

/** @var string $settingsPath */
$settingsPath = require __DIR__ . '/initial-settings-path.php';
$settings = new InitialSettingsFile($settingsPath)->load();
new InitialSettingsValidator()->validate($settings);
$path = getenv('INITIAL_BLACKLIST_CONFIG');
$explicit = false !== $path && '' !== $path;
$path = $explicit ? $path : 'protected/initial-blacklist.php';
$path = str_starts_with(haystack: $path, needle: '/') ? $path : __DIR__ . '/../' . $path;
$ids = [];
if (is_file($path)) {
    /** @psalm-var mixed $ids */
    $ids = require $path;
} elseif ($explicit) {
    throw new RuntimeException('Initial blacklist configuration file does not exist.');
}
if (!is_array($ids) || !array_is_list($ids)) {
    throw new RuntimeException('Initial blacklist must be a list of non-empty permit IDs.');
}
foreach ($ids as $id) {
    if (!is_string($id) || '' === trim($id)) {
        throw new RuntimeException('Initial blacklist must be a list of non-empty permit IDs.');
    }
}
if (count(array_unique($ids)) !== count($ids)) {
    throw new RuntimeException('Initial blacklist must not contain duplicate IDs.');
}

return [
    'settings' => $settings->values(),
    'blacklisted_ids' => $ids,
];
