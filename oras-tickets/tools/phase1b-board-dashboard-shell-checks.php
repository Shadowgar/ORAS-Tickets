<?php
/**
 * Phase 1B board dashboard shell checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase1b-board-dashboard-shell-checks.php
 */

use ORAS\Tickets\Frontend\Board_Reports;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasPhase1bBoardDashboardShellException extends RuntimeException {}

function orasPhase1bFail(string $message): void
{
    throw new OrasPhase1bBoardDashboardShellException($message);
}

function orasPhase1bAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasPhase1bFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1bRunChecks(): void
{
    if (! shortcode_exists('oras_board_reports')) {
        Board_Reports::register();
    }

    orasPhase1bAssert(shortcode_exists('oras_board_reports'), '[oras_board_reports] shortcode is registered');
    orasPhase1bAssert(
        has_action('admin_post_oras_board_reports_export_spreadsheet', array(Board_Reports::class, 'handle_export_spreadsheet')) !== false,
        'Spreadsheet export action remains registered'
    );
    orasPhase1bAssert(
        has_action('admin_post_oras_board_reports_export_pdf', array(Board_Reports::class, 'handle_export_pdf')) !== false,
        'PDF export action remains registered'
    );

    $role_name = 'phase1b_board_exporter';
    add_role(
        $role_name,
        'Phase 1B Board Exporter',
        array(
            'read'                             => true,
            'oras_tickets_view_board_dashboard' => true,
            'oras_tickets_export_reports'       => true,
        )
    );

    $user_suffix = wp_generate_password(8, false);
    $user_id = wp_create_user(
        'phase1b_board_' . $user_suffix,
        wp_generate_password(20, true, true),
        'phase1b_board_' . $user_suffix . '@example.org'
    );
    orasPhase1bAssert(is_int($user_id) && $user_id > 0, 'Fixture board report user created');

    $user = get_user_by('id', $user_id);
    orasPhase1bAssert($user instanceof WP_User, 'Fixture board report user loaded');
    $user->set_role($role_name);
    wp_set_current_user($user_id);

    $_GET = array();
    $default_html = Board_Reports::render_shortcode();

    orasPhase1bAssert(str_contains($default_html, 'oras-board-reports'), '[oras_board_reports] renders board reports wrapper');
    orasPhase1bAssert(str_contains($default_html, 'Event Management Dashboard'), 'Dashboard shell heading renders');
    orasPhase1bAssert(str_contains($default_html, 'Ticket Sales'), 'Ticket Sales tab renders');
    orasPhase1bAssert(str_contains($default_html, 'RSVPs'), 'RSVPs tab renders');
    orasPhase1bAssert(str_contains($default_html, 'Communications'), 'Communications tab renders');
    orasPhase1bAssert(str_contains($default_html, 'Attendees'), 'Attendees tab renders');
    orasPhase1bAssert(str_contains($default_html, 'Event Statistics'), 'Event Statistics tab renders');
    orasPhase1bAssert(str_contains($default_html, 'Create Spreadsheet'), 'Existing spreadsheet export link renders');
    orasPhase1bAssert(str_contains($default_html, 'Create PDF'), 'Existing PDF export link renders');
    orasPhase1bAssert(str_contains($default_html, 'oras_board_report_type'), 'Existing report filter remains present');
    orasPhase1bAssert(str_contains($default_html, 'oras_board_after'), 'Existing date filters remain present');
    orasPhase1bAssert(str_contains($default_html, 'oras_board_status'), 'Existing status filter remains present');
    orasPhase1bAssert(str_contains($default_html, 'oras_board_search'), 'Existing search filter remains present');

    $_GET = array('oras_board_tab' => 'communications');
    $communications_html = Board_Reports::render_shortcode();
    orasPhase1bAssert(str_contains($communications_html, 'Communications tools will be added in Phase 1D.'), 'Communications placeholder renders');

    $_GET = array('oras_board_tab' => 'attendees');
    $attendees_html = Board_Reports::render_shortcode();
    orasPhase1bAssert(str_contains($attendees_html, 'Show Attendees'), 'Attendees tab renders implemented dashboard');

    $_GET = array('oras_board_tab' => 'statistics');
    $statistics_html = Board_Reports::render_shortcode();
    orasPhase1bAssert(str_contains($statistics_html, 'Show Statistics'), 'Event Statistics tab renders implemented dashboard');

    wp_delete_user((int) $user_id);
    remove_role($role_name);
    wp_set_current_user(0);
}

try {
    orasPhase1bRunChecks();
    echo "Phase 1B board dashboard shell checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 1B board dashboard shell checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
