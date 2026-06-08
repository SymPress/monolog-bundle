<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Hook;

use SymPress\MonologBundle\Value\LogRecordBuffer;

final class ProfilerLogBridge
{
    public const string FILTER_ENTRIES = 'sympress_profiler_log_entries';

    public function __construct(
        private readonly LogRecordBuffer $buffer,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function entries(array $entries): array
    {
        return [...$entries, ...$this->buffer->entries()];
    }
}
