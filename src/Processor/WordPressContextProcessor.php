<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class WordPressContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $extra['wordpress'] = array_filter(
            [
                'hook'       => function_exists('current_filter') ? current_filter() : null,
                'doing_ajax' => $this->doingAjax(),
                'doing_cron' => $this->doingCron(),
                'doing_rest' => $this->doingRest(),
                'is_admin'   => function_exists('is_admin') ? is_admin() : null,
                'user_id'    => $this->userId(),
                'site_id'    => function_exists('get_current_blog_id') ? get_current_blog_id() : null,
                'network_id' => function_exists('get_current_network_id') ? get_current_network_id() : null,
                'multisite'  => function_exists('is_multisite') ? is_multisite() : null,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return $record->with(extra: $extra);
    }

    private function doingRest(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request metadata only for logging context.
        return isset($_REQUEST['rest_route']) && $_REQUEST['rest_route'] !== '';
    }

    private function doingAjax(): bool
    {
        if (function_exists('wp_doing_ajax')) {
            return wp_doing_ajax();
        }

        return defined('DOING_AJAX') && (bool) constant('DOING_AJAX');
    }

    private function doingCron(): bool
    {
        if (function_exists('wp_doing_cron')) {
            return wp_doing_cron();
        }

        return defined('DOING_CRON') && (bool) constant('DOING_CRON');
    }

    private function userId(): ?int
    {
        if (!function_exists('did_action') || !function_exists('get_current_user_id')) {
            return null;
        }

        return did_action('init') > 0 ? get_current_user_id() : null;
    }
}
