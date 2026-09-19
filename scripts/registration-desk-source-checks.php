<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

function sanitize_email( mixed $value ): string {
	return strtolower( trim( (string) $value ) ); }
function sanitize_text_field( mixed $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone WordPress-function test double.
	return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function oras_source_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$base = dirname( __DIR__ ) . '/oras-tickets/includes/Registration_Desk/';
foreach ( array( 'Coverage_Store.php', 'Recovery_Cursor.php', 'Source_Change_Listener.php', 'Source_Adapter.php', 'Source_Resolver.php', 'Eligibility.php', 'Projection_Service.php' ) as $file ) {
	oras_source_assert( file_exists( $base . $file ), "{$file} exists" );
	require_once $base . $file;
}

$resolver = '\\ORAS\\Tickets\\Registration_Desk\\Source_Resolver';
$policy   = '\\ORAS\\Tickets\\Registration_Desk\\Eligibility';

$config = array(
	'enabled'  => true,
	'revision' => 4,
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
			'max_attendees'         => 1,
		),
		array(
			'option_uuid'           => '22222222-2222-4222-8222-222222222222',
			'label'                 => 'Synthetic Family',
			'available_for_new'     => true,
			'existing_access_valid' => true,
			'classification'        => 'family',
			'validity_type'         => 'full_event',
			'source_product_ids'    => array( 43 ),
			'source_event_ids'      => array( 123 ),
			'max_attendees'         => 5,
		),
		array(
			'option_uuid'           => '33333333-3333-4333-8333-333333333333',
			'label'                 => 'Synthetic Day',
			'available_for_new'     => true,
			'existing_access_valid' => true,
			'classification'        => 'individual',
			'validity_type'         => 'one_day',
			'source_product_ids'    => array( 44 ),
			'source_event_ids'      => array( 123 ),
			'valid_local_date'      => '2026-10-08',
			'max_attendees'         => 1,
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
oras_source_assert( false === $resolved['available_for_new'], 'Disabled-for-new does not invalidate existing access' );

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
