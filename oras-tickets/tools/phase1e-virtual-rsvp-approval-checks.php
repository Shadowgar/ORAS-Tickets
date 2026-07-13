<?php
/**
 * Phase 1E virtual RSVP approval workflow checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase1e-virtual-rsvp-approval-checks.php
 */

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Frontend\Event_RSVP;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasPhase1eVirtualApprovalException extends RuntimeException {}

function orasPhase1eFail(string $message): void
{
    throw new OrasPhase1eVirtualApprovalException($message);
}

function orasPhase1eAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasPhase1eFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1eAssertSame($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        orasPhase1eFail(
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

function orasPhase1eRunChecks(): void
{
    if (! shortcode_exists('oras_board_reports')) {
        Board_Reports::register();
    }
    orasPhase1eAssert(false !== has_action('admin_post_oras_board_reports_update_rsvp_approval'), 'Approval admin-post action is registered');

    Communication_Log_Store::install_schema();

    $suffix = wp_generate_password(8, false);
    $event_id = wp_insert_post(
        array(
            'post_type'    => 'tribe_events',
            'post_status'  => 'publish',
            'post_title'   => 'ORAS Phase1E Virtual Approval &#8211; ' . $suffix,
            'post_content' => 'Phase 1E event details.',
        ),
        true
    );
    orasPhase1eAssert(is_int($event_id) && $event_id > 0, 'Fixture event created');
    update_post_meta($event_id, '_oras_rsvp_v1', array('enabled' => true, 'capacity' => 100, 'waitlist_enabled' => true));
    update_post_meta($event_id, '_EventVideoSource', 'zoom');
    update_post_meta($event_id, '_EventZoomMeetingLink', 'https://example.org/private-zoom-' . $suffix);

    $manager_role = 'phase1e_rsvp_manager';
    $viewer_role = 'phase1e_board_viewer';
    add_role($manager_role, 'Phase 1E RSVP Manager', array('read' => true, 'oras_tickets_view_board_dashboard' => true, 'oras_tickets_manage_rsvps' => true));
    add_role($viewer_role, 'Phase 1E Board Viewer', array('read' => true, 'oras_tickets_view_board_dashboard' => true));

    $manager_id = wp_create_user('phase1e_manager_' . $suffix, wp_generate_password(20, true, true), 'phase1e_manager_' . $suffix . '@example.org');
    $viewer_id = wp_create_user('phase1e_viewer_' . $suffix, wp_generate_password(20, true, true), 'phase1e_viewer_' . $suffix . '@example.org');
    $attendee_id = wp_create_user('phase1e_attendee_' . $suffix, wp_generate_password(20, true, true), 'phase1e_attendee_' . $suffix . '@example.org');
    orasPhase1eAssert(is_int($manager_id) && $manager_id > 0, 'Manager user created');
    orasPhase1eAssert(is_int($viewer_id) && $viewer_id > 0, 'Viewer user created');
    orasPhase1eAssert(is_int($attendee_id) && $attendee_id > 0, 'Attendee user created');

    $manager = get_user_by('id', $manager_id);
    $viewer = get_user_by('id', $viewer_id);
    orasPhase1eAssert($manager instanceof WP_User, 'Manager user loaded');
    orasPhase1eAssert($viewer instanceof WP_User, 'Viewer user loaded');
    $manager->set_role($manager_role);
    $viewer->set_role($viewer_role);

    update_user_meta($attendee_id, '_oras_rsvp_event_' . $event_id, 'yes');
    update_user_meta($attendee_id, '_oras_rsvp_event_' . $event_id . '_attendance_mode', Ticket::ATTENDANCE_MODE_VIRTUAL);
    update_user_meta($attendee_id, '_oras_rsvp_event_' . $event_id . '_approval_status', Event_RSVP::APPROVAL_STATUS_PENDING);
    update_user_meta(
        $attendee_id,
        '_oras_rsvp_event_' . $event_id . '_contact',
        array(
            'first_name' => 'Phase1E',
            'last_name'  => 'Virtual RSVP',
            'email'      => 'phase1e_attendee_' . $suffix . '@example.org',
        )
    );

    wp_set_current_user($viewer_id);
    $viewer_result = Event_RSVP::update_approval_status($event_id, $attendee_id, Event_RSVP::APPROVAL_STATUS_APPROVED);
    orasPhase1eAssert(is_wp_error($viewer_result), 'Approve action requires manage RSVPs capability');
    orasPhase1eAssertSame(Event_RSVP::get_user_approval_status($event_id, $attendee_id), Event_RSVP::APPROVAL_STATUS_PENDING, 'Unauthorized approve does not change approval status');

    wp_set_current_user($manager_id);
    $approve_result = Event_RSVP::update_approval_status($event_id, $attendee_id, Event_RSVP::APPROVAL_STATUS_APPROVED);
    orasPhase1eAssert(! is_wp_error($approve_result), 'Authorized approve action succeeds');
    orasPhase1eAssertSame(Event_RSVP::get_user_approval_status($event_id, $attendee_id), Event_RSVP::APPROVAL_STATUS_APPROVED, 'Approve updates approval status');
    orasPhase1eAssertSame(Event_RSVP::get_user_approved_by($event_id, $attendee_id), (int) $manager_id, 'Approve captures approved-by user ID');
    orasPhase1eAssert(Event_RSVP::get_user_approved_at($event_id, $attendee_id) !== '', 'Approve captures approved-at timestamp');

    $approval_email = Event_RSVP::build_virtual_approval_email($event_id, $attendee_id, Event_RSVP::APPROVAL_STATUS_APPROVED);
    orasPhase1eAssert(! str_contains((string) ($approval_email['subject'] ?? ''), '&#8211;'), 'Approval email subject decodes event title entities');
    orasPhase1eAssert(str_contains((string) ($approval_email['body'] ?? ''), '<!doctype html>'), 'Approval email renders HTML body');
    orasPhase1eAssert(str_contains((string) ($approval_email['body'] ?? ''), 'Virtual RSVP approved'), 'Approval email uses styled approval heading');
    orasPhase1eAssert(str_contains((string) ($approval_email['body'] ?? ''), 'Join Virtual Event'), 'Approval email includes join CTA');
    orasPhase1eAssert(str_contains((string) ($approval_email['body'] ?? ''), 'https://example.org/private-zoom-' . $suffix), 'Approval email includes virtual meeting link');

    $reject_result = Event_RSVP::update_approval_status($event_id, $attendee_id, Event_RSVP::APPROVAL_STATUS_REJECTED, 'Capacity changed');
    orasPhase1eAssert(! is_wp_error($reject_result), 'Authorized reject action succeeds');
    orasPhase1eAssertSame(Event_RSVP::get_user_approval_status($event_id, $attendee_id), Event_RSVP::APPROVAL_STATUS_REJECTED, 'Reject updates approval status');
    orasPhase1eAssertSame(Event_RSVP::get_user_rejection_reason($event_id, $attendee_id), 'Capacity changed', 'Reject stores rejection reason');

    $rejection_email = Event_RSVP::build_virtual_approval_email($event_id, $attendee_id, Event_RSVP::APPROVAL_STATUS_REJECTED, 'Capacity changed');
    orasPhase1eAssert(str_contains((string) ($rejection_email['body'] ?? ''), '<!doctype html>'), 'Rejection email renders HTML body');
    orasPhase1eAssert(! str_contains((string) ($rejection_email['body'] ?? ''), 'https://example.org/private-zoom-' . $suffix), 'Rejection email does not include virtual meeting link');

    $pending_result = Event_RSVP::update_approval_status($event_id, $attendee_id, Event_RSVP::APPROVAL_STATUS_PENDING);
    orasPhase1eAssert(! is_wp_error($pending_result), 'Return to pending action succeeds');
    orasPhase1eAssertSame(Event_RSVP::get_user_approval_status($event_id, $attendee_id), Event_RSVP::APPROVAL_STATUS_PENDING, 'Return to pending updates approval status');

    $_GET = array(
        'oras_board_tab'             => 'rsvps',
        'oras_board_event_id'        => (string) $event_id,
        'oras_board_search'          => 'Phase1E',
        'oras_board_attendance_type' => 'all',
        'oras_board_approval_status' => 'all',
    );
    $html = Board_Reports::render_shortcode();
    orasPhase1eAssert(str_contains($html, 'oras_board_rsvp_approval_nonce'), 'RSVP tab renders approval nonce field');
    orasPhase1eAssert(str_contains($html, 'Approve'), 'RSVP tab renders approve action');
    orasPhase1eAssert(str_contains($html, 'Reject'), 'RSVP tab renders reject action');
    orasPhase1eAssert(str_contains($html, 'Return to Pending'), 'RSVP tab renders return-to-pending action');
    orasPhase1eAssert(str_contains($html, 'View Details'), 'RSVP tab renders details action');
    orasPhase1eAssert(str_contains($html, 'Approved By'), 'RSVP tab renders approved-by column');
    orasPhase1eAssert(str_contains($html, 'Approved Date'), 'RSVP tab renders approved date column');

    $logs = Communication_Log_Store::query(array('event_id' => $event_id, 'limit' => 20));
    $related = wp_list_pluck($logs, 'related_action_type');
    orasPhase1eAssert(in_array('virtual_rsvp_approved', $related, true), 'Approval communication is logged');
    orasPhase1eAssert(in_array('virtual_rsvp_rejected', $related, true), 'Rejection communication is logged');

    wp_delete_user((int) $attendee_id);
    wp_delete_user((int) $viewer_id);
    wp_delete_user((int) $manager_id);
    wp_delete_post((int) $event_id, true);
    remove_role($manager_role);
    remove_role($viewer_role);
    wp_set_current_user(0);
}

try {
    orasPhase1eRunChecks();
    echo "Phase 1E virtual RSVP approval checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 1E virtual RSVP approval checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
