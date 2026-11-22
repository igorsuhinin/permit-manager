<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

#[Medium]
#[CoversNothing]
final class CoverageThresholdTest extends TestCase
{
    #[DataProvider('reports')]
    public function testThresholdFailsClosed(?string $xml, int $expectedStatus, string $expectedMessage): void
    {
        $report = tempnam(directory: sys_get_temp_dir(), prefix: 'permit-coverage-');
        self::assertIsString($report);
        try {
            if (null === $xml) {
                unlink($report);
            } else {
                self::assertNotFalse(file_put_contents(filename: $report, data: $xml));
            }
            // Report loading must work even when libxml is denied external resources.
            $process = proc_open(
                command: [
                    PHP_BINARY,
                    '-r',
                    'libxml_set_external_entity_loader(static fn() => null); '
                        . '$checker = $argv[1]; $argv = [$checker, $argv[2]]; require $checker;',
                    __DIR__ . '/../../bin/check-coverage.php',
                    $report,
                ],
                descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                pipes: $pipes,
            );
            self::assertIsResource($process);
            /** @var array{1: resource, 2: resource} $pipes */
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertIsString($stdout);
            self::assertIsString($stderr);
            self::assertSame($expectedStatus, proc_close($process));
            self::assertSame('', 0 === $expectedStatus ? $stderr : $stdout);
            self::assertStringContainsString($expectedMessage, 0 === $expectedStatus ? $stdout : $stderr);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }
    }

    /** @return iterable<string, array{?string, int, string}> */
    public static function reports(): iterable
    {
        $metrics = static fn(string $covered, string $total): string => '<coverage><project><metrics statements="'
            . $total . '" coveredstatements="' . $covered . '"/></project></coverage>';
        yield 'exact threshold' => [$metrics(covered: '95', total: '100'), 0, '95.0000%'];
        yield 'above threshold' => [$metrics(covered: '96', total: '100'), 0, '96.0000%'];
        yield 'full coverage' => [$metrics(covered: '100', total: '100'), 0, '100.0000%'];
        yield 'below threshold' => [$metrics(covered: '94', total: '100'), 1, '94.0000%'];
        yield 'rounding must not pass' => [$metrics(covered: '94999', total: '100000'), 1, '94.9990%'];
        yield 'missing report' => [null, 1, 'missing or unreadable'];
        yield 'malformed XML' => ['<coverage>', 1, 'no valid project metrics'];
        yield 'empty report' => ['', 1, 'no valid project metrics'];
        yield 'missing project metrics' => ['<coverage/>', 1, 'no valid project metrics'];
        yield 'file metrics cannot replace totals' => [
            '<coverage><project><file><metrics statements="100" coveredstatements="100"/></file></project></coverage>',
            1,
            'no valid project metrics',
        ];
        yield 'empty source' => [$metrics(covered: '0', total: '0'), 1, 'invalid or empty line counts'];
        yield 'missing counts' => [
            '<coverage><project><metrics/></project></coverage>',
            1,
            'invalid or empty line counts',
        ];
        yield 'non-numeric counts' => [$metrics(covered: 'invalid', total: '100'), 1, 'invalid or empty line counts'];
        yield 'negative counts' => [$metrics(covered: '-1', total: '100'), 1, 'invalid or empty line counts'];
        yield 'inconsistent counts' => [$metrics(covered: '101', total: '100'), 1, 'invalid or empty line counts'];
        yield 'overflowing counts' => [
            $metrics(covered: (string) PHP_INT_MAX, total: (string) PHP_INT_MAX),
            1,
            'invalid or empty line counts',
        ];
    }
}
