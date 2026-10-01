<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Value;

use Monolog\LogRecord;
use SymPress\MonologBundle\Support\LogRecordNormalizer;

final class LogRecordBuffer
{
    /** @var \SplQueue<array<string, mixed>> */
    private \SplQueue $entries;

    public function __construct(
        private readonly LogRecordNormalizer $normalizer,
        private readonly int $limit = 500,
    ) {

        if ($limit < 1) {
            throw new \InvalidArgumentException('Log buffer limit must be positive.');
        }
        $this->entries = new \SplQueue();
    }

    public function record(LogRecord $record): void
    {
        if ($this->entries->count() >= $this->limit) {
            $this->entries->dequeue();
        }

        $this->entries->enqueue($this->normalizer->normalize($record));
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return iterator_to_array($this->entries, false);
    }

    public function clear(): void
    {
        $this->entries = new \SplQueue();
    }
}
