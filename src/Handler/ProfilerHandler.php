<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use SymPress\MonologBundle\Value\LogRecordBuffer;

final class ProfilerHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogRecordBuffer $buffer,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {

        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer->record($record);
    }
}
