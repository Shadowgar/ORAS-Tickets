<?php
/**
 * Phase 1G waitlist, cancellation, and board promotion checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase1g-waitlist-cancellation-checks.php
 */

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Waitlist_Store;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasPhase1gWaitlistCancellationException extends RuntimeException {}

function orasPhase1gFail(string $message): void
{
    throw new OrasPhase1gWaitlistCancellationException($message);
}

function orasPhase1gAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasPhase1gFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1gAssertSame($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        orasPhase1gFail(
            sprintf(
                '%s. Expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1gCreateUser(string $prefix, string $suffix): int
{
    $user_id = wp_create_user(
        $prefix . '_' . $suffix,
        wp_generate_password(20, true, true),
        $prefix . '_' . $suffix . '@example.org'
    );

    if (! is_int($user_id) || $user_id <= 0) {
        orasPhase1gFail('Unable to create user: ' . $prefix);
    }

    return $user_id;
}

function orasPhase1gStoreRsvp(int $event_id, int $user_id, string $status, string $attendance_mode, string $approval_status = Event_RSVP::APPROVAL_STATUS_APPROVED): void
{
    update_user_meta($user_id, '_oras_rsvp_event_' . $event_id, $status);
    update_user_meta($user_id, '_oras_rsvp_event_' . $event_id . '_attendance_mode', $attendance_mode);
    update_user_meta($user_id, '_oras_rsvp_event_' . $event_id . '_approval_status', $approval_status);
    update_user_meta(
        $user_id,
        '_oras_rsvp_event_' . $event_id . '_contact',
        array(
            'first_name' => 'Phase1G',
            'last_name'  => 'Attendee ' . $user_id,
            'email'      => 'phase1g_' . $user_id . '@example.org',
            'phone'      => '555-0100',
            'note'       => 'Phase 1G note',
        )
    );
}

function orasPhase1gRunChecks(): void
{
    if (! shortcode_exists('oras_board_reports')) {
        Board_Reports::register();
    }
    Event_RSVP::register();
    Communication_Log_Store::install_schema();

    orasPhase1gAssert(false !== has_action('admin_post_oras_board_reports_update_waitlist'), 'Board waitlist admin-post action is registered');
    orasPhase1gAssert(false !== has_action('admin_post_oras_rsvp_cancel_confirm'), 'Logged-in cancellation admin-post action is registered');
    orasPhase1gAssert(false !== has_action('admin_post_nopriv_oras_rsvp_cancel_confirm'), 'Signed logged-out cancellation admin-post action is registered');

    $suffix = wp_generate_password(8, false);
    $event_id = wp_insert_post(
        array(
            'post_type'    => 'tribe_events',
            'post_status'  => 'publish',
            'post_title'   => 'ORAS Phase1G Waitlist ' . $suffix,
            'post_content' => 'Phase 1G event details.',
        ),
        true
    );
    orasPhase1gAssert(is_int($event_id) && $event_id > 0, 'Fixture event created');
    update_post_meta($event_id, '_oras_rsvp_v1', array('enabled' => true, 'capacity' => 1, 'waitlist_enabled' => true));
    update_post_meta($event_id, '_EventZoomMeetingLink', 'https://example.org/private-phase1g-zoom-' . $suffix);

    $board_role = 'phase1g_board_manager';
    add_role($board_role, 'Phase 1G Board Manager', array('read' => true, 'oras_tickets_view_board_dashboard' => true, 'oras_tickets_manage_rsvps' => true));

    $board_id = orasPhase1gCreateUser('phase1g_board', $suffix);
    $onsite_confirmed_id = orasPhase1gCreateUser('phase1g_confirmed_onsite', $suffix);
    $onsite_waitlist_id = orasPhase1gCreateUser('phase1g_waitlist_onsite', $suffix);
    $virtual_waitlist_id = orasPhase1gCreateUser('phase1g_waitlist_virtual', $suffix);
    $older_fallback_id = orasPhase1gCreateUser('phase1g_waitlist_fallback', $suffix);

    $board = get_user_by('id', $board_id);
    orasPhase1gAssert($board instanceof WP_User, 'Board user loaded');
    $board->set_role($board_role);
    wp_set_current_user($board_id);

    orasPhase1gStoreRsvp($event_id, $onsite_confirmed_id, 'yes', Ticket::ATTENDANCE_MODE_ONSITE);
    orasPhase1gStoreRsvp($event_id, $onsite_waitlist_id, 'waitlist', Ticket::ATTENDANCE_MODE_ONSITE);
    orasPhase1gStoreRsvp($event_id, $virtual_waitlist_id, 'waitlist', Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_PENDING);
    orasPhase1gStoreRsvp($event_id, $older_fallback_id, 'waitlist', Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_PENDING);
    Waitlist_Store::mark_waiting($event_id, $older_fallback_id, 'phase1g', $board_id, time() - 30);
    Waitlist_Store::mark_waiting($event_id, $onsite_waitlist_id, 'phase1g', $board_id, time() - 20);
    Waitlist_Store::mark_waiting($event_id, $virtual_waitlist_id, 'phase1g', $board_id, time() - 10);

    $_GET = array(
        'oras_board_tab'             => 'rsvps',
        'oras_board_event_id'        => (string) $event_id,
        'oras_board_search'          => '',
        'oras_board_attendance_type' => 'all',
        'oras_board_approval_status' => 'all',
    );
    $html = Board_Reports::render_shortcode();
    orasPhase1gAssert(str_contains($html, 'Waitlist Queue'), 'Board Reports RSVP tab renders dedicated waitlist section');
    orasPhase1gAssert(str_contains($html, 'Open Seat + Promote'), 'Board Reports waitlist section renders open-seat promote action');
    orasPhase1gAssert(str_contains($html, 'Remove from Waitlist'), 'Board Reports waitlist section renders remove action');

    $promotion_blocked = Event_RSVP::promote_waitlist_user($event_id, $onsite_waitlist_id, $board_id, 'phase1g-test', true);
    orasPhase1gAssert(is_wp_error($promotion_blocked), 'Promote respects capacity');
    orasPhase1gAssertSame(Event_RSVP::get_user_status($event_id, $onsite_waitlist_id), 'waitlist', 'Blocked promote leaves selected user waitlisted');

    $open_promote = Event_RSVP::open_seat_and_promote_waitlist_user($event_id, $onsite_waitlist_id, $board_id, 'phase1g-test');
    orasPhase1gAssert(! is_wp_error($open_promote), 'Open Seat + Promote succeeds');
    orasPhase1gAssertSame((int) get_post_meta($event_id, '_oras_rsvp_v1', true)['capacity'], 2, 'Open Seat + Promote increments RSVP capacity');
    orasPhase1gAssertSame(Event_RSVP::get_user_status($event_id, $onsite_waitlist_id), 'yes', 'Open Seat + Promote confirms selected waitlisted user');

    $cancel_url = Event_RSVP::create_cancellation_url($event_id, $onsite_confirmed_id);
    orasPhase1gAssert(str_contains($cancel_url, 'oras_rsvp_cancel=1'), 'Cancellation URL contains confirmation flag');
    $parts = wp_parse_url($cancel_url);
    parse_str((string) ($parts['query'] ?? ''), $query);
    $token = isset($query['token']) ? (string) $query['token'] : '';
    orasPhase1gAssert($token !== '', 'Cancellation URL contains signed token');
    orasPhase1gAssert(Event_RSVP::validate_cancellation_token($event_id, $onsite_confirmed_id, $token), 'Fresh cancellation token validates');
    orasPhase1gAssert(! Event_RSVP::validate_cancellation_token($event_id, $onsite_confirmed_id, 'bad-token'), 'Invalid cancellation token is rejected');

    $render = Event_RSVP::render_cancellation_confirmation($event_id, $onsite_confirmed_id, $token);
    orasPhase1gAssert(str_contains($render, 'Cancel RSVP'), 'Valid cancellation token renders confirmation button before mutation');
    orasPhase1gAssertSame(Event_RSVP::get_user_status($event_id, $onsite_confirmed_id), 'yes', 'Rendering confirmation does not mutate RSVP');

    $cancelled = Event_RSVP::cancel_rsvp_with_token($event_id, $onsite_confirmed_id, $token);
    orasPhase1gAssert(! is_wp_error($cancelled), 'Confirmed cancellation with valid token succeeds');
    orasPhase1gAssertSame(Event_RSVP::get_user_status($event_id, $onsite_confirmed_id), 'no', 'Confirmed cancellation changes RSVP to no');
    orasPhase1gAssert(! Event_RSVP::validate_cancellation_token($event_id, $onsite_confirmed_id, $token), 'Cancellation token is invalidated after use');
    orasPhase1gAssertSame(Event_RSVP::get_user_status($event_id, $older_fallback_id), 'yes', 'Cancellation falls back to oldest overall waitlist row when no same-mode row remains');

    $virtual_cancelled_id = orasPhase1gCreateUser('phase1g_confirmed_virtual', $suffix);
    $virtual_waitlist_2_id = orasPhase1gCreateUser('phase1g_waitlist_virtual_2', $suffix);
    update_post_meta($event_id, '_oras_rsvp_v1', array('enabled' => true, 'capacity' => 3, 'waitlist_enabled' => true));
    orasPhase1gStoreRsvp($event_id, $virtual_cancelled_id, 'yes', Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_APPROVED);
    orasPhase1gStoreRsvp($event_id, $virtual_waitlist_2_id, 'waitlist', Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_PENDING);
    Waitlist_Store::mark_waiting($event_id, $virtual_waitlist_2_id, 'phase1g', $board_id, time() - 5);
    $virtual_cancel_url = Event_RSVP::create_cancellation_url($event_id, $virtual_cancelled_id);
    $virtual_parts = wp_parse_url($virtual_cancel_url);
    parse_str((string) ($virtual_parts['query'] ?? ''), $virtual_query);
    $virtual_token = isset($virtual_query['token']) ? (string) $virtual_query['token'] : '';
    $virtual_cancelled = Event_RSVP::cancel_rsvp_with_token($event_id, $virtual_cancelled_id, $virtual_token);
    orasPhase1gAssert(! is_wp_error($virtual_cancelled), 'Virtual confirmed cancellation succeeds');
    orasPhase1gAssertSame(Event_RSVP::get_user_status($event_id, $virtual_waitlist_id), 'yes', 'Virtual cancellation promotes oldest virtual waitlist first');
    orasPhase1gAssertSame(Event_RSVP::get_user_status($event_id, $virtual_waitlist_2_id), 'waitlist', 'Younger virtual waitlist row remains waiting');
    orasPhase1gAssertSame(Event_RSVP::get_user_approval_status($event_id, $virtual_waitlist_id), Event_RSVP::APPROVAL_STATUS_PENDING, 'Virtual waitlist promotion preserves approval status');

    $logs = Communication_Log_Store::query(array('event_id' => $event_id, 'limit' => 50));
    $related = wp_list_pluck($logs, 'related_action_type');
    orasPhase1gAssert(in_array('rsvp_cancelled', $related, true), 'Cancellation communication log is recorded');
    orasPhase1gAssert(in_array('waitlist_auto_promoted', $related, true), 'Auto-promotion communication log is recorded');

    foreach (array($board_id, $onsite_confirmed_id, $onsite_waitlist_id, $virtual_waitlist_id, $older_fallback_id, $virtual_cancelled_id, $virtual_waitlist_2_id) as $user_id) {
        wp_delete_user((int) $user_id);
    }
    wp_delete_post((int) $event_id, true);
    remove_role($board_role);
    wp_set_current_user(0);
}

try {
    orasPhase1gRunChecks();
    echo "Phase 1G waitlist and cancellation checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 1G waitlist and cancellation checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
