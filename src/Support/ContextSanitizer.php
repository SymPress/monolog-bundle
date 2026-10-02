<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Support;

final class ContextSanitizer
{
    private const int MAX_DEPTH = 5;
    private const int MAX_ITEMS = 80;
    private const int MAX_STRING_LENGTH = 1000;

    /** @param list<string> $secrets Known literal credentials, including unlabelled occurrences. */
    public function __construct(private readonly array $secrets = [])
    {
    }

    /** @param array<array-key, mixed> $context */
    public function withSensitiveContext(array $context): self
    {
        $secrets = $this->secrets;
        $collect = function (array $values, int $depth = 0) use (&$collect, &$secrets): void {
            if ($depth >= self::MAX_DEPTH) {
                return;
            }
            foreach (array_slice($values, 0, self::MAX_ITEMS, true) as $key => $value) {
                if (is_string($key) && $this->shouldRedact($key) && is_string($value) && $value !== '') {
                    $secrets[] = $value;
                } elseif (is_array($value)) {
                    $collect($value, $depth + 1);
                }
            }
        };
        $collect($context);
        return new self(array_values(array_unique($secrets)));
    }

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
            return $this->sanitizeText($value);
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

    /** @return array<string, mixed> */
    private function throwable(\Throwable $throwable, int $depth): array
    {
        return [
            'class'    => $throwable::class,
            'message'  => $this->sanitizeText($throwable->getMessage()),
            'file'     => $this->sanitizeText($throwable->getFile()),
            'line'     => $throwable->getLine(),
            'trace'    => array_map(function (array $frame): array {
                // Arguments and objects can contain credentials; retain stack locations and call names.
                return $this->sanitizeArray(array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function', 'type'])));
            }, array_slice($throwable->getTrace(), 0, self::MAX_ITEMS)),
            'previous' => $throwable->getPrevious() instanceof \Throwable
                ? $this->sanitize($throwable->getPrevious(), $depth + 1)
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

        foreach (['password', 'pass', 'pwd', 'nonce', 'token', 'authorization', 'cookie', 'secret', 'credential', 'api_key', 'private_key', 'dsn'] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function sanitizeText(string $value): string
    {
        $secrets = $this->secrets;
        foreach (['DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $secret = defined($name) ? constant($name) : getenv($name);
            if (!is_string($secret) || $secret === '') {
                continue;
            }
            $secrets[] = $secret;
        }
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $value = str_replace([$secret, rawurlencode($secret)], '[redacted]', $value);
        }
        // Preserve the SQL operation and error code while removing quoted literal values.
        if (preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE)\s|SQLSTATE|\b(?:database|SQL) error\b/i', $value) === 1) {
            $value = preg_replace('/\'(?:\'\'|\\\\.|[^\'\\\\])*\'|"(?:""|\\\\.|[^"\\\\])*"/', "'[redacted]'", $value) ?? '[text redacted]';
        }
        $value = preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/_.=-]+/i', '[authorization redacted]', $value) ?? '[text redacted]';
        $value = preg_replace('~([a-z][a-z0-9+.-]*://)[^\s/@]+@~i', '$1[redacted]@', $value) ?? '[text redacted]';
        $value = preg_replace_callback('~https?://[^\s<>]+~i', static function (array $match): string {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone processor must work before WordPress pluggable APIs.
            $parts = parse_url($match[0]);
            if (!is_array($parts) || !isset($parts['host'])) {
                return '[url redacted]';
            }
            return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '') . (isset($parts['query']) ? '?[query redacted]' : '');
        }, $value) ?? '[text redacted]';
        $value = preg_replace('/\b(?:password|passwd|pwd|token|secret|authorization|cookie|nonce|api[_-]?key)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '[credential redacted]', $value) ?? '[text redacted]';
        return $this->truncate($value);
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_STRING_LENGTH) . '...';
    }
}
