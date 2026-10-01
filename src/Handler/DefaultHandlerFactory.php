<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler;

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
                return new RotatingFileHandler($path, 14, $candidate, true, 0600, true);
            }
        }
        throw new \InvalidArgumentException('Invalid default log level.');
    }
}
