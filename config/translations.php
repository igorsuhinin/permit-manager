<?php

declare(strict_types=1);

use App\Http\Translation\AdminLanguage;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;

$translator = new Translator('en');
$translator->setFallbackLocales(['en']);
$translator->addLoader('php', new PhpFileLoader());
foreach (array_keys(AdminLanguage::LANGUAGES) as $locale) {
    $translator->addResource(
        format: 'php',
        resource: __DIR__ . '/../translations/admin.' . $locale . '.php',
        locale: $locale,
        domain: 'admin',
    );
}

/** @var array{messages: array<string, string>, parameters: array<string, string>} $publicUi */
$publicUi = require __DIR__ . '/public-ui.php';
$translator->addLoader('array', new ArrayLoader());
$translator->addResource(format: 'array', resource: $publicUi['messages'], locale: 'lv', domain: 'public');

return $translator;
