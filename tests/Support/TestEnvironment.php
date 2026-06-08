<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
}

if (!function_exists('current_filter')) {
    function current_filter(): string
    {
        return 'unit_test';
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook): int
    {
        return $hook === 'init' ? 1 : 0;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 123;
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return 1;
    }
}

if (!function_exists('get_current_network_id')) {
    function get_current_network_id(): int
    {
        return 1;
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(
            private readonly string $message,
        ) {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
