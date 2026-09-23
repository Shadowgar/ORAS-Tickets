<?php // phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Standalone PMPro and WordPress test doubles retain upstream signatures.

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

function sanitize_text_field( mixed $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone WordPress-function test double.
	return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function sanitize_email( mixed $value ): string {
	return strtolower( trim( (string) $value ) ); }
function esc_url_raw( mixed $value ): string {
	return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : ''; }
function absint( mixed $value ): int {
	return abs( (int) $value ); }
function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['oras_membership_options'][ $key ] ?? $default; }
function pmpro_getLevel( int $level_id ): object|false {
	return $GLOBALS['oras_membership_levels'][ $level_id ] ?? false; }
function pmpro_getAllLevels( bool $include_hidden = false, bool $use_cache = true ): array {
	return array_values( $GLOBALS['oras_membership_levels'] ?? array() ); }
function pmpro_url( string $page, string $query = '' ): string {
	return 'https://oras.example/membership-account/membership-' . $page . '/' . $query; }

function oras_membership_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$base = dirname( __DIR__ ) . '/oras-tickets/includes/Registration_Desk/';
foreach ( array( 'Membership_Offering_Resolver.php', 'Config.php', 'Schema.php', 'Store.php', 'Offline_Membership_Store.php', 'Membership_Credit_Service.php', 'Member_Lookup_Service.php', 'Training_Service.php' ) as $file ) {
	oras_membership_assert( file_exists( $base . $file ), "{$file} exists" );
	require_once $base . $file;
}

$schema = '\\ORAS\\Tickets\\Registration_Desk\\Schema';
$config = '\\ORAS\\Tickets\\Registration_Desk\\Config';
$credit = '\\ORAS\\Tickets\\Registration_Desk\\Membership_Credit_Service';
$resolver = '\\ORAS\\Tickets\\Registration_Desk\\Membership_Offering_Resolver';

$sql = $schema::build_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
oras_membership_assert( 6 === count( $sql ), 'Schema retains live stores and adds one isolated training table' );
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
		array(
			'level_id'     => 0,
			'display_name' => 'Invalid',
		),
	)
);
oras_membership_assert( 1 === count( $mappings ), 'Only complete membership mappings are retained' );
oras_membership_assert( 7 === $mappings[0]['level_id'], 'Membership level remains explicitly mapped' );
oras_membership_assert( array( 'level_id', 'event_sale_enabled' ) === array_keys( $mappings[0] ), 'Desk configuration stores only canonical level identity and event-sale eligibility' );

$level = (object) array(
	'id'                => 7,
	'name'              => 'Annual Individual Membership',
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
$GLOBALS['oras_membership_levels'] = array( 7 => $level );
$offering = $resolver::resolve( 7 );
oras_membership_assert( is_array( $offering ) && 'Annual Individual Membership' === $offering['display_name'], 'Membership display name comes from the canonical PMPro level' );
oras_membership_assert( '35.00' === $offering['price'], 'Membership reference price comes from the canonical PMPro level' );
oras_membership_assert( false !== strpos( $offering['checkout_url'], 'level=7' ), 'Membership checkout URL is generated by the canonical membership system' );
oras_membership_assert( 'Every 1 Year' === $offering['period_label'], 'Membership offering exposes canonical renewal semantics' );
$level->name = 'Renamed Annual Membership';
$level->initial_payment = '42.00';
$renamed = $resolver::resolve( 7 );
oras_membership_assert( 'Renamed Annual Membership' === $renamed['display_name'] && '42.00' === $renamed['price'], 'Canonical membership rename and price changes flow through without desk reconfiguration' );
oras_membership_assert( null === $resolver::resolve( 999 ), 'Unavailable canonical membership levels are not offered' );

$GLOBALS['oras_membership_options'][ $config::MEMBERSHIP_MAPPINGS_OPTION ] = $mappings;
$eligible = $config::membership_mapping( 7 );
oras_membership_assert( is_array( $eligible ) && 'Renamed Annual Membership' === $eligible['display_name'], 'Eligible desk membership resolves current canonical facts at use time' );
$discounted = $credit::discount_level_data( $level );
oras_membership_assert( 0.0 === $discounted['initial_payment'], 'Credit makes the already-paid checkout amount zero' );
oras_membership_assert( '35.00' === $discounted['billing_amount'], 'Credit preserves the normal future renewal amount' );
oras_membership_assert( 1 === $discounted['cycle_number'] && 'Year' === $discounted['cycle_period'], 'Credit preserves renewal cadence' );

$message = $credit::email_message(
	array(
		'first_name'      => 'John',
		'level_name'      => 'Individual Membership',
		'reference_price' => '35.00',
		'payment_method'  => 'cash',
		'event_title'     => 'Test Event',
		'checkout_url'    => 'https://example.org/level-7',
		'credit_code'     => 'ORAS-TEST-CODE',
	)
);
foreach ( array( 'John', 'Oil Region Astronomical Society', 'Individual Membership', 'Cash', 'Test Event', 'https://example.org/level-7', 'ORAS-TEST-CODE', 'SHOULD NOT BE CHARGED AGAIN', 'same email address' ) as $required ) {
	oras_membership_assert( false !== stripos( $message, $required ), "Activation email contains {$required}" );
}
$card_message = $credit::email_message( array_merge( array( 'payment_method' => 'card' ), array(
	'first_name' => 'Card', 'level_name' => 'Individual Membership', 'reference_price' => '35.00',
	'event_title' => 'Test Event', 'checkout_url' => 'https://example.org/level-7', 'credit_code' => 'ORAS-TEST-CARD',
) ) );
oras_membership_assert( false !== strpos( $card_message, 'Card payment' ) && false !== strpos( $card_message, 'SHOULD NOT BE CHARGED AGAIN' ), 'Card activation email identifies the recorded method and prevents another charge' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
$source = (string) file_get_contents( $base . 'Membership_Credit_Service.php' );
foreach ( array( 'wp_insert_user(', 'pmpro_changeMembershipLevel(', 'wc_create_order(', 'payment_complete(' ) as $forbidden ) {
	oras_membership_assert( false === strpos( $source, $forbidden ), "Kiosk credit service never calls {$forbidden}" );
}
oras_membership_assert( false !== strpos( $source, "'pmpro_check_discount_code'" ), 'Credit is bound to the purchaser email during checkout validation' );
oras_membership_assert( false !== strpos( $source, "'pmpro_after_checkout'" ), 'Successful PMPro checkout marks the activation redeemed' );
oras_membership_assert( false !== strpos( $source, 'function correct_contact' ), 'Manager service supports pending contact correction without replacing the credit' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
$lookup_source = (string) file_get_contents( $base . 'Member_Lookup_Service.php' );
oras_membership_assert(
	false !== strpos( $lookup_source, 'if ( isset( $seen_emails[ $email ] ) )' ),
	'Member Lookup does not append an offline activation already represented by the shared membership report'
);

$training_source = (string) file_get_contents( $base . 'Training_Service.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
oras_membership_assert( false !== strpos( $training_source, 'record_membership_state' ) && false !== strpos( $training_source, 'canonical_membership_offerings' ), 'Training membership uses explicit synthetic state and canonical display offerings' );
foreach ( array( 'wp_mail(', 'pmpro_changeMembershipLevel(', 'Membership_Credit_Service', 'Offline_Membership_Store' ) as $forbidden ) {
	oras_membership_assert( false === strpos( $training_source, $forbidden ), "Training membership never calls {$forbidden}" );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
$admin_source = (string) file_get_contents( $base . 'Admin_Settings.php' );
oras_membership_assert( false === strpos( $admin_source, "'[display_name]'" ) && false === strpos( $admin_source, "'[price]'" ) && false === strpos( $admin_source, "'[checkout_url]'" ), 'Administrator membership settings do not duplicate canonical names, prices, or checkout URLs' );

echo "Registration Desk membership checks passed.\n";
