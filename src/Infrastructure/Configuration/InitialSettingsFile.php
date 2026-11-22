<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Application\Configuration\InitialSettings;
use App\Application\Exception\InitialSettingsAlreadyExistException;
use App\Application\Port\InitialSettingsStoreInterface;

final readonly class InitialSettingsFile implements InitialSettingsStoreInterface
{
    public function __construct(private string $path) {}

    #[\Override]
    public function create(InitialSettings $settings): string
    {
        if (file_exists($this->path) || is_link($this->path)) {
            throw new InitialSettingsAlreadyExistException();
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir(directory: $directory, permissions: 0700, recursive: true)) {
            throw new \RuntimeException('Cannot create the private initial settings directory.');
        }
        $handle = @fopen(filename: $this->path, mode: 'x');
        if (false === $handle) {
            throw new \RuntimeException('Cannot create the private initial settings file.');
        }
        $complete = false;
        try {
            if (!chmod(filename: $this->path, permissions: 0600)) {
                throw new \RuntimeException('Cannot protect the private initial settings file.');
            }
            $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($settings->values(), true) . ";\n";
            if (fwrite(stream: $handle, data: $contents) !== strlen($contents) || !fflush($handle)) {
                throw new \RuntimeException('Cannot save private initial settings.');
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete) {
                @unlink($this->path);
            }
        }

        return $this->path;
    }

    public function load(): InitialSettings
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException('Initial settings are missing. Run app:setup before database migrations.');
        }
        /** @psalm-var mixed $values */
        $values = require $this->path;
        $keys = ['admin_username', 'password_hash', 'signing_secret', 'hmac_signing_key', 'base_url', 'timezone'];
        $strings = [];
        foreach ($keys as $key) {
            $value = is_array($values) ? ($values[$key] ?? null) : null;
            if (!is_string($value)) {
                throw new \RuntimeException('Initial settings must contain all six string settings.');
            }
            $strings[$key] = $value;
        }

        return new InitialSettings(
            adminUsername: $strings['admin_username'],
            passwordHash: $strings['password_hash'],
            signingSecret: $strings['signing_secret'],
            hmacSigningKey: $strings['hmac_signing_key'],
            baseUrl: $strings['base_url'],
            timezone: $strings['timezone'],
        );
    }
}
