<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

$GLOBALS['oras_test_session_token'] = 'wordpress-session-a';
$GLOBALS['oras_test_options']       = array();
$GLOBALS['oras_test_transients']    = array();

function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone WordPress-function test double.
	return json_encode( $value ); }
function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme; }
function wp_get_session_token(): string {
	return (string) $GLOBALS['oras_test_session_token']; }
function wp_generate_uuid4(): string {
	static $counter = 0;
	++$counter;
	return sprintf( '00000000-0000-4000-8000-%012d', $counter );
}
function sanitize_text_field( mixed $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone WordPress-function test double.
	return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function absint( mixed $value ): int {
	return abs( (int) $value ); }
function wp_hash_password( string $password ): string {
	return password_hash( $password, PASSWORD_DEFAULT ); }
function wp_check_password( string $password, string $hash ): bool {
	return password_verify( $password, $hash ); }
function get_option( string $key, mixed $fallback = false ): mixed {
	return $GLOBALS['oras_test_options'][ $key ] ?? $fallback; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	$GLOBALS['oras_test_options'][ $key ] = $value;
	return true; }
function get_transient( string $key ): mixed {
	return $GLOBALS['oras_test_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration ): bool {
	$GLOBALS['oras_test_transients'][ $key ] = $value;
	return true; }
function delete_transient( string $key ): bool {
	unset( $GLOBALS['oras_test_transients'][ $key ] );
	return true; }

function oras_access_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$plugin_dir = dirname( __DIR__ ) . '/oras-tickets/';
$php_files  = array(
	'includes/Capabilities.php',
	'includes/Registration_Desk/Config.php',
	'includes/Registration_Desk/Access.php',
	'includes/Registration_Desk/Station_Session.php',
	'includes/Registration_Desk/Event_Catalog.php',
	'includes/Registration_Desk/Manager_Access.php',
	'includes/Registration_Desk/Admin_Settings.php',
	'includes/Registration_Desk/Landing_Page.php',
);

foreach ( $php_files as $file ) {
	oras_access_assert( file_exists( $plugin_dir . $file ), "{$file} exists" );
	require_once $plugin_dir . $file;
}
foreach ( array( 'assets/registration-desk/desk.css', 'assets/registration-desk/desk.js' ) as $file ) {
	oras_access_assert( file_exists( $plugin_dir . $file ), "{$file} exists" );
}

$config_class  = '\\ORAS\\Tickets\\Registration_Desk\\Config';
$station_class = '\\ORAS\\Tickets\\Registration_Desk\\Station_Session';
$access_class  = '\\ORAS\\Tickets\\Registration_Desk\\Access';
$catalog_class = '\\ORAS\\Tickets\\Registration_Desk\\Event_Catalog';
$manager_class = '\\ORAS\\Tickets\\Registration_Desk\\Manager_Access';
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
				'option_uuid'           => '11111111-1111-4111-8111-111111111111',
				'label'                 => 'Synthetic Individual',
				'available_for_new'     => false,
				'existing_access_valid' => true,
				'classification'        => 'individual',
				'validity_type'         => 'full_event',
				'source_product_ids'    => array( 42 ),
				'source_event_ids'      => array( 123, 456 ),
				'max_attendees'         => 4,
			),
		),
	)
);
oras_access_assert( false === $configured['options'][0]['available_for_new'], 'New-registration availability is independent' );
oras_access_assert( true === $configured['options'][0]['existing_access_valid'], 'Existing access validity is independent' );
oras_access_assert( array( 123, 456 ) === $configured['options'][0]['source_event_ids'], 'Explicit source-event access mappings are normalized' );
oras_access_assert( 4 === $configured['options'][0]['max_attendees'], 'Configured family-size ceiling is normalized' );
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
$GLOBALS['oras_test_session_token'] = 'wordpress-session-a';
$expired_payload = array(
	'v'               => 1,
	'station_uuid'    => '11111111-1111-4111-8111-111111111111',
	'user_id'         => 99,
	'event_id'        => 123,
	'config_revision' => 7,
	'operator_label'  => 'Expired',
	'issued_at'       => time() - 600,
	'expires_at'      => time() - 1,
	'wp_session'      => hash_hmac( 'sha256', 'wordpress-session-a', wp_salt( 'auth' ) ),
);
$expired_body = rtrim( strtr( base64_encode( (string) wp_json_encode( $expired_payload ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Benign URL-safe encoding for a synthetic signed-token test.
$expired_token = $expired_body . '.' . hash_hmac( 'sha256', $expired_body, wp_salt( 'auth' ) );
$expired_result = $station_class::validate( $expired_token, 99, 123, 7 );
oras_access_assert( $expired_result instanceof WP_Error && 'oras_desk_station_expired' === $expired_result->get_error_code(), 'Correctly signed expired station token is rejected without sleeping' );

oras_access_assert( $catalog_class::overlaps_year( '2025-12-30', '2026-01-02', 2026 ), 'Event crossing New Year overlaps the current year' );
oras_access_assert( ! $catalog_class::overlaps_year( '2025-01-01', '2025-12-31', 2026 ), 'Prior-year event does not overlap the current year' );
$ordered = $catalog_class::sort_rows(
	array(
		array(
			'event_id'   => 1,
			'start_date' => '2026-09-01',
			'end_date'   => '2026-09-01',
			'title'      => 'Earlier',
		),
		array(
			'event_id'   => 2,
			'start_date' => '2026-10-01',
			'end_date'   => '2026-10-02',
			'title'      => 'Upcoming',
		),
		array(
			'event_id'   => 3,
			'start_date' => '2026-09-19',
			'end_date'   => '2026-09-20',
			'title'      => 'Current',
		),
	),
	'2026-09-19'
);
oras_access_assert( array( 3, 2, 1 ) === array_column( $ordered, 'event_id' ), 'Catalog sorts current, upcoming, then earlier events' );

oras_access_assert( $manager_class::set_pin( '12' ) instanceof WP_Error, 'Manager PIN must contain exactly four digits' );
oras_access_assert( true === $manager_class::set_pin( '4826' ), 'Valid manager PIN can be configured' );
$stored_pin = (string) $GLOBALS['oras_test_options'][ $manager_class::PIN_HASH_OPTION ];
oras_access_assert( '4826' !== $stored_pin && wp_check_password( '4826', $stored_pin ), 'Manager PIN is stored only as a password hash' );
$manager_token = $manager_class::unlock( '4826', $session_a, 'test-device' );
oras_access_assert( is_string( $manager_token ), 'Correct PIN issues a manager token' );
oras_access_assert( is_array( $manager_class::validate( $manager_token, $session_a ) ), 'Manager token validates for the same station scope' );
$changed_station = $session_a;
$changed_station['event_id'] = 124;
oras_access_assert( $manager_class::validate( $manager_token, $changed_station ) instanceof WP_Error, 'Changing event invalidates manager mode' );

oras_access_assert( defined( $caps_class . '::REGISTRATION_DESK_ROLE' ), 'Dedicated desk role is defined' );
oras_access_assert( in_array( 'oras_tickets_use_registration_desk', $caps_class::REGISTRATION_DESK_CAPS, true ), 'Desk role can use the desk' );
oras_access_assert( in_array( 'oras_tickets_admit_registration_desk', $caps_class::REGISTRATION_DESK_CAPS, true ), 'Desk role can admit supported attendees' );
oras_access_assert( ! in_array( 'oras_tickets_checkin', $caps_class::REGISTRATION_DESK_CAPS, true ), 'Desk role does not receive legacy check-in' );
foreach ( array( 'oras_tickets_view_reports', 'oras_tickets_export_reports', 'oras_tickets_manage_memberships', 'manage_options' ) as $forbidden ) {
	oras_access_assert( ! in_array( $forbidden, $caps_class::REGISTRATION_DESK_CAPS, true ), "Desk role lacks {$forbidden}" );
}
oras_access_assert( method_exists( $access_class, 'register' ), 'Restricted-account guard can be registered' );
oras_access_assert( method_exists( $access_class, 'rest_pre_dispatch' ), 'Restricted-account guard covers REST bypasses' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$landing_code = (string) file_get_contents( $plugin_dir . 'includes/Registration_Desk/Landing_Page.php' );
oras_access_assert( false !== strpos( $landing_code, 'oras-registration-desk-root' ), 'Landing page renders the real application root' );
oras_access_assert( false !== strpos( $landing_code, 'wp_create_nonce' ), 'Landing page supplies WordPress REST authentication' );
oras_access_assert( false === strpos( $landing_code, 'Backend foundation placeholder' ), 'Foundation placeholder is removed' );
oras_access_assert( false !== strpos( $landing_code, 'flush_rewrite_rules' ), 'Existing plugin installs receive the desk rewrite migration' );
oras_access_assert( false !== strpos( $landing_code, 'permalink_structure' ), 'Desk URL supports test and production permalink modes' );
oras_access_assert( false !== strpos( $landing_code, 'filemtime' ), 'Desk assets are cache-busted when their files change' );
oras_access_assert( false !== strpos( $landing_code, 'show_admin_bar( false )' ), 'Desk stays distraction-free for administrator stations' );
oras_access_assert( false !== strpos( $landing_code, 'wp_timezone_string()' ), 'Desk browser formatting is bound to the WordPress timezone' );
oras_access_assert( false !== strpos( $landing_code, "'settingsUrl'" ), 'Manager kiosk can link to desk configuration without exposing it to volunteers' );
oras_access_assert( false !== strpos( $landing_code, 'apple-mobile-web-app-capable' ), 'Kiosk page alone opts into iOS standalone mode' );
oras_access_assert( false !== strpos( $landing_code, 'assets/registration-desk/oras-mark.png' ), 'Kiosk metadata uses the local official ORAS mark' );
oras_access_assert( file_exists( $plugin_dir . 'assets/registration-desk/oras-mark.png' ), 'Official ORAS mark is packaged locally' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$rest_code = (string) file_get_contents( $plugin_dir . 'includes/Registration_Desk/Rest_Controller.php' );
oras_access_assert( false !== strpos( $rest_code, '/registration-desk/events' ), 'Volunteer startup exposes the eligible event catalog' );
oras_access_assert( false !== strpos( $rest_code, "get_param( 'event_id' )" ), 'Station creation binds the explicitly selected event' );
oras_access_assert( false !== strpos( $rest_code, "'friendly_date'" ), 'Station bootstrap supplies a friendly site-local date' );
oras_access_assert( false !== strpos( $rest_code, 'html_entity_decode( wp_logout_url' ), 'Station bootstrap supplies a usable single-escaped logout URL' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$settings_code = (string) file_get_contents( $plugin_dir . 'includes/Registration_Desk/Admin_Settings.php' );
oras_access_assert( false !== strpos( $settings_code, 'options[' ), 'Administrator settings expose structured option fields' );
oras_access_assert( false === strpos( $settings_code, 'config_json' ), 'Administrator setup does not require raw JSON editing' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$desk_css = (string) file_get_contents( $plugin_dir . 'assets/registration-desk/desk.css' );
oras_access_assert( false !== strpos( $desk_css, '[hidden]' ), 'Conditional desk fields honor the HTML hidden state' );

echo "Registration Desk access checks passed.\n";
