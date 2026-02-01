<?php
namespace ORAS\Tickets;

use ORAS\Tickets\Support\Logger;
require_once ORAS_TICKETS_DIR . 'includes/Domain/Meta.php';
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket.php';
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket_Collection.php';
// Admin metabox for Phase 1.2
// Admin metabox is kept in repo but no longer auto-initialized; using native ET editor + provider.
require_once ORAS_TICKETS_DIR . 'includes/Admin/Tickets_Metabox.php';
// Frontend tickets display (Phase 1.3 - read-only)
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Tickets_Display.php';
// Commerce provider (Woo) - loaded later when dependencies are available.


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

		// Hard deps: Event Tickets + WooCommerce. (TEC required indirectly for actual use.)
		$has_event_tickets = class_exists( 'Tribe__Tickets__Main' );
		$has_woo          = class_exists( 'WooCommerce' );

		Logger::instance()->log( 'Event Tickets present? ' . ( $has_event_tickets ? 'yes' : 'no' ) );
		Logger::instance()->log( 'WooCommerce present? ' . ( $has_woo ? 'yes' : 'no' ) );

		if ( ! $has_event_tickets || ! $has_woo ) {
			add_action( 'admin_notices', function () use ( $has_event_tickets, $has_woo ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				$missing = [];
				if ( ! $has_event_tickets ) {
					$missing[] = 'Event Tickets (free)';
				}
				if ( ! $has_woo ) {
					$missing[] = 'WooCommerce';
				}
				printf(
					'<div class="notice notice-error"><p><strong>ORAS Tickets</strong> requires: %s</p></div>',
					esc_html( implode( ', ', $missing ) )
				);
			} );

			Logger::instance()->log( 'Bootstrap aborted: missing dependencies' );
			return;
		}

		// Phase 1 modules will be loaded here next.
		add_action( 'init', [ $this, 'register_phase1' ], 20 );
		// Register commerce provider once plugins are loaded and classes are available.
		add_action( 'plugins_loaded', [ $this, 'register_commerce' ], 20 );

		Logger::instance()->log( 'Bootstrap init complete' );
	}

	public function register_phase1(): void {
		// Register Phase 1 modules.
		Logger::instance()->log( 'Phase 1 registration hook fired (init)' );

		// Frontend: register read-only tickets display on single event pages.
		\ORAS\Tickets\Frontend\Tickets_Display::instance()->init();

		// Commerce provider initialization moved to register_commerce() hooked on plugins_loaded.
	}

	/**
	 * Load and initialize commerce provider when WP plugins have loaded and required classes exist.
	 */
	public function register_commerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! class_exists( 'Tribe__Tickets__Tickets' ) ) {
			return;
		}

		if ( ! class_exists( 'Tribe__Tickets__Ticket_Object' ) ) {
			return;
		}

		require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Ticket_Object.php';
		require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Provider.php';

		// Safe to call maybe_init() multiple times.
		if ( method_exists( '\\ORAS\\Tickets\\Commerce\\Woo\\Provider', 'maybe_init' ) ) {
			\ORAS\Tickets\Commerce\Woo\Provider::maybe_init();
		}
	}
}
