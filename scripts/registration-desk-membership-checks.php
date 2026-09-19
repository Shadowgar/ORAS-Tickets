<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function sanitize_email( mixed $value ): string { return strtolower( trim( (string) $value ) ); }
function esc_url_raw( mixed $value ): string { return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : ''; }
function absint( mixed $value ): int { return abs( (int) $value ); }

function oras_membership_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$base = dirname( __DIR__ ) . '/oras-tickets/includes/Registration_Desk/';
foreach ( array( 'Config.php', 'Schema.php', 'Store.php', 'Offline_Membership_Store.php', 'Membership_Credit_Service.php', 'Member_Lookup_Service.php' ) as $file ) {
	oras_membership_assert( file_exists( $base . $file ), "{$file} exists" );
	require_once $base . $file;
}

$schema = '\\ORAS\\Tickets\\Registration_Desk\\Schema';
$config = '\\ORAS\\Tickets\\Registration_Desk\\Config';
$credit = '\\ORAS\\Tickets\\Registration_Desk\\Membership_Credit_Service';

$sql = $schema::build_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
oras_membership_assert( 5 === count( $sql ), 'Schema adds exactly one pending offline-membership table' );
$joined = implode( "\n", $sql );
oras_membership_assert( false !== strpos( $joined, 'wp_oras_offline_memberships' ), 'Pending activation table is desk-owned' );
oras_membership_assert( false !== strpos( $joined, 'UNIQUE KEY request_uuid (request_uuid)' ), 'Membership recording is idempotent by request identity' );
oras_membership_assert( false !== strpos( $joined, 'UNIQUE KEY credit_code (credit_code)' ), 'Membership credits are unique' );
oras_membership_assert( false !== strpos( $joined, 'linked_user_id bigint(20) unsigned NULL' ), 'Redemption can link to the website user' );

$mappings = $config::normalize_membership_mappings(
	array(
		array(
			'level_id'     => 7,
			'display_name' => ' Individual ',
			'price'        => '35.00',
			'checkout_url' => 'https://oras.org/membership-account/membership-checkout/?level=7',
		),
		array( 'level_id' => 0, 'display_name' => 'Invalid' ),
	)
);
oras_membership_assert( 1 === count( $mappings ), 'Only complete membership mappings are retained' );
oras_membership_assert( 7 === $mappings[0]['level_id'], 'Membership level remains explicitly mapped' );
oras_membership_assert( 'zero_initial_preserve_recurring' === $mappings[0]['behavior'], 'Credit behavior is fixed to paid-period-only semantics' );

$level = (object) array(
	'id'                => 7,
	'initial_payment'   => '35.00',
	'billing_amount'    => '35.00',
	'cycle_number'      => 1,
	'cycle_period'      => 'Year',
	'billing_limit'     => 0,
	'trial_amount'      => '0.00',
	'trial_limit'       => 0,
	'expiration_number' => 0,
	'expiration_period' => '',
);
$discounted = $credit::discount_level_data( $level );
oras_membership_assert( 0.0 === $discounted['initial_payment'], 'Credit makes the already-paid checkout amount zero' );
oras_membership_assert( '35.00' === $discounted['billing_amount'], 'Credit preserves the normal future renewal amount' );
oras_membership_assert( 1 === $discounted['cycle_number'] && 'Year' === $discounted['cycle_period'], 'Credit preserves renewal cadence' );

$message = $credit::email_message(
	array(
		'first_name' => 'John', 'level_name' => 'Individual Membership', 'reference_price' => '35.00',
		'payment_method' => 'cash', 'event_title' => 'Test Event', 'checkout_url' => 'https://example.org/level-7',
		'credit_code' => 'ORAS-TEST-CODE',
	)
);
foreach ( array( 'John', 'Oil Region Astronomical Society', 'Individual Membership', 'Cash', 'Test Event', 'https://example.org/level-7', 'ORAS-TEST-CODE', 'SHOULD NOT BE CHARGED AGAIN', 'same email address' ) as $required ) {
	oras_membership_assert( false !== stripos( $message, $required ), "Activation email contains {$required}" );
}

$source = (string) file_get_contents( $base . 'Membership_Credit_Service.php' );
foreach ( array( 'wp_insert_user(', 'pmpro_changeMembershipLevel(', 'wc_create_order(', 'payment_complete(' ) as $forbidden ) {
	oras_membership_assert( false === strpos( $source, $forbidden ), "Kiosk credit service never calls {$forbidden}" );
}
oras_membership_assert( false !== strpos( $source, "'pmpro_check_discount_code'" ), 'Credit is bound to the purchaser email during checkout validation' );
oras_membership_assert( false !== strpos( $source, "'pmpro_after_checkout'" ), 'Successful PMPro checkout marks the activation redeemed' );

echo "Registration Desk membership checks passed.\n";
