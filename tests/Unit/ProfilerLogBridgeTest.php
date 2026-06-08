<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use SymPress\MonologBundle\Hook\ProfilerLogBridge;
use SymPress\MonologBundle\Support\ContextSanitizer;
use SymPress\MonologBundle\Support\LogRecordNormalizer;
use SymPress\MonologBundle\Value\LogRecordBuffer;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class ProfilerLogBridgeTest extends TestCase
{
    public function test_it_appends_buffered_monolog_entries_to_profiler_entries(): void
    {
        $buffer = new LogRecordBuffer(new LogRecordNormalizer(new ContextSanitizer()));
        $bridge = new ProfilerLogBridge($buffer);

        $buffer->record(new LogRecord(
            datetime: new \DateTimeImmutable('2026-04-26T12:00:00+00:00'),
            channel: 'app',
            level: Level::Info,
            message: 'Booted',
        ));

        $entries = $bridge->entries([
            [
                'level' => 'warning',
                'message' => 'PHP warning',
            ],
        ]);

        self::assertCount(2, $entries);
        self::assertSame('PHP warning', $entries[0]['message']);
        self::assertSame('Booted', $entries[1]['message']);
    }
}
