<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use SymPress\MonologBundle\Hook\WordPressSecurityAuditLogger;

final class WordPressSecurityAuditLoggerTest extends TestCase
{
    public function testEventsRetainIdsAndNeverIncludeLoginInputsOrOptionValues(): void
    {
        $handler = new TestHandler();
        $audit = new WordPressSecurityAuditLogger(new Logger('security', [$handler]));
        $audit->loginSucceeded('loginSentinel', (object) ['ID' => 42, 'user_login' => 'loginSentinel', 'user_pass' => 'passwordSentinel']);
        $audit->loginFailed('guessedUsernameSentinel', new \WP_Error('messageSentinel', 'incorrect_password'));
        $audit->loginFailed('guessedUsernameSentinel', new \WP_Error('messageSentinel', 'unsafeCodeSentinel'));
        $audit->roleSet(42, 'administrator', ['editor']);
        $audit->roleAdded(42, 'editor');
        $audit->roleRemoved(42, 'subscriber');
        $audit->pluginActivated('my-plugin/plugin.php', true);
        $audit->pluginDeactivated('my-plugin/plugin.php', false);
        $audit->themeSwitched('themeDisplayNameSentinel', null);
        $audit->optionUpdated('siteurl', 'oldUrlSentinel', 'newUrlSentinel');
        $audit->optionUpdated('admin_email', 'oldEmailSentinel', 'newEmailSentinel');
        $audit->optionUpdated('private_api_token', 'tokenSentinel', 'tokenSentinel');
        $records = $handler->getRecords();
        self::assertCount(11, $records);
        self::assertSame(42, $records[0]->context['user_id']);
        self::assertSame(123, $records[0]->context['actor_id']);
        self::assertSame(1, $records[0]->context['site_id']);
        self::assertSame('incorrect_password', $records[1]->context['reason']);
        self::assertSame('authentication_failed', $records[2]->context['reason']);
        self::assertSame(['editor'], $records[3]->context['previous_roles']);
        self::assertSame('my-plugin/plugin.php', $records[6]->context['plugin']);
        self::assertTrue($records[6]->context['network_wide']);
        self::assertSame('option.updated', $records[9]->context['event']);
        $json = json_encode(array_map(static fn ($record): array => $record->toArray(), $records), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Sentinel', $json);
        foreach (['ip', 'username', 'password', 'old_value', 'new_value'] as $key) {
            foreach ($records as $record) {
                self::assertArrayNotHasKey($key, $record->context);
            }
        }
    }

    public function testAuditIdentifiersAreBoundedAndDisabledRecorderIsSilent(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('security', [$handler]);
        $audit = new WordPressSecurityAuditLogger($logger);
        $audit->roleSet(-1, str_repeat('x', 1000), array_fill(0, 1000, "rawSentinel\n"));
        $audit->pluginActivated("plugin.php\nrawSentinel", false);
        $audit->pluginActivated('../outside.php', false);
        $records = $handler->getRecords();
        self::assertSame(0, $records[0]->context['user_id']);
        self::assertSame('[invalid]', $records[0]->context['role']);
        self::assertCount(20, $records[0]->context['previous_roles']);
        self::assertSame('[invalid]', $records[1]->context['plugin']);
        self::assertSame('[invalid]', $records[2]->context['plugin']);
        $disabled = new WordPressSecurityAuditLogger($logger, false);
        $disabled->loginFailed('Sentinel');
        $disabled->optionUpdated('admin_email', 'Sentinel', 'Sentinel');
        self::assertCount(3, $handler->getRecords());
    }
}
