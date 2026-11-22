<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[Medium]
#[CoversNothing]
final class WorkflowTest extends TestCase
{
    /**
     * @return array{
     *     on: array<string, mixed>, permissions: array<string, string>,
     *     jobs: array<string, array{
     *         name: string, needs?: string|list<string>, if?: string,
     *         permissions?: array<string, string>, env?: array<string, string>,
     *         steps: list<array<string, mixed>>
     *     }>
     * }
     */
    private function workflow(): array
    {
        /** @var array{
         *     on: array<string, mixed>, permissions: array<string, string>,
         *     jobs: array<string, array{
         *         name: string, needs?: string|list<string>, if?: string,
         *         permissions?: array<string, string>, env?: array<string, string>,
         *         steps: list<array<string, mixed>>
         *     }>
         * } $workflow
         */
        $workflow = Yaml::parseFile(__DIR__ . '/../../.github/workflows/quality.yaml');

        return $workflow;
    }

    public function testEverySupportedEventRequiresMutationAndReleaseVerification(): void
    {
        $workflow = $this->workflow();
        $events = array_keys($workflow['on']);
        sort($events);
        self::assertSame(['merge_group', 'pull_request', 'push', 'schedule', 'workflow_dispatch'], $events);
        self::assertNull($workflow['on']['push']);
        self::assertNull($workflow['on']['pull_request']);
        $jobs = $workflow['jobs'];
        self::assertArrayNotHasKey('if', $jobs['quality']);
        self::assertArrayNotHasKey('if', $jobs['mutation']);
        self::assertArrayNotHasKey('if', $jobs['release']);
        self::assertSame(['quality', 'mutation'], $jobs['release']['needs'] ?? null);
        self::assertSame(['quality', 'mutation', 'release'], $jobs['required']['needs'] ?? null);
        self::assertSame('${{ always() }}', $jobs['required']['if'] ?? null);
        self::assertSame('Required checks', $jobs['required']['name']);
        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        foreach ($jobs as $id => $job) {
            self::assertArrayNotHasKey('continue-on-error', $job);
            if ('publish' !== $id) {
                self::assertSame(['contents' => 'read'], $job['permissions'] ?? $workflow['permissions']);
            }
            foreach ($job['steps'] as $step) {
                self::assertArrayNotHasKey('continue-on-error', $step);
                if (isset($step['uses'])) {
                    self::assertIsString($step['uses']);
                    self::assertThat(
                        $step['uses'],
                        self::matchesRegularExpression('/\A[\w.-]+\/[\w.-]+@[a-f0-9]{40}\z/'),
                    );
                }
            }
        }
        $gate = array_last($jobs['required']['steps']);
        self::assertNotNull($gate);
        self::assertSame('bash bin/check-ci-results.sh', $gate['run']);
        self::assertSame([
            'CI_QUALITY_RESULT' => '${{ needs.quality.result }}',
            'CI_MUTATION_RESULT' => '${{ needs.mutation.result }}',
            'CI_RELEASE_RESULT' => '${{ needs.release.result }}',
        ], $gate['env']);
    }

    public function testReleaseUploadFollowsAllRequiredVerificationSteps(): void
    {
        $jobs = $this->workflow()['jobs'];
        self::assertContains('make check', array_column($jobs['quality']['steps'], 'run'));
        self::assertContains('make browser-check', array_column($jobs['quality']['steps'], 'run'));
        self::assertContains('make test-mysql', array_column($jobs['quality']['steps'], 'run'));
        self::assertContains('make infection', array_column($jobs['mutation']['steps'], 'run'));
        $steps = $jobs['release']['steps'];
        $uploadPosition = null;
        foreach ($steps as $index => $step) {
            if ('Upload verified release' === ($step['name'] ?? null)) {
                $uploadPosition = $index;
                self::assertArrayNotHasKey('if', $step);
            }
        }
        self::assertNotNull($uploadPosition);
        foreach (['make release', 'make release-smoke', 'make test-browser'] as $command) {
            $positions = [];
            foreach ($steps as $index => $step) {
                if ($command === ($step['run'] ?? null)) {
                    self::assertArrayNotHasKey('if', $step);
                    $positions[] = $index;
                }
            }
            self::assertCount(1, $positions);
            self::assertLessThan($uploadPosition, $positions[0]);
        }
    }

    public function testReleasePublicationRequiresAcceptedVersionTagAndLimitedPermissions(): void
    {
        $publication = $this->workflow()['jobs']['publish'];
        self::assertSame(['required'], $publication['needs'] ?? null);
        self::assertSame(
            "\${{ github.event_name == 'push' && startsWith(github.ref, 'refs/tags/v') }}",
            $publication['if'] ?? null,
        );
        self::assertSame(['actions' => 'read', 'contents' => 'write'], $publication['permissions'] ?? null);
        self::assertSame([
            'GH_TOKEN' => '${{ github.token }}',
            'GH_REPO' => '${{ github.repository }}',
            'RELEASE_TAG' => '${{ github.ref_name }}',
        ], $publication['env'] ?? null);
        foreach ($publication['steps'] as $step) {
            if (isset($step['uses'])) {
                self::assertIsString($step['uses']);
                self::assertFalse(str_starts_with($step['uses'], 'actions/checkout@'));
            }
        }
    }

    public function testDependencyAutomationCoversRuntimeToolsAndCoordinatedBrowserUpdates(): void
    {
        /** @var array{
         *     version: int, multi-ecosystem-groups: array<string, array{schedule: array{interval: string}}>,
         *     updates: list<array{package-ecosystem: string, directory?: string, directories?: list<string>,
         *         multi-ecosystem-group?: string, patterns?: list<string>, schedule?: array{interval: string}}>
         * } $config
         */
        $config = Yaml::parseFile(__DIR__ . '/../../.github/dependabot.yml');
        self::assertSame(2, $config['version']);
        $coverage = [];
        $browser = [];
        foreach ($config['updates'] as $update) {
            $ecosystem = $update['package-ecosystem'];
            foreach ($update['directories'] ?? [$update['directory'] ?? ''] as $directory) {
                $coverage[] = $ecosystem . ':' . $directory;
                if ('/tests/Browser' === $directory) {
                    $browser[$ecosystem] = $update['multi-ecosystem-group'] ?? null;
                    self::assertNotEmpty($update['patterns'] ?? []);
                } else {
                    self::assertSame('weekly', $update['schedule']['interval'] ?? null);
                }
            }
        }
        sort($coverage);
        self::assertSame([
            'composer:/', 'docker:/', 'docker:/docker', 'docker:/tests/Browser',
            'github-actions:/', 'npm:/tests/Browser',
        ], $coverage);
        self::assertSame(['npm' => 'browser-tools', 'docker' => 'browser-tools'], $browser);
        self::assertSame('weekly', $config['multi-ecosystem-groups']['browser-tools']['schedule']['interval']);
    }

    #[DataProvider('jobResults')]
    public function testActualRequiredCheckScriptRejectsEveryIncompleteJob(
        string $quality,
        string $mutation,
        string $release,
        bool $success,
    ): void {
        $results = [
            'CI_QUALITY_RESULT' => $quality, 'CI_MUTATION_RESULT' => $mutation, 'CI_RELEASE_RESULT' => $release,
        ];
        $process = proc_open(
            command: ['/bin/bash', __DIR__ . '/../../bin/check-ci-results.sh'],
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
            env_vars: array_filter($results, static fn(string $result): bool => '' !== $result),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($success ? 0 : 1, proc_close($process));
        self::assertIsString($output);
        self::assertIsString($error);
        if ($success) {
            self::assertThat($output, self::stringContains('All required checks passed'));
            self::assertSame('', $error);
        } else {
            self::assertSame('', $output);
            self::assertThat($error, self::stringContains('did not succeed'));
        }
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function jobResults(): iterable
    {
        $results = ['success', 'failure', 'cancelled', 'skipped'];
        foreach ($results as $quality) {
            foreach ($results as $mutation) {
                foreach ($results as $release) {
                    yield $quality . '/' . $mutation . '/' . $release => [
                        $quality, $mutation, $release,
                        ['success', 'success', 'success'] === [$quality, $mutation, $release],
                    ];
                }
            }
        }
        yield 'missing result' => ['success', '', 'success', false];
        yield 'unknown result' => ['success', 'unexpected', 'success', false];
    }
}
