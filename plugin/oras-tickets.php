<?php
/**
 * Plugin Name: ORAS Tickets
 * Description: Internal Event Tickets add-on for ORAS (Phase 1 MVP).
 * Version: 0.1.0
 * Author: ORAS
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORAS_TICKETS_VERSION', '0.1.0' );
define( 'ORAS_TICKETS_FILE', __FILE__ );
define( 'ORAS_TICKETS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORAS_TICKETS_URL', plugin_dir_url( __FILE__ ) );

// Toggle debug logging by setting in wp-config.php:
// define('ORAS_TICKETS_DEBUG', true);
if ( ! defined( 'ORAS_TICKETS_DEBUG' ) ) {
	define( 'ORAS_TICKETS_DEBUG', false );
}

require_once ORAS_TICKETS_DIR . 'includes/Support/Logger.php';
require_once ORAS_TICKETS_DIR . 'includes/Bootstrap.php';

add_action( 'plugins_loaded', static function () {
	\ORAS\Tickets\Bootstrap::instance()->init();
}, 20 );
