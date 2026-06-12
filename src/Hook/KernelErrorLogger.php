<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Hook;

use Psr\Log\LoggerInterface;

final class KernelErrorLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(\Throwable $throwable): void
    {
        $this->logger->critical(
            $throwable->getMessage(),
            [
                'exception' => $throwable,
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ],
        );
    }
}
