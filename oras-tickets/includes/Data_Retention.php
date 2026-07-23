<?php

namespace ORAS\Tickets;

use ORAS\Tickets\Admin\Pages\Settings_Page;

if (! defined('ABSPATH')) {
    exit;
}

final class Data_Retention
{
    public const ACTION_HOOK = 'oras_tickets_daily_retention_cleanup';

    public static function register(): void
    {
        add_action(self::ACTION_HOOK, array(self::class, 'cleanup'));
        if (! wp_next_scheduled(self::ACTION_HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::ACTION_HOOK);
        }
    }

    public static function cleanup(): int
    {
        $settings = Settings_Page::get_settings();
        $days = absint($settings['privacy']['communication_retention_days'] ?? 0);
        if ($days <= 0) {
            return 0;
        }

        return Communication_Log_Store::delete_completed_before(
            gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS))
        );
    }
}
