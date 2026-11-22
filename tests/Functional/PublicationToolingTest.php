<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

#[Medium]
#[CoversNothing]
final class PublicationToolingTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/permit-publication-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo|string $file */
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
        }
        rmdir($this->directory);
    }

    private function file(string $path, string $contents): void
    {
        $directory = dirname($this->directory . '/' . $path);
        if (!is_dir($directory)) {
            mkdir(directory: $directory, permissions: 0777, recursive: true);
        }
        file_put_contents(filename: $this->directory . '/' . $path, data: $contents);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function execute(array $command, string $input = ''): array
    {
        $process = proc_open(
            command: $command,
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
            cwd: $this->directory,
        );
        self::assertIsResource($process);
        /* @var array{resource, resource, resource} $pipes */
        fwrite(stream: $pipes[0], data: $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($error);

        return [proc_close($process), $output . $error];
    }

    /** @return list<string> */
    private function sourceFixture(): array
    {
        $script = file_get_contents(__DIR__ . '/../../bin/build-source-review.sh');
        self::assertIsString($script);
        $this->file(path: 'bin/build-source-review.sh', contents: $script);
        $paths = [
            'LICENSE', 'docs/publication.md', 'composer.json', 'composer.lock', 'psalm.xml',
        ];
        foreach ($paths as $path) {
            $this->file(path: $path, contents: 'public fixture');
        }

        return [...$paths, 'bin/build-source-review.sh'];
    }

    /**
     * @param list<string> $paths
     *
     * @return array{int, string}
     */
    private function build(array $paths): array
    {
        return $this->execute(['bash', 'bin/build-source-review.sh'], implode(separator: "\0", array: $paths) . "\0");
    }

    public function testSourceCandidateExcludesOperationalStateAndIsReproducible(): void
    {
        $paths = $this->sourceFixture();
        $excluded = [
            '.git/config', '.env', 'protected/public-ui.php', 'protected/initial-settings.php', 'vendor/installed.php',
            '.phpunit.cache/report.json', '.psalm.cache/report.json',
            'dist/old.tar.gz', '.idea/workspace.xml', '.agents/context.md',
            'PROFESSIONAL_IMPROVEMENTS_PLAN.md', 'tests/Browser/node_modules/package/index.js',
        ];
        foreach ($excluded as $path) {
            $this->file(path: $path, contents: 'private fixture');
        }
        $this->file(path: 'src/public.php', contents: '<?php // public fixture');
        $paths = [...$paths, ...$excluded, 'src/public.php'];
        [$status, $output] = $this->build($paths);
        self::assertSame(0, $status, $output);
        $archive = 'dist/permit-manager-source-review.tar.gz';
        [$status, $listing] = $this->execute(['tar', '-tzf', $archive]);
        self::assertSame(0, $status);
        self::assertStringContainsString('./src/public.php', $listing);
        self::assertStringContainsString('./psalm.xml', $listing);
        foreach ($excluded as $path) {
            self::assertStringNotContainsString('./' . $path, $listing);
        }
        $hash = hash_file(algo: 'sha256', filename: $this->directory . '/' . $archive);
        [$status, $output] = $this->build($paths);
        self::assertSame(0, $status, $output);
        self::assertSame($hash, hash_file(algo: 'sha256', filename: $this->directory . '/' . $archive));
    }

    #[DataProvider('unsafeSource')]
    public function testUnsafeSourceInputsAreRejected(string $kind): void
    {
        $paths = $this->sourceFixture();
        $path = 'src/private.key';
        if ('private file' === $kind) {
            $this->file(path: $path, contents: 'private fixture');
        } elseif ('file symlink' === $kind) {
            $this->file(path: 'private.txt', contents: 'private fixture');
            mkdir($this->directory . '/src');
            $path = 'src/linked.php';
            symlink(target: '../private.txt', link: $this->directory . '/' . $path);
        } elseif ('parent symlink' === $kind) {
            $this->file(path: 'private-source/linked.php', contents: 'private fixture');
            symlink(target: 'private-source', link: $this->directory . '/src');
            $path = 'src/linked.php';
        } else {
            $path = 'src/missing.php';
        }
        [$status] = $this->build([...$paths, $path]);
        self::assertSame(1, $status);
        self::assertFileDoesNotExist($this->directory . '/dist/permit-manager-source-review.tar.gz');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeSource(): iterable
    {
        foreach (['private file', 'file symlink', 'parent symlink', 'missing file'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('assetChanges')]
    public function testAssetInventoryProtectsLegacyFiles(string $change, int $expectedStatus): void
    {
        $script = file_get_contents(__DIR__ . '/../../bin/check-assets.php');
        self::assertIsString($script);
        $this->file(path: 'bin/check-assets.php', contents: $script);
        $this->file(path: 'public/vendor/style.css', contents: 'fixture');
        $this->file(path: 'public/permit/assets/font.woff', contents: 'fixture');
        $entry = [
            'path' => 'vendor/style.css', 'url' => 'https://example.com/style.css',
            'sha256' => hash(algo: 'sha256', data: 'fixture'), 'license' => 'MIT', 'version' => '1',
        ];
        $legacy = array_replace($entry, [
            'path' => 'permit/assets/font.woff', 'url' => null, 'license' => 'NOASSERTION',
        ]);
        $manifest = ['files' => [$entry], 'legacy_files' => [$legacy]];
        if ('modified' === $change) {
            $this->file(path: 'public/permit/assets/font.woff', contents: 'modified');
        } elseif ('unrecorded' === $change) {
            $this->file(path: 'public/permit/assets/other.woff', contents: 'fixture');
        } elseif ('missing inventory' === $change) {
            unset($manifest['legacy_files']);
        } elseif ('unverified vendor' === $change) {
            $manifest['files'][0]['url'] = null;
            $manifest['files'][0]['license'] = 'NOASSERTION';
        }
        $this->file(path: 'assets.lock.json', contents: json_encode(value: $manifest, flags: JSON_THROW_ON_ERROR));
        [$status, $output] = $this->execute([PHP_BINARY, 'bin/check-assets.php']);
        self::assertSame($expectedStatus, $status, $output);
    }

    /** @return iterable<string, array{string, int}> */
    public static function assetChanges(): iterable
    {
        foreach (['unchanged', 'modified', 'unrecorded', 'missing inventory', 'unverified vendor'] as $change) {
            yield $change => [$change, 'unchanged' === $change ? 0 : 1];
        }
    }
}
