<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Value;

use Monolog\LogRecord;
use SymPress\MonologBundle\Support\LogRecordNormalizer;

final class LogRecordBuffer
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public function __construct(
        private readonly LogRecordNormalizer $normalizer,
        private readonly int $limit = 500,
    ) {
    }

    public function record(LogRecord $record): void
    {
        if (count($this->entries) >= $this->limit) {
            array_shift($this->entries);
        }

        $this->entries[] = $this->normalizer->normalize($record);
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
