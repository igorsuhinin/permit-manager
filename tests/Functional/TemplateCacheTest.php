<?php

declare(strict_types=1);

namespace Tests\Functional;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;
use Tests\Support\Database;
use Twig\Environment;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class TemplateCacheTest extends TestCase
{
    private string $directory;
    /** @var array<string, string|false> */
    private array $environment = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/permit-twig-' . bin2hex(random_bytes(8));
        mkdir(directory: $this->directory . '/templates', permissions: 0700, recursive: true);
        file_put_contents(
            filename: $this->directory . '/ui.php',
            data: '<?php return ["messages" => ["cache-test" => "<unchanged>"]];',
        );
        foreach (
            [
                'APP_ENV' => 'production', 'TWIG_CACHE_ENABLED' => '',
                'TWIG_CACHE_DIRECTORY' => $this->directory . '/cache', 'TWIG_CACHE_VERSION' => '',
                'PUBLIC_TEMPLATE_DIRECTORY' => $this->directory . '/templates',
                'PUBLIC_UI_CONFIG' => $this->directory . '/ui.php',
            ] as $name => $value
        ) {
            $this->environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name . '=' . $value);
        }
        $files = new \RecursiveIteratorIterator(
            iterator: new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            mode: \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo|string $file */
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    private function templates(): Environment
    {
        /** @var Environment $templates */
        $templates = require __DIR__ . '/../../config/templates.php';

        return $templates;
    }

    #[DataProvider('environments')]
    public function testExplicitCacheDefaultsAndOverrides(string $environment, string $flag, bool $enabled): void
    {
        putenv('APP_ENV=' . $environment);
        putenv('TWIG_CACHE_ENABLED=' . $flag);
        $twig = $this->templates();
        self::assertSame($enabled, false !== $twig->getCache());
        self::assertSame(in_array($environment, ['local', 'test'], true), $twig->isAutoReload());
        self::assertFalse($twig->isDebug());
        self::assertTrue($twig->isStrictVariables());
        self::assertDirectoryDoesNotExist($this->directory . '/cache');
    }

    /** @return iterable<array{string, string, bool}> */
    public static function environments(): iterable
    {
        yield ['production', '', true];
        yield ['', '', true];
        yield ['local', '', false];
        yield ['test', '', false];
        yield ['browser', '', true];
        yield ['local', '1', true];
        yield ['production', '0', false];
    }

    public function testInvalidCacheFlagIsRejected(): void
    {
        putenv('TWIG_CACHE_ENABLED=yes');
        $this->expectException(\InvalidArgumentException::class);
        $this->templates();
    }

    public function testNamespaceIsStableButChangesForReleaseOrExplicitVersion(): void
    {
        $first = $this->templates()->getCache();
        self::assertIsString($first);
        self::assertSame($first, $this->templates()->getCache());
        putenv('TWIG_CACHE_VERSION=private-template-update');
        self::assertNotSame($first, $this->templates()->getCache());
        mkdir(directory: $this->directory . '/release/config', permissions: 0700, recursive: true);
        mkdir($this->directory . '/release/templates');
        copy(
            from: __DIR__ . '/../../config/templates.php',
            to: $this->directory . '/release/config/templates.php',
        );
        /**
         * @var Environment $other
         *
         * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
         */
        $other = require $this->directory . '/release/config/templates.php';
        self::assertNotSame($this->templates()->getCache(), $other->getCache());
        self::assertDirectoryDoesNotExist($this->directory . '/cache');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCachePreservesEscapingOverridesAndNoStoreAndReusesCompiledFiles(): void
    {
        file_put_contents(
            filename: $this->directory . '/templates/home.html.twig',
            data: 'Private template {{ public_text("cache-test") }}',
        );
        $app = ApplicationFactory::create(connection: Database::create());
        $request = new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/');
        $response = $app->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Private template &lt;unchanged&gt;', (string) $response->getBody());
        $path = $this->templates()->getCache();
        self::assertIsString($path);
        $files = glob($path . '/*/*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files);
        $algorithms = array_fill(start_index: 0, count: count($files), value: 'sha256');
        $before = array_map(hash_file(...), $algorithms, $files);
        self::assertSame((string) $response->getBody(), (string) $app->handle($request)->getBody());
        self::assertSame($before, array_map(hash_file(...), $algorithms, $files));
        putenv('TWIG_CACHE_ENABLED=0');
        $uncached = ApplicationFactory::create(connection: Database::create())->handle($request);
        self::assertSame((string) $response->getBody(), (string) $uncached->getBody());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnwritableCacheProducesGenericErrorAndPrivateCorrelatedDiagnostic(): void
    {
        file_put_contents(filename: $this->directory . '/blocked', data: 'unchanged');
        putenv('TWIG_CACHE_DIRECTORY=' . $this->directory . '/blocked');
        $logs = new TestHandler();
        $app = ApplicationFactory::create(
            logger: new Logger(name: 'test', handlers: [$logs]),
            connection: Database::create(),
        );
        $response = $app->handle(new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/'));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString($this->directory, (string) $response->getBody());
        self::assertTrue($logs->hasErrorRecords());
        self::assertSame($response->getHeaderLine('X-Request-ID'), $logs->getRecords()[0]->context['request_id']);
        self::assertSame('unchanged', file_get_contents($this->directory . '/blocked'));
    }
}
