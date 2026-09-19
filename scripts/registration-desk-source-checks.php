<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

$GLOBALS['oras_source_meta'] = array();

function sanitize_email( mixed $value ): string {
	return strtolower( trim( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone WordPress-function test double.
	return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function absint( mixed $value ): int {
	return abs( (int) $value ); }
function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone WordPress-function test double.
	return json_encode( $value ); }
function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
	return $GLOBALS['oras_source_meta'][ $post_id ][ $key ] ?? ''; }
function wc_get_product( int $product_id ): object {
	return new class($product_id) {
		public function __construct( private int $id ) {}
		public function get_name(): string {
			return 'Canonical product ' . $this->id; }
		public function managing_stock(): bool {
			return false; }
		public function is_purchasable(): bool {
			return true; }
		public function is_in_stock(): bool {
			return true; }
	};
}
function oras_source_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$includes = dirname( __DIR__ ) . '/oras-tickets/includes/';
foreach ( array( 'Domain/Meta.php', 'Domain/Ticket.php', 'Domain/Ticket_Collection.php', 'Domain/Pricing/Price_Resolver.php', 'Domain/Event_Offering_Resolver.php' ) as $file ) {
	require_once $includes . $file;
}
$base = $includes . 'Registration_Desk/';
foreach ( array( 'Coverage_Store.php', 'Recovery_Cursor.php', 'Source_Change_Listener.php', 'Source_Adapter.php', 'Source_Resolver.php', 'Eligibility.php', 'Projection_Service.php' ) as $file ) {
	oras_source_assert( file_exists( $base . $file ), "{$file} exists" );
	require_once $base . $file;
}

$resolver = '\\ORAS\\Tickets\\Registration_Desk\\Source_Resolver';
$policy   = '\\ORAS\\Tickets\\Registration_Desk\\Eligibility';

$ticket = static fn( string $key, string $name ): array => array(
	'ticket_key'      => $key,
	'name'            => $name,
	'price'           => '25.00',
	'price_phases'    => array(),
	'capacity'        => 0,
	'sale_start'      => '',
	'sale_end'        => '',
	'description'     => '',
	'attendance_mode' => 'onsite',
	'hide_sold_out'   => false,
);
$GLOBALS['oras_source_meta'][123]['_oras_tickets_v1'] = array(
	'schema'  => 1,
	'tickets' => array(
		'individual' => $ticket( 'individual', 'Canonical Individual' ),
		'family'     => $ticket( 'family', 'Canonical Family' ),
		'day'        => $ticket( 'day', 'Canonical Day' ),
	),
);
$GLOBALS['oras_source_meta'][123]['_oras_tickets_woo_map_v1'] = array( 42, 43, 44 );

$config = array(
	'enabled'      => true,
	'revision'     => 4,
	'ticket_rules' => array(
		array(
			'ticket_key'     => 'individual',
			'classification' => 'individual',
			'validity_type'  => 'full_event',
			'max_attendees'  => 1,
		),
		array(
			'ticket_key'     => 'family',
			'classification' => 'family',
			'validity_type'  => 'full_event',
			'max_attendees'  => 5,
		),
		array(
			'ticket_key'       => 'day',
			'classification'   => 'individual',
			'validity_type'    => 'one_day',
			'valid_local_date' => '2026-10-08',
			'max_attendees'    => 1,
		),
	),
	'entitlements' => array(
		array(
			'entitlement_uuid'  => '11111111-1111-4111-8111-111111111111',
			'source_event_id'   => 456,
			'source_product_id' => 42,
			'classification'    => 'individual',
			'validity_type'     => 'full_event',
			'max_attendees'     => 1,
		),
	),
);

$base_evidence = array(
	'order_id'          => 9001,
	'order_item_id'     => 7001,
	'product_id'        => 42,
	'source_event_id'   => 123,
	'quantity'          => 1,
	'refunded_quantity' => 0,
	'order_status'      => 'processing',
	'contact_name'      => 'Purchaser Name',
	'email'             => 'person@example.org',
	'phone'             => '555-0100',
	'ticket_index'      => 99,
	'item_label'        => 'A misleading label',
);

$resolved = $resolver::resolve( $base_evidence, 123, $config );
oras_source_assert( 'supported' === $resolved['resolution'], 'Exact event and product evidence resolves a configured option' );
oras_source_assert( 'individual' === $resolved['classification'], 'Configured individual classification is retained' );
oras_source_assert( 'eligible' === $resolved['eligibility'], 'Processing direct individual is eligible' );
oras_source_assert( true === $resolved['available_for_new'], 'Current canonical availability is retained without a desk override' );

$completed = $policy::evaluate( array_merge( $base_evidence, array( 'order_status' => 'completed' ) ), $resolved['option'] );
oras_source_assert( 'eligible' === $completed['state'], 'Completed source is eligible' );
$on_hold = $policy::evaluate( array_merge( $base_evidence, array( 'order_status' => 'on-hold' ) ), $resolved['option'] );
oras_source_assert( 'explicit_unpaid_required' === $on_hold['state'], 'On-hold source requires explicit unpaid admission' );
foreach ( array( 'pending', 'failed' ) as $order_status ) {
	$result = $policy::evaluate( array_merge( $base_evidence, array( 'order_status' => $order_status ) ), $resolved['option'] );
	oras_source_assert( 'not_active' === $result['state'], "{$order_status} is not normal active admission" );
}
foreach ( array( 'cancelled', 'refunded' ) as $order_status ) {
	$result = $policy::evaluate( array_merge( $base_evidence, array( 'order_status' => $order_status ) ), $resolved['option'] );
	oras_source_assert( 'revoked' === $result['state'], "{$order_status} is revoked" );
}
$partial = $policy::evaluate(
	array_merge(
		$base_evidence,
		array(
			'quantity'          => 2,
			'refunded_quantity' => 1,
		)
	),
	$resolved['option']
);
oras_source_assert( 'review_required' === $partial['state'], 'Ambiguous partial refund requires review' );
$unknown = $policy::evaluate( array_merge( $base_evidence, array( 'order_status' => 'custom-status' ) ), $resolved['option'] );
oras_source_assert( 'review_required' === $unknown['state'], 'Unknown status requires review' );

$family = $resolver::resolve( array_merge( $base_evidence, array( 'product_id' => 43 ) ), 123, $config );
oras_source_assert( 'supported' === $family['resolution'], 'Explicitly configured family source is supported in V1' );
oras_source_assert( 5 === $family['max_attendees'], 'Family source preserves its configured attendee limit' );
$one_day = $resolver::resolve( array_merge( $base_evidence, array( 'product_id' => 44 ) ), 123, $config );
oras_source_assert( 'supported' === $one_day['resolution'], 'Explicitly configured one-day source is supported in V1' );
oras_source_assert( '2026-10-08' === $one_day['valid_local_date'], 'One-day source retains its configured date' );
$cross_event = $resolver::resolve( array_merge( $base_evidence, array( 'source_event_id' => 456 ) ), 123, $config );
oras_source_assert( 'supported' === $cross_event['resolution'], 'Explicit cross-event mapping grants target-event access' );
$unmapped_cross_event = $resolver::resolve( array_merge( $base_evidence, array( 'source_event_id' => 789 ) ), 123, $config );
oras_source_assert( 'review_required' === $unmapped_cross_event['resolution'], 'Unconfigured cross-event access is never inferred' );
$ambiguous = $resolver::resolve(
	array_merge(
		$base_evidence,
		array(
			'product_id'   => 999,
			'ticket_index' => 0,
			'item_label'   => 'Synthetic Individual',
		)
	),
	123,
	$config
);
oras_source_assert( 'review_required' === $ambiguous['resolution'], 'Numeric index and label do not classify an unmapped source' );

$new_code = '';
foreach ( glob( $base . '*.php' ) as $file ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local source fixtures.
	$new_code .= (string) file_get_contents( $file );
}
foreach ( array( 'payment_complete(', 'wc_create_order(', 'wp_insert_user(', 'update_status(', 'update_meta_data(', 'saveOrder(' ) as $forbidden_call ) {
	oras_source_assert( false === strpos( $new_code, $forbidden_call ), "Desk backend does not call {$forbidden_call}" );
}
oras_source_assert( false !== strpos( $new_code, 'PMPro_Discount_Code' ), 'The explicit offline-membership exception reuses PMPro credits' );

echo "Registration Desk source checks passed.\n";
