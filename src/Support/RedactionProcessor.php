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
        return $record->with(
            message: $this->sanitizer->sanitizeText($record->message),
            context: $this->sanitizer->sanitizeArray($record->context),
            extra: $this->sanitizer->sanitizeArray($record->extra),
        );
    }
}
