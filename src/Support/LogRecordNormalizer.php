<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Support;

use Monolog\LogRecord;

final class LogRecordNormalizer
{
    public function __construct(
        private readonly ContextSanitizer $sanitizer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(LogRecord $record): array
    {
        $location = $this->location($record);

        return [
            'type' => 0,
            'label' => $record->level->getName(),
            'level' => $record->level->toPsrLogLevel(),
            'level_name' => $record->level->getName(),
            'level_value' => $record->level->value,
            'message' => $record->message,
            'channel' => $record->channel,
            'context' => $this->sanitizer->sanitizeArray($record->context),
            'extra' => $this->sanitizer->sanitizeArray($record->extra),
            'file' => $location['file'],
            'line' => $location['line'],
            'captured_at' => $record->datetime->format(DATE_ATOM),
            'source' => 'monolog',
        ];
    }

    /**
     * @return array{file: string, line: int}
     */
    private function location(LogRecord $record): array
    {
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            return [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        $file = $record->context['file'] ?? '';
        $line = $record->context['line'] ?? 0;

        return [
            'file' => (is_scalar($file) || $file instanceof \Stringable) ? (string) $file : '',
            'line' => is_numeric($line) ? (int) $line : 0,
        ];
    }
}
