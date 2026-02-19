<?php

namespace ORAS\Tickets;

use ORAS\Tickets\Support\Logger;

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
require_once ORAS_TICKETS_DIR . 'includes/Templates/Template_Loader.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Cart_Pricing.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Api/Member_Hub_Tickets.php'; // NOSONAR legacy include

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

        require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Capacity_Consumption.php'; // NOSONAR legacy include
        $cc = new \ORAS\Tickets\Commerce\Woo\Capacity_Consumption();
        $cc->register();

        require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Order_Autocomplete.php'; // NOSONAR legacy include
        $oa = new \ORAS\Tickets\Commerce\Woo\Order_Autocomplete();
        $oa->register();

        \ORAS\Tickets\Commerce\Woo\Cart_Pricing::register();

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

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }

        $rsvp_settings = get_post_meta( $event_id, '_oras_rsvp_v1', true );
        if ( ! is_array( $rsvp_settings ) ) {
wp_send_json_error( 'No RSVP settings found for this event' );
        }

        $capacity = isset( $rsvp_settings['capacity'] ) ? absint( $rsvp_settings['capacity'] ) : 0;

        // Get users with RSVP status
        $yes_users = get_users(
            array(
                'meta_key'     => '_oras_rsvp_event_' . $event_id,
                'meta_value'   => 'yes',
                'meta_compare' => '=',
            )
        );

        $waitlist_users = get_users(
            array(
                'meta_query' => array(
                    array(
                        'key'     => '_oras_rsvp_event_' . $event_id,
                        'value'   => 'waitlist',
                        'compare' => '=',
                    ),
                ),
                'orderby'    => 'meta_value_num',
                'meta_key'   => '_oras_rsvp_event_' . $event_id . '_ts',
                'order'      => 'ASC',
            )
        );

        $yes_count = count( $yes_users );
        $waitlist_count = count( $waitlist_users );
        $is_full = $yes_count >= $capacity;

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
                'stats'     => array(
                    'capacity'       => $capacity,
                    'yes_count'      => $yes_count,
                    'waitlist_count' => $waitlist_count,
                    'is_full'        => $is_full,
                ),
                'attendees' => $attendees,
            )
        );
    }

    public function handle_attendees_dashboard_data(): void {
        check_ajax_referer( 'oras_rsvp_dashboard', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
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

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
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

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
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

                $key = 'user_' . $user->ID;
                $rsvp_attendees[ $key ] = array(
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

                $key = $attendee['user_id'] > 0 ? 'user_' . $attendee['user_id'] : 'guest_' . strtolower( $email ) . '_' . $attendee['order_id'];
                $ticket_attendees[ $key ] = $attendee;
            }
        }

        // Merge and determine source
        $all_keys = array_unique( array_merge( array_keys( $rsvp_attendees ), array_keys( $ticket_attendees ) ) );

        foreach ( $all_keys as $key ) {
            $rsvp_data = isset( $rsvp_attendees[ $key ] ) ? $rsvp_attendees[ $key ] : null;
            $ticket_data = isset( $ticket_attendees[ $key ] ) ? $ticket_attendees[ $key ] : null;

            $attendee = null;
            $attendee_key = '';

            if ( $rsvp_data && $ticket_data ) {
                // Both
                $attendee = array(
                    'name'         => $rsvp_data['name'],
                    'email'        => $rsvp_data['email'],
                    'source'       => 'Both',
                    'user_id'      => $rsvp_data['user_id'],
                    'order_id'     => $ticket_data['order_id'],
                    'order_status' => $ticket_data['order_status'],
                );
                $attendee_key = 'u_' . $rsvp_data['user_id'];
            } elseif ( $rsvp_data ) {
                $attendee = $rsvp_data;
                $attendee_key = 'u_' . $rsvp_data['user_id'];
            } elseif ( $ticket_data ) {
                $attendee = $ticket_data;
                $attendee_key = $ticket_data['user_id'] > 0 ? 'u_' . $ticket_data['user_id'] : 'o_' . $ticket_data['order_id'];
            }

            if ( $attendee ) {
                // Add attendee_key and note
                $attendee['attendee_key'] = $attendee_key;
                $attendee['note'] = isset( $notes[ $attendee_key ] ) ? $notes[ $attendee_key ]['note'] : '';

                // Apply has_note_only filter
                if ( $has_note_only && empty( $attendee['note'] ) ) {
                    continue;
                }

                $attendees[] = $attendee;
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

        $orders = array();
        foreach ( $product_ids as $pid ) {
            $ords = wc_get_orders( array(
                'status'  => array( 'processing', 'completed' ),
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

            if ( $user_id > 0 ) {
                $attendees[] = array(
                    'name'         => $name,
                    'email'        => $email,
                    'source'       => 'Ticket',
                    'user_id'      => $user_id,
                    'order_id'     => $order_id,
                    'order_status' => $order_status,
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
                );
            }
        }

        return $attendees;
    }

    public function handle_attendees_export_csv(): void {
        check_admin_referer( 'oras_rsvp_dashboard' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
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
        fputcsv( $output, array( 'Name', 'Email', 'Source', 'User ID', 'Order ID', 'Order Status', 'User Admin URL', 'Order Admin URL', 'Note' ) );

        // CSV data
        foreach ( $attendees as $attendee ) {
            $user_admin_url = $attendee['user_id'] > 0 ? admin_url( 'user-edit.php?user_id=' . $attendee['user_id'] ) : '';
            $order_admin_url = $attendee['order_id'] > 0 ? admin_url( 'post.php?post=' . $attendee['order_id'] . '&action=edit' ) : '';

            fputcsv( $output, array(
                $attendee['name'],
                $attendee['email'],
                $attendee['source'],
                $attendee['user_id'] ?: '',
                $attendee['order_id'] ?: '',
                $attendee['order_status'],
                $user_admin_url,
                $order_admin_url,
                $attendee['note'],
            ) );
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

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_die( 'Invalid event ID' );
        }

        $users = get_users(
            array(
                'meta_key'     => '_oras_rsvp_event_' . $event_id,
                'meta_value'   => $status,
                'meta_compare' => '=',
            )
        );

        $filename = 'rsvp-' . $status . '-' . get_the_title( $event_id ) . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'Name', 'Email', 'Status' ) );

        foreach ( $users as $user ) {
            fputcsv( $output, array(
                $user->display_name,
                $user->user_email,
                ucfirst( $status ),
            ) );
        }

        fclose( $output );
        exit;
    }

    public function handle_rsvp_promote(): void {
        check_admin_referer( 'oras_rsvp_dashboard' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
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

        $capacity = isset( $rsvp_settings['capacity'] ) ? absint( $rsvp_settings['capacity'] ) : 0;

        // Count current yes RSVPs
        $yes_count = count( get_users(
            array(
                'meta_key'     => '_oras_rsvp_event_' . $event_id,
                'meta_value'   => 'yes',
                'meta_compare' => '=',
            )
        ) );

        if ( $yes_count >= $capacity ) {
            wp_die( 'Event is already at capacity' );
        }

        // Find the oldest waitlist user
        $waitlist_users = get_users(
            array(
                'meta_query' => array(
                    array(
                        'key'     => '_oras_rsvp_event_' . $event_id,
                        'value'   => 'waitlist',
                        'compare' => '=',
                    ),
                ),
                'orderby'    => 'meta_value_num',
                'meta_key'   => '_oras_rsvp_event_' . $event_id . '_ts',
                'order'      => 'ASC',
                'number'     => 1,
            )
        );

        if ( empty( $waitlist_users ) ) {
            wp_die( 'No users on waitlist' );
        }

        $user = $waitlist_users[0];
        update_user_meta( $user->ID, '_oras_rsvp_event_' . $event_id, 'yes' );

        // Redirect back to dashboard with success message
        wp_redirect( admin_url( 'admin.php?page=oras-tickets-dashboard&promoted=1' ) );
        exit;
    }
}
