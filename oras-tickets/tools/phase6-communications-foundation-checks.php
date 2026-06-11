<?php
/**
 * Phase 6 communications foundation checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase6-communications-foundation-checks.php
 */

use ORAS\Tickets\Capabilities;
use ORAS\Tickets\Communication_Log_Store;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasPhase6CommunicationsFoundationException extends RuntimeException {}

function orasPhase6Fail(string $message): void
{
    throw new OrasPhase6CommunicationsFoundationException($message);
}

function orasPhase6Assert(bool $condition, string $message): void
{
    if (! $condition) {
        orasPhase6Fail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase6AssertSame($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        orasPhase6Fail(
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

function orasPhase6RunFoundationChecks(): void
{
    if (! function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }

    if (! class_exists(Communication_Log_Store::class)) {
        orasPhase6Fail('Communication_Log_Store class exists');
    }

    Communication_Log_Store::install_schema();
    orasPhase6Assert(Communication_Log_Store::table_exists(), 'Communication log table exists after install');

    $event_id = wp_insert_post(
        array(
            'post_title'  => 'ORAS Phase6 Communications ' . wp_generate_password(6, false),
            'post_status' => 'publish',
            'post_type'   => 'tribe_events',
        )
    );
    orasPhase6Assert(is_int($event_id) && $event_id > 0, 'Fixture event created');

    $sender_suffix = wp_generate_password(8, false);
    $sender_id = wp_create_user(
        'phase6_sender_' . $sender_suffix,
        wp_generate_password(20, true, true),
        'phase6_sender_' . $sender_suffix . '@example.org'
    );
    orasPhase6Assert(is_int($sender_id) && $sender_id > 0, 'Fixture sender user created');
    $sender = get_user_by('id', $sender_id);
    orasPhase6Assert($sender instanceof WP_User, 'Fixture sender user loaded');

    $log_id = Communication_Log_Store::insert(
        array(
            'event_id'               => (int) $event_id,
            'sender_user_id'         => (int) $sender_id,
            'sender_display_name'    => (string) $sender->display_name,
            'sender_email'           => (string) $sender->user_email,
            'recipient_segment'      => 'ticket_purchasers',
            'recipient_count'        => 3,
            'email_subject'          => '=Phase 6 subject',
            'email_body_snapshot'    => 'Phase 6 body snapshot',
            'sent_at'                => '2026-06-10 12:00:00',
            'send_status'            => 'sent',
            'failed_recipient_count' => 0,
            'related_action_type'    => 'event_update',
        )
    );
    orasPhase6Assert($log_id > 0, 'Communication log insert returns row id');

    $rows = Communication_Log_Store::query(
        array(
            'event_id' => (int) $event_id,
            'search'   => 'Phase 6 subject',
        )
    );
    orasPhase6AssertSame(count($rows), 1, 'Communication log query returns matching row');
    orasPhase6AssertSame((int) $rows[0]['sender_user_id'], (int) $sender_id, 'Communication log stores sender user id');
    orasPhase6AssertSame((string) $rows[0]['sender_email'], (string) $sender->user_email, 'Communication log stores sender email');
    orasPhase6AssertSame((string) $rows[0]['email_subject'], '=Phase 6 subject', 'Communication log stores raw subject snapshot');

    $detail = Communication_Log_Store::get((int) $log_id);
    orasPhase6Assert(is_array($detail), 'Communication log detail loads by id');
    orasPhase6AssertSame((string) $detail['related_action_type'], 'event_update', 'Communication log stores related action type');

    wp_delete_post((int) $event_id, true);
    wp_delete_user((int) $sender_id);

    $created_board_role = false;
    if (! get_role('board')) {
        add_role('board', 'Board', array('read' => true));
        $created_board_role = true;
    }

    Capabilities::ensure_board_communication_caps();

    $board_role = get_role('board');
    orasPhase6Assert($board_role instanceof WP_Role, 'Board role exists for capability test');
    orasPhase6Assert($board_role->has_cap('oras_tickets_view_board_dashboard'), 'Board role can view board dashboard');
    orasPhase6Assert($board_role->has_cap('oras_tickets_send_notifications'), 'Board role can send notifications');
    orasPhase6Assert($board_role->has_cap('oras_tickets_manage_rsvps'), 'Board role can manage RSVP approvals');

    $board_user_suffix = wp_generate_password(8, false);
    $board_user_id = wp_create_user(
        'phase6_board_' . $board_user_suffix,
        wp_generate_password(20, true, true),
        'phase6_board_' . $board_user_suffix . '@example.org'
    );
    orasPhase6Assert(is_int($board_user_id) && $board_user_id > 0, 'Board-only fixture user created');

    $board_user = get_user_by('id', $board_user_id);
    orasPhase6Assert($board_user instanceof WP_User, 'Board-only fixture user loaded');
    $board_user->set_role('board');
    orasPhase6Assert(user_can($board_user_id, 'oras_tickets_view_board_dashboard'), 'Board-only user can view board dashboard');
    orasPhase6Assert(user_can($board_user_id, 'oras_tickets_send_notifications'), 'Board-only user can send notifications');

    $board_member_role = get_role('board_member');
    if ($board_member_role) {
        orasPhase6Assert($board_member_role->has_cap('oras_tickets_view_board_dashboard'), 'Board Member role can view board dashboard');
        orasPhase6Assert($board_member_role->has_cap('oras_tickets_send_notifications'), 'Board Member role can send notifications');
    } else {
        echo "SKIP: Board Member role does not exist in this environment.\n";
    }

    wp_delete_user((int) $board_user_id);
    if ($created_board_role) {
        remove_role('board');
    }
}

try {
    orasPhase6RunFoundationChecks();
    echo "Phase 6 communications foundation checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 6 communications foundation checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
