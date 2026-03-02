<?php
/**
 * Bootstrap regression checks for Phase 0-2 hardening.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/bootstrap-regression-checks.php
 */

use ORAS\Tickets\Bootstrap;
use ORAS\Tickets\Frontend\Door_Prizes;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasBootstrapRegressionException extends RuntimeException {}

/**
 * @throws OrasBootstrapRegressionException
 */
function orasBootstrapFail(string $message): void
{
    throw new OrasBootstrapRegressionException($message);
}

/**
 * @throws OrasBootstrapRegressionException
 */
function orasBootstrapAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasBootstrapFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

/**
 * @param mixed $actual
 * @param mixed $expected
 *
 * @throws OrasBootstrapRegressionException
 */
function orasBootstrapAssertSame($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        orasBootstrapFail(
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
 * @throws OrasBootstrapRegressionException
 */
function orasBootstrapRunChecks(): void
{
    if (! defined('ORAS_TICKETS_DIR')) {
        orasBootstrapFail('ORAS_TICKETS_DIR not defined. Ensure oras-tickets plugin is active.');
    }

    $has_tec = post_type_exists('tribe_events') || class_exists('Tribe__Events__Main');
    $has_woo = class_exists('WooCommerce');

    orasBootstrapAssert($has_tec, 'TEC dependency is available in runtime');
    orasBootstrapAssert($has_woo, 'WooCommerce dependency is available in runtime');

    $bootstrap = Bootstrap::instance();
    orasBootstrapAssert($bootstrap === Bootstrap::instance(), 'Bootstrap::instance is stable singleton');

    $bootstrap->init();
    orasBootstrapAssertSame(
        has_action('init', array($bootstrap, 'register_phase1')),
        20,
        'Bootstrap registers Phase 1 hook on init at priority 20'
    );

    $bootstrap->register_phase1();

    $required_actions = array(
        array('wp_ajax_oras_rsvp_dashboard_data', array($bootstrap, 'handle_rsvp_dashboard_data')),
        array('wp_ajax_oras_waitlist_queue_data', array($bootstrap, 'handle_waitlist_queue_data')),
        array('wp_ajax_oras_waitlist_bulk_promote', array($bootstrap, 'handle_waitlist_bulk_promote')),
        array('wp_ajax_oras_waitlist_promote_user', array($bootstrap, 'handle_waitlist_promote_user')),
        array('wp_ajax_oras_waitlist_remove_user', array($bootstrap, 'handle_waitlist_remove_user')),
        array('wp_ajax_oras_attendees_dashboard_data', array($bootstrap, 'handle_attendees_dashboard_data')),
        array('wp_ajax_oras_attendees_send_email', array($bootstrap, 'handle_attendees_send_email')),
        array('wp_ajax_oras_attendees_save_note', array($bootstrap, 'handle_attendees_save_note')),
        array('admin_post_oras_attendees_export_csv', array($bootstrap, 'handle_attendees_export_csv')),
        array('admin_post_oras_rsvp_export_yes', array($bootstrap, 'handle_rsvp_export_yes')),
        array('admin_post_oras_rsvp_export_waitlist', array($bootstrap, 'handle_rsvp_export_waitlist')),
        array('admin_post_oras_rsvp_promote', array($bootstrap, 'handle_rsvp_promote')),
    );

    foreach ($required_actions as $entry) {
        $hook     = (string) $entry[0];
        $callback = $entry[1];
        orasBootstrapAssert(
            has_action($hook, $callback) !== false,
            'Required hook registered: ' . $hook
        );
    }

    orasBootstrapAssert(
        has_filter('the_content', array(Door_Prizes::class, 'append_to_content')) !== false,
        'Door prizes frontend renderer is attached to the_content'
    );
}

try {
    orasBootstrapRunChecks();
    echo "Bootstrap regression checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Bootstrap regression checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
