<?php

declare(strict_types=1);

namespace Tests\Support;

final readonly class ConcurrentProcesses
{
    /**
     * @param list<string> $operations
     *
     * @return list<array<string, string>>
     */
    public static function run(array $operations): array
    {
        $workers = [];
        try {
            foreach ($operations as $operation) {
                $process = proc_open(
                    command: [PHP_BINARY, __DIR__ . '/concurrent-worker.php', $operation],
                    descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    pipes: $pipes,
                    cwd: __DIR__ . '/../..',
                );
                if (!is_resource($process)) {
                    throw new \RuntimeException('Could not start the concurrency test worker.');
                }
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }
            foreach ($workers as $worker) {
                if ("READY\n" !== self::line($worker['pipes'][1])) {
                    throw new \RuntimeException('Concurrency worker failed before its barrier.');
                }
            }
            foreach ($workers as $worker) {
                fwrite(stream: $worker['pipes'][0], data: "GO\n");
                fflush($worker['pipes'][0]);
            }
            $results = [];
            foreach ($workers as $worker) {
                $result = json_decode(
                    json: self::line($worker['pipes'][1]),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );
                if (!is_array($result) || !isset($result['result']) || 'error' === $result['result']) {
                    throw new \RuntimeException('Concurrency worker failed: ' . json_encode(
                        value: $result,
                        flags: JSON_THROW_ON_ERROR,
                    ));
                }
                $entry = [];
                /** @var mixed $value */
                foreach ($result as $key => $value) {
                    if (!is_string($key) || !is_string($value)) {
                        throw new \RuntimeException('Invalid concurrency worker result.');
                    }
                    $entry[$key] = $value;
                }
                $results[] = $entry;
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process']);
                }
                proc_close($worker['process']);
            }
        }
    }

    /** @param resource $stream */
    private static function line($stream): string
    {
        $read = [$stream];
        $write = $except = null;
        if (1 !== stream_select(read: $read, write: $write, except: $except, seconds: 10)) {
            throw new \RuntimeException('Concurrency worker timed out.');
        }
        $line = fgets($stream);
        if (false === $line) {
            throw new \RuntimeException('Concurrency worker exited without a result.');
        }

        return $line;
    }
}
