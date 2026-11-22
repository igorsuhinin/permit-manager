<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/** @var Twig\Environment $twig */
$twig = require __DIR__ . '/../config/templates.php';
/** @var Symfony\Contracts\Translation\TranslatorInterface $translator */
$translator = require __DIR__ . '/../config/translations.php';
$twig->addExtension(new App\Http\Translation\AdminTranslationExtension($translator));
/** @var array{messages: array<string, string>, parameters: array<string, string>} $config */
$config = require __DIR__ . '/../config/public-ui.php';
$twig->addExtension(new App\Http\Translation\PublicUi($translator, $config['parameters']));
/** @var Twig\Loader\FilesystemLoader $loader */
$loader = $twig->getLoader();
foreach ($loader->getPaths() as $directory) {
    if (!is_dir($directory)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo|string $file */
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !str_ends_with(haystack: $file->getFilename(), needle: '.twig')) {
            continue;
        }
        $name = substr(string: $file->getPathname(), offset: strlen($directory) + 1);
        $twig->compileSource(new Twig\Source(
            code: (string) file_get_contents($file->getPathname()),
            name: $name,
            path: $file->getPathname(),
        ));
    }
}
echo "Twig templates are valid.\n";
