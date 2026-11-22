<?php

declare(strict_types=1);

$lock = getenv('CHECK_HISTORY_LOCK_FILE');

return [
    'retention_days' => 90,
    'batch_size' => 500,
    'max_batches' => 10,
    // Use the same absolute private path across releases and cron invocations.
    'lock_file' => false === $lock || '' === $lock ? __DIR__ . '/../protected/maintenance/check-history.lock' : $lock,
];
