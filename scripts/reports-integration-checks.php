<?php
/**
 * Reports integration checks for admin-post capability/nonce enforcement.
 *
 * Runs inside wp-env via:
 *   wp eval-file /tmp/oras-reports-integration-checks.php
 */

use ORAS\Tickets\Admin\Admin_Menu;
use ORAS\Tickets\Admin\Pages\Speaker_Reports_Page;
use ORAS\Tickets\Capabilities;

if (! defined('ABSPATH')) {
    exit(1);
}

final class Oras_Reports_Check_Exception extends RuntimeException {}
final class Oras_Reports_Wp_Die extends RuntimeException {}

/**
 * @throws Oras_Reports_Check_Exception
 */
function oras_reports_fail(string $message): void
{
    throw new Oras_Reports_Check_Exception($message);
}

/**
 * @throws Oras_Reports_Check_Exception
 */
function oras_reports_assert(bool $condition, string $message): void
{
    if (! $condition) {
        oras_reports_fail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

/**
 * @param mixed $actual
 * @param mixed $expected
 *
 * @throws Oras_Reports_Check_Exception
 */
function oras_reports_assert_same($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        oras_reports_fail(
            sprintf(
                '%s (expected=%s actual=%s)',
                $message,
                wp_json_encode($expected),
                wp_json_encode($actual)
            )
        );
    }

    echo 'PASS: ' . $message . "\n";
}

/**
 * @throws Oras_Reports_Check_Exception
 */
function oras_reports_create_user(string $prefix, string $suffix, string $role = 'subscriber'): int
{
    $login = sanitize_user($prefix . '_' . $suffix . '_' . wp_generate_password(4, false));
    $email = $login . '@example.org';
    $pass  = wp_generate_password(20, true, true);
    $id    = wp_create_user($login, $pass, $email);

    if (! is_int($id) || $id <= 0) {
        oras_reports_fail('Unable to create user: ' . $prefix);
    }

    $user = new WP_User($id);
    $user->set_role($role);

    return $id;
}

/**
 * @param callable $callback
 * @param array<string,mixed> $post_data
 * @return array{message:string,response:int}
 * @throws Oras_Reports_Check_Exception
 */
function oras_reports_call_wp_die_handler(callable $callback, array $post_data, int $user_id): array
{
    $die_capture = static function ($message = '', $title = '', $args = array()): void {
        $response = 0;
        if (is_array($args) && isset($args['response'])) {
            $response = (int) $args['response'];
        }

        $text = is_scalar($message) ? (string) $message : wp_json_encode($message);
        throw new Oras_Reports_Wp_Die('resp:' . $response . '|msg:' . $text);
    };

    add_filter(
        'wp_die_handler',
        static function () use ($die_capture) {
            return $die_capture;
        }
    );

    wp_set_current_user($user_id);
    $_POST    = $post_data;
    $_GET     = array();
    $_REQUEST = $post_data;

    try {
        call_user_func($callback);
    } catch (Oras_Reports_Wp_Die $e) {
        $raw = $e->getMessage();
        if (strpos($raw, 'resp:') !== 0) {
            oras_reports_fail('Unexpected wp_die capture payload: ' . $raw);
        }

        $parts = explode('|msg:', $raw, 2);
        $resp  = (int) str_replace('resp:', '', $parts[0]);
        $msg   = $parts[1] ?? '';

        return array(
            'message'  => wp_strip_all_tags($msg),
            'response' => $resp,
        );
    }

    oras_reports_fail('Expected wp_die but callback returned normally.');
}

/**
 * @throws Oras_Reports_Check_Exception
 */
function oras_reports_run_checks(): void
{
    if (! defined('ORAS_TICKETS_DIR')) {
        oras_reports_fail('ORAS_TICKETS_DIR not defined. Ensure oras-tickets plugin is active.');
    }

    require_once ORAS_TICKETS_DIR . 'includes/Capabilities.php';
    require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php';

    Capabilities::add_caps();

    $menu = new Admin_Menu();
    $menu->register();

    oras_reports_assert(
        has_action('admin_post_oras_tickets_export_csv', array($menu, 'handle_export_csv')) !== false,
        'Reports export admin-post hook is registered'
    );
    oras_reports_assert(
        has_action('admin_post_oras_speaker_reports_export_csv') !== false,
        'Speaker reports export admin-post hook is registered'
    );

    $admin_ids = get_users(
        array(
            'role'   => 'administrator',
            'fields' => 'ids',
            'number' => 1,
        )
    );

    oras_reports_assert(! empty($admin_ids), 'Administrator user exists');

    $admin_id = (int) $admin_ids[0];
    $suffix   = gmdate('YmdHis') . '_' . wp_rand(1000, 9999);

    $subscriber_id = oras_reports_create_user('oras_reports_sub', $suffix, 'subscriber');

    try {
        $result = oras_reports_call_wp_die_handler(
            array($menu, 'handle_export_csv'),
            array(
                'oras_tickets_reports_nonce' => wp_create_nonce('oras_tickets_reports'),
            ),
            $subscriber_id
        );
        oras_reports_assert_same($result['response'], 403, 'Reports export blocks unauthorized capability with 403');
        oras_reports_assert(stripos($result['message'], 'Not allowed') !== false, 'Reports export unauthorized message is returned');

        $result = oras_reports_call_wp_die_handler(
            array($menu, 'handle_export_csv'),
            array(),
            $admin_id
        );
        oras_reports_assert_same($result['response'], 400, 'Reports export missing nonce returns 400');
        oras_reports_assert(stripos($result['message'], 'Invalid request') !== false, 'Reports export missing nonce message is returned');

        $result = oras_reports_call_wp_die_handler(
            array($menu, 'handle_export_csv'),
            array(
                'oras_tickets_reports_nonce' => 'invalid_nonce_value',
            ),
            $admin_id
        );
        oras_reports_assert_same($result['response'], 400, 'Reports export invalid nonce returns 400');
        oras_reports_assert(stripos($result['message'], 'Invalid request') !== false, 'Reports export invalid nonce message is returned');

        $speaker_page = new Speaker_Reports_Page();

        $result = oras_reports_call_wp_die_handler(
            array($speaker_page, 'handle_export_csv'),
            array(
                'oras_speaker_reports_export_nonce' => wp_create_nonce('oras_speaker_reports_export_csv'),
            ),
            $subscriber_id
        );
        oras_reports_assert_same($result['response'], 403, 'Speaker export blocks unauthorized capability with 403');
        oras_reports_assert(stripos($result['message'], 'Not allowed') !== false, 'Speaker export unauthorized message is returned');

        $result = oras_reports_call_wp_die_handler(
            array($speaker_page, 'handle_export_csv'),
            array(),
            $admin_id
        );
        oras_reports_assert_same($result['response'], 0, 'Speaker export missing nonce returns default wp_die response');
        oras_reports_assert(stripos($result['message'], 'Invalid request') !== false, 'Speaker export missing nonce message is returned');

        $result = oras_reports_call_wp_die_handler(
            array($speaker_page, 'handle_export_csv'),
            array(
                'oras_speaker_reports_export_nonce' => 'invalid_nonce_value',
            ),
            $admin_id
        );
        oras_reports_assert_same($result['response'], 0, 'Speaker export invalid nonce returns default wp_die response');
        oras_reports_assert(stripos($result['message'], 'Invalid request') !== false, 'Speaker export invalid nonce message is returned');
    } finally {
        if ($subscriber_id > 0) {
            wp_delete_user($subscriber_id);
        }
    }
}

try {
    oras_reports_run_checks();
    echo "Reports integration checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Reports integration checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
