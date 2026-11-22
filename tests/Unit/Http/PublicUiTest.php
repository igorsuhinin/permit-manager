<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\Permit\Enum\PermitFailureReason;
use App\Http\Translation\PublicUi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader as TwigArrayLoader;

#[Small]
#[CoversClass(PublicUi::class)]
final class PublicUiTest extends TestCase
{
    public function testPrivateParametersAreEscapedAndPublicLanguageStaysLatvian(): void
    {
        $translator = new Translator('ru');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource(
            format: 'array',
            resource: [
                'parking' => '%id%: %location%',
                'reason.revoked' => 'Anulēta',
                'reason.request' => 'Nederīgs pieprasījums',
            ],
            locale: 'lv',
            domain: 'public',
        );
        $translator->addResource(
            format: 'array',
            resource: ['parking' => 'Wrong language'],
            locale: 'ru',
            domain: 'public',
        );
        $ui = new PublicUi($translator, ['%location%' => '<b>Privāta teritorija</b>']);
        $twig = new Environment(
            new TwigArrayLoader(['test' => "{{ public_text('parking', {'%id%': id}) }}"]),
            ['autoescape' => 'html'],
        );
        $twig->addExtension($ui);
        self::assertSame('26/1: &lt;b&gt;Privāta teritorija&lt;/b&gt;', $twig->render('test', ['id' => '26/1']));
        self::assertSame('26/1: Other', $ui->text('parking', ['%id%' => '26/1', '%location%' => 'Other']));
        self::assertSame('Anulēta', $ui->reason(PermitFailureReason::Revoked));
        self::assertSame('Nederīgs pieprasījums', $ui->reason(PermitFailureReason::InvalidRequest));
    }

    public function testEveryFailureReasonHasALatvianMessage(): void
    {
        $translator = new Translator('ru');
        $translator->addLoader('array', new ArrayLoader());
        /** @var array<string, string> $messages */
        $messages = require __DIR__ . '/../../../translations/public.lv.php';
        $translator->addResource(format: 'array', resource: $messages, locale: 'lv', domain: 'public');
        $ui = new PublicUi($translator, []);
        foreach (PermitFailureReason::cases() as $reason) {
            self::assertNotSame('reason.' . $reason->value, $ui->reason($reason));
        }
    }

    #[CoversNothing]
    public function testPrivateConfigOverridesKeepUnspecifiedDefaults(): void
    {
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'public-ui-');
        self::assertNotFalse($path);
        $previous = getenv('PUBLIC_UI_CONFIG');
        file_put_contents(
            filename: $path,
            data: ('<?php return [\'messages\' => [\'check.title\' => \'Piemēra pārbaude\'], \'parameters\' => '
                . '[\'%location%\' => \'Piemēra teritorijā\']];'),
        );
        putenv('PUBLIC_UI_CONFIG=' . $path);
        try {
            /** @var array{messages: array<string, string>, parameters: array<string, string>} $config */
            $config = require __DIR__ . '/../../../config/public-ui.php';
            self::assertSame('Piemēra pārbaude', $config['messages']['check.title']);
            self::assertSame('Piemēra teritorijā', $config['parameters']['%location%']);
            self::assertSame('Ir derīga', $config['messages']['check.valid']);
            self::assertSame('autostāvvietas teritorijā', $config['parameters']['%guest_location%']);
        } finally {
            putenv(false === $previous ? 'PUBLIC_UI_CONFIG' : 'PUBLIC_UI_CONFIG=' . $previous);
            unlink($path);
        }
    }

    #[CoversNothing]
    public function testInvalidPrivateConfigIsRejectedWithoutExposingItsValues(): void
    {
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'public-ui-');
        self::assertNotFalse($path);
        $previous = getenv('PUBLIC_UI_CONFIG');
        file_put_contents(filename: $path, data: "<?php return ['messages' => ['private-value' => ['invalid']]];");
        putenv('PUBLIC_UI_CONFIG=' . $path);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('Public UI values must be string maps.');
        try {
            require __DIR__ . '/../../../config/public-ui.php';
        } finally {
            putenv(false === $previous ? 'PUBLIC_UI_CONFIG' : 'PUBLIC_UI_CONFIG=' . $previous);
            unlink($path);
        }
    }
}
