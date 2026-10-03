<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use SymPress\MonologBundle\Support\ProfilerCollectionGate;
use SymPress\MonologBundle\Value\LogRecordBuffer;

final class ProfilerHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogRecordBuffer $buffer,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly ?ProfilerCollectionGate $gate = null,
    ) {

        parent::__construct($level, $bubble);
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->gate?->allows() === true && parent::isHandling($record);
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer->record($record);
    }
}
