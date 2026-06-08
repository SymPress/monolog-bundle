<?php

/**
 * Plugin Name: Monolog Bundle
 * Description: Monolog logger integration for the WordPress kernel.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.5
 * Author: Brian Schaeffner
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SymPress\MonologBundle;

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists(MonologBundle::class)) {
    foreach (
        [
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
            __DIR__ . '/vendor/autoload.php',
        ] as $autoloader
    ) {
        if (!is_readable($autoloader)) {
            continue;
        }

        require_once $autoloader;
        break;
    }
}
