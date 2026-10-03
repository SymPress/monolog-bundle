<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\MonologBundle\Support\ContextSanitizer;
use SymPress\MonologBundle\Support\RedactionProcessor;

final class ContextSanitizerTest extends TestCase
{
    public function testDiagnosticCountsRemainVisibleWhilePasswordsAndExceptionsAreSanitized(): void
    {
        $secret = 'actualPasswordSentinel';
        $exception = (static fn (string $password): \RuntimeException => new \RuntimeException(
            'SQLSTATE[23000] failed after 11 attempts with ' . $password,
            previous: new \RuntimeException('Previous attempt 1 with ' . $password),
        ))($secret);
        $counts = ['bypass' => '1', 'tests_passed' => '1', 'testsPassed' => '11', 'passed' => 11, 'bypass_count' => 1];
        $record = (new RedactionProcessor(new ContextSanitizer()))(new LogRecord(
            datetime: new \DateTimeImmutable('2026-10-03T11:11:11+00:00'),
            channel: 'app',
            level: Level::Error,
            message: '11 tests passed, bypass 1 with ' . $secret,
            context: $counts + ['password' => $secret, 'exception' => $exception],
            extra: ['tests_passed' => '1', 'detail' => 'Attempt 11 with ' . $secret],
        ));

        foreach ($counts as $key => $value) {
            self::assertSame($value, $record->context[$key]);
        }
        self::assertSame('1', $record->extra['tests_passed']);
        self::assertSame('11 tests passed, bypass 1 with [redacted]', $record->message);
        self::assertSame('Attempt 11 with [redacted]', $record->extra['detail']);
        self::assertSame('[redacted]', $record->context['password']);
        $sanitized = $record->context['exception'];
        self::assertSame('SQLSTATE[23000] failed after 11 attempts with [redacted]', $sanitized['message']);
        self::assertSame('Previous attempt 1 with [redacted]', $sanitized['previous']['message']);
        self::assertSame($exception->getFile(), $sanitized['file']);
        self::assertSame($exception->getLine(), $sanitized['line']);
        self::assertNotEmpty($sanitized['trace']);
        foreach ($sanitized['trace'] as $index => $frame) {
            self::assertArrayNotHasKey('args', $frame);
            self::assertArrayNotHasKey('object', $frame);
            self::assertSame($exception->getTrace()[$index]['file'] ?? null, $frame['file'] ?? null);
            self::assertSame($exception->getTrace()[$index]['line'] ?? null, $frame['line'] ?? null);
            self::assertSame($exception->getTrace()[$index]['function'], $frame['function']);
        }
        self::assertStringNotContainsString($secret, json_encode([$record->context, $record->extra], JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string}> */
    public static function credentialKeys(): iterable
    {
        foreach (['pass', 'DB_PASS', 'user_pass', 'smtpPass', 'ftp-pass', 'dbpass', 'userpass', 'password', 'dbPassword', 'passwd', 'passphrase', 'ssh_passphrase', 'token', 'api_token', 'PHPSESSID'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('credentialKeys')]
    public function testActualCredentialsStillMaskUnlabelledOccurrences(string $key): void
    {
        $secret = 'actualCredentialSentinel';
        $sanitizer = (new ContextSanitizer())->withSensitiveContext([$key => $secret]);
        self::assertSame([$key => '[redacted]'], $sanitizer->sanitizeArray([$key => $secret]));
        self::assertSame('Attempt 11 with [redacted]', $sanitizer->sanitizeText('Attempt 11 with ' . $secret));
    }

    public function testActualShortPasswordsRemainRedacted(): void
    {
        $sanitizer = (new ContextSanitizer())->withSensitiveContext(['password' => '1']);
        self::assertSame(['password' => '[redacted]'], $sanitizer->sanitizeArray(['password' => '1']));
        self::assertSame('Password [redacted]', $sanitizer->sanitizeText('Password 1'));
    }
}
