<?php

declare(strict_types=1);

namespace App\Http\Response;

use Twig\Environment;

final readonly class Renderer
{
    public function __construct(private Environment $twig) {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template . '.html.twig', $data);
    }
}
