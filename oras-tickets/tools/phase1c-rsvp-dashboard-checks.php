<?php
/**
 * Phase 1C RSVP dashboard checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase1c-rsvp-dashboard-checks.php
 */

use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Reporting\Board_Report_Service;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasPhase1cRsvpDashboardException extends RuntimeException {}

function orasPhase1cFail(string $message): void
{
    throw new OrasPhase1cRsvpDashboardException($message);
}

function orasPhase1cAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasPhase1cFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1cAssertSame($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        orasPhase1cFail(
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

function orasPhase1cRunChecks(): void
{
    if (! shortcode_exists('oras_board_reports')) {
        Board_Reports::register();
    }

    $role_name = 'phase1c_board_exporter';
    add_role(
        $role_name,
        'Phase 1C Board Exporter',
        array(
            'read'                               => true,
            'oras_tickets_view_board_dashboard' => true,
            'oras_tickets_export_reports'       => true,
        )
    );

    $suffix = wp_generate_password(8, false);
    $event_id = wp_insert_post(
        array(
            'post_type'   => 'tribe_events',
            'post_status' => 'publish',
            'post_title'  => 'ORAS Phase1C RSVP ' . $suffix,
        ),
        true
    );
    orasPhase1cAssert(is_int($event_id) && $event_id > 0, 'Fixture event created');

    update_post_meta(
        $event_id,
        '_oras_rsvp_v1',
        array(
            'enabled'          => true,
            'capacity'         => 100,
            'waitlist_enabled' => true,
        )
    );

    $board_user_id = wp_create_user(
        'phase1c_board_' . $suffix,
        wp_generate_password(20, true, true),
        'phase1c_board_' . $suffix . '@example.org'
    );
    orasPhase1cAssert(is_int($board_user_id) && $board_user_id > 0, 'Fixture board user created');
    $board_user = get_user_by('id', $board_user_id);
    orasPhase1cAssert($board_user instanceof WP_User, 'Fixture board user loaded');
    $board_user->set_role($role_name);

    $legacy_user_id = wp_create_user(
        'phase1c_legacy_' . $suffix,
        wp_generate_password(20, true, true),
        'phase1c_legacy_' . $suffix . '@example.org'
    );
    $virtual_user_id = wp_create_user(
        'phase1c_virtual_' . $suffix,
        wp_generate_password(20, true, true),
        'phase1c_virtual_' . $suffix . '@example.org'
    );
    orasPhase1cAssert(is_int($legacy_user_id) && $legacy_user_id > 0, 'Legacy RSVP fixture user created');
    orasPhase1cAssert(is_int($virtual_user_id) && $virtual_user_id > 0, 'Virtual RSVP fixture user created');

    $legacy_user = get_user_by('id', $legacy_user_id);
    $virtual_user = get_user_by('id', $virtual_user_id);
    orasPhase1cAssert($legacy_user instanceof WP_User, 'Legacy RSVP fixture user loaded');
    orasPhase1cAssert($virtual_user instanceof WP_User, 'Virtual RSVP fixture user loaded');

    wp_update_user(array('ID' => $legacy_user_id, 'display_name' => 'Phase1C Legacy RSVP'));
    wp_update_user(array('ID' => $virtual_user_id, 'display_name' => 'Phase1C Virtual RSVP'));

    update_user_meta($legacy_user_id, '_oras_rsvp_event_' . $event_id, 'yes');
    update_user_meta(
        $legacy_user_id,
        '_oras_rsvp_event_' . $event_id . '_contact',
        array(
            'first_name' => 'Phase1C',
            'last_name'  => 'Legacy RSVP',
            'email'      => 'phase1c_legacy_' . $suffix . '@example.org',
        )
    );

    update_user_meta($virtual_user_id, '_oras_rsvp_event_' . $event_id, 'yes');
    update_user_meta($virtual_user_id, '_oras_rsvp_event_' . $event_id . '_attendance_mode', Ticket::ATTENDANCE_MODE_VIRTUAL);
    update_user_meta($virtual_user_id, '_oras_rsvp_event_' . $event_id . '_approval_status', 'pending');
    update_user_meta(
        $virtual_user_id,
        '_oras_rsvp_event_' . $event_id . '_contact',
        array(
            'first_name' => 'Phase1C',
            'last_name'  => 'Virtual RSVP',
            'email'      => 'phase1c_virtual_' . $suffix . '@example.org',
        )
    );

    $service = new Board_Report_Service();
    $all_rows = $service->get_rows(
        Board_Report_Service::TYPE_RSVP,
        array(
            'event_id'        => $event_id,
            'status'          => 'all',
            'attendance_type' => 'all',
            'approval_status' => 'all',
        )
    );
    orasPhase1cAssert(count($all_rows) >= 2, 'RSVP report service returns fixture RSVP rows');

    $legacy_row = null;
    foreach ($all_rows as $row) {
        if (($row['email'] ?? '') === 'phase1c_legacy_' . $suffix . '@example.org') {
            $legacy_row = $row;
            break;
        }
    }

    orasPhase1cAssert(is_array($legacy_row), 'Legacy RSVP row is present');
    orasPhase1cAssertSame($legacy_row['attendance_type'] ?? '', Ticket::ATTENDANCE_MODE_ONSITE, 'Legacy RSVP missing attendance defaults to onsite');
    orasPhase1cAssertSame($legacy_row['approval_status'] ?? '', 'approved', 'Legacy RSVP missing approval defaults to approved');

    $virtual_rows = $service->get_rows(
        Board_Report_Service::TYPE_RSVP,
        array(
            'event_id'        => $event_id,
            'status'          => 'all',
            'attendance_type' => Ticket::ATTENDANCE_MODE_VIRTUAL,
            'approval_status' => 'all',
            'search'          => 'Phase1C',
        )
    );
    orasPhase1cAssertSame(count($virtual_rows), 1, 'RSVP report service filters virtual attendees');
    orasPhase1cAssertSame($virtual_rows[0]['approval_status'] ?? '', 'pending', 'Virtual RSVP keeps pending approval status');

    $pending_rows = $service->get_rows(
        Board_Report_Service::TYPE_RSVP,
        array(
            'event_id'        => $event_id,
            'status'          => 'all',
            'attendance_type' => 'all',
            'approval_status' => 'pending',
            'search'          => 'Phase1C',
        )
    );
    orasPhase1cAssertSame(count($pending_rows), 1, 'RSVP report service filters pending approval rows');

    wp_set_current_user($board_user_id);
    $_GET = array(
        'oras_board_tab'             => 'rsvps',
        'oras_board_event_id'        => (string) $event_id,
        'oras_board_search'          => 'Phase1C',
        'oras_board_attendance_type' => 'all',
        'oras_board_approval_status' => 'all',
    );
    $html = Board_Reports::render_shortcode();

    orasPhase1cAssert(str_contains($html, 'name="oras_board_attendance_type"'), 'RSVP tab renders attendance type filter');
    orasPhase1cAssert(str_contains($html, 'name="oras_board_approval_status"'), 'RSVP tab renders approval status filter');
    orasPhase1cAssert(str_contains($html, 'Phase1C Legacy RSVP'), 'RSVP tab renders legacy RSVP row');
    orasPhase1cAssert(str_contains($html, 'Phase1C Virtual RSVP'), 'RSVP tab renders virtual RSVP row');
    orasPhase1cAssert(str_contains($html, 'On Site'), 'RSVP tab renders onsite label');
    orasPhase1cAssert(str_contains($html, 'Virtual'), 'RSVP tab renders virtual label');
    orasPhase1cAssert(str_contains($html, 'Approved'), 'RSVP tab renders approved label');
    orasPhase1cAssert(str_contains($html, 'Pending'), 'RSVP tab renders pending label');
    orasPhase1cAssert(str_contains($html, 'Source'), 'RSVP tab includes source column');
    orasPhase1cAssert(str_contains($html, 'Create Spreadsheet'), 'RSVP tab reuses spreadsheet export link');
    orasPhase1cAssert(str_contains($html, 'Create PDF'), 'RSVP tab reuses PDF export link');

    wp_delete_user((int) $legacy_user_id);
    wp_delete_user((int) $virtual_user_id);
    wp_delete_user((int) $board_user_id);
    wp_delete_post((int) $event_id, true);
    remove_role($role_name);
    wp_set_current_user(0);
}

try {
    orasPhase1cRunChecks();
    echo "Phase 1C RSVP dashboard checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 1C RSVP dashboard checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
