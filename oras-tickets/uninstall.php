<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$cap_file = __DIR__ . '/includes/Capabilities.php';
if ( file_exists( $cap_file ) ) {
    require_once $cap_file;
    if ( class_exists( '\\ORAS\\Tickets\\Capabilities' ) ) {
        \ORAS\Tickets\Capabilities::remove_caps();
    }
}
