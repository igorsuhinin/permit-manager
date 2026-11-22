<?php

declare(strict_types=1);

namespace Tests\Support;

final class InitialSettingsFixture
{
    /** @param array<string, mixed> $overrides */
    public static function withConfiguration(array $overrides, \Closure $run): void
    {
        /** @var array<string, string> $settings */
        $settings = require __DIR__ . '/../Fixtures/initial-settings.php';
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'permit-initial-');
        if (false === $path) {
            throw new \RuntimeException('Could not create the isolated initial settings fixture.');
        }
        $previous = getenv('INITIAL_SETTINGS_CONFIG');
        try {
            file_put_contents(
                filename: $path,
                data: '<?php return ' . var_export(array_replace($settings, $overrides), true) . ';',
            );
            putenv('INITIAL_SETTINGS_CONFIG=' . $path);
            $run();
        } finally {
            putenv(false === $previous ? 'INITIAL_SETTINGS_CONFIG' : 'INITIAL_SETTINGS_CONFIG=' . $previous);
            unlink($path);
        }
    }
}
