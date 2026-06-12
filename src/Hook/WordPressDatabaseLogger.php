<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Hook;

use Psr\Log\LoggerInterface;

final class WordPressDatabaseLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(): void
    {
        $lastError = $this->lastWpdbError();

        if ($lastError === null) {
            return;
        }

        $this->logger->error(
            $lastError['message'],
            [
                'query'  => $lastError['query'],
                'errors' => $lastError['errors'],
            ],
        );
    }

    /** @return array{message: string, query: string, errors: mixed}|null */
    private function lastWpdbError(): ?array
    {
        global $EZSQL_ERROR, $wpdb;

        if (is_array($EZSQL_ERROR ?? null) && $EZSQL_ERROR !== []) {
            $last = end($EZSQL_ERROR);

            if (is_array($last)) {
                return [
                    'message' => is_string($last['error_str'] ?? null) ? $last['error_str'] : 'WordPress database error.',
                    'query'   => is_string($last['query'] ?? null) ? $last['query'] : '',
                    'errors'  => $EZSQL_ERROR,
                ];
            }
        }

        if (is_object($wpdb ?? null) && is_string($wpdb->last_error ?? null) && $wpdb->last_error !== '') {
            return [
                'message' => $wpdb->last_error,
                'query'   => is_string($wpdb->last_query ?? null) ? $wpdb->last_query : '',
                'errors'  => [],
            ];
        }

        return null;
    }
}
