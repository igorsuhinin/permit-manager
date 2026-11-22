<?php

declare(strict_types=1);

namespace App\Application\Validator;

use App\Application\Configuration\InitialSettings;

final readonly class InitialSettingsValidator
{
    public function validate(InitialSettings $settings): void
    {
        if (
            '' === trim($settings->adminUsername) || strlen($settings->adminUsername) > 255
            || 1 === preg_match(pattern: '/[\x00-\x1f\x7f]/', subject: $settings->adminUsername)
        ) {
            throw new \InvalidArgumentException('Provide an admin username without control characters (1–255 bytes).');
        }
        if (null === password_get_info($settings->passwordHash)['algo']) {
            throw new \InvalidArgumentException('Provide a valid admin password hash.');
        }
        if ('' === $settings->signingSecret) {
            throw new \InvalidArgumentException('Provide a non-empty legacy signing secret.');
        }
        if (1 !== preg_match(pattern: '/\A[0-9a-fA-F]{64}\z/', subject: $settings->hmacSigningKey)) {
            throw new \InvalidArgumentException('The HMAC signing key must contain exactly 64 hexadecimal characters.');
        }
        if (
            false === filter_var($settings->baseUrl, FILTER_VALIDATE_URL)
            || !in_array(parse_url($settings->baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || null !== parse_url($settings->baseUrl, PHP_URL_QUERY)
            || null !== parse_url($settings->baseUrl, PHP_URL_FRAGMENT)
            || null !== parse_url($settings->baseUrl, PHP_URL_USER)
        ) {
            throw new \InvalidArgumentException('Provide an HTTP(S) base URL without credentials, query, or fragment.');
        }
        try {
            if ('' !== $settings->timezone) {
                new \DateTimeZone($settings->timezone);

                return;
            }
        } catch (\Exception) {
            // Empty and unknown timezones share the validation error below.
        }
        throw new \InvalidArgumentException('Provide a valid timezone.');
    }
}
