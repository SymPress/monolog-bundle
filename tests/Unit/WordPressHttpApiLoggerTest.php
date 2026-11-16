<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\MonologBundle\Hook\WordPressHttpApiLogger;

final class WordPressHttpApiLoggerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function urls(): iterable
    {
        yield 'slack webhook' => ['https://hooks.slack.com/services/T000/B000/slackSecret', 'https://hooks.slack.com/[path redacted]'];
        yield 'discord webhook' => ['https://discord.com/api/webhooks/123/discordSecret', 'https://discord.com/[path redacted]'];
        yield 'telegram bot' => ['https://api.telegram.org/bot123456:telegramSecret/sendMessage?chat_id=1', 'https://api.telegram.org/[path redacted]'];
        yield 'origin only' => ['http://example.test:8080/', 'http://example.test:8080'];
        yield 'unparsable' => ['not a url', '[url redacted]'];
    }

    #[DataProvider('urls')]
    public function testFailedRequestsLogOnlyTheOrigin(string $url, string $expected): void
    {
        $handler = new TestHandler();
        (new WordPressHttpApiLogger(new Logger('http', [$handler])))->record(
            ['response' => ['code' => 403, 'message' => 'Forbidden']],
            'response',
            'WpOrg\Requests\Requests',
            ['method' => 'POST'],
            $url,
        );

        self::assertCount(1, $handler->getRecords());
        self::assertSame($expected, $handler->getRecords()[0]->context['url']);
    }
}
