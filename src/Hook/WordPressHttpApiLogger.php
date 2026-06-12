<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Hook;

use Psr\Log\LoggerInterface;

final class WordPressHttpApiLogger
{
    private const array SUCCESS_CODES = [
        200,
        201,
        202,
        203,
        204,
        205,
        206,
        207,
        300,
        301,
        302,
        303,
        304,
        305,
        306,
        307,
        308,
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $parsedArgs */
    public function record(mixed $response, string $context, string $class, array $parsedArgs, string $url): void
    {
        if ($context !== 'response') {
            return;
        }

        if ($this->isWpError($response)) {
            $this->logger->error(
                sprintf('WP HTTP API Error: %s', $response->get_error_message()),
                $this->context($response, $context, $class, $parsedArgs, $url),
            );

            return;
        }

        if (!is_array($response)) {
            return;
        }

        $statusCode = $this->statusCode($response);

        if ($statusCode === null || in_array($statusCode, self::SUCCESS_CODES, true)) {
            return;
        }

        $this->logger->warning(
            sprintf('WP HTTP API returned status %d.', $statusCode),
            $this->context($response, $context, $class, $parsedArgs, $url),
        );
    }

    private function isWpError(mixed $response): bool
    {
        return class_exists('WP_Error') && $response instanceof \WP_Error;
    }

    /** @param array<string, mixed> $response */
    private function statusCode(array $response): ?int
    {
        $responseMeta = is_array($response['response'] ?? null) ? $response['response'] : [];
        $code = $responseMeta['code'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * @param array<string, mixed> $parsedArgs
     * @return array<string, mixed>
     */
    private function context(mixed $response, string $context, string $class, array $parsedArgs, string $url): array
    {
        $logContext = [
            'transport'    => $class,
            'context'      => $context,
            'url'          => $url,
            'request_args' => $this->requestArgs($parsedArgs),
        ];

        if (is_array($response)) {
            $responseMeta = is_array($response['response'] ?? null) ? $response['response'] : [];
            $logContext['status_code'] = is_numeric($responseMeta['code'] ?? null) ? (int) $responseMeta['code'] : null;
            $logContext['response_message'] = is_scalar($responseMeta['message'] ?? null)
                ? (string) $responseMeta['message']
                : '';
        }

        return $logContext;
    }

    /**
     * @param array<string, mixed> $parsedArgs
     * @return array<string, mixed>
     */
    private function requestArgs(array $parsedArgs): array
    {
        $allowed = [];

        foreach (['method', 'timeout', 'redirection', 'httpversion', 'blocking'] as $key) {
            if (!array_key_exists($key, $parsedArgs)) {
                continue;
            }

            $allowed[$key] = $parsedArgs[$key];
        }

        return $allowed;
    }
}
