<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Support;

final readonly class ProfilerCollectionGate
{
    public function __construct(private ?object $gate = null)
    {
    }

    public function allows(): bool
    {
        return $this->gate !== null && is_callable([$this->gate, 'shouldCollect']) && $this->gate->shouldCollect() === true;
    }
}
