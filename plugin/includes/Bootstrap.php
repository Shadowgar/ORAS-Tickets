<?php

namespace ORAS\Tickets;

use ORAS\Tickets\Support\Logger;

require_once ORAS_TICKETS_DIR . 'includes/Domain/Meta.php';
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket.php';
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket_Collection.php';
require_once ORAS_TICKETS_DIR . 'includes/Domain/Pricing/Price_Resolver.php';
// Admin metabox for Phase 1.2
// Admin metabox is kept in repo but no longer auto-initialized; using native ET editor + provider.
require_once ORAS_TICKETS_DIR . 'includes/Admin/Tickets_Metabox.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Event_Addon_Metabox.php';
// Admin hub (Phase 2.9)
require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Speaker_CPT.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Event_Speakers_Metabox.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_Agenda_Metabox.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_RSVP_Metabox.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Reports_Aggregator.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Dashboard_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Reports_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Settings_Page.php';
// Frontend tickets display (Phase 1.3 - read-only)
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Tickets_Display.php';
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Event_Agenda_Render.php';
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Ticket_Print_Controller.php';
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Virtual_Access.php';
	require_once ORAS_TICKETS_DIR . 'includes/Frontend/Event_RSVP.php';
require_once ORAS_TICKETS_DIR . 'includes/Templates/Template_Loader.php';
require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Cart_Pricing.php';
require_once ORAS_TICKETS_DIR . 'includes/Api/Member_Hub_Tickets.php';

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

		require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Product_Sync.php';
		$ps = new \ORAS\Tickets\Commerce\Woo\Product_Sync();
		$ps->register();

		require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Capacity_Consumption.php';
		$cc = new \ORAS\Tickets\Commerce\Woo\Capacity_Consumption();
		$cc->register();

		require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Order_Autocomplete.php';
		$oa = new \ORAS\Tickets\Commerce\Woo\Order_Autocomplete();
		$oa->register();

		\ORAS\Tickets\Commerce\Woo\Cart_Pricing::register();

		$api = new \ORAS\Tickets\Api\Member_Hub_Tickets();
		$api->register();

		require_once ORAS_TICKETS_DIR . 'includes/Api/Rsvp.php';
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
			require_once ORAS_TICKETS_DIR . 'includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php';
			\ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Attendees_Metabox::register();
			require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php';
			$admin_menu = new \ORAS\Tickets\Admin\Admin_Menu();
			$admin_menu->register();

			// RSVP Dashboard handlers
			add_action( 'wp_ajax_oras_rsvp_dashboard_data', array( $this, 'handle_rsvp_dashboard_data' ) );
			add_action( 'admin_post_oras_rsvp_export_yes', array( $this, 'handle_rsvp_export_yes' ) );
			add_action( 'admin_post_oras_rsvp_export_waitlist', array( $this, 'handle_rsvp_export_waitlist' ) );
			add_action( 'admin_post_oras_rsvp_promote', array( $this, 'handle_rsvp_promote' ) );

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
