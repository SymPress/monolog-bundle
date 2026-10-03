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
    public function testClosedGateRunsNoHandlerProcessorsAndAuthorizedGateCollects(): void
    {
        $buffer = new LogRecordBuffer(new LogRecordNormalizer(new ContextSanitizer()), 2);
        $gate = new class {
            public bool $open = false;
            public int $calls = 0;
            public function shouldCollect(): bool { ++$this->calls; return $this->open; }
        };
        $handler = new \SymPress\MonologBundle\Handler\ProfilerHandler($buffer, gate: new \SymPress\MonologBundle\Support\ProfilerCollectionGate($gate));
        $calls = 0;
        $handler->pushProcessor(static function (LogRecord $record) use (&$calls): LogRecord { ++$calls; return $record; });
        $logger = new \Monolog\Logger('test', [$handler]);
        $logger->debug('closed');
        self::assertSame(0, $calls);
        self::assertSame(1, $gate->calls);
        self::assertSame([], $buffer->entries());
        $gate->open = true;
        foreach (['first', 'second', 'third'] as $message) { $logger->debug($message); }
        self::assertSame(3, $calls);
        self::assertSame(4, $gate->calls);
        self::assertSame(['second', 'third'], array_column($buffer->entries(), 'message'));
        $buffer->clear();
        self::assertSame([], $buffer->entries());
    }

    public function testNativeHandlerChecksGateOnceAndKeepsLevelAndBubbleSemantics(): void
    {
        foreach ([true, false] as $bubble) {
            $buffer = new LogRecordBuffer(new LogRecordNormalizer(new ContextSanitizer()));
            $gate = new class {
                public bool $open = true;
                public int $calls = 0;
                public function shouldCollect(): bool { ++$this->calls; return $this->open; }
            };
            $handler = new \SymPress\MonologBundle\Handler\ProfilerHandler($buffer, Level::Info, $bubble, new \SymPress\MonologBundle\Support\ProfilerCollectionGate($gate));
            $calls = 0;
            $handler->pushProcessor(static function (LogRecord $record) use (&$calls): LogRecord { ++$calls; return $record; });
            self::assertSame(!$bubble, $handler->handle(new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'accepted')));
            self::assertSame(1, $gate->calls);
            self::assertSame(1, $calls);
            self::assertFalse($handler->handle(new LogRecord(new \DateTimeImmutable(), 'test', Level::Debug, 'below level')));
            $gate->open = false;
            self::assertFalse($handler->handle(new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'unauthorized')));
            self::assertSame(3, $gate->calls);
            self::assertSame(1, $calls);
            self::assertSame(['accepted'], array_column($buffer->entries(), 'message'));
            $gate->open = true;
            $next = new \Monolog\Handler\TestHandler();
            (new \Monolog\Logger('test', [$handler, $next]))->info('logger');
            self::assertSame($bubble, $next->hasInfoRecords());
            self::assertSame(4, $gate->calls);
            self::assertSame(['accepted', 'logger'], array_column($buffer->entries(), 'message'));
        }
    }

    public function testLoggerProcessorCannotReuseAnEarlierAuthorizationDecision(): void
    {
        $buffer = new LogRecordBuffer(new LogRecordNormalizer(new ContextSanitizer()));
        $gate = new class {
            public bool $open = true;
            public int $calls = 0;
            public function shouldCollect(): bool { ++$this->calls; return $this->open; }
        };
        $handler = new \SymPress\MonologBundle\Handler\ProfilerHandler($buffer, gate: new \SymPress\MonologBundle\Support\ProfilerCollectionGate($gate));
        $calls = 0;
        $handler->pushProcessor(static function (LogRecord $record) use (&$calls): LogRecord { ++$calls; return $record; });
        $logger = new \Monolog\Logger('test', [$handler]);
        $logger->pushProcessor(static function (LogRecord $record) use ($gate): LogRecord { $gate->open = false; return $record; });
        $logger->info('authorization changed');
        self::assertSame(2, $gate->calls);
        self::assertSame(0, $calls);
        self::assertSame([], $buffer->entries());
    }

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
