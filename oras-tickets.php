<?php
/**
 * Plugin Name: ORAS Tickets
 * Description: ORAS event ticketing integrated with The Events Calendar and WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: ORAS
 * License: GPL-2.0-or-later
 * Text Domain: oras-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

define( 'ORAS_TICKETS_VERSION', '0.1.0' );
define( 'ORAS_TICKETS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ORAS_TICKETS_URL', plugin_dir_url( __FILE__ ) );

require_once ORAS_TICKETS_PATH . 'includes/Bootstrap.php';

add_action( 'plugins_loaded', function () {
  \ORAS\Tickets\Bootstrap::instance()->init();
} );
