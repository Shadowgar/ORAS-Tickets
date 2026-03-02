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
require_once ORAS_TICKETS_DIR . 'includes/Admin/Reports_Aggregator.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Dashboard_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Reports_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Settings_Page.php'; // NOSONAR legacy include
// Frontend tickets display (Phase 1.3 - read-only)
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Tickets_Display.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Event_Agenda_Render.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Ticket_Print_Controller.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Virtual_Access.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Event_RSVP.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Board_Dashboard.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/RSVP.php'; // NOSONAR include: helper
require_once ORAS_TICKETS_DIR . 'includes/Waitlist_Store.php'; // NOSONAR include: waitlist storage
require_once ORAS_TICKETS_DIR . 'includes/Security/Csv_Safety.php'; // NOSONAR include: CSV export hardening helper
require_once ORAS_TICKETS_DIR . 'includes/Templates/Template_Loader.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Cart_Pricing.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Api/Member_Hub_Tickets.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'src/Integrations/QuickBooks/Module.php'; // NOSONAR legacy include

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Bootstrap {


    private static ?Bootstrap $instance = null;

    public static function instance(): Bootstrap {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {
        Logger::instance()->log( 'Bootstrap init start' );
        \ORAS\Tickets\Waitlist_Store::maybe_upgrade();

// Hard deps: TEC (tribe_events) and WooCommerce.
        $has_tec = post_type_exists( 'tribe_events' ) || class_exists( 'Tribe__Events__Main' );
        $has_woo = class_exists( 'WooCommerce' );

        Logger::instance()->log( 'TEC present? ' . ( $has_tec ? 'yes' : 'no' ) );
        Logger::instance()->log( 'WooCommerce present? ' . ( $has_woo ? 'yes' : 'no' ) );

        if ( ! $has_tec || ! $has_woo ) {
            add_action(
                'admin_notices',
                function () use ( $has_tec, $has_woo ) {
                    if ( ! current_user_can( 'activate_plugins' ) ) {
                        return;
                    }
                    $missing = array();
                    if ( ! $has_tec ) {
                        $missing[] = 'The Events Calendar (tribe_events)';
                    }
                    if ( ! $has_woo ) {
                        $missing[] = 'WooCommerce';
                    }
                    printf(
                        '<div class="notice notice-error"><p><strong>ORAS Tickets</strong> requires: %s</p></div>',
                        esc_html( implode( ', ', $missing ) )
                    );
                }
            );

            Logger::instance()->log( 'Bootstrap aborted: missing dependencies' );
            return;
        }

        // Phase 1 modules will be loaded here next.
        add_action( 'init', array( $this, 'register_phase1' ), 20 );

        Logger::instance()->log( 'Bootstrap init complete' );
    }

    public function register_phase1(): void {
        // Register Phase 1 modules.
        Logger::instance()->log( 'Phase 1 registration hook fired (init)' );

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

        require_once ORAS_TICKETS_DIR . 'includes/Api/Rsvp.php'; // NOSONAR legacy include
        $rsvp_api = new \ORAS\Tickets\Api\Rsvp();
        $rsvp_api->register();

        $speaker_cpt = new \ORAS\Tickets\Admin\Speaker_CPT();
        $speaker_cpt->register();

        \ORAS\Tickets\Frontend\Event_Agenda_Render::register();
        \ORAS\Tickets\Frontend\Virtual_Access::register();
        \ORAS\Tickets\Frontend\Event_RSVP::register();
        \ORAS\Tickets\Frontend\Board_Dashboard::register();
        \ORAS\Tickets\Templates\Template_Loader::register();

        // Admin-only (or WP-CLI): register ticket metabox and admin hub.
        if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            \ORAS\Tickets\Admin\Tickets_Metabox::instance()->init();
            $event_addon_metabox = new \ORAS\Tickets\Admin\Event_Addon_Metabox();
            $event_addon_metabox->register();
            \ORAS\Tickets\Admin\Metaboxes\Event_Agenda_Metabox::register();
            \ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Metabox::register();
            require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php'; // NOSONAR legacy include
            \ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Attendees_Metabox::register();
            require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php'; // NOSONAR legacy include
            $admin_menu = new \ORAS\Tickets\Admin\Admin_Menu();
            $admin_menu->register();

            // RSVP Dashboard handlers
            add_action( 'wp_ajax_oras_rsvp_dashboard_data', array( $this, 'handle_rsvp_dashboard_data' ) );
            add_action( 'wp_ajax_oras_waitlist_queue_data', array( $this, 'handle_waitlist_queue_data' ) );
            add_action( 'wp_ajax_oras_waitlist_bulk_promote', array( $this, 'handle_waitlist_bulk_promote' ) );
            add_action( 'wp_ajax_oras_waitlist_promote_user', array( $this, 'handle_waitlist_promote_user' ) );
            add_action( 'wp_ajax_oras_waitlist_remove_user', array( $this, 'handle_waitlist_remove_user' ) );
            add_action( 'admin_post_oras_rsvp_export_yes', array( $this, 'handle_rsvp_export_yes' ) );
            add_action( 'admin_post_oras_rsvp_export_waitlist', array( $this, 'handle_rsvp_export_waitlist' ) );
            add_action( 'admin_post_oras_rsvp_promote', array( $this, 'handle_rsvp_promote' ) );

            // Attendees Dashboard handlers
            add_action( 'wp_ajax_oras_attendees_dashboard_data', array( $this, 'handle_attendees_dashboard_data' ) );
            add_action( 'wp_ajax_oras_attendees_send_email', array( $this, 'handle_attendees_send_email' ) );
            add_action( 'wp_ajax_oras_attendees_save_note', array( $this, 'handle_attendees_save_note' ) );
            add_action( 'admin_post_oras_attendees_export_csv', array( $this, 'handle_attendees_export_csv' ) );

            // do not return; allow further initialization below
        }

        // Initialize Tickets_Display when not in admin contexts. This ensures frontend rendering
        // runs in normal page requests and that WP-CLI can also initialize the frontend display
        // when `is_admin()` is false.
        if ( ! is_admin() ) {
            \ORAS\Tickets\Frontend\Tickets_Display::instance()->init();
            \ORAS\Tickets\Frontend\Ticket_Print_Controller::instance()->init();
        }
    }

    public function handle_rsvp_dashboard_data(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_view_attendees' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }

        $rsvp_settings = $this->get_rsvp_settings( $event_id );
        if ( ! is_array( $rsvp_settings ) ) {
            wp_send_json_error( 'No RSVP settings found for this event' );
        }

        $stats = $this->get_rsvp_stats_from_settings( $event_id, $rsvp_settings );
        $yes_users = get_users(
            array(
                'meta_key'     => '_oras_rsvp_event_' . $event_id,
                'meta_value'   => 'yes',
                'meta_compare' => '=',
            )
        );
        $waitlist_users = \ORAS\Tickets\Waitlist_Store::get_waiting_users( $event_id );

        $attendees = array();
        foreach ( $yes_users as $user ) {
            $attendees[] = array(
                'name'   => $user->display_name,
                'email'  => $user->user_email,
                'status' => 'Yes',
            );
        }
        foreach ( $waitlist_users as $user ) {
            $attendees[] = array(
                'name'   => $user->display_name,
                'email'  => $user->user_email,
                'status' => 'Waitlist',
            );
        }

        wp_send_json_success(
            array(
                'stats'     => $stats,
                'attendees' => $attendees,
            )
        );
    }

    public function handle_waitlist_queue_data(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_manage_rsvps' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }

        $rsvp_settings = $this->get_rsvp_settings( $event_id );
        if ( ! is_array( $rsvp_settings ) ) {
            wp_send_json_error( 'No RSVP settings found for this event' );
        }

        $queue_rows = \ORAS\Tickets\Waitlist_Store::get_event_rows( $event_id, array( 'waiting' ), 250, 'joined_asc' );
        $history_rows = \ORAS\Tickets\Waitlist_Store::get_event_rows( $event_id, array( 'waiting', 'promoted', 'left' ), 250, 'updated_desc' );

        $queue = array();
        $position = 1;
        foreach ( $queue_rows as $row ) {
            $queue[] = $this->format_waitlist_row( $row, $position );
            $position++;
        }

        $history = array();
        foreach ( $history_rows as $row ) {
            $history[] = $this->format_waitlist_row( $row, 0 );
        }

        wp_send_json_success(
            array(
                'stats'   => $this->get_rsvp_stats_from_settings( $event_id, $rsvp_settings ),
                'queue'   => $queue,
                'history' => $history,
            )
        );
    }

    public function handle_waitlist_bulk_promote(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_manage_rsvps' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }

        $rsvp_settings = $this->get_rsvp_settings( $event_id );
        if ( ! is_array( $rsvp_settings ) ) {
            wp_send_json_error( 'No RSVP settings found for this event' );
        }

        $requested = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 1;
        $requested = max( 1, min( 25, $requested ) );

        $result = \ORAS\Tickets\Support\DbLock::forEvent(
            $event_id,
            function () use ( $event_id, $rsvp_settings, $requested ): array {
                $stats = $this->get_rsvp_stats_from_settings( $event_id, $rsvp_settings );
                $capacity = (int) $stats['capacity'];
                $available_slots = (int) $stats['available_slots'];

                if ( $capacity > 0 && $available_slots <= 0 ) {
                    return array( 'error' => 'Event is already at capacity' );
                }

                $limit = $capacity > 0 ? min( $requested, $available_slots ) : $requested;
                if ( $limit <= 0 ) {
                    return array( 'error' => 'No capacity available for promotion' );
                }

                $promoted_user_ids = \ORAS\Tickets\Waitlist_Store::bulk_promote_waiting(
                    $event_id,
                    $limit,
                    get_current_user_id(),
                    'dashboard-bulk'
                );

                if ( empty( $promoted_user_ids ) ) {
                    return array( 'error' => 'No users available on waitlist' );
                }

                foreach ( $promoted_user_ids as $promoted_user_id ) {
                    update_user_meta( (int) $promoted_user_id, '_oras_rsvp_event_' . $event_id, 'yes' );
                    delete_user_meta( (int) $promoted_user_id, '_oras_rsvp_event_' . $event_id . '_ts' );
                }

                return array(
                    'promoted_count'    => count( $promoted_user_ids ),
                    'promoted_user_ids' => array_values( array_map( 'absint', $promoted_user_ids ) ),
                    'stats'             => $this->get_rsvp_stats_from_settings( $event_id, $rsvp_settings ),
                );
            }
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( (string) $result['error'] );
        }

        wp_send_json_success(
            array(
                'promoted_count'    => (int) ( $result['promoted_count'] ?? 0 ),
                'promoted_user_ids' => isset( $result['promoted_user_ids'] ) && is_array( $result['promoted_user_ids'] ) ? $result['promoted_user_ids'] : array(),
                'stats'             => isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array(),
            )
        );
    }

    public function handle_waitlist_promote_user(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_manage_rsvps' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        if ( ! $event_id || ! $user_id ) {
            wp_send_json_error( 'Invalid event or user' );
        }

        $rsvp_settings = $this->get_rsvp_settings( $event_id );
        if ( ! is_array( $rsvp_settings ) ) {
            wp_send_json_error( 'No RSVP settings found for this event' );
        }

        $result = \ORAS\Tickets\Support\DbLock::forEvent(
            $event_id,
            function () use ( $event_id, $user_id, $rsvp_settings ): array {
                $stats = $this->get_rsvp_stats_from_settings( $event_id, $rsvp_settings );
                $capacity = (int) $stats['capacity'];
                $available_slots = (int) $stats['available_slots'];
                if ( $capacity > 0 && $available_slots <= 0 ) {
                    return array( 'error' => 'Event is already at capacity' );
                }

                if ( ! \ORAS\Tickets\Waitlist_Store::promote_user( $event_id, $user_id, get_current_user_id(), 'dashboard-manual' ) ) {
                    return array( 'error' => 'Unable to promote selected user' );
                }

                update_user_meta( $user_id, '_oras_rsvp_event_' . $event_id, 'yes' );
                delete_user_meta( $user_id, '_oras_rsvp_event_' . $event_id . '_ts' );

                return array(
                    'user_id' => $user_id,
                    'stats'   => $this->get_rsvp_stats_from_settings( $event_id, $rsvp_settings ),
                );
            }
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( (string) $result['error'] );
        }

        wp_send_json_success(
            array(
                'user_id' => (int) ( $result['user_id'] ?? $user_id ),
                'stats'   => isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array(),
            )
        );
    }

    public function handle_waitlist_remove_user(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_manage_rsvps' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        if ( ! $event_id || ! $user_id ) {
            wp_send_json_error( 'Invalid event or user' );
        }

        if ( ! \ORAS\Tickets\Waitlist_Store::remove_waiting_user( $event_id, $user_id, get_current_user_id(), 'dashboard-remove' ) ) {
            wp_send_json_error( 'Unable to remove selected user from waitlist' );
        }

        update_user_meta( $user_id, '_oras_rsvp_event_' . $event_id, 'no' );
        delete_user_meta( $user_id, '_oras_rsvp_event_' . $event_id . '_ts' );

        $rsvp_settings = $this->get_rsvp_settings( $event_id );
        $stats = is_array( $rsvp_settings )
            ? $this->get_rsvp_stats_from_settings( $event_id, $rsvp_settings )
            : array();

        wp_send_json_success(
            array(
                'user_id' => $user_id,
                'stats'   => $stats,
            )
        );
    }

    private function get_rsvp_settings( int $event_id ): ?array {
        $settings = get_post_meta( $event_id, '_oras_rsvp_v1', true );
        return is_array( $settings ) ? $settings : null;
    }

    /**
     * @param array<string, mixed> $rsvp_settings
     * @return array<string, int|bool>
     */
    private function get_rsvp_stats_from_settings( int $event_id, array $rsvp_settings ): array {
        $capacity = isset( $rsvp_settings['capacity'] ) ? absint( $rsvp_settings['capacity'] ) : 0;
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
        $waitlist_count = \ORAS\Tickets\Waitlist_Store::count_waiting( $event_id );
        $is_full = $capacity > 0 && $yes_count >= $capacity;
        $available_slots = $capacity > 0 ? max( 0, $capacity - $yes_count ) : 999999;

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
    private function format_waitlist_row( object $row, int $position ): array {
        $user_id = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
        $actor_user_id = isset( $row->actor_user_id ) ? absint( $row->actor_user_id ) : 0;

        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        $actor = $actor_user_id > 0 ? get_userdata( $actor_user_id ) : false;

        return array(
            'id'            => isset( $row->id ) ? absint( $row->id ) : 0,
            'position'      => max( 0, $position ),
            'user_id'       => $user_id,
            'name'          => $user instanceof \WP_User ? $user->display_name : ( 'User #' . $user_id ),
            'email'         => $user instanceof \WP_User ? $user->user_email : '',
            'status'        => isset( $row->status ) ? sanitize_key( (string) $row->status ) : '',
            'joined_at'     => isset( $row->joined_at ) ? (string) $row->joined_at : '',
            'updated_at'    => isset( $row->updated_at ) ? (string) $row->updated_at : '',
            'promoted_at'   => isset( $row->promoted_at ) && is_string( $row->promoted_at ) ? $row->promoted_at : '',
            'removed_at'    => isset( $row->removed_at ) && is_string( $row->removed_at ) ? $row->removed_at : '',
            'last_action'   => isset( $row->last_action ) ? sanitize_key( (string) $row->last_action ) : '',
            'source'        => isset( $row->source ) ? sanitize_key( (string) $row->source ) : '',
            'actor_user_id' => $actor_user_id,
            'actor_name'    => $actor instanceof \WP_User ? $actor->display_name : '',
        );
    }

    public function handle_attendees_dashboard_data(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_view_attendees' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }

        $source_filter = isset( $_POST['source_filter'] ) ? sanitize_key( $_POST['source_filter'] ) : 'all';
        $ticket_status = isset( $_POST['ticket_status'] ) ? sanitize_key( $_POST['ticket_status'] ) : 'all';
        $guests_only = isset( $_POST['guests_only'] ) && $_POST['guests_only'] === '1';
        $has_note_only = isset( $_POST['has_note_only'] ) && $_POST['has_note_only'] === '1';
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        $attendees = $this->get_filtered_attendees( $event_id, $source_filter, $ticket_status, $guests_only, $search, $has_note_only );

        wp_send_json_success( array( 'attendees' => $attendees ) );
    }

    public function handle_attendees_send_email(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_send_notifications' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }

        $source_filter = isset( $_POST['source_filter'] ) ? sanitize_key( $_POST['source_filter'] ) : 'all';
        $ticket_status = isset( $_POST['ticket_status'] ) ? sanitize_key( $_POST['ticket_status'] ) : 'all';
        $guests_only = isset( $_POST['guests_only'] ) && $_POST['guests_only'] === '1';
        $has_note_only = isset( $_POST['has_note_only'] ) && $_POST['has_note_only'] === '1';
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        $subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $bcc = isset( $_POST['bcc'] ) && $_POST['bcc'] === '1';
        $cc_me = isset( $_POST['cc_me'] ) && $_POST['cc_me'] === '1';

        if ( empty( $subject ) || empty( $message ) ) {
            wp_send_json_error( 'Subject and message are required' );
        }

        // Get filtered attendees using the same logic
        $attendees = $this->get_filtered_attendees( $event_id, $source_filter, $ticket_status, $guests_only, $search, $has_note_only );

        // Build recipients list
        $recipients = array();
        foreach ( $attendees as $attendee ) {
            $email = strtolower( trim( $attendee['email'] ) );
            if ( is_email( $email ) && ! in_array( $email, $recipients, true ) ) {
                $recipients[] = $email;
            }
        }

        $recipient_count = count( $recipients );
        if ( $recipient_count === 0 ) {
            wp_send_json_error( 'No recipients found for current filters' );
        }

        if ( $recipient_count > 300 ) {
            wp_send_json_error( 'Too many recipients (max 300)' );
        }

        // Send emails
        $admin_email = get_option( 'admin_email' );
        $chunks = 0;

        if ( $bcc ) {
            // Send one email with BCC
            $chunk_size = 50;
            $email_chunks = array_chunk( $recipients, $chunk_size );

            foreach ( $email_chunks as $chunk ) {
                $headers = array();
                foreach ( $chunk as $email ) {
                    $headers[] = 'Bcc: ' . $email;
                }

                if ( $cc_me ) {
                    $headers[] = 'Cc: ' . $admin_email;
                }

                $result = wp_mail( $admin_email, $subject, $message, $headers );
                if ( ! $result ) {
                    wp_send_json_error( 'Failed to send email to some recipients' );
                }
                $chunks++;
            }
        } else {
            // Send individual emails
            $chunk_size = 10; // Smaller chunks for individual emails
            $email_chunks = array_chunk( $recipients, $chunk_size );

            foreach ( $email_chunks as $chunk ) {
                foreach ( $chunk as $email ) {
                    $headers = array();
                    if ( $cc_me ) {
                        $headers[] = 'Cc: ' . $admin_email;
                    }

                    $result = wp_mail( $email, $subject, $message, $headers );
                    if ( ! $result ) {
                        wp_send_json_error( 'Failed to send email to some recipients' );
                    }
                }
                $chunks++;
            }
        }

        wp_send_json_success( array( 'recipients' => $recipient_count, 'chunks' => $chunks ) );
    }

    public function handle_attendees_save_note(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'oras_tickets_manage_attendees' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }

        $attendee_key = isset( $_POST['attendee_key'] ) ? sanitize_key( $_POST['attendee_key'] ) : '';
        if ( empty( $attendee_key ) ) {
            wp_send_json_error( 'Invalid attendee key' );
        }

        $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

        // Get existing envelope
        $envelope = get_post_meta( $event_id, '_oras_attendee_notes_v1', true );
        if ( ! is_array( $envelope ) ) {
            $envelope = array(
                'version' => 1,
                'items'   => array(),
            );
        }

        if ( ! isset( $envelope['items'] ) ) {
            $envelope['items'] = array();
        }

        if ( empty( $note ) ) {
            // Remove the note
            unset( $envelope['items'][ $attendee_key ] );
        } else {
            // Save the note
            $envelope['items'][ $attendee_key ] = array(
                'note' => $note,
                'ts'   => time(),
                'by'   => get_current_user_id(),
            );
        }

        // Save the envelope
        update_post_meta( $event_id, '_oras_attendee_notes_v1', $envelope );

        wp_send_json_success();
    }

    private function get_filtered_attendees( int $event_id, string $source_filter, string $ticket_status, bool $guests_only, string $search, bool $has_note_only = false ): array {
        $attendees = array();
        $rsvp_attendees = array();
        $ticket_attendees = array();
        $matched_rsvp_user_ids = array();

        // Get notes envelope
        $notes_envelope = get_post_meta( $event_id, '_oras_attendee_notes_v1', true );
        $notes = is_array( $notes_envelope ) && isset( $notes_envelope['items'] ) ? $notes_envelope['items'] : array();

        // Get RSVP YES attendees
        if ( $source_filter === 'all' || $source_filter === 'rsvp' || $source_filter === 'both' ) {
            $yes_users = get_users(
                array(
                    'meta_key'     => '_oras_rsvp_event_' . $event_id,
                    'meta_value'   => 'yes',
                    'meta_compare' => '=',
                )
            );

            foreach ( $yes_users as $user ) {
                $name = $user->display_name;
                $email = $user->user_email;

                if ( $search !== '' && stripos( $name, $search ) === false && stripos( $email, $search ) === false ) {
                    continue;
                }

                $rsvp_attendees[ (int) $user->ID ] = array(
                    'name'         => $name,
                    'email'        => $email,
                    'source'       => 'RSVP',
                    'user_id'      => (int) $user->ID,
                    'order_id'     => 0,
                    'order_status' => '',
                );
            }
        }

        // Get ticket attendees
        if ( $source_filter === 'all' || $source_filter === 'tickets' || $source_filter === 'both' ) {
            $ticket_list = $this->get_ticket_attendees_for_event( $event_id );

            foreach ( $ticket_list as $attendee ) {
                $name = $attendee['name'];
                $email = $attendee['email'];

                if ( $search !== '' && stripos( $name, $search ) === false && stripos( $email, $search ) === false ) {
                    continue;
                }

                // Apply ticket status filter
                if ( $ticket_status !== 'all' && $attendee['order_status'] !== $ticket_status ) {
                    continue;
                }

                // Apply guests only filter
                if ( $guests_only && $attendee['user_id'] > 0 ) {
                    continue;
                }

                $ticket_attendees[] = $attendee;
            }
        }

        // Ticket rows are authoritative for commerce visibility; merge RSVP marker by user when present.
        foreach ( $ticket_attendees as $ticket_data ) {
            $attendee = $ticket_data;
            $user_id = (int) ( $ticket_data['user_id'] ?? 0 );
            $attendee_key = $user_id > 0
                ? 'u_' . $user_id
                : 'o_' . (int) ( $ticket_data['order_id'] ?? 0 );

            if ( $user_id > 0 && isset( $rsvp_attendees[ $user_id ] ) ) {
                $rsvp_data = $rsvp_attendees[ $user_id ];
                $attendee['source'] = 'Both';
                $attendee['name'] = $rsvp_data['name'];
                $attendee['email'] = $rsvp_data['email'];
                $matched_rsvp_user_ids[ $user_id ] = true;
            }

            $attendee['attendee_key'] = $attendee_key;
            $attendee['note'] = isset( $notes[ $attendee_key ] ) ? $notes[ $attendee_key ]['note'] : '';

            if ( $has_note_only && empty( $attendee['note'] ) ) {
                continue;
            }

            $attendees[] = $attendee;
        }

        // Append RSVP-only users not represented in ticket rows.
        if ( $source_filter === 'all' || $source_filter === 'rsvp' || $source_filter === 'both' ) {
            foreach ( $rsvp_attendees as $user_id => $rsvp_data ) {
                if ( isset( $matched_rsvp_user_ids[ (int) $user_id ] ) ) {
                    continue;
                }

                $attendee_key = 'u_' . $rsvp_data['user_id'];
                $rsvp_data['attendee_key'] = $attendee_key;
                $rsvp_data['note'] = isset( $notes[ $attendee_key ] ) ? $notes[ $attendee_key ]['note'] : '';

                if ( $has_note_only && empty( $rsvp_data['note'] ) ) {
                    continue;
                }

                $attendees[] = $rsvp_data;
            }
        }

        return $attendees;
    }

    private function get_ticket_attendees_for_event( int $event_id ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $map = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
        if ( ! is_array( $map ) || empty( $map ) ) {
            return array();
        }

        $product_ids = array();
        foreach ( $map as $pid ) {
            $pid = absint( $pid );
            if ( $pid > 0 ) {
                $product_ids[] = $pid;
            }
        }
        if ( empty( $product_ids ) ) {
            return array();
        }
        $product_lookup = array_fill_keys( $product_ids, true );

        $orders = array();
        foreach ( $product_ids as $pid ) {
            $ords = wc_get_orders( array(
                // Keep this aligned with attendee dashboard ticket status filters.
                'status'  => array( 'completed', 'processing', 'on-hold', 'pending', 'refunded', 'cancelled', 'failed' ),
                'product_id' => $pid,
                'limit'   => -1,
            ) );
            $orders = array_merge( $orders, $ords );
        }

        $unique_orders = array();
        foreach ( $orders as $order ) {
            $unique_orders[ $order->get_id() ] = $order;
        }

        $attendees = array();
        foreach ( $unique_orders as $order ) {
            $user_id = (int) $order->get_user_id();
            $order_id = $order->get_id();
            $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $email = $order->get_billing_email();
            $order_status = $order->get_status();
            $ticket_count = 0;

            foreach ( $order->get_items() as $item ) {
                if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
                    continue;
                }

                $product_id = (int) $item->get_product_id();
                if ( $product_id <= 0 || ! isset( $product_lookup[ $product_id ] ) ) {
                    continue;
                }

                $qty = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
                $ticket_count += max( 1, $qty );
            }

            if ( $ticket_count <= 0 ) {
                continue;
            }

            for ( $idx = 1; $idx <= $ticket_count; $idx++ ) {
                if ( $user_id > 0 ) {
                    $attendees[] = array(
                        'name'         => $name,
                        'email'        => $email,
                        'source'       => 'Ticket',
                        'user_id'      => $user_id,
                        'order_id'     => $order_id,
                        'order_status' => $order_status,
                        'ticket_index' => $idx,
                        'ticket_total' => $ticket_count,
                    );
                } else {
                    // Guest
                    $attendees[] = array(
                        'name'         => $name,
                        'email'        => $email,
                        'source'       => 'Ticket (Guest)',
                        'user_id'      => 0,
                        'order_id'     => $order_id,
                        'order_status' => $order_status,
                        'ticket_index' => $idx,
                        'ticket_total' => $ticket_count,
                    );
                }
            }
        }

        return $attendees;
    }

    public function handle_attendees_export_csv(): void {
        check_admin_referer( 'oras_rsvp_dashboard' );

        if ( ! current_user_can( 'oras_tickets_export_reports' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_die( 'Invalid event ID' );
        }

        $source_filter = isset( $_GET['source_filter'] ) ? sanitize_key( $_GET['source_filter'] ) : 'all';
        $ticket_status = isset( $_GET['ticket_status'] ) ? sanitize_key( $_GET['ticket_status'] ) : 'all';
        $guests_only = isset( $_GET['guests_only'] ) && $_GET['guests_only'] === '1';
        $has_note_only = isset( $_GET['has_note_only'] ) && $_GET['has_note_only'] === '1';
        $search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

        // Get filtered attendees
        $attendees = $this->get_filtered_attendees( $event_id, $source_filter, $ticket_status, $guests_only, $search, $has_note_only );

        // Output CSV
        $event_title = get_the_title( $event_id );
        $filename = 'attendees-' . sanitize_title( $event_title ) . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // CSV headers
        fputcsv( $output, CsvSafety::row( array( 'Name', 'Email', 'Source', 'User ID', 'Order ID', 'Order Status', 'User Admin URL', 'Order Admin URL', 'Note' ) ) );

        // CSV data
        foreach ( $attendees as $attendee ) {
            $user_admin_url = $attendee['user_id'] > 0 ? admin_url( 'user-edit.php?user_id=' . $attendee['user_id'] ) : '';
            $order_admin_url = $attendee['order_id'] > 0 ? admin_url( 'post.php?post=' . $attendee['order_id'] . '&action=edit' ) : '';

            fputcsv( $output, CsvSafety::row( array(
                $attendee['name'],
                $attendee['email'],
                $attendee['source'],
                $attendee['user_id'] ?: '',
                $attendee['order_id'] ?: '',
                $attendee['order_status'],
                $user_admin_url,
                $order_admin_url,
                $attendee['note'],
            ) ) );
        }

        fclose( $output );
        exit;
    }

    public function handle_rsvp_export_yes(): void {
        $this->handle_rsvp_export( 'yes' );
    }

    public function handle_rsvp_export_waitlist(): void {
        $this->handle_rsvp_export( 'waitlist' );
    }

    private function handle_rsvp_export( string $status ): void {
        check_admin_referer( 'oras_rsvp_dashboard' );

        if ( ! current_user_can( 'oras_tickets_export_reports' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_die( 'Invalid event ID' );
        }

        if ( 'waitlist' === $status ) {
            $users = \ORAS\Tickets\Waitlist_Store::get_waiting_users( $event_id );
        } else {
            $users = get_users(
                array(
                    'meta_key'     => '_oras_rsvp_event_' . $event_id,
                    'meta_value'   => $status,
                    'meta_compare' => '=',
                )
            );
        }

        $filename = 'rsvp-' . $status . '-' . get_the_title( $event_id ) . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, CsvSafety::row( array( 'Name', 'Email', 'Status' ) ) );

        foreach ( $users as $user ) {
            fputcsv( $output, CsvSafety::row( array(
                $user->display_name,
                $user->user_email,
                ucfirst( $status ),
            ) ) );
        }

        fclose( $output );
        exit;
    }

    public function handle_rsvp_promote(): void {
        check_admin_referer( 'oras_rsvp_dashboard' );

        if ( ! current_user_can( 'oras_tickets_manage_rsvps' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_die( 'Invalid event ID' );
        }

        $rsvp_settings = get_post_meta( $event_id, '_oras_rsvp_v1', true );
        if ( ! is_array( $rsvp_settings ) ) {
            wp_die( 'No RSVP settings found for this event' );
        }

        $result = \ORAS\Tickets\Support\DbLock::forEvent(
            $event_id,
            function () use ( $event_id, $rsvp_settings ) {
                $capacity = isset( $rsvp_settings['capacity'] ) ? absint( $rsvp_settings['capacity'] ) : 0;

                $yes_count = count( get_users(
                    array(
                        'meta_key'     => '_oras_rsvp_event_' . $event_id,
                        'meta_value'   => 'yes',
                        'meta_compare' => '=',
                    )
                ) );

                if ( $capacity > 0 && $yes_count >= $capacity ) {
                    return new \WP_Error( 'oras_rsvp_capacity', 'Event is already at capacity' );
                }

                $promoted_user_id = \ORAS\Tickets\Waitlist_Store::promote_next_waiting(
                    $event_id,
                    get_current_user_id(),
                    'dashboard'
                );

                if ( $promoted_user_id <= 0 ) {
                    return new \WP_Error( 'oras_rsvp_waitlist_empty', 'No users on waitlist' );
                }

                update_user_meta( $promoted_user_id, '_oras_rsvp_event_' . $event_id, 'yes' );
                delete_user_meta( $promoted_user_id, '_oras_rsvp_event_' . $event_id . '_ts' );

                return $promoted_user_id;
            }
        );

        if ( is_wp_error( $result ) ) {
            wp_die( $result->get_error_message() );
        }

        // Redirect back to dashboard with success message
        wp_safe_redirect( admin_url( 'admin.php?page=oras-tickets&tab=rsvp&promoted=1' ) );
        exit;
    }
}
