<?php

declare(strict_types=1);

namespace App\Http\Translation;

use Psr\Http\Message\ServerRequestInterface;

final class AdminLanguage
{
    public const array LANGUAGES = ['en' => 'English', 'lv' => 'Latviešu', 'ru' => 'Русский'];
    public const string COOKIE = 'admin_language';

    public function resolve(ServerRequestInterface $request): string
    {
        /** @var mixed $locale */
        foreach (
            [
                $request->getQueryParams()['lang'] ?? null,
                $request->getCookieParams()[self::COOKIE] ?? null,
            ] as $locale
        ) {
            if (is_string($locale) && isset(self::LANGUAGES[$locale])) {
                return $locale;
            }
        }

        return 'en';
    }

    /** @return array<string, array{label: string, url: string}> */
    public function links(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $links = [];
        foreach (self::LANGUAGES as $locale => $label) {
            $links[$locale] = [
                'label' => $label,
                'url' => $request->getUri()->getPath() . '?' . http_build_query(array_replace(
                    $query,
                    ['lang' => $locale],
                )),
            ];
        }

        return $links;
    }
}
