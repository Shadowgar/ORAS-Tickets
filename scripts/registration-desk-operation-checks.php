<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
	}
}

function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

function oras_operation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

$base = dirname( __DIR__ ) . '/oras-tickets/includes/Registration_Desk/';
foreach ( array( 'Service.php', 'Rest_Controller.php' ) as $file ) {
	oras_operation_assert( file_exists( $base . $file ), "{$file} exists" );
	require_once $base . $file;
}

$service = '\\ORAS\\Tickets\\Registration_Desk\\Service';
$rest    = '\\ORAS\\Tickets\\Registration_Desk\\Rest_Controller';
oras_operation_assert( class_exists( $service ), 'Attendance service loads' );
oras_operation_assert( class_exists( $rest ), 'REST controller loads' );

$hash_a = $service::payload_hash( array( 'last_name' => 'Person', 'first_name' => 'Actual', 'unpaid' => false ) );
$hash_b = $service::payload_hash( array( 'unpaid' => false, 'first_name' => 'Actual', 'last_name' => 'Person' ) );
$hash_c = $service::payload_hash( array( 'unpaid' => true, 'first_name' => 'Actual', 'last_name' => 'Person' ) );
oras_operation_assert( $hash_a === $hash_b, 'Payload hashing is stable across associative-key order' );
oras_operation_assert( $hash_a !== $hash_c, 'Payload hashing binds meaningful request changes' );
oras_operation_assert( 64 === strlen( $hash_a ), 'Payload hash is SHA-256' );

oras_operation_assert( true === $service::date_is_within_event( '2026-10-06', '2026-10-06', '2026-10-11' ), 'Event start date is admissible' );
oras_operation_assert( true === $service::date_is_within_event( '2026-10-11', '2026-10-06', '2026-10-11' ), 'Event end date is admissible' );
oras_operation_assert( false === $service::date_is_within_event( '2026-10-12', '2026-10-06', '2026-10-11' ), 'Date after event is rejected without a grace period' );

foreach ( array( 'station', 'search', 'detail', 'confirm_and_check_in', 'recent', 'reverse' ) as $method ) {
	oras_operation_assert( method_exists( $rest, $method ), "REST controller exposes {$method} contract" );
}

$service_code = (string) file_get_contents( $base . 'Service.php' );
oras_operation_assert( false !== strpos( $service_code, 'Store::transaction' ), 'Business mutation and audit use a database transaction' );
oras_operation_assert( false !== strpos( $service_code, 'source_adapter->load' ), 'Check-in revalidates the Woo source immediately' );
oras_operation_assert( false !== strpos( $service_code, 'explicit_unpaid_required' ), 'On-hold admission requires explicit unpaid intent' );
oras_operation_assert( false !== strpos( $service_code, 'expected_record_version' ), 'Reversal binds the expected attendance version' );
oras_operation_assert( false !== strpos( $service_code, 'current_attendance' ), 'Replay response includes current attendance state' );

$rest_code = (string) file_get_contents( $base . 'Rest_Controller.php' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/station' ), 'Station bootstrap has a dedicated route' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/registrations' ), 'Search uses operational registrations route' );
oras_operation_assert( false === strpos( $rest_code, '/orders/(?P<' ), 'No desk route uses an order ID as registration identity' );

echo "Registration Desk operation checks passed.\n";
