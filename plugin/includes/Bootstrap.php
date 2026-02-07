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
// Admin hub (Phase 2.9)
require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Reports_Aggregator.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Dashboard_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Reports_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Settings_Page.php';
// Frontend tickets display (Phase 1.3 - read-only)
require_once ORAS_TICKETS_DIR . 'includes/Frontend/Tickets_Display.php';
require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Cart_Pricing.php';

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

		// Hard deps: TEC (tribe_events) and WooCommerce.
		$has_tec = post_type_exists('tribe_events') || class_exists('Tribe__Events__Main');
		$has_woo = class_exists('WooCommerce');

		Logger::instance()->log('TEC present? ' . ($has_tec ? 'yes' : 'no'));
		Logger::instance()->log('WooCommerce present? ' . ($has_woo ? 'yes' : 'no'));

		if (! $has_tec || ! $has_woo) {
			add_action('admin_notices', function () use ($has_tec, $has_woo) {
				if (! current_user_can('activate_plugins')) {
					return;
				}
				$missing = [];
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
			});

			Logger::instance()->log('Bootstrap aborted: missing dependencies');
			return;
		}

		// Phase 1 modules will be loaded here next.
		add_action('init', [$this, 'register_phase1'], 20);

		Logger::instance()->log('Bootstrap init complete');
	}

	public function register_phase1(): void
	{
		// Register Phase 1 modules.
		Logger::instance()->log('Phase 1 registration hook fired (init)');

		require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Product_Sync.php';
		$ps = new \ORAS\Tickets\Commerce\Woo\Product_Sync();
		$ps->register();

		require_once ORAS_TICKETS_DIR . 'includes/Commerce/Woo/Capacity_Consumption.php';
		$cc = new \ORAS\Tickets\Commerce\Woo\Capacity_Consumption();
		$cc->register();

		\ORAS\Tickets\Commerce\Woo\Cart_Pricing::register();

		// Admin-only (or WP-CLI): register ticket metabox and admin hub.
		if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
			\ORAS\Tickets\Admin\Tickets_Metabox::instance()->init();
			require_once ORAS_TICKETS_DIR . 'includes/Admin/Admin_Menu.php';
			$admin_menu = new \ORAS\Tickets\Admin\Admin_Menu();
			$admin_menu->register();
			// do not return; allow further initialization below
		}

		// Initialize Tickets_Display when not in admin contexts. This ensures frontend rendering
		// runs in normal page requests and that WP-CLI can also initialize the frontend display
		// when `is_admin()` is false.
		if (! is_admin()) {
			\ORAS\Tickets\Frontend\Tickets_Display::instance()->init();
		}
	}
}
