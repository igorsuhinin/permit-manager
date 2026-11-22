<?php

declare(strict_types=1);

namespace App\Http\Translation;

use App\Domain\Permit\Enum\PermitFailureReason;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PublicUi extends AbstractExtension
{
    /** @param array<string, string> $parameters */
    public function __construct(private readonly TranslatorInterface $translator, private readonly array $parameters) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_text', $this->text(...)),
            new TwigFunction('public_reason', $this->reason(...)),
        ];
    }

    /** @param array<string, string> $parameters */
    public function text(string $key, array $parameters = []): string
    {
        return $this->translator->trans(
            id: $key,
            parameters: $parameters + $this->parameters,
            domain: 'public',
            locale: 'lv',
        );
    }

    public function reason(PermitFailureReason $reason): string
    {
        return $this->text('reason.' . $reason->value);
    }
}
