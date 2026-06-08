<?php

declare(strict_types=1);

foreach (
    [
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 3) . '/vendor/autoload.php',
    ] as $autoloader
) {
    if (!is_readable($autoloader)) {
        continue;
    }

    require $autoloader;
    break;
}

require __DIR__ . '/Support/TestEnvironment.php';
