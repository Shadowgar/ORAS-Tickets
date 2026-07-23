<?php

namespace ORAS\Tickets;

if (! defined('ABSPATH')) {
    exit;
}

final class Privacy_Manager
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', array(self::class, 'register_exporter'));
        add_action('admin_init', array(self::class, 'add_policy_text'));
    }

    public static function register_exporter(array $exporters): array
    {
        $exporters['oras-tickets'] = array(
            'exporter_friendly_name' => __('ORAS event registrations', 'oras-tickets'),
            'callback'               => array(self::class, 'export_personal_data'),
        );
        return $exporters;
    }

    public static function export_personal_data(string $email, int $page = 1): array
    {
        $user = get_user_by('email', sanitize_email($email));
        if (! $user instanceof \WP_User) {
            return array('data' => array(), 'done' => true);
        }

        $data = array();
        foreach (get_user_meta($user->ID) as $key => $values) {
            if (strpos((string) $key, '_oras_rsvp_event_') !== 0) {
                continue;
            }
            $data[] = array(
                'group_id'    => 'oras-event-rsvps',
                'group_label' => __('ORAS event RSVPs', 'oras-tickets'),
                'item_id'     => sanitize_key((string) $key),
                'data'        => array(
                    array('name' => (string) $key, 'value' => maybe_serialize($values)),
                ),
            );
        }

        return array('data' => $data, 'done' => true);
    }

    public static function add_policy_text(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content(
                __('ORAS Tickets', 'oras-tickets'),
                wp_kses_post(__('<p>ORAS Tickets stores event registration contact details, event-question answers, attendance and approval status, waitlist history, and communication audit records for event operations.</p>', 'oras-tickets'))
            );
        }
    }
}
