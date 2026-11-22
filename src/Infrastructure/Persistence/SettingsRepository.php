<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Configuration\Settings;
use App\Application\Port\SettingsProviderInterface;
use Doctrine\DBAL\Connection;

final readonly class SettingsRepository implements SettingsProviderInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function load(): Settings
    {
        $values = $this->connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings');
        $secret = self::required($values, 'signing_secret');
        $hmac = self::required($values, 'hmac_signing_key');
        $password = self::required($values, 'password_hash');
        $url = self::required($values, 'base_url');
        $timezone = self::required($values, 'timezone');
        // Reject invalid configuration before issuing permits.
        new \DateTimeZone($timezone);
        if (
            false === filter_var(
                $url,
                FILTER_VALIDATE_URL,
            ) || null === password_get_info($password)['algo']
            || 1 !== preg_match(pattern: '/\A[0-9a-fA-F]{64}\z/', subject: $hmac)
        ) {
            throw new \RuntimeException('Invalid application settings.');
        }

        /** @var string $hmacKey Validated as 64 hexadecimal characters above. */
        $hmacKey = hex2bin($hmac);

        return new Settings(
            secret: $secret,
            hmacKey: $hmacKey,
            passwordHash: $password,
            baseUrl: $url,
            timezone: $timezone,
        );
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return non-empty-string
     */
    private static function required(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new \RuntimeException('Application settings are incomplete. Run database migrations.');
        }

        return $value;
    }

    #[\Override]
    public function adminUsername(): string
    {
        $username = $this->connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'admin_username'",
        );
        if (!is_string($username) || '' === trim($username)) {
            throw new \RuntimeException('Admin settings are missing. Apply database migrations.');
        }

        return $username;
    }
}
