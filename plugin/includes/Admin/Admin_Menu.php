<?php

namespace ORAS\Tickets\Admin;

require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Dashboard_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Reports_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Speaker_Obligations_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Speaker_Reports_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Settings_Page.php';

use ORAS\Tickets\Admin\Pages\Dashboard_Page;
use ORAS\Tickets\Admin\Pages\Reports_Page;
use ORAS\Tickets\Admin\Pages\Speaker_Obligations_Page;
use ORAS\Tickets\Admin\Pages\Speaker_Reports_Page;
use ORAS\Tickets\Admin\Pages\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Menu {


	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_oras_tickets_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_init', array( Settings_Page::class, 'register_settings' ) );
		( new Speaker_Obligations_Page() )->register();
		( new Speaker_Reports_Page() )->register();
	}

	public function register_menu(): void {
		$capability = 'manage_woocommerce';

		add_menu_page(
			__( 'ORAS Tickets', 'oras-tickets' ),
			__( 'ORAS Tickets', 'oras-tickets' ),
			$capability,
			'oras-tickets',
			array( $this, 'render_dashboard' ),
			'dashicons-tickets-alt',
			56
		);

		add_submenu_page(
			'oras-tickets',
			__( 'Dashboard', 'oras-tickets' ),
			__( 'Dashboard', 'oras-tickets' ),
			$capability,
			'oras-tickets',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'oras-tickets',
			__( 'Reports', 'oras-tickets' ),
			__( 'Reports', 'oras-tickets' ),
			$capability,
			'oras-tickets-reports',
			array( $this, 'render_reports' )
		);

		add_submenu_page(
			'oras-tickets',
			__( 'Speaker Obligations', 'oras-tickets' ),
			__( 'Speaker Obligations', 'oras-tickets' ),
			$capability,
			'oras-tickets-speaker-obligations',
			array( $this, 'render_speaker_obligations' )
		);

		add_submenu_page(
			'oras-tickets',
			__( 'Speaker Reports', 'oras-tickets' ),
			__( 'Speaker Reports', 'oras-tickets' ),
			$capability,
			'oras-tickets-speaker-reports',
			array( $this, 'render_speaker_reports' )
		);

		add_submenu_page(
			'oras-tickets',
			__( 'Settings', 'oras-tickets' ),
			__( 'Settings', 'oras-tickets' ),
			$capability,
			'oras-tickets-settings',
			array( $this, 'render_settings' )
		);
	}

	public function render_dashboard(): void {
		( new Dashboard_Page() )->render();
	}

	public function render_reports(): void {
		( new Reports_Page() )->render();
	}

	public function render_settings(): void {
		( new Settings_Page() )->render();
	}

	public function render_speaker_obligations(): void {
		( new Speaker_Obligations_Page() )->render();
	}

	public function render_speaker_reports(): void {
		( new Speaker_Reports_Page() )->render();
	}

	public function handle_export_csv(): void {
		( new Reports_Page() )->export_csv();
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_oras-tickets' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'oras-dashboard-rsvp',
			ORAS_TICKETS_URL . 'assets/admin/dashboard-rsvp.js',
			array( 'jquery' ),
			ORAS_TICKETS_VERSION,
			true
		);

		// Add inline script to define the global object
		wp_add_inline_script(
			'oras-dashboard-rsvp',
			'var orasDashboardRsvp = ' . wp_json_encode(
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'adminPostUrl' => admin_url( 'admin-post.php' ),
					'nonce'      => wp_create_nonce( 'oras_rsvp_dashboard' ),
				)
			) . ';',
			'before'
		);
	}
}
