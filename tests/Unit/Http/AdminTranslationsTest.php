<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Translation\AdminLanguage;
use App\Http\Translation\AdminTranslationExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

#[Small]
#[CoversClass(AdminTranslationExtension::class)]
final class AdminTranslationsTest extends TestCase
{
    public function testCataloguesHaveMatchingKeysAndPlaceholders(): void
    {
        /** @var array<string, string> $english */
        $english = require __DIR__ . '/../../../translations/admin.en.php';
        foreach (array_keys(AdminLanguage::LANGUAGES) as $locale) {
            /**
             * @var array<string, string> $messages
             *
             * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
             */
            $messages = require __DIR__ . '/../../../translations/admin.' . $locale . '.php';
            self::assertSame(array_keys($english), array_keys($messages));
            foreach ($english as $key => $source) {
                self::assertNotSame('', $messages[$key]);
                preg_match_all(pattern: '/%[a-z]+%/', subject: $source, matches: $expected);
                preg_match_all(pattern: '/%[a-z]+%/', subject: $messages[$key], matches: $actual);
                self::assertSame($expected[0], $actual[0], $locale . ':' . $key);
            }
        }
    }

    public function testEnglishFallbackAndExplicitLocalesDoNotLeakBetweenRequests(): void
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource(
            format: 'array',
            resource: ['greeting' => 'Hello %name%', 'fallback' => 'English'],
            locale: 'en',
            domain: 'admin',
        );
        $translator->addResource(
            format: 'array',
            resource: ['greeting' => 'Sveiki, %name%'],
            locale: 'lv',
            domain: 'admin',
        );
        $extension = new AdminTranslationExtension($translator);
        self::assertSame(
            'Sveiki, Anna',
            $extension->translate(key: 'greeting', locale: 'lv', parameters: ['%name%' => 'Anna']),
        );
        self::assertSame(
            'Hello Anna',
            $extension->translate(key: 'greeting', locale: 'en', parameters: ['%name%' => 'Anna']),
        );
        self::assertSame('English', $extension->translate(key: 'fallback', locale: 'lv'));
    }
}
