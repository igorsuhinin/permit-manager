<?php

declare(strict_types=1);

namespace App\Application\Authentication;

final readonly class AdminCredentials
{
    public function __construct(public string $username, private string $passwordHash) {}

    public function matches(string $username, string $password): bool
    {
        return hash_equals(
            known_string: $this->username,
            user_string: $username,
        ) && password_verify(password: $password, hash: $this->passwordHash);
    }

    public function fingerprint(): string
    {
        return hash(algo: 'sha256', data: $this->username . "\0" . $this->passwordHash);
    }
}
