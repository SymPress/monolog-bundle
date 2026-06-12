<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Handler\FingersCrossed;

use Monolog\Handler\FingersCrossed\ActivationStrategyInterface;
use Monolog\LogRecord;

final class HttpStatusCodeActivationStrategy implements ActivationStrategyInterface
{
    /** @param list<array{code: int, urls: list<string>}> $excludedHttpCodes */
    public function __construct(
        private readonly array $excludedHttpCodes,
        private readonly ActivationStrategyInterface $inner,
    ) {
    }

    public function isHandlerActivated(LogRecord $record): bool
    {
        if (!$this->inner->isHandlerActivated($record)) {
            return false;
        }

        $statusCode = $this->statusCode($record);

        if ($statusCode === null) {
            return true;
        }

        $url = $this->url($record);

        foreach ($this->excludedHttpCodes as $excluded) {
            if ($excluded['code'] !== $statusCode) {
                continue;
            }

            if ($excluded['urls'] === []) {
                return false;
            }

            foreach ($excluded['urls'] as $pattern) {
                if ($url !== '' && preg_match(sprintf('~%s~', str_replace('~', '\~', $pattern)), $url) === 1) {
                    return false;
                }
            }
        }

        return true;
    }

    private function statusCode(LogRecord $record): ?int
    {
        foreach ([$record->context, $record->extra] as $payload) {
            foreach (['status_code', 'status', 'http_code', 'response_code'] as $key) {
                $value = $payload[$key] ?? null;

                if (is_int($value)) {
                    return $value;
                }

                if (is_string($value) && ctype_digit($value)) {
                    return (int) $value;
                }
            }
        }

        return null;
    }

    private function url(LogRecord $record): string
    {
        foreach ([$record->context, $record->extra] as $payload) {
            foreach (['url', 'uri', 'request_uri', 'path'] as $key) {
                $value = $payload[$key] ?? null;

                if (is_scalar($value) || $value instanceof \Stringable) {
                    return (string) $value;
                }
            }
        }

        return '';
    }
}
