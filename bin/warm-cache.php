<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

try {
    require __DIR__ . '/../vendor/autoload.php';
    // Compile private templates with restrictive defaults; do not render pages or load database settings.
    umask(0077);
    $builder = new ContainerBuilder();
    $builder->addDefinitions(__DIR__ . '/../config/container.php');
    /** @var Environment $twig */
    $twig = $builder->build()->get(Environment::class);
    if (false === $twig->getCache()) {
        echo "Twig cache is disabled; no files were written.\n";
        exit;
    }
    $loader = $twig->getLoader();
    if (!$loader instanceof FilesystemLoader) {
        throw new RuntimeException('Cache warming requires the configured filesystem loader.');
    }
    $names = [];
    foreach ($loader->getPaths() as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        /** @var SplFileInfo|string $file */
        foreach ($files as $file) {
            if (
                $file instanceof SplFileInfo && $file->isFile()
                && str_ends_with(haystack: $file->getFilename(), needle: '.twig')
            ) {
                $names[substr(string: $file->getPathname(), offset: strlen($directory) + 1)] = true;
            }
        }
    }
    ksort($names);
    foreach (array_keys($names) as $name) {
        $twig->load($name);
    }
    echo 'Twig cache warmed: ' . count($names) . " templates.\n";
} catch (Throwable $exception) {
    error_log('Template cache warmup failed: ' . $exception::class);
    fwrite(stream: STDERR, data: "Template cache warmup failed. Check private configuration and cache permissions.\n");
    exit(1);
}
