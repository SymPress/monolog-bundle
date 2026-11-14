<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

final class DefaultHandlerFactory
{
    public static function create(mixed $directory, string $environment, string $level, string $path): HandlerInterface
    {
        if ($directory === null || $directory === false || $directory === '') {
            return new DisabledLogHandler();
        }
        if ($level === 'auto') {
            $level = in_array($environment, ['local', 'development', 'dev', 'test'], true) ? 'debug' : 'warning';
        }
        foreach (Level::cases() as $candidate) {
            if (strtolower($candidate->name) === strtolower($level)) {
                // A read-only release directory must not turn every log call into an exception.
                return self::isWritable(dirname($path))
                    ? new RotatingFileHandler($path, 14, $candidate, true, 0600, true)
                    : new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $candidate);
            }
        }
        throw new \InvalidArgumentException('Invalid default log level.');
    }

    /** True when the directory exists and is writable, or its nearest existing parent is, so it can be created. */
    private static function isWritable(string $directory): bool
    {
        while (!is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- runs before WP_Filesystem
        return is_writable($directory);
    }
}
