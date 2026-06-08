<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use SymPress\MonologBundle\Support\ContextSanitizer;
use SymPress\MonologBundle\Support\LogRecordNormalizer;
use SymPress\MonologBundle\Value\LogRecordBuffer;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogRecordBufferTest extends TestCase
{
    public function test_it_normalizes_monolog_records_for_profiler_storage(): void
    {
        $buffer = new LogRecordBuffer(new LogRecordNormalizer(new ContextSanitizer()));
        $exception = new \RuntimeException('Broken');

        $buffer->record(new LogRecord(
            datetime: new \DateTimeImmutable('2026-04-26T12:00:00+00:00'),
            channel: 'http',
            level: Level::Error,
            message: 'Request failed',
            context: [
                'exception' => $exception,
                'authorization' => 'secret',
            ],
            extra: [
                'wordpress' => ['hook' => 'http_api_debug'],
            ],
        ));

        $entries = $buffer->entries();

        self::assertCount(1, $entries);
        self::assertSame('error', $entries[0]['level']);
        self::assertSame('ERROR', $entries[0]['label']);
        self::assertSame('http', $entries[0]['channel']);
        self::assertSame('monolog', $entries[0]['source']);
        self::assertSame('[redacted]', $entries[0]['context']['authorization']);
        self::assertSame($exception->getFile(), $entries[0]['file']);
    }
}
