<?php

namespace ORAS\Tickets;

use ORAS\Tickets\Security\CsvSafety;
use ORAS\Tickets\Support\Logger;

require_once ORAS_TICKETS_DIR . 'includes/Support/DbLock.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Domain/Meta.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket_Collection.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Domain/Pricing/Price_Resolver.php'; // NOSONAR legacy include
// Admin metabox for Phase 1.2
// Admin metabox is kept in repo but no longer auto-initialized; using native ET editor + provider.
require_once ORAS_TICKETS_DIR . 'includes/Admin/Tickets_Metabox.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Event_Addon_Metabox.php'; // NOSONAR legacy include
// Admin hub (Phase 2.9)
require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Speaker_CPT.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Event_Speakers_Metabox.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_Agenda_Metabox.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_RSVP_Metabox.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Reports_Aggregator.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Dashboard_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Reports_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Settings_Page.php'; // NOSONAR legacy include
// Frontend tickets display (Phase 1.3 - read-only)
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Tickets_Display.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Event_Agenda_Render.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Event_List_View.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Ticket_Print_Controller.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Virtual_Access.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Event_RSVP.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Board_Dashboard.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Board_Reports.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Door_Prizes.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/RSVP.php'; // NOSONAR include: helper
require_once ORAS_TICKETS_DIR . 'includes/Waitlist_Store.php'; // NOSONAR include: waitlist storage
require_once ORAS_TICKETS_DIR . 'includes/Communication_Log_Store.php'; // NOSONAR include: communications audit storage
require_once ORAS_TICKETS_DIR . 'includes/Communication_Recipients.php'; // NOSONAR include: communications recipient resolver
require_once ORAS_TICKETS_DIR . 'includes/Security/Csv_Safety.php'; // NOSONAR include: CSV export hardening helper
require_once ORAS_TICKETS_DIR . 'includes/Reporting/Contact_Normalizer.php'; // NOSONAR include: board-safe contact normalization
require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Exporter.php'; // NOSONAR include: board-safe CSV export
require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Service.php'; // NOSONAR include: board reports data service
require_once ORAS_TICKETS_DIR . 'includes/Security/Ticket_Checkin_Token.php'; // NOSONAR include: signed check-in token service
require_once ORAS_TICKETS_DIR . 'includes/Templates/Template_Loader.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Cart_Pricing.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Api/Member_Hub_Tickets.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Api/Checkin.php'; // NOSONAR include: check-in REST routes
require_once ORAS_TICKETS_DIR . 'src/Integrations/QuickBooks/Module.php'; // NOSONAR legacy include

if (! defined('ABSPATH')) {
    exit;
}

final class Bootstrap
{


    private static ?Bootstrap $instance = null;

    public static function instance(): Bootstrap
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void
    {
        Logger::instance()->log('Bootstrap init start');
        \ORAS\Tickets\Waitlist_Store::maybe_upgrade();
        \ORAS\Tickets\Communication_Log_Store::maybe_upgrade();
        if (class_exists(\ORAS\Tickets\Capabilities::class)) {
            \ORAS\Tickets\Capabilities::ensure_board_communication_caps();
        }

        // Hard deps: TEC (tribe_events) and WooCommerce.
        $has_tec = post_type_exists('tribe_events') || class_exists('Tribe__Events__Main');
        $has_woo = class_exists('WooCommerce');

        Logger::instance()->log('TEC present? ' . ($has_tec ? 'yes' : 'no'));
        Logger::instance()->log('WooCommerce present? ' . ($has_woo ? 'yes' : 'no'));

        if (! $has_tec || ! $has_woo) {
            add_action(
                'admin_notices',
                function () use ($has_tec, $has_woo) {
                    if (! current_user_can('activate_plugins')) {
                        return;
                    }
                    $missing = array();
                    if (! $has_tec) {
                        $missing[] = 'The Events Calendar (tribe_events)';
                    }
                    if (! $has_woo) {
                        $missing[] = 'WooCommerce';
                    }
                    printf(
                        '<div class="notice notice-error"><p><strong>ORAS Tickets</strong> requires: %s</p></div>',
                        esc_html(implode(', ', $missing))
                    );
                }
            );

            Logger::instance()->log('Bootstrap aborted: missing dependencies');
            return;
        }

        // Phase 1 modules will be loaded here next.
        add_action('init', array($this, 'register_phase1'), 20);

        Logger::instance()->log('Bootstrap init complete');
    }

    public function register_phase1(): void
    {
        // Register Phase 1 modules.
        Logger::instance()->log('Phase 1 registration hook fired (init)');

        require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Product_Sync.php'; // NOSONAR legacy include
        $ps = new \ORAS\Tickets\Commerce\Woo\Product_Sync();
        $ps->register();

        require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Stripe_Intent_Description.php'; // NOSONAR legacy include
        $sid = new \ORAS\Tickets\Commerce\Woo\Stripe_Intent_Description();
        $sid->register();

        require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Capacity_Consumption.php'; // NOSONAR legacy include
        $cc = new \ORAS\Tickets\Commerce\Woo\Capacity_Consumption();
        $cc->register();

        require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Order_Autocomplete.php'; // NOSONAR legacy include
        $oa = new \ORAS\Tickets\Commerce\Woo\Order_Autocomplete();
        $oa->register();

        \ORAS\Tickets\Commerce\Woo\Cart_Pricing::register();

        $quickbooks_module = new \ORAS\Tickets\Integrations\QuickBooks\Module();
        $quickbooks_module->register();

        $api = new \ORAS\Tickets\Api\Member_Hub_Tickets();
        $api->register();

        $checkin_api = new \ORAS\Tickets\Api\Checkin();
        $checkin_api->register();

        require_once ORAS_TICKETS_DIR . 'includes/Api/Rsvp.php'; // NOSONAR legacy include
        $rsvp_api = new \ORAS\Tickets\Api\Rsvp();
        $rsvp_api->register();

        $speaker_cpt = new \ORAS\Tickets\Admin\Speaker_CPT();
        $speaker_cpt->register();

        \ORAS\Tickets\Frontend\Event_Agenda_Render::register();
    \ORAS\Tickets\Frontend\Event_List_View::register();
        \ORAS\Tickets\Frontend\Virtual_Access::register();
        \ORAS\Tickets\Frontend\Event_RSVP::register();
        \ORAS\Tickets\Frontend\Board_Dashboard::register();
        \ORAS\Tickets\Frontend\Board_Reports::register();
        \ORAS\Tickets\Frontend\Door_Prizes::register();
        \ORAS\Tickets\Templates\Template_Loader::register();

        // Admin-only (or WP-CLI): register ticket metabox and admin hub.
        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            \ORAS\Tickets\Admin\Tickets_Metabox::instance()->init();
            $event_addon_metabox = new \ORAS\Tickets\Admin\Event_Addon_Metabox();
            $event_addon_metabox->register();
            \ORAS\Tickets\Admin\Metaboxes\Event_Agenda_Metabox::register();
            \ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Metabox::register();
            \ORAS\Tickets\Admin\Metaboxes\Event_Door_Prizes_Metabox::register();
            require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php'; // NOSONAR legacy include
            \ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Attendees_Metabox::register();
            require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php'; // NOSONAR legacy include
            $admin_menu = new \ORAS\Tickets\Admin\Admin_Menu();
            $admin_menu->register();

            // RSVP Dashboard handlers
            add_action('wp_ajax_oras_rsvp_dashboard_data', array($this, 'handle_rsvp_dashboard_data'));
            add_action('wp_ajax_oras_rsvp_remove_attendee', array($this, 'handle_rsvp_remove_attendee'));
            add_action('wp_ajax_oras_waitlist_queue_data', array($this, 'handle_waitlist_queue_data'));
            add_action('wp_ajax_oras_waitlist_bulk_promote', array($this, 'handle_waitlist_bulk_promote'));
            add_action('wp_ajax_oras_waitlist_promote_user', array($this, 'handle_waitlist_promote_user'));
            add_action('wp_ajax_oras_waitlist_remove_user', array($this, 'handle_waitlist_remove_user'));
            add_action('admin_post_oras_rsvp_export_yes', array($this, 'handle_rsvp_export_yes'));
            add_action('admin_post_oras_rsvp_export_waitlist', array($this, 'handle_rsvp_export_waitlist'));
            add_action('admin_post_oras_rsvp_promote', array($this, 'handle_rsvp_promote'));

            // Attendees Dashboard handlers
            add_action('wp_ajax_oras_attendees_dashboard_data', array($this, 'handle_attendees_dashboard_data'));
            add_action('wp_ajax_oras_attendees_send_email', array($this, 'handle_attendees_send_email'));
            add_action('wp_ajax_oras_attendees_save_note', array($this, 'handle_attendees_save_note'));
            add_action('admin_post_oras_attendees_export_csv', array($this, 'handle_attendees_export_csv'));

            // do not return; allow further initialization below
        }

        // Initialize Tickets_Display when not in admin contexts. This ensures frontend rendering
        // runs in normal page requests and that WP-CLI can also initialize the frontend display
        // when `is_admin()` is false.
        if (! is_admin()) {
            \ORAS\Tickets\Frontend\Tickets_Display::instance()->init();
            \ORAS\Tickets\Frontend\Ticket_Print_Controller::instance()->init();
        }
    }

    public function handle_rsvp_dashboard_data(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! current_user_can('oras_tickets_view_attendees')) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (! $event_id) {
            wp_send_json_error('Invalid event ID');
        }

        $rsvp_settings = $this->get_rsvp_settings($event_id);
        if (! is_array($rsvp_settings)) {
            wp_send_json_error('No RSVP settings found for this event');
        }

        $stats = $this->get_rsvp_stats_from_settings($event_id, $rsvp_settings);
        $yes_users = get_users(
            array(
                'meta_key'     => '_oras_rsvp_event_' . $event_id,
                'meta_value'   => 'yes',
                'meta_compare' => '=',
            )
        );
        $waitlist_users = \ORAS\Tickets\Waitlist_Store::get_waiting_users($event_id);
        $can_manage_rsvps = $this->can_manage_rsvp_dashboard();

        $attendees = array();
        foreach ($yes_users as $user) {
            $attendees[] = array(
                'user_id'    => (int) $user->ID,
                'name'       => $user->display_name,
                'email'      => $user->user_email,
                'status'     => 'Confirmed',
                'status_key' => 'yes',
                'can_remove' => $can_manage_rsvps,
            );
        }
        foreach ($waitlist_users as $user) {
            $attendees[] = array(
                'user_id'    => (int) $user->ID,
                'name'       => $user->display_name,
                'email'      => $user->user_email,
                'status'     => 'Waitlist',
                'status_key' => 'waitlist',
                'can_remove' => $can_manage_rsvps,
            );
        }

        wp_send_json_success(
            array(
                'stats'     => $stats,
                'attendees' => $attendees,
            )
        );
    }

    public function handle_waitlist_queue_data(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! $this->can_manage_rsvp_dashboard()) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (! $event_id) {
            wp_send_json_error('Invalid event ID');
        }

        $rsvp_settings = $this->get_rsvp_settings($event_id);
        if (! is_array($rsvp_settings)) {
            wp_send_json_error('No RSVP settings found for this event');
        }

        $queue_rows = \ORAS\Tickets\Waitlist_Store::get_event_rows($event_id, array('waiting'), 250, 'joined_asc');
        $history_rows = \ORAS\Tickets\Waitlist_Store::get_event_rows($event_id, array('waiting', 'promoted', 'left'), 250, 'updated_desc');

        $queue = array();
        $position = 1;
        foreach ($queue_rows as $row) {
            $queue[] = $this->format_waitlist_row($row, $position);
            $position++;
        }

        $history = array();
        foreach ($history_rows as $row) {
            $history[] = $this->format_waitlist_row($row, 0);
        }

        wp_send_json_success(
            array(
                'stats'   => $this->get_rsvp_stats_from_settings($event_id, $rsvp_settings),
                'queue'   => $queue,
                'history' => $history,
            )
        );
    }

    public function handle_waitlist_bulk_promote(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! $this->can_manage_rsvp_dashboard()) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (! $event_id) {
            wp_send_json_error('Invalid event ID');
        }

        $rsvp_settings = $this->get_rsvp_settings($event_id);
        if (! is_array($rsvp_settings)) {
            wp_send_json_error('No RSVP settings found for this event');
        }

        $requested = isset($_POST['count']) ? absint($_POST['count']) : 1;
        $requested = max(1, min(25, $requested));

        $result = \ORAS\Tickets\Support\DbLock::forEvent(
            $event_id,
            function () use ($event_id, $rsvp_settings, $requested): array {
                $stats = $this->get_rsvp_stats_from_settings($event_id, $rsvp_settings);
                $capacity = (int) $stats['capacity'];
                $available_slots = (int) $stats['available_slots'];

                if ($capacity > 0 && $available_slots <= 0) {
                    return array('error' => 'Event is already at capacity');
                }

                $limit = $capacity > 0 ? min($requested, $available_slots) : $requested;
                if ($limit <= 0) {
                    return array('error' => 'No capacity available for promotion');
                }

                $promoted_user_ids = \ORAS\Tickets\Waitlist_Store::bulk_promote_waiting(
                    $event_id,
                    $limit,
                    get_current_user_id(),
                    'dashboard-bulk'
                );

                if (empty($promoted_user_ids)) {
                    return array('error' => 'No users available on waitlist');
                }

                foreach ($promoted_user_ids as $promoted_user_id) {
                    update_user_meta((int) $promoted_user_id, '_oras_rsvp_event_' . $event_id, 'yes');
                    delete_user_meta((int) $promoted_user_id, '_oras_rsvp_event_' . $event_id . '_ts');
                }

                return array(
                    'promoted_count'    => count($promoted_user_ids),
                    'promoted_user_ids' => array_values(array_map('absint', $promoted_user_ids)),
                    'stats'             => $this->get_rsvp_stats_from_settings($event_id, $rsvp_settings),
                );
            }
        );

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        if (isset($result['error'])) {
            wp_send_json_error((string) $result['error']);
        }

        wp_send_json_success(
            array(
                'promoted_count'    => (int) ($result['promoted_count'] ?? 0),
                'promoted_user_ids' => isset($result['promoted_user_ids']) && is_array($result['promoted_user_ids']) ? $result['promoted_user_ids'] : array(),
                'stats'             => isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : array(),
            )
        );
    }

    public function handle_waitlist_promote_user(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! $this->can_manage_rsvp_dashboard()) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (! $event_id || ! $user_id) {
            wp_send_json_error('Invalid event or user');
        }

        $rsvp_settings = $this->get_rsvp_settings($event_id);
        if (! is_array($rsvp_settings)) {
            wp_send_json_error('No RSVP settings found for this event');
        }

        $result = \ORAS\Tickets\Support\DbLock::forEvent(
            $event_id,
            function () use ($event_id, $user_id, $rsvp_settings): array {
                $stats = $this->get_rsvp_stats_from_settings($event_id, $rsvp_settings);
                $capacity = (int) $stats['capacity'];
                $available_slots = (int) $stats['available_slots'];
                if ($capacity > 0 && $available_slots <= 0) {
                    return array('error' => 'Event is already at capacity');
                }

                if (! \ORAS\Tickets\Waitlist_Store::promote_user($event_id, $user_id, get_current_user_id(), 'dashboard-manual')) {
                    return array('error' => 'Unable to promote selected user');
                }

                update_user_meta($user_id, '_oras_rsvp_event_' . $event_id, 'yes');
                delete_user_meta($user_id, '_oras_rsvp_event_' . $event_id . '_ts');

                return array(
                    'user_id' => $user_id,
                    'stats'   => $this->get_rsvp_stats_from_settings($event_id, $rsvp_settings),
                );
            }
        );

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        if (isset($result['error'])) {
            wp_send_json_error((string) $result['error']);
        }

        wp_send_json_success(
            array(
                'user_id' => (int) ($result['user_id'] ?? $user_id),
                'stats'   => isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : array(),
            )
        );
    }

    public function handle_rsvp_remove_attendee(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! $this->can_manage_rsvp_dashboard()) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (! $event_id || ! $user_id) {
            wp_send_json_error('Invalid event or user');
        }

        $this->send_rsvp_removal_response($event_id, $user_id, 'dashboard-remove');
    }

    public function handle_waitlist_remove_user(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! $this->can_manage_rsvp_dashboard()) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (! $event_id || ! $user_id) {
            wp_send_json_error('Invalid event or user');
        }

        $this->send_rsvp_removal_response(
            $event_id,
            $user_id,
            'dashboard-waitlist-remove'
        );
    }

    private function send_rsvp_removal_response(
        int $event_id,
        int $user_id,
        string $source
    ): void
    {
        $removed = $this->remove_rsvp_attendee($event_id, $user_id, $source);
        if (is_wp_error($removed)) {
            wp_send_json_error($removed->get_error_message());
        }

        $rsvp_settings = $this->get_rsvp_settings($event_id);
        $stats = is_array($rsvp_settings)
            ? $this->get_rsvp_stats_from_settings($event_id, $rsvp_settings)
            : array();

        wp_send_json_success(
            array(
                'user_id' => $user_id,
                'stats'   => $stats,
            )
        );
    }

    private function get_rsvp_settings(int $event_id): ?array
    {
        $settings = get_post_meta($event_id, '_oras_rsvp_v1', true);
        return is_array($settings) ? $settings : null;
    }

    /**
     * @param array<string, mixed> $rsvp_settings
     * @return array<string, int|bool>
     */
    private function get_rsvp_stats_from_settings(int $event_id, array $rsvp_settings): array
    {
        $capacity = isset($rsvp_settings['capacity']) ? absint($rsvp_settings['capacity']) : 0;
        $yes_count = count(
            get_users(
                array(
                    'meta_key'     => '_oras_rsvp_event_' . $event_id,
                    'meta_value'   => 'yes',
                    'meta_compare' => '=',
                    'fields'       => 'ID',
                )
            )
        );
        $waitlist_count = \ORAS\Tickets\Waitlist_Store::count_waiting($event_id);
        $is_full = $capacity > 0 && $yes_count >= $capacity;
        $available_slots = $capacity > 0 ? max(0, $capacity - $yes_count) : 999999;

        return array(
            'capacity'        => $capacity,
            'yes_count'       => $yes_count,
            'waitlist_count'  => $waitlist_count,
            'is_full'         => $is_full,
            'available_slots' => $available_slots,
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function format_waitlist_row(object $row, int $position): array
    {
        $user_id = isset($row->user_id) ? absint($row->user_id) : 0;
        $actor_user_id = isset($row->actor_user_id) ? absint($row->actor_user_id) : 0;

        $user = $user_id > 0 ? get_userdata($user_id) : false;
        $actor = $actor_user_id > 0 ? get_userdata($actor_user_id) : false;

        return array(
            'id'            => isset($row->id) ? absint($row->id) : 0,
            'position'      => max(0, $position),
            'user_id'       => $user_id,
            'name'          => $user instanceof \WP_User ? $user->display_name : ('User #' . $user_id),
            'email'         => $user instanceof \WP_User ? $user->user_email : '',
            'status'        => isset($row->status) ? sanitize_key((string) $row->status) : '',
            'joined_at'     => isset($row->joined_at) ? (string) $row->joined_at : '',
            'updated_at'    => isset($row->updated_at) ? (string) $row->updated_at : '',
            'promoted_at'   => isset($row->promoted_at) && is_string($row->promoted_at) ? $row->promoted_at : '',
            'removed_at'    => isset($row->removed_at) && is_string($row->removed_at) ? $row->removed_at : '',
            'last_action'   => isset($row->last_action) ? sanitize_key((string) $row->last_action) : '',
            'source'        => isset($row->source) ? sanitize_key((string) $row->source) : '',
            'actor_user_id' => $actor_user_id,
            'actor_name'    => $actor instanceof \WP_User ? $actor->display_name : '',
        );
    }

    public function handle_attendees_dashboard_data(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! current_user_can('oras_tickets_view_attendees')) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (! $event_id) {
            wp_send_json_error('Invalid event ID');
        }

        $source_filter = isset($_POST['source_filter']) ? sanitize_key($_POST['source_filter']) : 'all';
        $ticket_status = isset($_POST['ticket_status']) ? sanitize_key($_POST['ticket_status']) : $this->get_default_attendee_ticket_status();
        $guests_only = isset($_POST['guests_only']) && $_POST['guests_only'] === '1';
        $has_note_only = isset($_POST['has_note_only']) && $_POST['has_note_only'] === '1';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

        $attendees = $this->get_filtered_attendees($event_id, $source_filter, $ticket_status, $guests_only, $search, $has_note_only);

        wp_send_json_success(
            array(
                'attendees' => $attendees,
                'summary'   => $this->build_attendee_summary($attendees),
            )
        );
    }

    public function handle_attendees_send_email(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! current_user_can('oras_tickets_send_notifications')) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (! $event_id) {
            wp_send_json_error('Invalid event ID');
        }

        $source_filter = isset($_POST['source_filter']) ? sanitize_key($_POST['source_filter']) : 'all';
        $ticket_status = isset($_POST['ticket_status']) ? sanitize_key($_POST['ticket_status']) : $this->get_default_attendee_ticket_status();
        $guests_only = isset($_POST['guests_only']) && $_POST['guests_only'] === '1';
        $has_note_only = isset($_POST['has_note_only']) && $_POST['has_note_only'] === '1';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $bcc = isset($_POST['bcc']) && $_POST['bcc'] === '1';
        $cc_me = isset($_POST['cc_me']) && $_POST['cc_me'] === '1';

        if (empty($subject) || empty($message)) {
            wp_send_json_error('Subject and message are required');
        }

        // Get filtered attendees using the same logic
        $attendees = $this->get_filtered_attendees($event_id, $source_filter, $ticket_status, $guests_only, $search, $has_note_only);

        // Build recipients list
        $recipients = array();
        foreach ($attendees as $attendee) {
            $email = strtolower(trim($attendee['email']));
            if (is_email($email) && ! in_array($email, $recipients, true)) {
                $recipients[] = $email;
            }
        }

        $recipient_count = count($recipients);
        if ($recipient_count === 0) {
            wp_send_json_error('No recipients found for current filters');
        }

        if ($recipient_count > 300) {
            wp_send_json_error('Too many recipients (max 300)');
        }

        // Send emails
        $admin_email = get_option('admin_email');
        $chunks = 0;

        if ($bcc) {
            // Send one email with BCC
            $chunk_size = 50;
            $email_chunks = array_chunk($recipients, $chunk_size);

            foreach ($email_chunks as $chunk) {
                $headers = array();
                foreach ($chunk as $email) {
                    $headers[] = 'Bcc: ' . $email;
                }

                if ($cc_me) {
                    $headers[] = 'Cc: ' . $admin_email;
                }

                $result = wp_mail($admin_email, $subject, $message, $headers);
                if (! $result) {
                    wp_send_json_error('Failed to send email to some recipients');
                }
                $chunks++;
            }
        } else {
            // Send individual emails
            $chunk_size = 10; // Smaller chunks for individual emails
            $email_chunks = array_chunk($recipients, $chunk_size);

            foreach ($email_chunks as $chunk) {
                foreach ($chunk as $email) {
                    $headers = array();
                    if ($cc_me) {
                        $headers[] = 'Cc: ' . $admin_email;
                    }

                    $result = wp_mail($email, $subject, $message, $headers);
                    if (! $result) {
                        wp_send_json_error('Failed to send email to some recipients');
                    }
                }
                $chunks++;
            }
        }

        wp_send_json_success(array('recipients' => $recipient_count, 'chunks' => $chunks));
    }

    public function handle_attendees_save_note(): void
    {
        check_ajax_referer('oras_rsvp_dashboard', 'nonce');

        if (! current_user_can('oras_tickets_manage_attendees')) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (! $event_id) {
            wp_send_json_error('Invalid event ID');
        }

        $attendee_key = isset($_POST['attendee_key']) ? sanitize_key($_POST['attendee_key']) : '';
        if (empty($attendee_key)) {
            wp_send_json_error('Invalid attendee key');
        }

        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        // Get existing envelope
        $envelope = get_post_meta($event_id, '_oras_attendee_notes_v1', true);
        if (! is_array($envelope)) {
            $envelope = array(
                'version' => 1,
                'items'   => array(),
            );
        }

        if (! isset($envelope['items'])) {
            $envelope['items'] = array();
        }

        if (empty($note)) {
            // Remove the note
            unset($envelope['items'][$attendee_key]);
        } else {
            // Save the note
            $envelope['items'][$attendee_key] = array(
                'note' => $note,
                'ts'   => time(),
                'by'   => get_current_user_id(),
            );
        }

        // Save the envelope
        update_post_meta($event_id, '_oras_attendee_notes_v1', $envelope);

        wp_send_json_success();
    }

    private function get_filtered_attendees(int $event_id, string $source_filter, string $ticket_status, bool $guests_only, string $search, bool $has_note_only = false): array
    {
        $attendees = array();
        $rsvp_attendees = array();
        $ticket_attendees = array();
        $matched_rsvp_user_ids = array();
        $ticket_status = $this->normalize_attendee_ticket_status($ticket_status);

        // Get notes envelope
        $notes_envelope = get_post_meta($event_id, '_oras_attendee_notes_v1', true);
        $notes = is_array($notes_envelope) && isset($notes_envelope['items']) ? $notes_envelope['items'] : array();

        // Get RSVP YES attendees
        if ($source_filter === 'all' || $source_filter === 'rsvp' || $source_filter === 'both') {
            $yes_users = get_users(
                array(
                    'meta_key'     => '_oras_rsvp_event_' . $event_id,
                    'meta_value'   => 'yes',
                    'meta_compare' => '=',
                )
            );

            foreach ($yes_users as $user) {
                $name = $user->display_name;
                $email = $user->user_email;

                if ($search !== '' && stripos($name, $search) === false && stripos($email, $search) === false) {
                    continue;
                }

                $rsvp_attendees[(int) $user->ID] = array(
                    'name'         => $name,
                    'email'        => $email,
                    'source'       => 'RSVP',
                    'phone'        => (string) get_user_meta((int) $user->ID, 'billing_phone', true),
                    'address'      => '',
                    'item_label'   => __('RSVP', 'oras-tickets'),
                    'quantity'     => 1,
                    'user_id'      => (int) $user->ID,
                    'order_id'     => 0,
                    'order_status' => '',
                );
            }
        }

        // Get ticket attendees
        if ($source_filter === 'all' || $source_filter === 'tickets' || $source_filter === 'both') {
            $ticket_list = $this->get_ticket_attendees_for_event($event_id);

            foreach ($ticket_list as $attendee) {
                $name = $attendee['name'];
                $email = $attendee['email'];
                $item_label = isset($attendee['item_label']) ? (string) $attendee['item_label'] : '';

                if (
                    $search !== ''
                    && stripos($name, $search) === false
                    && stripos($email, $search) === false
                    && stripos($item_label, $search) === false
                ) {
                    continue;
                }

                // Apply ticket status filter
                if (! $this->attendee_ticket_status_matches($ticket_status, (string) ($attendee['order_status'] ?? ''))) {
                    continue;
                }

                // Apply guests only filter
                if ($guests_only && $attendee['user_id'] > 0) {
                    continue;
                }

                $ticket_attendees[] = $attendee;
            }
        }

        // Ticket rows are authoritative for commerce visibility; merge RSVP marker by user when present.
        foreach ($ticket_attendees as $ticket_data) {
            $attendee = $ticket_data;
            $user_id = (int) ($ticket_data['user_id'] ?? 0);
            $order_id = (int) ($ticket_data['order_id'] ?? 0);
            $attendee_key = $this->build_attendee_key(
                $user_id,
                $order_id,
                (string) ($ticket_data['email'] ?? ''),
                (string) ($ticket_data['item_label'] ?? '')
            );

            if ($user_id > 0 && isset($rsvp_attendees[$user_id])) {
                $rsvp_data = $rsvp_attendees[$user_id];
                $attendee['source'] = 'Both';
                $attendee['name'] = $rsvp_data['name'];
                $attendee['email'] = $rsvp_data['email'];
                $matched_rsvp_user_ids[$user_id] = true;
            }

            $attendee['attendee_key'] = $attendee_key;
            $attendee['note'] = $this->resolve_attendee_note(
                $notes,
                $attendee_key,
                array(
                    'u_' . $user_id,
                    'o_' . $order_id,
                )
            );

            if ($has_note_only && empty($attendee['note'])) {
                continue;
            }

            $attendees[] = $this->normalize_attendee_row($attendee);
        }

        // Append RSVP-only users not represented in ticket rows.
        if ($source_filter === 'all' || $source_filter === 'rsvp' || $source_filter === 'both') {
            foreach ($rsvp_attendees as $user_id => $rsvp_data) {
                if (isset($matched_rsvp_user_ids[(int) $user_id])) {
                    continue;
                }

                $attendee_key = 'u_' . $rsvp_data['user_id'];
                $rsvp_data['attendee_key'] = $attendee_key;
                $rsvp_data['note'] = $this->resolve_attendee_note($notes, $attendee_key, array());

                if ($has_note_only && empty($rsvp_data['note'])) {
                    continue;
                }

                $attendees[] = $this->normalize_attendee_row($rsvp_data);
            }
        }

        return $this->group_attendees_rows($attendees);
    }

    /**
     * @param array<int, array<string, mixed>> $attendees
     * @return array<string, int>
     */
    private function build_attendee_summary(array $attendees): array
    {
        $order_ids = array();
        $total_tickets = 0;

        foreach ($attendees as $attendee) {
            $order_id = isset($attendee['order_id']) ? absint($attendee['order_id']) : 0;
            if ($order_id <= 0) {
                continue;
            }

            $order_ids[$order_id] = true;
            $quantity = isset($attendee['quantity']) ? max(0, (int) $attendee['quantity']) : 0;
            $total_tickets += $quantity;
        }

        return array(
            'total_rows'    => count($attendees),
            'total_orders'  => count($order_ids),
            'total_tickets' => $total_tickets,
        );
    }

    /**
     * @return true|\WP_Error
     */
    private function remove_rsvp_attendee(int $event_id, int $user_id, string $source)
    {
        $meta_key = '_oras_rsvp_event_' . $event_id;
        $current_status = sanitize_key((string) get_user_meta($user_id, $meta_key, true));
        $waitlist_status = \ORAS\Tickets\Waitlist_Store::get_current_waitlist_status($event_id, $user_id);

        if ('waiting' === $waitlist_status) {
            if (! \ORAS\Tickets\Waitlist_Store::remove_waiting_user($event_id, $user_id, get_current_user_id(), $source)) {
                return new \WP_Error('oras_rsvp_remove_waitlist_failed', 'Unable to remove selected user from waitlist');
            }
        } elseif ('yes' !== $current_status && 'waitlist' !== $current_status) {
            return new \WP_Error('oras_rsvp_remove_invalid_state', 'Selected attendee does not have an active RSVP');
        }

        update_user_meta($user_id, $meta_key, 'no');
        delete_user_meta($user_id, $meta_key . '_ts');
        delete_user_meta($user_id, $meta_key . '_attendance_mode');
        delete_user_meta($user_id, $meta_key . '_approval_status');

        return true;
    }

    private function can_manage_rsvp_dashboard(): bool
    {
        return current_user_can('oras_tickets_manage_rsvps')
            || current_user_can('oras_tickets_manage_events')
            || current_user_can('manage_options');
    }

    private function get_ticket_attendees_for_event(int $event_id): array
    {
        if (! function_exists('wc_get_orders')) {
            return array();
        }

        $attendees = array();
        $statuses = array('completed', 'processing', 'on-hold', 'pending', 'refunded', 'cancelled', 'failed');
        $orders_by_id = array();
        $map = get_post_meta($event_id, '_oras_tickets_woo_map_v1', true);
        $product_ids = array();
        $product_lookup = array();

        if (is_array($map)) {
            foreach ($map as $product_id) {
                $product_id = absint($product_id);
                if ($product_id > 0) {
                    $product_ids[] = $product_id;
                }
            }
            if (! empty($product_ids)) {
                $product_lookup = array_fill_keys($product_ids, true);
            }
        }

        if (! empty($product_ids)) {
            foreach ($product_ids as $product_id) {
                $matched_orders = wc_get_orders(
                    array(
                        'status'     => $statuses,
                        'product_id' => $product_id,
                        'limit'      => -1,
                    )
                );

                foreach ($matched_orders as $order) {
                    if ($order instanceof \WC_Order) {
                        $orders_by_id[(int) $order->get_id()] = $order;
                    }
                }
            }
        }

        // Some Woo setups do not honor product_id filtering consistently.
        // If mapped-product lookup produced no orders, fall back to paged scan.
        if (empty($orders_by_id)) {
            // Legacy fallback: if map metadata is missing, scan in pages.
            $page = 1;
            $limit = 100;
            do {
                $orders = wc_get_orders(
                    array(
                        'status'  => $statuses,
                        'limit'   => $limit,
                        'page'    => $page,
                        'orderby' => 'date',
                        'order'   => 'DESC',
                    )
                );
                if (empty($orders)) {
                    break;
                }

                foreach ($orders as $order) {
                    if ($order instanceof \WC_Order) {
                        $orders_by_id[(int) $order->get_id()] = $order;
                    }
                }

                ++$page;
                $count = count($orders);
            } while ($count === $limit);
        }

        foreach ($orders_by_id as $order) {
            if (! $order instanceof \WC_Order) {
                continue;
            }

            $user_id = (int) $order->get_user_id();
            $order_id = $order->get_id();
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $email = $order->get_billing_email();
            $phone = (string) $order->get_billing_phone();
            $address = trim(
                implode(
                    ', ',
                    array_filter(
                        array(
                            (string) $order->get_billing_address_1(),
                            (string) $order->get_billing_address_2(),
                            (string) $order->get_billing_city(),
                            (string) $order->get_billing_state(),
                            (string) $order->get_billing_postcode(),
                            (string) $order->get_billing_country(),
                        ),
                        static function (string $value): bool {
                            return trim($value) !== '';
                        }
                    )
                )
            );
            $order_status = $order->get_status();

            foreach ($order->get_items() as $item) {
                if (! $item instanceof \WC_Order_Item_Product) {
                    continue;
                }

                $linked_event = (int) $item->get_meta('_oras_ticket_event_id', true);
                $item_product_id = (int) $item->get_product_id();
                $is_mapped_product = $item_product_id > 0 && isset($product_lookup[$item_product_id]);
                if ($linked_event !== $event_id && ! $is_mapped_product) {
                    continue;
                }

                $qty = (int) $item->get_quantity();
                $ticket_count = max(1, $qty);
                $ticket_name = trim((string) $item->get_meta('_oras_ticket_name', true));
                if ($ticket_name === '') {
                    $ticket_name = (string) $item->get_name();
                }

                for ($idx = 1; $idx <= $ticket_count; $idx++) {
                    if ($user_id > 0) {
                        $attendees[] = array(
                            'name'         => $name,
                            'email'        => $email,
                            'phone'        => $phone,
                            'address'      => $address,
                            'source'       => 'Ticket',
                            'item_label'   => $ticket_name,
                            'quantity'     => 1,
                            'user_id'      => $user_id,
                            'order_id'     => $order_id,
                            'order_status' => $order_status,
                            'ticket_index' => $idx,
                            'ticket_total' => $ticket_count,
                        );
                    } else {
                        $attendees[] = array(
                            'name'         => $name,
                            'email'        => $email,
                            'phone'        => $phone,
                            'address'      => $address,
                            'source'       => 'Ticket (Guest)',
                            'item_label'   => $ticket_name,
                            'quantity'     => 1,
                            'user_id'      => 0,
                            'order_id'     => $order_id,
                            'order_status' => $order_status,
                            'ticket_index' => $idx,
                            'ticket_total' => $ticket_count,
                        );
                    }
                }
            }
        }

        return $attendees;
    }

    public function handle_attendees_export_csv(): void
    {
        check_admin_referer('oras_rsvp_dashboard');

        if (! current_user_can('oras_tickets_export_reports')) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
        if (! $event_id) {
            wp_die('Invalid event ID');
        }

        $source_filter = isset($_GET['source_filter']) ? sanitize_key($_GET['source_filter']) : 'all';
        $ticket_status = isset($_GET['ticket_status']) ? sanitize_key($_GET['ticket_status']) : $this->get_default_attendee_ticket_status();
        $guests_only = isset($_GET['guests_only']) && $_GET['guests_only'] === '1';
        $has_note_only = isset($_GET['has_note_only']) && $_GET['has_note_only'] === '1';
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        // Get filtered attendees
        $attendees = $this->get_filtered_attendees($event_id, $source_filter, $ticket_status, $guests_only, $search, $has_note_only);

        // Output CSV
        $event_title = get_the_title($event_id);
        $filename = 'attendees-' . sanitize_title($event_title) . '-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // CSV headers
        fputcsv($output, CsvSafety::row(array('Name', 'Email', 'Source', 'User ID', 'Order ID', 'Order Status', 'User Admin URL', 'Order Admin URL', 'Note')));

        // CSV data
        foreach ($attendees as $attendee) {
            $user_admin_url = $attendee['user_id'] > 0 ? admin_url('user-edit.php?user_id=' . $attendee['user_id']) : '';
            $order_admin_url = $attendee['order_id'] > 0 ? admin_url('post.php?post=' . $attendee['order_id'] . '&action=edit') : '';

            fputcsv($output, CsvSafety::row(array(
                $attendee['name'],
                $attendee['email'],
                $attendee['source'],
                $attendee['user_id'] ?: '',
                $attendee['order_id'] ?: '',
                $attendee['order_status'],
                $user_admin_url,
                $order_admin_url,
                $attendee['note'],
            )));
        }

        fclose($output);
        exit;
    }

    private function get_default_attendee_ticket_status(): string
    {
        return 'paid-active';
    }

    private function normalize_attendee_ticket_status(string $ticket_status): string
    {
        $allowed = array(
            'all',
            'paid-active',
            'completed',
            'processing',
            'on-hold',
            'pending',
            'refunded',
            'cancelled',
            'failed',
        );
        if (! in_array($ticket_status, $allowed, true)) {
            return $this->get_default_attendee_ticket_status();
        }

        return $ticket_status;
    }

    private function attendee_ticket_status_matches(string $ticket_status, string $order_status): bool
    {
        if ($ticket_status === 'all') {
            return true;
        }
        if ($ticket_status === 'paid-active') {
            return in_array($order_status, array('completed', 'processing', 'on-hold'), true);
        }

        return $order_status === $ticket_status;
    }

    private function build_attendee_key(int $user_id, int $order_id, string $email, string $item_label): string
    {
        if ($user_id > 0 && $order_id > 0) {
            return 'u_' . $user_id . '_o_' . $order_id;
        }
        if ($user_id > 0) {
            return 'u_' . $user_id;
        }
        if ($order_id > 0) {
            return 'g_' . $order_id . '_' . md5(strtolower(trim($email)) . '|' . $item_label);
        }

        return 'g_' . md5(strtolower(trim($email)) . '|' . $item_label);
    }

    /**
     * @param array<string, mixed> $notes
     * @param string[]             $legacy_keys
     */
    private function resolve_attendee_note(array $notes, string $attendee_key, array $legacy_keys): string
    {
        if (isset($notes[$attendee_key]) && is_array($notes[$attendee_key])) {
            $value = $notes[$attendee_key]['note'] ?? '';
            return is_string($value) ? $value : '';
        }

        foreach ($legacy_keys as $legacy_key) {
            if (! is_string($legacy_key) || $legacy_key === '') {
                continue;
            }
            if (isset($notes[$legacy_key]) && is_array($notes[$legacy_key])) {
                $value = $notes[$legacy_key]['note'] ?? '';
                return is_string($value) ? $value : '';
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attendee
     * @return array<string, mixed>
     */
    private function normalize_attendee_row(array $attendee): array
    {
        $quantity = isset($attendee['quantity']) ? (int) $attendee['quantity'] : 0;
        if ($quantity <= 0) {
            $quantity = 1;
        }

        return array(
            'name'         => isset($attendee['name']) ? (string) $attendee['name'] : '',
            'email'        => isset($attendee['email']) ? (string) $attendee['email'] : '',
            'source'       => isset($attendee['source']) ? (string) $attendee['source'] : '',
            'phone'        => isset($attendee['phone']) ? (string) $attendee['phone'] : '',
            'address'      => isset($attendee['address']) ? (string) $attendee['address'] : '',
            'item_label'   => isset($attendee['item_label']) ? (string) $attendee['item_label'] : '',
            'quantity'     => $quantity,
            'user_id'      => isset($attendee['user_id']) ? (int) $attendee['user_id'] : 0,
            'order_id'     => isset($attendee['order_id']) ? (int) $attendee['order_id'] : 0,
            'order_status' => isset($attendee['order_status']) ? (string) $attendee['order_status'] : '',
            'attendee_key' => isset($attendee['attendee_key']) ? (string) $attendee['attendee_key'] : '',
            'note'         => isset($attendee['note']) ? (string) $attendee['note'] : '',
        );
    }

    private function attendee_group_key(array $attendee): string
    {
        $user_id = (int) ($attendee['user_id'] ?? 0);
        $order_id = (int) ($attendee['order_id'] ?? 0);
        if ($user_id > 0 && $order_id > 0) {
            return 'u:' . $user_id . '|o:' . $order_id;
        }
        if ($user_id > 0) {
            return 'u:' . $user_id;
        }

        $email = strtolower(trim((string) ($attendee['email'] ?? '')));
        $item_label = (string) ($attendee['item_label'] ?? '');
        if ($order_id > 0) {
            return 'g:o:' . $order_id . '|e:' . $email . '|i:' . $item_label;
        }

        return 'g:e:' . $email . '|i:' . $item_label;
    }

    /**
     * @param array<int, array<string, mixed>> $attendees
     * @return array<int, array<string, mixed>>
     */
    private function group_attendees_rows(array $attendees): array
    {
        $grouped = array();
        $order = array();

        foreach ($attendees as $attendee) {
            $row = $this->normalize_attendee_row($attendee);
            $group_key = $this->attendee_group_key($row);
            if (! isset($grouped[$group_key])) {
                $grouped[$group_key] = $row;
                $order[] = $group_key;
                continue;
            }

            $grouped[$group_key]['quantity'] = (int) $grouped[$group_key]['quantity'] + (int) $row['quantity'];
            if ((string) $grouped[$group_key]['note'] === '' && (string) $row['note'] !== '') {
                $grouped[$group_key]['note'] = $row['note'];
            }
            if ((string) $grouped[$group_key]['item_label'] === '' && (string) $row['item_label'] !== '') {
                $grouped[$group_key]['item_label'] = $row['item_label'];
            }
            if ((string) $grouped[$group_key]['order_status'] === '' && (string) $row['order_status'] !== '') {
                $grouped[$group_key]['order_status'] = $row['order_status'];
            }
        }

        $result = array();
        foreach ($order as $group_key) {
            if (! isset($grouped[$group_key])) {
                continue;
            }
            $result[] = $grouped[$group_key];
        }

        return $result;
    }

    public function handle_rsvp_export_yes(): void
    {
        $this->handle_rsvp_export('yes');
    }

    public function handle_rsvp_export_waitlist(): void
    {
        $this->handle_rsvp_export('waitlist');
    }

    private function handle_rsvp_export(string $status): void
    {
        check_admin_referer('oras_rsvp_dashboard');

        if (! current_user_can('oras_tickets_export_reports')) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
        if (! $event_id) {
            wp_die('Invalid event ID');
        }

        if ('waitlist' === $status) {
            $users = \ORAS\Tickets\Waitlist_Store::get_waiting_users($event_id);
        } else {
            $users = get_users(
                array(
                    'meta_key'     => '_oras_rsvp_event_' . $event_id,
                    'meta_value'   => $status,
                    'meta_compare' => '=',
                )
            );
        }

        $filename = 'rsvp-' . $status . '-' . get_the_title($event_id) . '-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fputcsv($output, CsvSafety::row(array('Name', 'Email', 'Status')));

        foreach ($users as $user) {
            fputcsv($output, CsvSafety::row(array(
                $user->display_name,
                $user->user_email,
                ucfirst($status),
            )));
        }

        fclose($output);
        exit;
    }

    public function handle_rsvp_promote(): void
    {
        check_admin_referer('oras_rsvp_dashboard');

        if (! current_user_can('oras_tickets_manage_rsvps')) {
            wp_die('Insufficient permissions');
        }

        $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
        if (! $event_id) {
            wp_die('Invalid event ID');
        }

        $rsvp_settings = get_post_meta($event_id, '_oras_rsvp_v1', true);
        if (! is_array($rsvp_settings)) {
            wp_die('No RSVP settings found for this event');
        }

        $result = \ORAS\Tickets\Support\DbLock::forEvent(
            $event_id,
            function () use ($event_id, $rsvp_settings) {
                $capacity = isset($rsvp_settings['capacity']) ? absint($rsvp_settings['capacity']) : 0;

                $yes_count = count(get_users(
                    array(
                        'meta_key'     => '_oras_rsvp_event_' . $event_id,
                        'meta_value'   => 'yes',
                        'meta_compare' => '=',
                    )
                ));

                if ($capacity > 0 && $yes_count >= $capacity) {
                    return new \WP_Error('oras_rsvp_capacity', 'Event is already at capacity');
                }

                $promoted_user_id = \ORAS\Tickets\Waitlist_Store::promote_next_waiting(
                    $event_id,
                    get_current_user_id(),
                    'dashboard'
                );

                if ($promoted_user_id <= 0) {
                    return new \WP_Error('oras_rsvp_waitlist_empty', 'No users on waitlist');
                }

                update_user_meta($promoted_user_id, '_oras_rsvp_event_' . $event_id, 'yes');
                delete_user_meta($promoted_user_id, '_oras_rsvp_event_' . $event_id . '_ts');

                return $promoted_user_id;
            }
        );

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }

        // Redirect back to dashboard with success message
        wp_safe_redirect(admin_url('admin.php?page=oras-tickets&tab=rsvp&promoted=1'));
        exit;
    }
}
