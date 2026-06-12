<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleHandler extends StreamHandler
{
    /**
     * @param array<int|string, int|string|Level> $verbosityLevels
     * @param array<string, mixed> $formatterOptions
     */
    public function __construct(
        mixed $output = null,
        bool $bubble = true,
        array $verbosityLevels = [],
        array $formatterOptions = [],
        private readonly bool $interactiveOnly = false,
    ) {

        parent::__construct(
            $this->streamFromOutput($output),
            $this->levelFromShellVerbosity($verbosityLevels),
            $bubble,
        );

        $this->setFormatter(new LineFormatter(
            format: $this->stringOption($formatterOptions, 'format')
                ?? "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            dateFormat: $this->stringOption($formatterOptions, 'date_format'),
            allowInlineLineBreaks: (bool) ($formatterOptions['allow_inline_line_breaks'] ?? false),
            ignoreEmptyContextAndExtra: (bool) ($formatterOptions['ignore_empty_context_and_extra'] ?? false),
        ));
    }

    public function handle(LogRecord $record): bool
    {
        if ($this->interactiveOnly && !$this->isInteractive()) {
            return false;
        }

        return parent::handle($record);
    }

    private function streamFromOutput(mixed $output): mixed
    {
        if (is_resource($output) || is_string($output)) {
            return $output;
        }

        return 'php://stderr';
    }

    /** @param array<int|string, int|string|Level> $verbosityLevels */
    private function levelFromShellVerbosity(array $verbosityLevels): Level
    {
        $verbosity = (int) (getenv('SHELL_VERBOSITY') ?: 0);
        $defaultLevels = [
            -1 => 'error',
            0  => 'warning',
            1  => 'notice',
            2  => 'info',
            3  => 'debug',
        ];

        $verbosityMap = [
            OutputInterface::VERBOSITY_QUIET        => $verbosityLevels[OutputInterface::VERBOSITY_QUIET]
                ?? $verbosityLevels['VERBOSITY_QUIET']
                ?? 'error',
            OutputInterface::VERBOSITY_NORMAL       => $verbosityLevels[OutputInterface::VERBOSITY_NORMAL]
                ?? $verbosityLevels['VERBOSITY_NORMAL']
                ?? 'warning',
            OutputInterface::VERBOSITY_VERBOSE      => $verbosityLevels[OutputInterface::VERBOSITY_VERBOSE]
                ?? $verbosityLevels['VERBOSITY_VERBOSE']
                ?? 'notice',
            OutputInterface::VERBOSITY_VERY_VERBOSE => $verbosityLevels[OutputInterface::VERBOSITY_VERY_VERBOSE]
                ?? $verbosityLevels['VERBOSITY_VERY_VERBOSE']
                ?? 'info',
            OutputInterface::VERBOSITY_DEBUG        => $verbosityLevels[OutputInterface::VERBOSITY_DEBUG]
                ?? $verbosityLevels['VERBOSITY_DEBUG']
                ?? 'debug',
        ];

        $level = match (true) {
            $verbosity <= -1 => $defaultLevels[-1],
            $verbosity >= 3 => $verbosityMap[OutputInterface::VERBOSITY_DEBUG],
            $verbosity === 2 => $verbosityMap[OutputInterface::VERBOSITY_VERY_VERBOSE],
            $verbosity === 1 => $verbosityMap[OutputInterface::VERBOSITY_VERBOSE],
            default => $verbosityMap[OutputInterface::VERBOSITY_NORMAL],
        };

        return $this->normalizeLevel($level);
    }

    private function normalizeLevel(mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        if (is_int($level)) {
            return Level::tryFrom($level) ?? Level::Warning;
        }

        if (is_string($level)) {
            if (is_numeric($level)) {
                return Level::tryFrom((int) $level) ?? Level::Warning;
            }

            return match (strtolower($level)) {
                'debug' => Level::Debug,
                'info' => Level::Info,
                'notice' => Level::Notice,
                'warning' => Level::Warning,
                'error' => Level::Error,
                'critical' => Level::Critical,
                'alert' => Level::Alert,
                'emergency' => Level::Emergency,
                default => Level::Warning,
            };
        }

        return Level::Warning;
    }

    /** @param array<string, mixed> $options */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isInteractive(): bool
    {
        if (defined('STDERR') && function_exists('stream_isatty')) {
            return stream_isatty(STDERR);
        }

        return PHP_SAPI === 'cli';
    }
}
