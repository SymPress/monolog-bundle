<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\DependencyInjection;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;

final class FormatterConfigurator
{
    public function __construct(
        private readonly bool $includeStacktraces = false,
        private readonly ?string $basePath = null,
    ) {
    }

    public function __invoke(HandlerInterface $handler): void
    {
        if (!$handler instanceof FormattableHandlerInterface) {
            return;
        }

        $formatter = $handler->getFormatter();

        if ($this->includeStacktraces && ($formatter instanceof LineFormatter || $formatter instanceof JsonFormatter)) {
            $formatter->includeStacktraces();
        }

        if ($this->basePath !== null && $formatter instanceof LineFormatter) {
            $formatter->setBasePath($this->basePath);
        }
    }
}
