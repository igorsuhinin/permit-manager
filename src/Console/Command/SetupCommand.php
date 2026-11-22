<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Command\PrepareInitialSettingsCommand;
use App\Application\Command\PrepareInitialSettingsHandler;
use App\Application\Exception\InitialSettingsAlreadyExistException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:setup', description: 'Create private initial settings without changing the database.')]
final readonly class SetupCommand
{
    public function __construct(private PrepareInitialSettingsHandler $prepare) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'Initial administrator username (default: admin).')]
        ?string $adminUsername = null,
        #[Option(description: 'Base URL for QR links (default: http://localhost:8080).')]
        ?string $baseUrl = null,
        #[Option(description: 'Application timezone (default: Europe/Riga).')]
        ?string $timezone = null,
    ): int {
        try {
            $password = $secret = $key = null;
            if ($input->isInteractive()) {
                $adminUsername ??= $this->answer(io: $io, question: 'Admin username', default: 'admin');
                $baseUrl ??= $this->answer(io: $io, question: 'Base URL', default: 'http://localhost:8080');
                $timezone ??= $this->answer(io: $io, question: 'Timezone', default: 'Europe/Riga');
                $password = $this->secret($io, 'Admin password (blank generates a password)');
                $secret = $this->secret($io, 'Existing legacy signing secret (blank generates a new secret)');
                $key = $this->secret($io, 'Existing HMAC key in hex (blank generates a new key)');
            }
            $result = $this->prepare->handle(new PrepareInitialSettingsCommand(
                adminUsername: $adminUsername ?? 'admin',
                baseUrl: $baseUrl ?? 'http://localhost:8080',
                timezone: $timezone ?? 'Europe/Riga',
                password: $password,
                signingSecret: $secret,
                hmacSigningKey: $key,
            ));
            $io->success('Private initial settings created. No database settings were changed.');
            $io->text('Configuration: ' . OutputFormatter::escape($result->path));
            $io->text('Admin username: ' . OutputFormatter::escape($result->adminUsername));
            if (null !== $result->generatedPassword) {
                $io->text('Generated password: ' . $result->generatedPassword);
                $io->note('Save this password securely now; only its hash is stored.');
            }
            $io->note('Run database migrations to initialize a fresh database. Existing databases remain unchanged.');

            return Command::SUCCESS;
        } catch (InitialSettingsAlreadyExistException) {
            $io->error('Initial settings already exist. They have not been overwritten.');

            return Command::FAILURE;
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        } catch (\Throwable $exception) {
            error_log('Initial settings setup failed: ' . $exception::class);
            $io->error('Setup failed. Check the private configuration path and filesystem permissions.');

            return Command::FAILURE;
        }
    }

    private function answer(SymfonyStyle $io, string $question, string $default): string
    {
        $value = $io->ask(question: $question, default: $default);
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Enter a text value.');
        }

        return $value;
    }

    private function secret(SymfonyStyle $io, string $question): ?string
    {
        $prompt = new Question($question);
        $prompt->setHidden(true)->setTrimmable(false);
        /** @psalm-var mixed $value */
        $value = $io->askQuestion($prompt);
        if (is_string($value)) {
            $value = rtrim(string: $value, characters: "\r\n");
        }

        return is_string($value) && '' !== $value ? $value : null;
    }
}
