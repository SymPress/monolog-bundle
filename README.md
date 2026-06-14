# SymPress Monolog Bundle

[![Checks](https://img.shields.io/github/actions/workflow/status/SymPress/monolog-bundle/qa.yml?branch=main&label=checks)](https://github.com/SymPress/monolog-bundle/actions/workflows/qa.yml) [![Release](https://img.shields.io/github/v/release/SymPress/monolog-bundle?label=release)](https://github.com/SymPress/monolog-bundle/releases) [![PHP](https://img.shields.io/packagist/dependency-v/sympress/monolog-bundle/php.svg?label=php)](https://packagist.org/packages/sympress/monolog-bundle) [![Downloads](https://img.shields.io/packagist/dt/sympress/monolog-bundle.svg?label=downloads)](https://packagist.org/packages/sympress/monolog-bundle/stats) [![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE) [![Security Policy](https://img.shields.io/badge/security-policy-2ea44f.svg)](SECURITY.md)

Monolog integration bundle for SymPress WordPress kernel applications.

The package provides a Composer-powered WordPress MU plugin that registers a
Monolog logger, channel loggers, configurable handlers, processors, and
WordPress-specific logging hooks through the SymPress kernel service container.

## Installation

```bash
composer require sympress/monolog-bundle
```

The package requires PHP 8.5, `sympress/kernel`, `monolog/monolog`, and
`psr/log`.

## Features

- Default PSR-3 logger service exposed as `logger` and `Psr\Log\LoggerInterface`
- Channel logger support through `monolog.logger` tags and Monolog attributes
- Configurable handlers for stream, rotating file, fingers crossed, grouped,
  filtered, console, mail, syslog, socket, Slack webhook, and related Monolog
  handler types
- Handler-to-channel routing with inclusive and exclusive channel rules
- Tagged processor registration with handler or channel targeting
- WordPress runtime context processor
- Kernel throwable, WordPress database, and HTTP API logging hooks
- Profiler bridge that forwards buffered Monolog records to SymPress Profiler

## Usage

When the SymPress kernel discovers the package, it registers
`SymPress\MonologBundle\MonologBundle` and loads
`monolog-bundle/monolog-bundle.php` as the MU plugin entry point.

```php
<?php

use Psr\Log\LoggerInterface;

final readonly class ImportOrders
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        $this->logger->info('Import started.');
    }
}
```

## Configuration

The package ships with a default stream handler and a profiler buffer handler.
Project configuration can override handlers and channels through the Monolog
extension.

```yaml
monolog:
    channels:
        - security
    handlers:
        main:
            type: fingers_crossed
            action_level: error
            handler: nested
        nested:
            type: stream
            path: '%kernel.logs_dir%/%kernel.environment%.log'
            level: debug
            nested: true
        security_file:
            type: stream
            path: '%kernel.logs_dir%/security.log'
            channels: [security]
```

Services can request channel-specific loggers by using Monolog's
`WithMonologChannel` attribute or the `monolog.logger` service tag.

## Development

```bash
composer install
composer test
composer cs:analyze
composer cs
```

Use `composer cs:fix` to apply automatic style fixes.

## License

This package is licensed under `GPL-2.0-or-later`.
