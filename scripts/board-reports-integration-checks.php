<?php
/**
 * Board reports integration checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /tmp/oras-board-reports-integration-checks.php
 */

use ORAS\Tickets\Capabilities;
use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Reporting\Board_Report_Exporter;
use ORAS\Tickets\Reporting\Board_Report_Service;
use ORAS\Tickets\Reporting\Observer_Pass_Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Board_Reports_Check_Exception extends RuntimeException {}
function oras_board_reports_fail( string $message ): void {
	throw new Oras_Board_Reports_Check_Exception( $message );
}

function oras_board_reports_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		oras_board_reports_fail( $message );
	}

	echo 'PASS: ' . $message . "\n";
}

function oras_board_reports_assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		oras_board_reports_fail(
			sprintf(
				'%s (expected=%s actual=%s)',
				$message,
				wp_json_encode( $expected ),
				wp_json_encode( $actual )
			)
		);
	}

	echo 'PASS: ' . $message . "\n";
}

function oras_board_reports_create_user( string $prefix, string $suffix, string $role = 'subscriber' ): int {
	$login = sanitize_user( $prefix . '_' . $suffix . '_' . wp_generate_password( 4, false ) );
	$id = wp_create_user( $login, wp_generate_password( 20, true, true ), $login . '@example.org' );
	if ( ! is_int( $id ) || $id <= 0 ) {
		oras_board_reports_fail( 'Unable to create user: ' . $prefix );
	}

	$user = new WP_User( $id );
	$user->set_role( $role );

	return $id;
}

function oras_board_reports_create_product( string $name, string $price, string $bucket = '' ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( $price );
	$product->set_price( $price );
	$product_id = $product->save();
	if ( ! is_int( $product_id ) || $product_id <= 0 ) {
		oras_board_reports_fail( 'Unable to create product: ' . $name );
	}

	if ( $bucket !== '' ) {
		update_post_meta( $product_id, '_oras_qbo_bucket', $bucket );
	}

	return $product_id;
}

function oras_board_reports_create_observer_product( string $name, string $price, string $slug ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_virtual( true );
	$product->set_regular_price( $price );
	$product->set_price( $price );
	$product_id = $product->save();
	if ( ! is_int( $product_id ) || $product_id <= 0 ) {
		oras_board_reports_fail( 'Unable to create Observer Pass product: ' . $name );
	}

	return $product_id;
}

function oras_board_reports_add_contact_to_order( WC_Order $order ): void {
	$order->set_billing_first_name( 'Board' );
	$order->set_billing_last_name( 'Buyer' );
	$order->set_billing_email( 'board.buyer@example.org' );
	$order->set_billing_phone( '555-0101' );
	$order->set_billing_address_1( '123 Observatory Lane' );
	$order->set_billing_city( 'Oil City' );
	$order->set_billing_state( 'PA' );
	$order->set_billing_postcode( '16301' );
	$order->update_meta_data( '_oras_forbidden_transaction_id', 'forbidden-transaction' );
	$order->update_meta_data( '_oras_forbidden_stripe_intent_id', 'forbidden-stripe-intent' );
	$order->save();
}

function oras_board_reports_create_order( int $product_id, int $qty ): WC_Order {
	$order = wc_create_order();
	if ( ! $order instanceof WC_Order ) {
		oras_board_reports_fail( 'Unable to create Woo order' );
	}

	$order->add_product( wc_get_product( $product_id ), $qty );
	oras_board_reports_add_contact_to_order( $order );
	$order->calculate_totals();
	$order->update_status( 'completed' );

	return $order;
}

function oras_board_reports_set_order_date( WC_Order $order, DateTimeImmutable $date ): void {
	$order->set_date_created( $date->getTimestamp() );
	$order->save();
}

function oras_board_reports_set_daily_dates( WC_Order $order, string $start, string $checkout, string $booking_status = 'confirmed' ): void {
	$item = current( $order->get_items( 'line_item' ) );
	if ( ! $item instanceof WC_Order_Item_Product ) {
		oras_board_reports_fail( 'Daily Observer Pass line item missing' );
	}

	$item->update_meta_data( '_wapbk_booking_date', $start );
	$item->update_meta_data( '_wapbk_checkout_date', $checkout );
	$item->update_meta_data( '_wapbk_booking_status', $booking_status );
	$item->save();
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function oras_board_reports_find_product_row( array $rows, int $product_id ): array {
	foreach ( $rows as $row ) {
		if ( $product_id === (int) ( $row['product_id'] ?? 0 ) ) {
			return $row;
		}
	}

	oras_board_reports_fail( 'Observer Pass row not found for product ' . $product_id );
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function oras_board_reports_find_order_row( array $rows, WC_Order $order ): array {
	foreach ( $rows as $row ) {
		if ( (int) $order->get_id() === (int) ( $row['order_id'] ?? 0 ) ) {
			return $row;
		}
	}

	oras_board_reports_fail( 'Observer Pass row not found for order ' . $order->get_id() );
}

function oras_board_reports_refund_item( WC_Order $order, int $quantity, float $amount ): void {
	$item = current( $order->get_items( 'line_item' ) );
	if ( ! $item instanceof WC_Order_Item_Product ) {
		oras_board_reports_fail( 'Observer Pass refund line item missing' );
	}

	$refund = wc_create_refund(
		array(
			'amount'         => $amount,
			'reason'         => 'Observer Pass integration fixture',
			'order_id'       => $order->get_id(),
			'refund_payment' => false,
			'restock_items'  => false,
			'line_items'     => array(
				$item->get_id() => array(
					'qty'          => -1 * $quantity,
					'refund_total' => -1 * $amount,
					'refund_tax'   => array(),
				),
			),
		)
	);

	if ( is_wp_error( $refund ) || ! $refund instanceof WC_Order_Refund ) {
		oras_board_reports_fail( 'Unable to create attributable Observer Pass refund' );
	}
}

function oras_board_reports_run_checks(): void {
	if ( ! defined( 'ORAS_TICKETS_DIR' ) ) {
		oras_board_reports_fail( 'ORAS_TICKETS_DIR not defined. Ensure oras-tickets plugin is active.' );
	}

	require_once ORAS_TICKETS_DIR . 'includes/Capabilities.php';
	require_once ORAS_TICKETS_DIR . 'includes/Frontend/Board_Reports.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Contact_Normalizer.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Exporter.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Service.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Observer_Pass_Report_Service.php';

	Capabilities::add_caps();
	Board_Reports::register();

	oras_board_reports_assert( shortcode_exists( 'oras_board_reports' ), 'Board reports shortcode is registered' );
	oras_board_reports_assert( has_action( 'admin_post_oras_board_reports_export_csv' ) !== false, 'Board reports export action is registered' );
	oras_board_reports_assert( function_exists( 'wc_create_order' ), 'WooCommerce is available' );

	$admin_ids = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ids',
			'number' => 1,
		)
	);
	oras_board_reports_assert( ! empty( $admin_ids ), 'Administrator user exists' );

	$admin_id = (int) $admin_ids[0];
	$suffix = gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
	$subscriber_id = oras_board_reports_create_user( 'oras_board_sub', $suffix );
	$rsvp_user_id = oras_board_reports_create_user( 'oras_board_rsvp', $suffix );
	$created_posts = array();
	$created_orders = array();

	try {
		$event_id = wp_insert_post(
			array(
				'post_type'   => 'tribe_events',
				'post_status' => 'publish',
				'post_title'  => 'Board Reports Event ' . $suffix,
			)
		);
		oras_board_reports_assert( is_int( $event_id ) && $event_id > 0, 'Fixture event created' );
		$created_posts[] = (int) $event_id;

		update_post_meta(
			$event_id,
			'_oras_rsvp_v1',
			array(
				'enabled'          => 1,
				'capacity'         => 10,
				'waitlist_enabled' => 1,
			)
		);

		$ticket_product_id = oras_board_reports_create_product( 'Board Reports Ticket ' . $suffix, '10.00' );
		$observer_product_id = oras_board_reports_create_product( 'Observer Pass ' . $suffix, '5.00', 'observer_pass' );
		$merch_product_id = oras_board_reports_create_product( 'Merch Shirt ' . $suffix, '20.00', 'merchandise' );
		$annual_product_id = oras_board_reports_create_observer_product( 'Annual Observer Pass ' . $suffix, '60.00', 'annual-observer-pass-' . strtolower( $suffix ) );
		$daily_product_id = oras_board_reports_create_observer_product( 'Daily Observer Pass ' . $suffix, '16.00', 'daily-observer-pass-' . strtolower( $suffix ) );
		$created_posts[] = $ticket_product_id;
		$created_posts[] = $observer_product_id;
		$created_posts[] = $merch_product_id;
		$created_posts[] = $annual_product_id;
		$created_posts[] = $daily_product_id;

		add_filter(
			'oras_tickets_observer_annual_product_ids',
			static function ( array $ids ) use ( $annual_product_id ): array {
				return array( $annual_product_id );
			}
		);
		add_filter(
			'oras_tickets_observer_daily_product_ids',
			static function ( array $ids ) use ( $daily_product_id ): array {
				return array( $daily_product_id );
			}
		);

		$ticket_order = oras_board_reports_create_order( $ticket_product_id, 2 );
		$ticket_item = current( $ticket_order->get_items( 'line_item' ) );
		if ( ! $ticket_item instanceof WC_Order_Item_Product ) {
			oras_board_reports_fail( 'Ticket order line item missing' );
		}
		$ticket_item->update_meta_data( '_oras_ticket_event_id', (int) $event_id );
		$ticket_item->update_meta_data( '_oras_ticket_index', '0' );
		$ticket_item->update_meta_data( '_oras_ticket_name', 'General Admission' );
		$ticket_item->save();
		$created_orders[] = $ticket_order;

		$observer_order = oras_board_reports_create_order( $observer_product_id, 1 );
		$merch_order = oras_board_reports_create_order( $merch_product_id, 3 );
		$created_orders[] = $observer_order;
		$created_orders[] = $merch_order;

		$today = new DateTimeImmutable( '2026-08-28 00:00:00', wp_timezone() );
		$annual_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $annual_order, new DateTimeImmutable( '2026-08-01 12:00:00', wp_timezone() ) );
		$created_orders[] = $annual_order;

		$daily_order = oras_board_reports_create_order( $daily_product_id, 2 );
		oras_board_reports_set_order_date( $daily_order, new DateTimeImmutable( '2026-08-20 12:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $daily_order, '2026-08-28', '2026-08-30' );
		$created_orders[] = $daily_order;

		$expiring_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $expiring_order, new DateTimeImmutable( '2025-09-27 12:00:00', wp_timezone() ) );
		$created_orders[] = $expiring_order;

		$expired_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $expired_order, new DateTimeImmutable( '2025-08-28 12:00:00', wp_timezone() ) );
		$created_orders[] = $expired_order;

		$upcoming_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $upcoming_order, new DateTimeImmutable( '2026-08-22 12:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $upcoming_order, '2026-08-29', '2026-08-30' );
		$created_orders[] = $upcoming_order;

		$past_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $past_order, new DateTimeImmutable( '2026-08-22 11:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $past_order, '2026-08-27', '2026-08-28' );
		$created_orders[] = $past_order;

		$missing_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $missing_order, new DateTimeImmutable( '2026-08-22 10:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $missing_order, 'not-a-date', '' );
		$created_orders[] = $missing_order;

		$booking_cancelled_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $booking_cancelled_order, new DateTimeImmutable( '2026-08-22 09:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $booking_cancelled_order, '2026-08-28', '2026-08-29', 'cancelled' );
		$created_orders[] = $booking_cancelled_order;

		$cancelled_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $cancelled_order, new DateTimeImmutable( '2026-07-01 12:00:00', wp_timezone() ) );
		$cancelled_order->update_status( 'cancelled' );
		$created_orders[] = $cancelled_order;

		$failed_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $failed_order, new DateTimeImmutable( '2026-07-02 12:00:00', wp_timezone() ) );
		$failed_order->update_status( 'failed' );
		$created_orders[] = $failed_order;

		$refunded_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $refunded_order, new DateTimeImmutable( '2026-07-03 12:00:00', wp_timezone() ) );
		$refunded_order->update_status( 'refunded' );
		$created_orders[] = $refunded_order;

		$pending_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $pending_order, new DateTimeImmutable( '2026-07-04 12:00:00', wp_timezone() ) );
		$pending_order->update_status( 'pending' );
		$created_orders[] = $pending_order;

		$partial_refund_order = oras_board_reports_create_order( $annual_product_id, 2 );
		oras_board_reports_set_order_date( $partial_refund_order, new DateTimeImmutable( '2026-06-01 12:00:00', wp_timezone() ) );
		oras_board_reports_refund_item( $partial_refund_order, 1, 60.0 );
		$created_orders[] = $partial_refund_order;

		$mixed_order = oras_board_reports_create_order( $annual_product_id, 1 );
		$mixed_order->add_product( wc_get_product( $merch_product_id ), 1 );
		$mixed_order->calculate_totals();
		oras_board_reports_set_order_date( $mixed_order, new DateTimeImmutable( '2026-08-15 12:00:00', wp_timezone() ) );
		$created_orders[] = $mixed_order;

		$fully_refunded_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $fully_refunded_order, new DateTimeImmutable( '2026-05-01 12:00:00', wp_timezone() ) );
		oras_board_reports_refund_item( $fully_refunded_order, 1, 60.0 );
		$created_orders[] = $fully_refunded_order;

		update_user_meta( $rsvp_user_id, '_oras_rsvp_event_' . $event_id, 'yes' );
		update_user_meta( $rsvp_user_id, '_oras_rsvp_event_' . $event_id . '_attendance_mode', 'onsite' );
		update_user_meta(
			$rsvp_user_id,
			'_oras_rsvp_event_' . $event_id . '_contact',
			array(
				'first_name' => 'RSVP',
				'last_name'  => 'Guest',
				'email'      => 'rsvp.guest@example.org',
				'phone'      => '555-0202',
				'note'       => 'Needs chair access',
			)
		);
		oras_board_reports_assert( true, 'RSVP contact fixture stored' );

		$service = new Board_Report_Service();
		$ticket_rows = $service->get_event_ticket_buyers( $event_id, array( 'status' => 'all' ) );
		$rsvp_rows = $service->get_rsvp_attendees( $event_id, array( 'status' => 'all' ) );
		$observer_rows = $service->get_observer_pass_buyers( array( 'status' => 'all' ) );
		$merch_rows = $service->get_merchandise_buyers( array( 'status' => 'all' ) );

		oras_board_reports_assert( count( $ticket_rows ) >= 1, 'Ticket buyer report returns fixture row' );
		oras_board_reports_assert_same( (string) $ticket_rows[0]['email'], 'board.buyer@example.org', 'Ticket buyer report includes billing email' );
		oras_board_reports_assert_same( (int) $ticket_rows[0]['quantity'], 2, 'Ticket buyer report keeps item quantity' );
		oras_board_reports_assert( count( $rsvp_rows ) >= 1, 'RSVP report returns fixture row' );
		oras_board_reports_assert_same( (string) $rsvp_rows[0]['phone'], '555-0202', 'RSVP report includes supplied phone' );
		oras_board_reports_assert( count( $observer_rows ) >= 1, 'Observer pass report returns fixture row' );
		oras_board_reports_assert( count( $merch_rows ) >= 1, 'Merchandise report returns fixture row' );

		$observer_service = new Observer_Pass_Report_Service( $today );
		$observer_report = $observer_service->get_report();
		$annual_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $annual_order );
		$daily_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $daily_order );
		$expiring_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $expiring_order );
		$expired_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $expired_order );
		$upcoming_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $upcoming_order );
		$past_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $past_order );
		$missing_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $missing_order );
		$booking_cancelled_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $booking_cancelled_order );
		$cancelled_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $cancelled_order );
		$failed_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $failed_order );
		$refunded_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $refunded_order );
		$pending_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $pending_order );
		$partial_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $partial_refund_order );
		$mixed_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $mixed_order );
		$fully_refunded_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $fully_refunded_order );

		oras_board_reports_assert_same( $observer_report['available'], true, 'Observer Pass report is available' );
		oras_board_reports_assert_same( count( $observer_report['all_rows'] ), 15, 'Only configured Observer Pass products are normalized' );
		oras_board_reports_assert_same( $annual_row['pass_type'], Observer_Pass_Report_Service::PASS_ANNUAL, 'Annual product is identified' );
		oras_board_reports_assert_same( $annual_row['expiration_date'], '2027-08-01', 'Annual expiration is one calendar year after purchase' );
		oras_board_reports_assert_same( $annual_row['holder_label'], 'Purchaser/Passholder', 'Purchaser is explicitly labeled as holder fallback' );
		oras_board_reports_assert_same( $daily_row['valid_start'], '2026-08-28', 'Daily start date is normalized' );
		oras_board_reports_assert_same( $daily_row['valid_checkout'], '2026-08-30', 'Daily checkout stays exclusive' );
		oras_board_reports_assert_same( $daily_row['last_valid_date'], '2026-08-29', 'Daily visible range ends on the last observing night' );
		oras_board_reports_assert_same( $daily_row['order_number'], (string) $daily_order->get_order_number(), 'Displayed order number is preserved' );
		oras_board_reports_assert_same( $annual_row['operational_status'], Observer_Pass_Report_Service::STATUS_ACTIVE, 'Paid Annual pass is active before its final 30 days' );
		oras_board_reports_assert_same( $annual_row['is_valid'], true, 'Active Annual pass is valid' );
		oras_board_reports_assert_same( $expiring_row['operational_status'], Observer_Pass_Report_Service::STATUS_EXPIRING_SOON, 'Annual pass enters Expiring Soon at 30 days' );
		oras_board_reports_assert_same( $expiring_row['is_valid'], true, 'Expiring Soon Annual pass remains valid' );
		oras_board_reports_assert_same( $expired_row['operational_status'], Observer_Pass_Report_Service::STATUS_EXPIRED, 'Annual pass expires on its anniversary' );
		oras_board_reports_assert_same( $expired_row['is_valid'], false, 'Expired Annual pass is invalid' );
		oras_board_reports_assert_same( $daily_row['operational_status'], Observer_Pass_Report_Service::STATUS_TODAY, 'Inclusive Daily start and exclusive checkout contain today' );
		oras_board_reports_assert_same( $daily_row['is_valid'], true, 'Daily pass spanning Today is valid' );
		oras_board_reports_assert_same( $upcoming_row['operational_status'], Observer_Pass_Report_Service::STATUS_UPCOMING, 'Future Daily pass is upcoming' );
		oras_board_reports_assert_same( $upcoming_row['is_valid'], true, 'Upcoming paid Daily pass is valid' );
		oras_board_reports_assert_same( $past_row['operational_status'], Observer_Pass_Report_Service::STATUS_PAST, 'Daily checkout date is no longer valid' );
		oras_board_reports_assert_same( $past_row['is_valid'], false, 'Past Daily pass is invalid' );
		oras_board_reports_assert_same( $missing_row['operational_status'], Observer_Pass_Report_Service::STATUS_DATE_MISSING, 'Missing Daily dates stay auditable but invalid' );
		oras_board_reports_assert_same( $missing_row['is_valid'], false, 'Daily pass with missing dates is invalid' );
		oras_board_reports_assert_same( $booking_cancelled_row['operational_status'], Observer_Pass_Report_Service::STATUS_CANCELLED, 'Cancelled booking status takes precedence' );
		oras_board_reports_assert_same( $booking_cancelled_row['is_valid'], false, 'Cancelled booking is invalid' );
		oras_board_reports_assert_same( $cancelled_row['operational_status'], Observer_Pass_Report_Service::STATUS_CANCELLED, 'Cancelled Woo order stays auditable' );
		oras_board_reports_assert_same( $failed_row['operational_status'], Observer_Pass_Report_Service::STATUS_FAILED, 'Failed Woo order stays auditable' );
		oras_board_reports_assert_same( $refunded_row['operational_status'], Observer_Pass_Report_Service::STATUS_REFUNDED, 'Refunded Woo order stays auditable' );
		oras_board_reports_assert_same( $pending_row['operational_status'], Observer_Pass_Report_Service::STATUS_UNPAID, 'Pending unpaid order stays auditable but invalid' );
		oras_board_reports_assert_same( $pending_row['is_valid'], false, 'Pending unpaid order is invalid' );
		oras_board_reports_assert_same( $partial_row['refunded_quantity'], 1, 'Attributable refunded quantity is recorded' );
		oras_board_reports_assert_same( $partial_row['valid_quantity'], 1, 'Partial item refund reduces valid quantity' );
		oras_board_reports_assert_same( $partial_row['net_revenue'], 60.0, 'Partial item refund reduces Observer revenue' );
		oras_board_reports_assert_same( $fully_refunded_row['operational_status'], Observer_Pass_Report_Service::STATUS_REFUNDED, 'Fully refunded pass stays visible as refunded' );
		oras_board_reports_assert_same( $fully_refunded_row['valid_quantity'], 0, 'Fully refunded pass has no valid quantity' );
		oras_board_reports_assert_same( $mixed_row['net_revenue'], 60.0, 'Unrelated merchandise does not change Observer line revenue' );
		oras_board_reports_assert_same( $observer_report['summary']['active_annual'], 4, 'Active Annual summary uses valid quantity' );
		oras_board_reports_assert_same( $observer_report['summary']['daily_today'], 2, 'Daily Today summary uses valid quantity' );
		oras_board_reports_assert_same( $observer_report['summary']['daily_next_7'], 1, 'Next-seven-days summary excludes Today' );
		oras_board_reports_assert_same( $observer_report['summary']['revenue_ytd'], 260.0, 'YTD summary uses only net Observer line revenue' );

		$payload = wp_json_encode( array( $ticket_rows, $observer_rows, $merch_rows, $observer_report['all_rows'] ) );
		oras_board_reports_assert( false === strpos( (string) $payload, 'forbidden-transaction' ), 'Board report rows exclude transaction IDs' );
		oras_board_reports_assert( false === strpos( (string) $payload, 'forbidden-stripe-intent' ), 'Board report rows exclude Stripe metadata' );
		oras_board_reports_assert( false === stripos( (string) $payload, 'payment_method' ), 'Board report rows exclude payment method fields' );

		$csv_values = ( new Board_Report_Exporter() )->row_to_csv_values( $ticket_rows[0] );
		$csv_payload = implode( ',', $csv_values );
		oras_board_reports_assert( false === strpos( $csv_payload, 'forbidden-transaction' ), 'CSV row excludes transaction IDs' );
		oras_board_reports_assert( false === strpos( $csv_payload, 'forbidden-stripe-intent' ), 'CSV row excludes Stripe metadata' );

		wp_set_current_user( $subscriber_id );
		$subscriber_html = do_shortcode( '[oras_board_reports]' );
		oras_board_reports_assert( false !== strpos( $subscriber_html, 'do not have permission' ), 'Unauthorized subscriber cannot render board reports' );

		wp_set_current_user( $admin_id );
		$admin_html = do_shortcode( '[oras_board_reports]' );
		oras_board_reports_assert( false !== strpos( $admin_html, 'Board Reports' ), 'Authorized user can render board reports' );
	} finally {
		wp_delete_user( $subscriber_id );
		wp_delete_user( $rsvp_user_id );
		foreach ( $created_orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
		foreach ( $created_posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}

try {
	oras_board_reports_run_checks();
	echo "Board reports integration checks passed.\n";
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Board reports integration checks failed: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
