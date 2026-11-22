<?php

declare(strict_types=1);

namespace App\Infrastructure\Diagnostics;

use App\Application\Enum\DiagnosticStatus;
use App\Application\View\DiagnosticCheck;
use App\Infrastructure\Logging\SentryHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Sentry\Dsn;
use Twig\Environment;

final readonly class ConfigurationDiagnostics
{
    /**
     * @param \Closure(): Environment              $templates
     * @param \Closure(): mixed                    $publicUi
     * @param \Closure(): array{lock_file: string} $maintenance
     * @param \Closure(): LoggerInterface          $logger
     */
    public function __construct(
        private string $rootDirectory,
        private \Closure $templates,
        private \Closure $publicUi,
        private \Closure $maintenance,
        private \Closure $logger,
    ) {}

    /** @return list<DiagnosticCheck> */
    public function inspect(): array
    {
        return [
            $this->check(
                name: 'Public wording',
                probe: function (): string {
                    $this->overridePath(name: 'PUBLIC_UI_CONFIG', fallback: 'protected/public-ui.php');
                    ($this->publicUi)();

                    return 'Configuration is readable and valid; shared defaults are allowed.';
                },
                failure: 'Selected public wording configuration is missing, unreadable, or invalid.',
            ),
            $this->check(
                name: 'Templates',
                probe: $this->templates(...),
                failure: 'Selected template directory or template syntax is invalid or unreadable.',
            ),
            $this->check(
                name: 'Twig cache',
                probe: function (): string {
                    $cache = $this->templateEnvironment()->getCache();
                    if (false === $cache) {
                        return 'Compilation cache is disabled; no writable directory is required.';
                    }
                    if (!is_string($cache)) {
                        throw new \RuntimeException('Custom cache requires manual inspection.');
                    }
                    $this->writable(path: $cache, directory: true);

                    return 'Directory permissions checked; no cache files were created.';
                },
                failure: 'Cache configuration or directory permissions need review.',
            ),
            $this->check(
                name: 'Maintenance lock',
                probe: function (): string {
                    $this->writable(path: ($this->maintenance)()['lock_file'], directory: false);

                    return 'Target permissions checked; no directory, lock file, or lock was created.';
                },
                failure: 'Maintenance lock configuration or target permissions need review.',
            ),
            $this->check(
                name: 'Logger',
                probe: $this->logger(...),
                failure: 'Logger configuration or target permissions need review; custom sinks need manual inspection.',
            ),
        ];
    }

    private function templates(): string
    {
        $override = $this->overridePath(
            name: 'PUBLIC_TEMPLATE_DIRECTORY',
            fallback: 'protected/templates',
            directory: true,
        );
        $twig = $this->templateEnvironment();
        $directories = [$this->rootDirectory . '/templates'];
        if (null !== $override) {
            $directories[] = $override;
        }
        foreach (array_unique($directories) as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ));
            /** @var \SplFileInfo|string $file */
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || 'twig' !== $file->getExtension()) {
                    continue;
                }
                $name = substr(string: $file->getPathname(), offset: strlen($directory) + 1);
                $twig->parse($twig->tokenize($twig->getLoader()->getSourceContext($name)));
            }
        }

        return 'Shared and selected override templates parse successfully; no compilation cache was written.';
    }

    private function logger(): string
    {
        $dsn = getenv('SENTRY_DSN');
        $sentryExpected = false !== $dsn && '' !== $dsn;
        if ($sentryExpected) {
            Dsn::createFromString($dsn);
        }
        $sentryPresent = false;
        $logger = ($this->logger)();
        if (!$logger instanceof Logger || [] === $logger->getHandlers()) {
            throw new \RuntimeException('Logger requires manual inspection.');
        }
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof SentryHandler) {
                $sentryPresent = true;
                continue;
            }
            if ($handler instanceof ErrorLogHandler) {
                continue;
            }
            if (!$handler instanceof StreamHandler) {
                throw new \RuntimeException('Custom sink requires manual inspection.');
            }
            $url = $handler->getUrl();
            if (null === $url) {
                throw new \RuntimeException('Custom sink requires manual inspection.');
            }
            if (!str_contains(haystack: $url, needle: '://')) {
                $this->writable(path: $url, directory: false);
            }
        }
        if ($sentryExpected && !$sentryPresent) {
            throw new \RuntimeException('Configured telemetry handler is unavailable.');
        }

        return 'Configured sinks inspected; no log message was emitted and delivery was not probed.';
    }

    private function templateEnvironment(): Environment
    {
        $this->overridePath(name: 'PUBLIC_UI_CONFIG', fallback: 'protected/public-ui.php');

        return ($this->templates)();
    }

    private function overridePath(string $name, string $fallback, bool $directory = false): ?string
    {
        $value = getenv($name);
        $explicit = false !== $value && '' !== $value;
        $path = $explicit ? $value : $fallback;
        $path = $this->absolute($path);
        if (!file_exists($path) && !is_link($path) && !$explicit) {
            return null;
        }
        if (!is_readable($path) || ($directory ? !is_dir($path) : !is_file($path))) {
            throw new \RuntimeException('Override is missing or unreadable.');
        }

        return $path;
    }

    private function writable(string $path, bool $directory): void
    {
        if ('' === $path) {
            throw new \RuntimeException('Empty destination.');
        }
        $target = $this->absolute($path);
        if (file_exists($target) || is_link($target)) {
            if (($directory ? !is_dir($target) : !is_file($target)) || !is_writable($target)) {
                throw new \RuntimeException('Destination is not writable.');
            }
            $target = $directory ? $target : dirname($target);
        } else {
            while (!file_exists($target) && !is_link($target)) {
                $target = dirname($target);
            }
        }
        if (!is_dir($target) || !is_writable($target) || !is_executable($target)) {
            throw new \RuntimeException('Parent directory is not writable and searchable.');
        }
    }

    private function absolute(string $path): string
    {
        return str_starts_with(haystack: $path, needle: '/') ? $path : $this->rootDirectory . '/' . $path;
    }

    /** @param \Closure(): string $probe */
    private function check(string $name, \Closure $probe, string $failure): DiagnosticCheck
    {
        ob_start();
        try {
            $message = $probe();
            if ('' !== ob_get_contents()) {
                throw new \RuntimeException('Configuration emitted unexpected output.');
            }

            return new DiagnosticCheck(name: $name, status: DiagnosticStatus::Passed, message: $message);
        } catch (\Throwable) {
            return new DiagnosticCheck(name: $name, status: DiagnosticStatus::Failed, message: $failure);
        } finally {
            ob_end_clean();
        }
    }
}
