<?php
/** Independent configuration race worker for the disposable Registration Desk runtime. */

use ORAS\Tickets\Registration_Desk\Config;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$expected_marker = defined( 'ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED' ) ? ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED : '';
if ( '' === $expected_marker || ! hash_equals( $expected_marker, (string) get_option( 'oras_registration_desk_disposable_fixture_id', '' ) ) || 'tests-wordpress' !== DB_NAME ) {
	WP_CLI::error( 'Unsafe configuration worker runtime.' );
}
$index = defined( 'ORAS_REGISTRATION_DESK_WORKER_INDEX' ) ? (int) ORAS_REGISTRATION_DESK_WORKER_INDEX : 0;
$config_race_mode = defined( 'ORAS_REGISTRATION_DESK_CONFIG_RACE_MODE' ) ? ORAS_REGISTRATION_DESK_CONFIG_RACE_MODE : '';
$context = get_option( 'oras_registration_desk_integration_context', array() );
if ( ! in_array( $index, array( 1, 2 ), true ) || ! is_array( $context ) || empty( $context['config_race'] ) ) {
	WP_CLI::error( 'Configuration worker context is missing.' );
}
wp_set_current_user( (int) $context['admin_id'] );
$raw = array(
	'enabled' => true,
	'options' => array(),
);
if ( 'same_event' === $config_race_mode ) {
	$result = Config::save_event_config( (int) $context['config_race']['event_id'], $raw, 0 );
} elseif ( 'activation' === $config_race_mode ) {
	$target = 1 === $index ? (int) $context['config_race']['activation_a'] : (int) $context['config_race']['activation_b'];
	$result = Config::save_and_activate( $target, $raw, 0, (int) $context['event_id'] );
} else {
	WP_CLI::error( 'Configuration worker mode is invalid.' );
}
echo wp_json_encode(
	array(
		'worker' => $index,
		'result' => $result instanceof WP_Error ? $result->get_error_code() : 'success',
	)
);
