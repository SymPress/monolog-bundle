<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

final class DisabledLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        unset($record);
    }
}
