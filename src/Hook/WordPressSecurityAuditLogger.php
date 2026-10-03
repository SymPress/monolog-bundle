<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Hook;

use Psr\Log\LoggerInterface;

final readonly class WordPressSecurityAuditLogger
{
    public function __construct(private LoggerInterface $logger, private bool $enabled = true)
    {
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- WordPress passes the username; audit deliberately discards it.
    public function loginSucceeded(mixed $username, mixed $user): void
    {
        $this->record('login.succeeded', ['user_id' => $this->id(is_object($user) ? ($user->ID ?? null) : null)]);
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Never retain or look up an attempted login identifier.
    public function loginFailed(mixed $username, mixed $error = null): void
    {
        $code = $error instanceof \WP_Error ? $error->get_error_code() : '';
        $reason = in_array($code, ['invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password', 'authentication_failed', 'expired_session', 'spammer_account'], true)
            ? $code : 'authentication_failed';
        // Neither the attempted identifier nor an error message/data belongs in an audit record.
        $this->record('login.failed', ['reason' => $reason], 'warning');
    }

    /** @param array<array-key, mixed> $oldRoles */
    public function roleSet(int $userId, string $role, array $oldRoles): void
    {
        $this->record('user.role_set', ['user_id' => $this->id($userId), 'role' => $role === '' ? '' : $this->identifier($role), 'previous_roles' => $this->roles($oldRoles)]);
    }

    public function roleAdded(int $userId, string $role): void
    {
        $this->record('user.role_added', ['user_id' => $this->id($userId), 'role' => $this->identifier($role)]);
    }

    public function roleRemoved(int $userId, string $role): void
    {
        $this->record('user.role_removed', ['user_id' => $this->id($userId), 'role' => $this->identifier($role)]);
    }

    public function pluginActivated(string $plugin, bool $networkWide): void
    {
        $this->record('plugin.activated', ['plugin' => $this->plugin($plugin), 'network_wide' => $networkWide]);
    }

    public function pluginDeactivated(string $plugin, bool $networkWide): void
    {
        $this->record('plugin.deactivated', ['plugin' => $this->plugin($plugin), 'network_wide' => $networkWide]);
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Discard the free-form display name supplied by WordPress.
    public function themeSwitched(string $themeName, mixed $newTheme, mixed $oldTheme = null): void
    {
        $this->record('theme.switched', ['theme' => $this->theme($newTheme), 'previous_theme' => $this->theme($oldTheme)]);
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Hook values may contain secrets; only record the allowlisted name.
    public function optionUpdated(string $option, mixed $oldValue, mixed $newValue): void
    {
        if (!in_array($option, ['siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role'], true)) {
            return;
        }

        $this->record('option.updated', ['option' => $option]);
    }

    /** @param array<string, mixed> $context */
    private function record(string $event, array $context, string $level = 'info'): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->logger->log($level, 'WordPress security audit: ' . $event . '.', [
            'event'    => $event,
            'actor_id' => function_exists('get_current_user_id') ? $this->id(get_current_user_id()) : 0,
            'site_id'  => function_exists('get_current_blog_id') ? $this->id(get_current_blog_id()) : 0,
        ] + $context);
    }

    private function id(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }

    private function identifier(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1 ? $value : '[invalid]';
    }

    /**
     * @param array<array-key, mixed> $roles
     * @return list<string>
     */
    private function roles(array $roles): array
    {
        return array_map($this->identifier(...), array_values(array_slice($roles, 0, 20)));
    }

    private function plugin(string $plugin): string
    {
        return strlen($plugin) <= 190 && !in_array('..', explode('/', $plugin), true)
            && preg_match('~^(?:[a-zA-Z0-9._-]+/)*[a-zA-Z0-9._-]+\.php$~D', $plugin) === 1 ? $plugin : '[invalid]';
    }

    private function theme(mixed $theme): string
    {
        return $theme instanceof \WP_Theme ? $this->identifier($theme->get_stylesheet()) : '[unknown]';
    }
}
