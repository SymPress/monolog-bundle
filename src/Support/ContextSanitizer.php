<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Support;

final class ContextSanitizer
{
    private const int MAX_DEPTH = 5;
    private const int MAX_ITEMS = 80;
    private const int MAX_STRING_LENGTH = 1000;

    public function sanitize(mixed $value, int $depth = 0, ?string $key = null): mixed
    {
        if ($key !== null && $this->shouldRedact($key)) {
            return '[redacted]';
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[depth limit reached]';
        }

        if ($value instanceof \Throwable) {
            return $this->throwable($value, $depth);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return $this->array($value, $depth);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if ($value instanceof \Stringable) {
            return $this->truncate((string) $value);
        }

        if (is_resource($value)) {
            return sprintf('[resource %s]', get_resource_type($value));
        }

        if (is_object($value)) {
            return sprintf('[object %s]', $value::class);
        }

        return sprintf('[%s]', gettype($value));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public function sanitizeArray(array $value): array
    {
        $sanitized = $this->sanitize($value);

        return is_array($sanitized) ? $sanitized : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function throwable(\Throwable $throwable, int $depth): array
    {
        return [
            'class' => $throwable::class,
            'message' => $this->truncate($throwable->getMessage()),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'previous' => $throwable->getPrevious() instanceof \Throwable
                ? $this->throwable($throwable->getPrevious(), $depth + 1)
                : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function array(array $value, int $depth): array
    {
        $sanitized = [];
        $slice = array_slice($value, 0, self::MAX_ITEMS, true);

        foreach ($slice as $itemKey => $itemValue) {
            $sanitized[$itemKey] = $this->sanitize(
                $itemValue,
                $depth + 1,
                is_string($itemKey) ? $itemKey : null,
            );
        }

        if (count($value) > self::MAX_ITEMS) {
            $sanitized['__truncated'] = sprintf('%d additional item(s) omitted.', count($value) - self::MAX_ITEMS);
        }

        return $sanitized;
    }

    private function shouldRedact(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (['password', 'pass', 'pwd', 'nonce', 'token', 'authorization', 'cookie', 'secret'] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_STRING_LENGTH) . '...';
    }
}
