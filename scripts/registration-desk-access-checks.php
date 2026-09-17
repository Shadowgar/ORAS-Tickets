<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['oras_test_session_token'] = 'wordpress-session-a';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
	}
}

function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function wp_salt( string $scheme = 'auth' ): string { return 'test-salt-' . $scheme; }
function wp_get_session_token(): string { return (string) $GLOBALS['oras_test_session_token']; }
function wp_generate_uuid4(): string {
	static $counter = 0;
	++$counter;
	return sprintf( '00000000-0000-4000-8000-%012d', $counter );
}
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function absint( mixed $value ): int { return abs( (int) $value ); }

function oras_access_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

$plugin = dirname( __DIR__ ) . '/oras-tickets/';
$files  = array(
	'includes/Capabilities.php',
	'includes/Registration_Desk/Config.php',
	'includes/Registration_Desk/Access.php',
	'includes/Registration_Desk/Station_Session.php',
	'includes/Registration_Desk/Admin_Settings.php',
	'includes/Registration_Desk/Landing_Page.php',
);

foreach ( $files as $file ) {
	oras_access_assert( file_exists( $plugin . $file ), "{$file} exists" );
	require_once $plugin . $file;
}

$config_class  = '\\ORAS\\Tickets\\Registration_Desk\\Config';
$station_class = '\\ORAS\\Tickets\\Registration_Desk\\Station_Session';
$access_class  = '\\ORAS\\Tickets\\Registration_Desk\\Access';
$caps_class    = '\\ORAS\\Tickets\\Capabilities';

$default = $config_class::normalize_event_config( array() );
oras_access_assert( false === $default['enabled'], 'Desk feature is inactive by default' );
oras_access_assert( 0 === $default['revision'], 'Missing configuration has revision zero' );
oras_access_assert( array() === $default['options'], 'Missing configuration has no enabled options' );

$configured = $config_class::normalize_event_config(
	array(
		'enabled'  => true,
		'revision' => 7,
		'options'  => array(
			array(
				'option_uuid'              => '11111111-1111-4111-8111-111111111111',
				'label'                    => 'Synthetic Individual',
				'available_for_new'        => false,
				'existing_access_valid'    => true,
				'classification'           => 'individual',
				'validity_type'             => 'full_event',
				'source_product_ids'        => array( 42 ),
			),
		),
	)
);
oras_access_assert( false === $configured['options'][0]['available_for_new'], 'New-registration availability is independent' );
oras_access_assert( true === $configured['options'][0]['existing_access_valid'], 'Existing access validity is independent' );
oras_access_assert( 7 === $configured['revision'], 'Configuration revision is preserved' );

$token_a = $station_class::issue( 99, 123, 7, 'Alice', 600 );
$token_b = $station_class::issue( 99, 123, 7, 'Bob', 600 );
oras_access_assert( is_string( $token_a ) && is_string( $token_b ) && $token_a !== $token_b, 'Two devices receive independent station tokens' );

$session_a = $station_class::validate( $token_a, 99, 123, 7 );
$session_b = $station_class::validate( $token_b, 99, 123, 7 );
oras_access_assert( is_array( $session_a ) && 'Alice' === $session_a['operator_label'], 'First device retains its operator label' );
oras_access_assert( is_array( $session_b ) && 'Bob' === $session_b['operator_label'], 'Second device retains its operator label' );
oras_access_assert( $session_a['station_uuid'] !== $session_b['station_uuid'], 'Station UUIDs are device-specific' );
oras_access_assert( $station_class::validate( $token_a, 99, 124, 7 ) instanceof WP_Error, 'Station token is bound to active event' );
oras_access_assert( $station_class::validate( $token_a, 99, 123, 8 ) instanceof WP_Error, 'Station token is bound to configuration revision' );
$GLOBALS['oras_test_session_token'] = 'wordpress-session-b';
oras_access_assert( $station_class::validate( $token_a, 99, 123, 7 ) instanceof WP_Error, 'WordPress session change invalidates station token' );

oras_access_assert( defined( $caps_class . '::REGISTRATION_DESK_ROLE' ), 'Dedicated desk role is defined' );
oras_access_assert( in_array( 'oras_tickets_use_registration_desk', $caps_class::REGISTRATION_DESK_CAPS, true ), 'Desk role can use the desk' );
oras_access_assert( in_array( 'oras_tickets_admit_registration_desk', $caps_class::REGISTRATION_DESK_CAPS, true ), 'Desk role can admit supported attendees' );
oras_access_assert( ! in_array( 'oras_tickets_checkin', $caps_class::REGISTRATION_DESK_CAPS, true ), 'Desk role does not receive legacy check-in' );
foreach ( array( 'oras_tickets_view_reports', 'oras_tickets_export_reports', 'oras_tickets_manage_memberships', 'manage_options' ) as $forbidden ) {
	oras_access_assert( ! in_array( $forbidden, $caps_class::REGISTRATION_DESK_CAPS, true ), "Desk role lacks {$forbidden}" );
}
oras_access_assert( method_exists( $access_class, 'register' ), 'Restricted-account guard can be registered' );
oras_access_assert( method_exists( $access_class, 'rest_pre_dispatch' ), 'Restricted-account guard covers REST bypasses' );

echo "Registration Desk access checks passed.\n";
