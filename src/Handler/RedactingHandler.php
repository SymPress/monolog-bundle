<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;
use Monolog\ResettableInterface;
use SymPress\MonologBundle\Support\RedactionProcessor;

final readonly class RedactingHandler implements HandlerInterface, ResettableInterface
{
    public function __construct(private HandlerInterface $inner, private RedactionProcessor $redactor)
    {
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->inner->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        return $this->inner->handle(($this->redactor)($record));
    }

    public function handleBatch(array $records): void
    {
        $this->inner->handleBatch(array_map($this->redactor, $records));
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function reset(): void
    {
        if (!($this->inner instanceof ResettableInterface)) {
            return;
        }

        $this->inner->reset();
    }
}
