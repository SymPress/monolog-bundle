<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Support;

use Monolog\LogRecord;

final readonly class RedactionProcessor
{
    public function __construct(private ContextSanitizer $sanitizer)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $sanitizer = $this->sanitizer->withSensitiveContext([$record->context, $record->extra]);
        return $record->with(
            message: $sanitizer->sanitizeText($record->message),
            context: $sanitizer->sanitizeArray($record->context),
            extra: $sanitizer->sanitizeArray($record->extra),
        );
    }
}
