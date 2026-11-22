<?php

declare(strict_types=1);

/** @var array<string, string> $settings */
$settings = require __DIR__ . '/initial-settings.php';
$port = getenv('DEMO_HTTP_PORT');
$settings['base_url'] = 'http://localhost:' . (false === $port ? '18080' : $port);

return $settings;
