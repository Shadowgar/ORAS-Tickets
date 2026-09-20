<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed -- Guarded disposable integration executable.

use ORAS\Tickets\Commerce\Woo\Product_Sync;
use ORAS\Tickets\Domain\Event_Offering_Resolver;
use ORAS\Tickets\Domain\Included_Event_Access;
use ORAS\Tickets\Domain\Ticket_Collection;
use ORAS\Tickets\Registration_Desk\Attendee_Store;
use ORAS\Tickets\Registration_Desk\Attendance_Store;
use ORAS\Tickets\Registration_Desk\Event_Stats_Service;
use ORAS\Tickets\Registration_Desk\Projection_Service;
use ORAS\Tickets\Registration_Desk\Registration_Store;
use ORAS\Tickets\Registration_Desk\Schema;
use ORAS\Tickets\Registration_Desk\Source_Adapter;
use ORAS\Tickets\Registration_Desk\Source_Resolver;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

function oras_included_fail( string $message ): void {
	WP_CLI::error( 'Included-event integration failure: ' . $message );
}

function oras_included_same( mixed $actual, mixed $expected, string $message ): void {
	if ( $actual !== $expected ) {
		oras_included_fail( $message . ' (expected ' . wp_json_encode( $expected ) . ', received ' . wp_json_encode( $actual ) . ')' );
	}
	WP_CLI::log( 'PASS: ' . $message );
}

function oras_included_true( bool $value, string $message ): void {
	if ( ! $value ) {
		oras_included_fail( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
}

function oras_included_guard(): void {
	$expected     = defined( 'ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED' ) ? ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED : '';
	$expected_url = defined( 'ORAS_REGISTRATION_DESK_TEST_URL_EXPECTED' ) ? ORAS_REGISTRATION_DESK_TEST_URL_EXPECTED : '';
	$actual       = get_option( 'oras_registration_desk_disposable_fixture_id', '' );
	if (
		'' === $expected
		|| '' === $expected_url
		|| ! hash_equals( (string) $expected, (string) $actual )
		|| 'tests-wordpress' !== DB_NAME
		|| 'tests-mysql' !== DB_HOST
		|| $expected_url !== get_option( 'home' )
		|| ! defined( 'ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE' )
		|| ! ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE
	) {
		oras_included_fail( 'disposable database or transport guard identity is not established.' );
	}
}

function oras_included_event( string $run, string $suffix, string $date ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => 'tribe_events',
			'post_status' => 'publish',
			'post_title'  => 'Bundled access fixture ' . $run . ' Event ' . $suffix,
			'meta_input'  => array(
				'_EventStartDate'    => $date . ' 09:00:00',
				'_EventEndDate'      => $date . ' 17:00:00',
				'_EventStartDateUTC' => $date . ' 13:00:00',
				'_EventEndDateUTC'   => $date . ' 21:00:00',
				'_EventTimezone'     => 'America/New_York',
			),
		)
	);
	if ( is_wp_error( $id ) || (int) $id <= 0 ) {
		oras_included_fail( 'synthetic Event ' . $suffix . ' could not be created.' );
	}

	return (int) $id;
}

/** @param array<int,int> $included_event_ids @return array<string,mixed> */
function oras_included_ticket( string $key, string $name, array $included_event_ids = array() ): array {
	return array(
		'ticket_key'         => $key,
		'name'               => $name,
		'price'              => '20.00',
		'price_phases'       => array(),
		'capacity'           => 50,
		'sale_start'         => '',
		'sale_end'           => '',
		'description'        => 'Synthetic bundled access regression fixture.',
		'attendance_mode'    => 'onsite',
		'hide_sold_out'      => false,
		'included_event_ids' => $included_event_ids,
	);
}

/** @return array{order_id:int,item_id:int} */
function oras_included_order( int $product_id, int $quantity, string $run, string $suffix, bool $snapshot = true ): array {
	$order = wc_create_order();
	if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
		oras_included_fail( 'synthetic order could not be created.' );
	}
	$order->set_billing_first_name( 'Bundle' );
	$order->set_billing_last_name( $suffix );
	$order->set_billing_email( strtolower( $suffix ) . '-' . $run . '@example.test' );
	$item = new WC_Order_Item_Product();
	$item->set_product( wc_get_product( $product_id ) );
	$item->set_quantity( $quantity );
	$item->set_subtotal( 20 * $quantity );
	$item->set_total( 20 * $quantity );
	if ( $snapshot ) {
		do_action( 'woocommerce_checkout_create_order_line_item', $item, 'included-' . $suffix, array(), $order );
	} else {
		$item->add_meta_data( '_oras_ticket_event_id', (string) get_post_meta( $product_id, '_oras_ticket_event_id', true ), true );
		$item->add_meta_data( '_oras_ticket_index', (string) get_post_meta( $product_id, '_oras_ticket_index', true ), true );
		$item->add_meta_data( '_oras_ticket_name', 'Legacy synthetic ticket', true );
	}
	$order->add_item( $item );
	$order->calculate_totals( false );
	$order->set_status( 'completed' );
	$order->save();
	$item_ids = array_keys( $order->get_items( 'line_item' ) );

	return array(
		'order_id' => (int) $order->get_id(),
		'item_id'  => (int) reset( $item_ids ),
	);
}

oras_included_guard();
Schema::install();
$context = get_option( 'oras_registration_desk_integration_context', array() );
if ( ! is_array( $context ) || empty( $context['admin_id'] ) ) {
	oras_included_fail( 'guarded Registration Desk context is unavailable.' );
}
wp_set_current_user( (int) $context['admin_id'] );
$users_before = count_users()['total_users'];
$run          = strtolower( wp_generate_password( 8, false, false ) );
$today        = wp_date( 'Y-m-d', null, wp_timezone() );
$events       = array(
	'a' => oras_included_event( $run, 'A', $today ),
	'b' => oras_included_event( $run, 'B', $today ),
	'c' => oras_included_event( $run, 'C', $today ),
);

$tickets_a = array(
	'a1' => oras_included_ticket( 'a1-' . $run, 'Ticket A1' ),
	'a2' => oras_included_ticket( 'a2-' . $run, 'Ticket A2', array( $events['b'] ) ),
	'a3' => oras_included_ticket( 'a3-' . $run, 'Ticket A3', array( $events['b'], $events['c'] ) ),
);
Ticket_Collection::save_for_event(
	$events['a'],
	array(
		'schema'  => 1,
		'tickets' => array_values( $tickets_a ),
	)
);
Ticket_Collection::save_for_event(
	$events['b'],
	array(
		'schema'  => 1,
		'tickets' => array( oras_included_ticket( 'b1-' . $run, 'Ticket B1' ) ),
	)
);
$sync = new Product_Sync();
$sync->on_save_event( $events['a'], get_post( $events['a'] ), true );
$sync->on_save_event( $events['b'], get_post( $events['b'] ), true );
$map_a = get_post_meta( $events['a'], '_oras_tickets_woo_map_v1', true );
$map_b = get_post_meta( $events['b'], '_oras_tickets_woo_map_v1', true );
oras_included_same( count( $map_a ), 3, 'Event A creates three canonical Woo products' );

$orders = array(
	'a1' => oras_included_order( (int) $map_a[0], 1, $run, 'A1' ),
	'a2' => oras_included_order( (int) $map_a[1], 1, $run, 'A2' ),
	'a3' => oras_included_order( (int) $map_a[2], 1, $run, 'A3' ),
	'b1' => oras_included_order( (int) $map_b[0], 1, $run, 'B1' ),
);
$adapter = new Source_Adapter();
$evidence = array();
foreach ( $orders as $key => $identity ) {
	$evidence[ $key ] = $adapter->load( $identity['order_id'], $identity['item_id'] );
	if ( is_wp_error( $evidence[ $key ] ) ) {
		oras_included_fail( 'source evidence could not be loaded for ' . $key . '.' );
	}
}
oras_included_same( $evidence['a1']['event_access']['included_events'], array(), 'Ticket A1 snapshots no included events' );
oras_included_same( array_column( $evidence['a2']['event_access']['included_events'], 'event_id' ), array( $events['b'] ), 'Ticket A2 snapshots Event B' );
oras_included_same( array_column( $evidence['a3']['event_access']['included_events'], 'event_id' ), array( $events['b'], $events['c'] ), 'Ticket A3 snapshots Events B and C' );

$a2_snapshot = $evidence['a2']['event_access'];
$a1_snapshot = $evidence['a1']['event_access'];
$tickets_a['a1']['included_event_ids'] = array( $events['b'] );
$tickets_a['a2']['included_event_ids'] = array();
$tickets_a['a2']['name'] = 'Ticket A2 renamed after purchase';
Ticket_Collection::save_for_event(
	$events['a'],
	array(
		'schema'  => 1,
		'tickets' => array( $tickets_a['a3'], $tickets_a['a1'], $tickets_a['a2'] ),
	)
);
oras_included_same( $adapter->load( $orders['a2']['order_id'], $orders['a2']['item_id'] )['event_access'], $a2_snapshot, 'Removing included access and renaming/reordering current tickets does not rewrite the old order' );
oras_included_same( $adapter->load( $orders['a1']['order_id'], $orders['a1']['item_id'] )['event_access'], $a1_snapshot, 'Adding included access later does not retroactively change an old order' );

$config_b = array(
	'enabled'      => true,
	'revision'     => 1,
	'ticket_rules' => array(),
	'entitlements' => array(),
	'options'      => array(),
);
$config_c = $config_b;
oras_included_same( Source_Resolver::resolve( $evidence['a2'], $events['a'], $config_b )['source_kind'], 'direct', 'Ticket A2 is direct evidence for primary Event A' );
oras_included_same( Source_Resolver::resolve( $evidence['a2'], $events['b'], $config_b )['source_kind'], 'included_event', 'Ticket A2 grants Event B from its immutable snapshot' );
oras_included_same( Source_Resolver::resolve( $evidence['a2'], $events['c'], $config_c )['resolution'], 'review_required', 'Ticket A2 does not grant unrelated Event C' );
oras_included_same( Source_Resolver::resolve( $evidence['a3'], $events['b'], $config_b )['source_kind'], 'included_event', 'Ticket A3 grants Event B' );
oras_included_same( Source_Resolver::resolve( $evidence['a3'], $events['c'], $config_c )['source_kind'], 'included_event', 'Ticket A3 grants Event C without transitive inference' );
oras_included_true( ! in_array( (int) $map_a[1], array_column( Event_Offering_Resolver::desk_offerings( $events['b'], $config_b ), 'product_id' ), true ), 'Bundled Event A ticket never becomes an Event B walk-in offering' );

$legacy_order = oras_included_order( (int) $map_a[1], 1, $run, 'Legacy', false );
$legacy_evidence = $adapter->load( $legacy_order['order_id'], $legacy_order['item_id'] );
$legacy_config = $config_b;
$legacy_config['entitlements'][] = array(
	'entitlement_uuid'  => wp_generate_uuid4(),
	'source_event_id'   => $events['a'],
	'source_product_id' => (int) $map_a[1],
	'classification'    => 'individual',
	'validity_type'     => 'full_event',
	'max_attendees'     => 1,
);
oras_included_same( Source_Resolver::resolve( $legacy_evidence, $events['b'], $legacy_config )['source_kind'], 'legacy_cross_event', 'Old order without a snapshot still uses explicit legacy cross-event access' );
oras_included_same( Source_Resolver::resolve( $evidence['a2'], $events['b'], $legacy_config )['source_kind'], 'included_event', 'Included-event snapshot takes precedence when a redundant legacy mapping also exists' );

$projection = new Projection_Service( $adapter );
$bundle_projection = $projection->reconcile_evidence( $events['b'], $evidence['a2'], $legacy_config );
$direct_projection = $projection->reconcile_evidence( $events['b'], $evidence['b1'], $config_b );
if ( is_wp_error( $bundle_projection ) || is_wp_error( $direct_projection ) ) {
	oras_included_fail( 'direct or included source projection failed.' );
}
$bundle_registration = $bundle_projection['registrations'][0];
$direct_registration = $direct_projection['registrations'][0];
oras_included_same( $bundle_registration['source_type'], 'online_included', 'Bundled projection records an honest included-event source' );
oras_included_same( $direct_registration['source_type'], 'online', 'Direct target-event projection remains a direct website source' );
$projection->reconcile_evidence( $events['b'], $evidence['a2'], $legacy_config );
global $wpdb;
$tables = Schema::table_names();
$same_item_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['registrations']} WHERE event_id=%d AND source_order_id=%d AND source_order_item_id=%d", $events['b'], $orders['a2']['order_id'], $orders['a2']['item_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table.
oras_included_same( $same_item_rows, 1, 'Snapshot plus legacy evidence converges on one target-event registration' );

$attendee = ( new Attendee_Store() )->confirm_individual( (int) $bundle_registration['id'], 'Bundle', 'Attendee' );
$attendance = ( new Attendance_Store() )->check_in( $events['b'], (int) $attendee['id'], $today, (int) $context['admin_id'], wp_generate_uuid4(), 'Integration test' );
oras_included_true( ! is_wp_error( $attendance ), 'Bundled attendee can retain normal attendance history' );
$stats = ( new Event_Stats_Service() )->for_event( $events['b'], $today );
oras_included_same( $stats['event_total']['direct_website_registrations'], 1, 'Event Stats counts one direct website registration' );
oras_included_same( $stats['event_total']['included_event_registrations'], 1, 'Event Stats and Board Reports shared source counts include the bundled registration once' );

$a2_order = wc_get_order( $orders['a2']['order_id'] );
$a2_order->set_status( 'cancelled' );
$a2_order->save();
$cancelled_evidence = $adapter->load( $orders['a2']['order_id'], $orders['a2']['item_id'] );
oras_included_same( Source_Resolver::resolve( $cancelled_evidence, $events['b'], $config_b )['eligibility'], 'revoked', 'Cancelling the source purchase revokes included-event admission' );
$projection->reconcile_evidence( $events['b'], $cancelled_evidence, $config_b );
oras_included_true( null !== ( new Attendance_Store() )->find_daily( $events['b'], (int) $attendee['id'], $today ), 'Revocation preserves previously recorded attendance history' );

$a3_order = wc_get_order( $orders['a3']['order_id'] );
$a3_order->set_status( 'refunded' );
$a3_order->save();
$refunded_evidence = $adapter->load( $orders['a3']['order_id'], $orders['a3']['item_id'] );
oras_included_same( Source_Resolver::resolve( $refunded_evidence, $events['b'], $config_b )['eligibility'], 'revoked', 'Fully refunded source purchase revokes included-event admission' );

$partial_order = oras_included_order( (int) $map_a[2], 2, $run, 'Partial' );
$refund = wc_create_refund(
	array(
		'order_id'   => $partial_order['order_id'],
		'amount'     => 20,
		'line_items' => array(
			$partial_order['item_id'] => array(
				'qty'          => 1,
				'refund_total' => 20,
				'refund_tax'   => array(),
			),
		),
		'reason'     => 'Synthetic partial-refund ambiguity',
	)
);
oras_included_true( ! is_wp_error( $refund ), 'Synthetic partial refund is recorded through WooCommerce' );
$partial_evidence = $adapter->load( $partial_order['order_id'], $partial_order['item_id'] );
oras_included_same( Source_Resolver::resolve( $partial_evidence, $events['b'], $config_b )['eligibility'], 'review_required', 'Partial refund ambiguity remains conservative review-required' );
oras_included_same( count_users()['total_users'], $users_before, 'Bundled access creates no attendee WordPress account' );

WP_CLI::success( 'Canonical included-event access passed guarded WooCommerce integration checks.' );
