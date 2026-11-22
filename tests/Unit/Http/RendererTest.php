<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Response\Renderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

#[Small]
#[CoversClass(Renderer::class)]
final class RendererTest extends TestCase
{
    public function testMissingTemplateDataRaisesAnError(): void
    {
        $twig = new Environment(new ArrayLoader(['valid.html.twig' => '{{ permit }}']), ['strict_variables' => true]);
        $this->expectException(RuntimeError::class);
        new Renderer($twig)->render('valid');
    }
}
