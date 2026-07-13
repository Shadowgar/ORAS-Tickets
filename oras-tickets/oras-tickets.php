<?php

/**
 * Plugin Name: ORAS Tickets
 * Description: Internal Event Tickets add-on for ORAS (Phase 1 MVP).
 * Version: 0.4.24
 * Author: ORAS
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package ORAS\Tickets
 */

use ORAS\Tickets\Admin\Event_Speakers_Metabox;
use ORAS\Tickets\Admin\Speaker_CPT;
use ORAS\Tickets\Bootstrap;
use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Event_Question_Attention_Store;
use ORAS\Tickets\Waitlist_Store;

if (! defined('ABSPATH')) {
    exit;
}

define('ORAS_TICKETS_VERSION', '0.4.24');
define('ORAS_TICKETS_FILE', __FILE__);
define('ORAS_TICKETS_DIR', plugin_dir_path(__FILE__));
define('ORAS_TICKETS_URL', plugin_dir_url(__FILE__));

// Toggle debug logging by setting in wp-config.php.
// Example in wp-config.php.
if (! defined('ORAS_TICKETS_DEBUG')) {
    define('ORAS_TICKETS_DEBUG', false);
}

require_once ORAS_TICKETS_DIR . 'includes/Support/Logger.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Bootstrap.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Capabilities.php'; // load capabilities helper

add_action(
    'plugins_loaded',
    static function () {
        $speaker_cpt = new Speaker_CPT();
        $speaker_cpt->register();

        if (is_admin()) {
            $event_speakers = new Event_Speakers_Metabox();
            $event_speakers->register();
        }

        Bootstrap::instance()->init();
    },
    20
);

register_activation_hook(ORAS_TICKETS_FILE, static function (): void {
    \ORAS\Tickets\Capabilities::add_caps();
    Waitlist_Store::install_schema();
    Communication_Log_Store::install_schema();
    Event_Question_Attention_Store::install_schema();
});
