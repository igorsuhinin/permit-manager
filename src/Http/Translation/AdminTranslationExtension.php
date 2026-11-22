<?php

declare(strict_types=1);

namespace App\Http\Translation;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class AdminTranslationExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('trans', $this->translate(...))];
    }

    /** @param array<string, string|int> $parameters */
    public function translate(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans(id: $key, parameters: $parameters, domain: 'admin', locale: $locale);
    }
}
