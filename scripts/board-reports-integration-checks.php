<?php
/**
 * Board reports integration checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /tmp/oras-board-reports-integration-checks.php
 */

use ORAS\Tickets\Capabilities;
use ORAS\Tickets\Event_Question_Attention_Store;
use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Reporting\Board_Report_Exporter;
use ORAS\Tickets\Reporting\Board_Report_Service;
use ORAS\Tickets\Reporting\Observer_Pass_Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Board_Reports_Check_Exception extends RuntimeException {}
final class Oras_Board_Reports_Wp_Die_Exception extends RuntimeException {}
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

/**
 * @param callable():void $callback
 */
function oras_board_reports_capture_wp_die( callable $callback ): string {
	$handler_filter = static function (): callable {
		return static function ( $message ): void {
			$text = is_scalar( $message ) ? wp_strip_all_tags( (string) $message ) : '';
			throw new Oras_Board_Reports_Wp_Die_Exception( $text );
		};
	};
	add_filter( 'wp_die_handler', $handler_filter );
	try {
		$callback();
	} catch ( Oras_Board_Reports_Wp_Die_Exception $error ) {
		return $error->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $handler_filter );
	}

	oras_board_reports_fail( 'Expected WordPress to reject the request' );
}

/**
 * @param array<string,mixed> $report
 * @param array<string,mixed> $filters
 */
function oras_board_reports_render_observer_dashboard( array $report, array $filters, int $page_id = 0 ): string {
	$renderer = new ReflectionMethod( Board_Reports::class, 'render_observer_passes_tab' );
	ob_start();
	try {
		$renderer->invoke( null, $report, $filters, $page_id );
	} catch ( Throwable $error ) {
		ob_end_clean();
		throw $error;
	}

	return (string) ob_get_clean();
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

	oras_board_reports_set_daily_item_dates( $item, $start, $checkout, $booking_status );
}

function oras_board_reports_set_daily_item_dates( WC_Order_Item_Product $item, string $start, string $checkout, string $booking_status = 'confirmed' ): void {
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

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function oras_board_reports_find_order_rows( array $rows, WC_Order $order ): array {
	return array_values(
		array_filter(
			$rows,
			static function ( array $row ) use ( $order ): bool {
				return (int) $order->get_id() === (int) ( $row['order_id'] ?? 0 );
			}
		)
	);
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

function oras_board_reports_refund_order_amount( WC_Order $order, float $amount ): void {
	$refund = wc_create_refund(
		array(
			'amount'         => $amount,
			'reason'         => 'Operational report dollar-only refund fixture',
			'order_id'       => $order->get_id(),
			'refund_payment' => false,
			'restock_items'  => false,
			'line_items'     => array(),
		)
	);

	if ( is_wp_error( $refund ) || ! $refund instanceof WC_Order_Refund ) {
		oras_board_reports_fail( 'Unable to create dollar-only refund fixture' );
	}
}

function oras_board_reports_run_checks(): void {
	if ( ! defined( 'ORAS_TICKETS_DIR' ) ) {
		oras_board_reports_fail( 'ORAS_TICKETS_DIR not defined. Ensure oras-tickets plugin is active.' );
	}

	require_once ORAS_TICKETS_DIR . 'includes/Capabilities.php';
	require_once ORAS_TICKETS_DIR . 'includes/Event_Question_Attention_Store.php';
	require_once ORAS_TICKETS_DIR . 'includes/Frontend/Board_Reports.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Contact_Normalizer.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Exporter.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Service.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Observer_Pass_Report_Service.php';

	Capabilities::add_caps();
	Board_Reports::register();

	oras_board_reports_assert( shortcode_exists( 'oras_board_reports' ), 'Board reports shortcode is registered' );
	oras_board_reports_assert( has_action( 'admin_post_oras_board_reports_export_csv' ) !== false, 'Board reports export action is registered' );
	oras_board_reports_assert(
		has_action( 'admin_post_oras_board_reports_print_observers_today', array( Board_Reports::class, 'handle_print_observers_today' ) ) !== false,
		'Authenticated Today Observer print action is registered'
	);
	oras_board_reports_assert( false === has_action( 'admin_post_nopriv_oras_board_reports_print_observers_today' ), 'No unauthenticated Today Observer print action is registered' );
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
	$created_attention_ids = array();
	$original_get = $_GET;
	$original_post = $_POST;
	$original_request = $_REQUEST;

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

		$later_upcoming_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $later_upcoming_order, new DateTimeImmutable( '2026-08-21 12:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $later_upcoming_order, '2026-09-03', '2026-09-05' );
		$later_upcoming_order->set_billing_first_name( 'Filter' );
		$later_upcoming_order->set_billing_last_name( 'Target' );
		$later_upcoming_order->set_billing_email( 'filter.target@example.org' );
		$later_upcoming_order->save();
		$created_orders[] = $later_upcoming_order;

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
		$later_upcoming_row = oras_board_reports_find_order_row( $observer_report['all_rows'], $later_upcoming_order );
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
		oras_board_reports_assert_same( count( $observer_report['all_rows'] ), 16, 'Only configured Observer Pass products are normalized' );
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
		oras_board_reports_assert_same( $later_upcoming_row['operational_status'], Observer_Pass_Report_Service::STATUS_UPCOMING, 'Later future Daily pass is upcoming' );
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
		oras_board_reports_assert_same( $fully_refunded_row['operational_status'], Observer_Pass_Report_Service::STATUS_REFUNDED, 'Fully refunded pass stays visible as refunded' );
		oras_board_reports_assert_same( $fully_refunded_row['valid_quantity'], 0, 'Fully refunded pass has no valid quantity' );
		oras_board_reports_assert_same( count( oras_board_reports_find_order_rows( $observer_report['all_rows'], $mixed_order ) ), 1, 'Observer plus merchandise produces one Observer row' );
		oras_board_reports_assert( ! array_key_exists( 'net_revenue', $partial_row ), 'Normalized rows exclude net revenue' );
		oras_board_reports_assert_same(
			$observer_report['summary'],
			array(
				'active_annual_count'     => 4,
				'daily_today_count'       => 2,
				'daily_next_7_days_count' => 2,
				'daily_this_month_count'  => 1,
			),
			'Observer summary contains only unfiltered operational counts'
		);

		$annual_report = $observer_service->get_report( array( 'pass_type' => Observer_Pass_Report_Service::PASS_ANNUAL ) );
		$daily_report = $observer_service->get_report( array( 'pass_type' => Observer_Pass_Report_Service::PASS_DAILY ) );
		$expiring_report = $observer_service->get_report( array( 'status' => Observer_Pass_Report_Service::STATUS_EXPIRING_SOON ) );
		$historical_report = $observer_service->get_report( array( 'status' => 'refunded_cancelled' ) );
		$name_report = $observer_service->get_report( array( 'search' => 'board buyer' ) );
		$email_report = $observer_service->get_report( array( 'search' => 'board.buyer@example.org' ) );
		$order_report = $observer_service->get_report( array( 'search' => (string) $daily_order->get_order_number() ) );
		$today_report = $observer_service->get_report( array( 'date_preset' => 'today' ) );
		$next_seven_report = $observer_service->get_report( array( 'date_preset' => 'next_7' ) );
		$month_report = $observer_service->get_report( array( 'date_preset' => 'this_month' ) );
		$year_report = $observer_service->get_report( array( 'date_preset' => 'this_year' ) );
		$custom_report = $observer_service->get_report(
			array(
				'date_preset' => 'custom',
				'after'       => '2026-09-01',
				'before'      => '2026-09-30',
			)
		);
		$invalid_date_report = $observer_service->get_report(
			array(
				'date_preset' => 'custom',
				'after'       => 'not-a-date',
				'before'      => 'also-not-a-date',
			)
		);

		oras_board_reports_assert_same( count( $annual_report['rows'] ), 10, 'Annual filter returns only Annual rows' );
		oras_board_reports_assert_same( array_values( array_unique( wp_list_pluck( $annual_report['rows'], 'pass_type' ) ) ), array( Observer_Pass_Report_Service::PASS_ANNUAL ), 'Annual filter excludes Daily rows' );
		oras_board_reports_assert_same( count( $daily_report['rows'] ), 6, 'Daily filter returns only Daily rows' );
		oras_board_reports_assert_same( array_values( array_unique( wp_list_pluck( $daily_report['rows'], 'pass_type' ) ) ), array( Observer_Pass_Report_Service::PASS_DAILY ), 'Daily filter excludes Annual rows' );
		oras_board_reports_assert_same( wp_list_pluck( $expiring_report['rows'], 'order_id' ), array( $expiring_order->get_id() ), 'Status filter returns the Expiring Soon row' );
		oras_board_reports_assert_same( count( $historical_report['rows'] ), 4, 'Combined Refunded/Cancelled filter returns audit rows' );
		oras_board_reports_assert_same( count( $name_report['rows'] ), 15, 'Search matches purchaser name case-insensitively' );
		oras_board_reports_assert_same( count( $email_report['rows'] ), 15, 'Search matches purchaser email' );
		oras_board_reports_assert_same( wp_list_pluck( $order_report['rows'], 'order_id' ), array( $daily_order->get_id() ), 'Search matches displayed order number' );
		oras_board_reports_assert_same( count( $today_report['rows'] ), 3, 'Today date preset matches Annual expiration and Daily observing nights' );
		oras_board_reports_assert_same( count( $next_seven_report['rows'] ), 3, 'Next 7 date preset begins tomorrow and matches overlapping Daily nights' );
		oras_board_reports_assert_same( count( $month_report['rows'] ), 5, 'This Month date preset uses operational dates' );
		oras_board_reports_assert_same( count( $year_report['rows'] ), 7, 'This Year date preset uses operational dates' );
		oras_board_reports_assert_same( count( $custom_report['rows'] ), 2, 'Custom inclusive range matches overlapping Daily nights and Annual expiration' );
		oras_board_reports_assert_same( count( $invalid_date_report['rows'] ), 16, 'Invalid custom dates are safely ignored' );
		oras_board_reports_assert_same( $annual_report['summary'], $observer_report['summary'], 'Summary is invariant under pass-type filtering' );
		oras_board_reports_assert_same( $name_report['summary'], $observer_report['summary'], 'Summary is invariant under search filtering' );
		oras_board_reports_assert_same( $custom_report['summary'], $observer_report['summary'], 'Summary is invariant under date filtering' );

		oras_board_reports_assert_same(
			array_slice( wp_list_pluck( $daily_report['rows'], 'order_id' ), 0, 3 ),
			array( $daily_order->get_id(), $upcoming_order->get_id(), $later_upcoming_order->get_id() ),
			'Daily rows sort Today then Upcoming by start date ascending'
		);
		oras_board_reports_assert_same(
			array_slice( wp_list_pluck( $annual_report['rows'], 'order_id' ), 0, 4 ),
			array( $partial_refund_order->get_id(), $annual_order->get_id(), $mixed_order->get_id(), $expiring_order->get_id() ),
			'Annual rows sort Active by expiration ascending then Expiring Soon'
		);
		$all_operational_statuses = wp_list_pluck( $observer_report['rows'], 'operational_status' );
		oras_board_reports_assert_same( array_slice( $all_operational_statuses, 5, 2 ), array( Observer_Pass_Report_Service::STATUS_UPCOMING, Observer_Pass_Report_Service::STATUS_UPCOMING ), 'All Passes places future rows after current valid rows' );
		oras_board_reports_assert( ! in_array( true, array_slice( wp_list_pluck( $observer_report['rows'], 'is_valid' ), 7 ), true ), 'All Passes places historical rows after valid rows' );

		$unknown_booking_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $unknown_booking_order, new DateTimeImmutable( '2026-08-23 12:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $unknown_booking_order, '2026-08-28', '2026-08-29', 'awaiting-review' );
		$created_orders[] = $unknown_booking_order;

		$missing_date_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $missing_date_order, new DateTimeImmutable( '2026-08-23 11:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $missing_date_order, '', '' );
		$created_orders[] = $missing_date_order;

		$malformed_date_order = oras_board_reports_create_order( $daily_product_id, 1 );
		oras_board_reports_set_order_date( $malformed_date_order, new DateTimeImmutable( '2026-08-23 10:00:00', wp_timezone() ) );
		oras_board_reports_set_daily_dates( $malformed_date_order, '2026-02-30', '2026-03-02' );
		$created_orders[] = $malformed_date_order;

		$missing_holder_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $missing_holder_order, new DateTimeImmutable( '2026-08-02 12:00:00', wp_timezone() ) );
		$missing_holder_order->set_billing_first_name( '' );
		$missing_holder_order->set_billing_last_name( '' );
		$missing_holder_order->save();
		$created_orders[] = $missing_holder_order;

		$zero_quantity_order = oras_board_reports_create_order( $annual_product_id, 1 );
		$zero_quantity_item = current( $zero_quantity_order->get_items( 'line_item' ) );
		if ( ! $zero_quantity_item instanceof WC_Order_Item_Product ) {
			oras_board_reports_fail( 'Zero-quantity Observer line item missing' );
		}
		$zero_quantity_item->set_quantity( 0 );
		$zero_quantity_item->save();
		$created_orders[] = $zero_quantity_order;

		$dollar_refund_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $dollar_refund_order, new DateTimeImmutable( '2026-08-03 12:00:00', wp_timezone() ) );
		oras_board_reports_refund_order_amount( $dollar_refund_order, 20.0 );
		$created_orders[] = $dollar_refund_order;

		$edge_report = $observer_service->get_report();
		$unknown_booking_row = oras_board_reports_find_order_row( $edge_report['all_rows'], $unknown_booking_order );
		$missing_date_row = oras_board_reports_find_order_row( $edge_report['all_rows'], $missing_date_order );
		$malformed_date_row = oras_board_reports_find_order_row( $edge_report['all_rows'], $malformed_date_order );
		$missing_holder_row = oras_board_reports_find_order_row( $edge_report['all_rows'], $missing_holder_order );
		$zero_quantity_row = oras_board_reports_find_order_row( $edge_report['all_rows'], $zero_quantity_order );
		$dollar_refund_row = oras_board_reports_find_order_row( $edge_report['all_rows'], $dollar_refund_order );

		oras_board_reports_assert_same( $unknown_booking_row['operational_status'], Observer_Pass_Report_Service::STATUS_TODAY, 'Unknown booking retains its Today date classification' );
		oras_board_reports_assert_same( $unknown_booking_row['booking_status'], 'awaiting-review', 'Unknown booking source status remains available for the future UI' );
		oras_board_reports_assert_same( $unknown_booking_row['is_valid'], false, 'Unknown booking status is invalid' );
		oras_board_reports_assert( ! in_array( $unknown_booking_order->get_id(), wp_list_pluck( $edge_report['today_rows'], 'order_id' ), true ), 'Unknown booking is excluded from Today visitors' );
		oras_board_reports_assert_same( $edge_report['summary']['daily_today_count'], 2, 'Invalid current-date bookings are excluded from Today summary counts' );
		oras_board_reports_assert_same( $missing_date_row['operational_status'], Observer_Pass_Report_Service::STATUS_DATE_MISSING, 'Missing Daily dates remain auditable and invalid' );
		oras_board_reports_assert_same( $malformed_date_row['operational_status'], Observer_Pass_Report_Service::STATUS_DATE_MISSING, 'Malformed Daily dates remain auditable and invalid' );
		oras_board_reports_assert_same( $missing_holder_row['holder_names'], array(), 'Missing purchaser billing name produces no holder names' );
		oras_board_reports_assert_same( $missing_holder_row['purchaser_name'], '', 'Missing purchaser billing name does not substitute private email as identity' );
		oras_board_reports_assert_same( $zero_quantity_row['quantity'], 0, 'Zero source quantity is not fabricated as one pass' );
		oras_board_reports_assert_same( $zero_quantity_row['valid_quantity'], 0, 'Zero source quantity has zero valid quantity' );
		oras_board_reports_assert_same( $zero_quantity_row['is_valid'], false, 'Zero source quantity cannot become a valid pass' );
		oras_board_reports_assert_same( $dollar_refund_row['refunded_quantity'], 0, 'Dollar-only refund does not invent a refunded quantity' );
		oras_board_reports_assert_same( $dollar_refund_row['valid_quantity'], 1, 'Dollar-only refund leaves valid quantity unchanged' );
		oras_board_reports_assert_same( $dollar_refund_row['is_valid'], true, 'Dollar-only refund leaves pass validity unchanged' );
		foreach ( array( 'net_revenue', 'refund_amount', 'revenue_attribution', 'financial_ambiguity' ) as $financial_key ) {
			oras_board_reports_assert( ! array_key_exists( $financial_key, $dollar_refund_row ), 'Operational row excludes financial field ' . $financial_key );
		}

		$multi_line_order = wc_create_order();
		if ( ! $multi_line_order instanceof WC_Order ) {
			oras_board_reports_fail( 'Unable to create multi-line Observer order' );
		}
		$multi_line_order->add_product( wc_get_product( $annual_product_id ), 1 );
		$multi_line_order->add_product( wc_get_product( $daily_product_id ), 1 );
		$multi_line_order->add_product( wc_get_product( $daily_product_id ), 1 );
		$multi_line_order->add_product( wc_get_product( $merch_product_id ), 1 );
		oras_board_reports_add_contact_to_order( $multi_line_order );
		$multi_line_order->calculate_totals();
		$multi_line_order->update_status( 'completed' );
		oras_board_reports_set_order_date( $multi_line_order, new DateTimeImmutable( '2026-08-24 12:00:00', wp_timezone() ) );
		foreach ( $multi_line_order->get_items( 'line_item' ) as $multi_line_item ) {
			if ( $multi_line_item instanceof WC_Order_Item_Product && $daily_product_id === (int) $multi_line_item->get_product_id() ) {
				oras_board_reports_set_daily_item_dates( $multi_line_item, '2026-08-28', '2026-08-29' );
			}
		}
		$created_orders[] = $multi_line_order;

		$multi_line_report = $observer_service->get_report();
		$multi_line_rows = oras_board_reports_find_order_rows( $multi_line_report['all_rows'], $multi_line_order );
		oras_board_reports_assert_same( count( $multi_line_rows ), 3, 'Annual plus two Daily lines produce three Observer rows' );
		oras_board_reports_assert_same( count( array_unique( wp_list_pluck( $multi_line_rows, 'item_id' ) ) ), 3, 'Multiple Observer rows retain distinct line-item identities' );
		$multi_line_type_counts = array_count_values( wp_list_pluck( $multi_line_rows, 'pass_type' ) );
		oras_board_reports_assert_same( (int) ( $multi_line_type_counts['annual'] ?? 0 ), 1, 'Multi-line order preserves its Annual classification' );
		oras_board_reports_assert_same( (int) ( $multi_line_type_counts['daily'] ?? 0 ), 2, 'Multi-line order preserves both Daily classifications' );

		$leap_order = oras_board_reports_create_order( $annual_product_id, 1 );
		oras_board_reports_set_order_date( $leap_order, new DateTimeImmutable( '2024-02-29 12:00:00', wp_timezone() ) );
		$created_orders[] = $leap_order;
		$leap_boundary_report = ( new Observer_Pass_Report_Service( new DateTimeImmutable( '2025-01-30 00:00:00', wp_timezone() ) ) )->get_report();
		$leap_final_day_report = ( new Observer_Pass_Report_Service( new DateTimeImmutable( '2025-02-28 00:00:00', wp_timezone() ) ) )->get_report();
		$leap_expired_report = ( new Observer_Pass_Report_Service( new DateTimeImmutable( '2025-03-01 00:00:00', wp_timezone() ) ) )->get_report();
		$leap_boundary_row = oras_board_reports_find_order_row( $leap_boundary_report['all_rows'], $leap_order );
		$leap_final_day_row = oras_board_reports_find_order_row( $leap_final_day_report['all_rows'], $leap_order );
		$leap_expired_row = oras_board_reports_find_order_row( $leap_expired_report['all_rows'], $leap_order );
		oras_board_reports_assert_same( $leap_boundary_row['expiration_date'], '2025-03-01', 'February 29 pass expires at the start of March 1 in a non-leap year' );
		oras_board_reports_assert_same( $leap_boundary_row['operational_status'], Observer_Pass_Report_Service::STATUS_EXPIRING_SOON, 'February 29 pass enters Expiring Soon exactly 30 days before March 1' );
		oras_board_reports_assert_same( $leap_final_day_row['is_valid'], true, 'February 29 pass remains valid through February 28' );
		oras_board_reports_assert_same( $leap_expired_row['operational_status'], Observer_Pass_Report_Service::STATUS_EXPIRED, 'February 29 pass expires beginning March 1' );
		oras_board_reports_assert_same( $leap_expired_row['is_valid'], false, 'February 29 pass is invalid on March 1' );

		$original_timezone_string = (string) get_option( 'timezone_string', '' );
		try {
			update_option( 'timezone_string', 'America/New_York' );
			$timezone_order = oras_board_reports_create_order( $annual_product_id, 1 );
			oras_board_reports_set_order_date( $timezone_order, new DateTimeImmutable( '2026-08-29 03:30:00', new DateTimeZone( 'UTC' ) ) );
			$created_orders[] = $timezone_order;
			$timezone_service = new Observer_Pass_Report_Service( new DateTimeImmutable( '2026-08-28 00:00:00', wp_timezone() ) );
			$timezone_row = oras_board_reports_find_order_row( $timezone_service->get_report()['all_rows'], $timezone_order );
			oras_board_reports_assert_same( $timezone_row['purchase_date'], '2026-08-28', 'Order timestamp near UTC midnight uses the local purchase date' );
			oras_board_reports_assert_same( $timezone_row['expiration_date'], '2027-08-28', 'Normal local anniversary remains unchanged near UTC midnight' );
		} finally {
			update_option( 'timezone_string', $original_timezone_string );
		}

		$before_pagination_report = $observer_service->get_report();
		$pagination_orders = array();
		for ( $pagination_index = 0; $pagination_index < 51; ++$pagination_index ) {
			$pagination_order = oras_board_reports_create_order( $annual_product_id, 1 );
			oras_board_reports_set_order_date( $pagination_order, new DateTimeImmutable( '2026-01-01 12:00:00', wp_timezone() ) );
			$pagination_orders[] = $pagination_order;
			$created_orders[] = $pagination_order;
		}
		$after_pagination_report = $observer_service->get_report();
		$pagination_row_order_ids = array_map( 'intval', wp_list_pluck( $after_pagination_report['all_rows'], 'order_id' ) );
		$pagination_order_ids = array_map(
			static function ( WC_Order $order ): int {
				return $order->get_id();
			},
			$pagination_orders
		);
		oras_board_reports_assert_same( count( $after_pagination_report['all_rows'] ) - count( $before_pagination_report['all_rows'] ), 51, 'Observer scan includes all 51 orders beyond one WooCommerce page' );
		oras_board_reports_assert_same( $after_pagination_report['summary']['active_annual_count'] - $before_pagination_report['summary']['active_annual_count'], 51, 'Operational summary includes all 51 paged Annual quantities' );
		oras_board_reports_assert_same( array_values( array_diff( $pagination_order_ids, $pagination_row_order_ids ) ), array(), 'Every paged Observer order is present in the complete snapshot' );

		$payload = wp_json_encode( array( $ticket_rows, $observer_rows, $merch_rows, $observer_report['all_rows'] ) );
		oras_board_reports_assert( false === strpos( (string) $payload, 'forbidden-transaction' ), 'Board report rows exclude transaction IDs' );
		oras_board_reports_assert( false === strpos( (string) $payload, 'forbidden-stripe-intent' ), 'Board report rows exclude Stripe metadata' );
		oras_board_reports_assert( false === stripos( (string) $payload, 'payment_method' ), 'Board report rows exclude payment method fields' );

		$csv_values = ( new Board_Report_Exporter() )->row_to_csv_values( $ticket_rows[0] );
		$csv_payload = implode( ',', $csv_values );
		oras_board_reports_assert( false === strpos( $csv_payload, 'forbidden-transaction' ), 'CSV row excludes transaction IDs' );
		oras_board_reports_assert( false === strpos( $csv_payload, 'forbidden-stripe-intent' ), 'CSV row excludes Stripe metadata' );

		$attention_id = Event_Question_Attention_Store::upsert(
			array(
				'event_id'       => $event_id,
				'source_type'    => 'rsvp',
				'source_id'      => 'task4-' . $suffix,
				'question_id'    => 'task4-attention-question',
				'question_label' => 'Task 4 attention fixture',
				'answer_value'   => 'Needs review',
				'rule_id'        => 'task4-attention-rule',
				'rule_label'     => 'Task 4 review',
				'status'         => Event_Question_Attention_Store::STATUS_OPEN,
			)
		);
		oras_board_reports_assert( $attention_id > 0, 'Open event attention fixture created' );
		$created_attention_ids[] = $attention_id;

		wp_set_current_user( $subscriber_id );
		$_GET = array( 'oras_board_tab' => 'observer_passes' );
		$subscriber_html = do_shortcode( '[oras_board_reports]' );
		oras_board_reports_assert( false !== strpos( $subscriber_html, 'do not have permission' ), 'Unauthorized subscriber cannot access Observer Passes' );

		wp_set_current_user( $admin_id );
		$_GET = array(
			'oras_board_tab'          => 'observer_passes',
			'oras_board_event_id'     => $event_id,
			'oras_board_search'       => 'event-only-filter',
			'oras_observer_pass_type' => 'daily',
			'oras_observer_status'    => 'today',
		);
		$observer_html = do_shortcode( '[oras_board_reports]' );
		oras_board_reports_assert( false !== strpos( $observer_html, '>Observer Passes<' ), 'Observer Passes tab appears for authorized users' );
		foreach ( array( 'Event Overview', 'Sales', 'RSVP Management', 'Attention Needed', 'Communications', 'Roster' ) as $existing_tab_label ) {
			oras_board_reports_assert( false !== strpos( $observer_html, '>' . $existing_tab_label . '<' ), 'Existing tab remains present: ' . $existing_tab_label );
		}
		oras_board_reports_assert( false !== strpos( $observer_html, 'oras-board-reports__observer' ), 'Observer Passes routes to its dedicated renderer' );
		foreach (
			array(
				'Active Annual Passes',
				'Daily Passes Today',
				'Upcoming Daily Passes — Next 7 Days',
				'Upcoming Daily Passes — This Month',
				"Today's Daily Observers",
				'Active Annual Observer Passes',
			) as $required_observer_text
		) {
			oras_board_reports_assert( false !== strpos( $observer_html, esc_html( $required_observer_text ) ), 'Observer dashboard contains ' . $required_observer_text );
		}
		oras_board_reports_assert_same( substr_count( $observer_html, 'data-observer-summary=' ), 4, 'Observer dashboard renders exactly four summary cards' );
		oras_board_reports_assert( false === stripos( $observer_html, 'revenue' ), 'Observer dashboard has no revenue card or financial total' );
		oras_board_reports_assert( false === strpos( $observer_html, 'Load Event Dashboard' ), 'Observer Passes omits the event selector' );
		oras_board_reports_assert( false === strpos( $observer_html, '<section class="oras-board-reports__overview-grid"' ), 'Observer Passes omits event metrics' );
		oras_board_reports_assert( false === strpos( $observer_html, '<section class="oras-board-reports__attention-notice"' ), 'Observer Passes omits the event attention center' );
		oras_board_reports_assert( false !== strpos( $observer_html, 'Board Reports / Event Management Dashboard' ), 'Observer Passes retains the Board Reports title' );
		oras_board_reports_assert( false !== strpos( $observer_html, 'This report excludes payment method, transaction, card, and accounting details.' ), 'Observer Passes retains the privacy notice' );
		oras_board_reports_assert( false === strpos( $observer_html, 'Selected event:' ), 'Event ID parameters do not affect Observer Passes content' );

		$_GET = array(
			'oras_board_tab'            => 'overview',
			'oras_board_event_id'       => $event_id,
			'oras_observer_pass_type'   => 'daily',
			'oras_observer_status'      => 'today',
			'oras_observer_date_preset' => 'next_7',
			'oras_observer_search'      => 'must-not-leak',
			'oras_observer_page'        => '4',
			'oras_observer_per_page'    => '100',
		);
		$admin_html = do_shortcode( '[oras_board_reports]' );
		oras_board_reports_assert( false !== strpos( $admin_html, 'Board Reports' ), 'Authorized user can render board reports' );
		oras_board_reports_assert( false !== strpos( $admin_html, 'Load Event Dashboard' ), 'Existing event tabs retain their event selector' );
		oras_board_reports_assert( false !== strpos( $admin_html, '<section class="oras-board-reports__overview-grid"' ), 'Existing event tabs retain their event metrics' );
		oras_board_reports_assert( false !== strpos( $admin_html, '<section class="oras-board-reports__attention-notice"' ), 'Existing event tabs retain their event attention center' );
		oras_board_reports_assert( false !== strpos( $admin_html, 'Board Reports Event ' . $suffix ), 'Observer parameters do not alter the selected event' );

		$build_tab_url = new ReflectionMethod( Board_Reports::class, 'build_tab_url' );
		$_GET = array(
			'page_id'                   => '123',
			'oras_board_tab'            => 'overview',
			'oras_board_event_id'       => (string) $event_id,
			'oras_board_status'         => 'completed',
			'oras_attention_status'     => 'open',
			'oras_comm_segment'         => 'all_attendees',
			'oras_observer_pass_type'   => 'annual',
			'oras_observer_status'      => 'active',
			'oras_observer_date_preset' => 'this_year',
			'oras_observer_after'       => '2026-01-01',
			'oras_observer_before'      => '2026-12-31',
			'oras_observer_search'      => 'buyer',
			'oras_observer_page'        => '2',
			'oras_observer_per_page'    => '50',
		);
		$observer_url = (string) $build_tab_url->invoke( null, 'observer_passes' );
		parse_str( (string) wp_parse_url( $observer_url, PHP_URL_QUERY ), $observer_url_args );
		oras_board_reports_assert_same( $observer_url_args['oras_board_tab'] ?? '', 'observer_passes', 'Observer navigation uses the existing Board Reports route' );
		oras_board_reports_assert_same( $observer_url_args['page_id'] ?? '', '123', 'Observer navigation retains the shortcode page context' );
		foreach ( array( 'oras_board_event_id', 'oras_board_status', 'oras_attention_status', 'oras_comm_segment' ) as $event_arg ) {
			oras_board_reports_assert( ! isset( $observer_url_args[ $event_arg ] ), 'Observer navigation removes event parameter ' . $event_arg );
		}

		$event_url = (string) $build_tab_url->invoke( null, 'overview' );
		parse_str( (string) wp_parse_url( $event_url, PHP_URL_QUERY ), $event_url_args );
		oras_board_reports_assert_same( $event_url_args['oras_board_event_id'] ?? '', (string) $event_id, 'Event navigation retains its event ID' );
		foreach ( array_keys( $_GET ) as $query_arg ) {
			if ( 0 === strpos( $query_arg, 'oras_observer_' ) ) {
				oras_board_reports_assert( ! isset( $event_url_args[ $query_arg ] ), 'Event navigation removes Observer parameter ' . $query_arg );
			}
		}

		$default_observer_filters = array(
			'pass_type'   => 'all',
			'status'      => 'all',
			'date_preset' => 'all',
			'after'       => '',
			'before'      => '',
			'search'      => '',
			'page'        => 1,
			'per_page'    => 25,
		);
		$base_dashboard_html = oras_board_reports_render_observer_dashboard( $observer_report, $default_observer_filters );
		foreach ( $observer_report['summary'] as $summary_key => $summary_value ) {
			oras_board_reports_assert( false !== strpos( $base_dashboard_html, 'data-observer-summary="' . $summary_key . '" data-value="' . $summary_value . '"' ), 'Summary card renders complete-snapshot value for ' . $summary_key );
		}
		$annual_dashboard_html = oras_board_reports_render_observer_dashboard(
			$annual_report,
			array_merge( $default_observer_filters, array( 'pass_type' => 'annual' ) )
		);
		$annual_list_start = strpos( $annual_dashboard_html, 'id="oras-active-annual-list"' );
		$annual_list_end = false !== $annual_list_start ? strpos( $annual_dashboard_html, '</section>', $annual_list_start ) : false;
		$annual_list_html = false !== $annual_list_start && false !== $annual_list_end ? substr( $annual_dashboard_html, $annual_list_start, $annual_list_end - $annual_list_start ) : '';
		oras_board_reports_assert( false !== strpos( $annual_list_html, 'board.buyer@example.org' ), 'Active Annual verification displays email when present' );
		oras_board_reports_assert( false !== strpos( $annual_list_html, 'data-search="board buyer board buyer board.buyer@example.org"' ), 'Active Annual instant search indexes email' );
		foreach ( $observer_report['summary'] as $summary_key => $summary_value ) {
			oras_board_reports_assert( false !== strpos( $annual_dashboard_html, 'data-observer-summary="' . $summary_key . '" data-value="' . $summary_value . '"' ), 'Summary card remains invariant under filtering for ' . $summary_key );
		}
		oras_board_reports_assert( false !== strpos( $annual_dashboard_html, 'data-pass-type="annual"' ), 'Pass-type filter renders Annual rows' );
		oras_board_reports_assert( false === strpos( $annual_dashboard_html, 'data-pass-type="daily"' ), 'Pass-type filter excludes Daily main-table rows' );

		oras_board_reports_assert( false !== strpos( $base_dashboard_html, 'name="oras_observer_pass_type"' ), 'Observer filter form uses namespaced pass type' );
		oras_board_reports_assert( false !== strpos( $base_dashboard_html, 'name="oras_observer_status"' ), 'Observer filter form uses namespaced status' );
		oras_board_reports_assert( false !== strpos( $base_dashboard_html, 'name="oras_observer_date_preset"' ), 'Observer filter form uses namespaced operational date' );
		oras_board_reports_assert( false !== strpos( $base_dashboard_html, 'name="oras_observer_after"' ), 'Observer filter form includes a namespaced From date' );
		oras_board_reports_assert( false !== strpos( $base_dashboard_html, 'name="oras_observer_before"' ), 'Observer filter form includes a namespaced To date' );
		oras_board_reports_assert( false !== strpos( $base_dashboard_html, 'name="oras_observer_search"' ), 'Observer filter form uses namespaced search' );
		oras_board_reports_assert(
			false === strpos( $base_dashboard_html, 'name="oras_observer_per_page"' ),
			'Observer filter form omits page-size controls'
		);

		$get_observer_filters = new ReflectionMethod( Board_Reports::class, 'get_observer_filters_from_request' );
		$_GET = array(
			'oras_observer_pass_type'   => 'daily',
			'oras_observer_status'      => 'today',
			'oras_observer_date_preset' => 'custom',
			'oras_observer_after'       => '2026-08-01',
			'oras_observer_before'      => 'not-a-date',
			'oras_observer_search'      => ' Board Buyer ',
			'oras_observer_page'        => '0',
			'oras_observer_per_page'    => '77',
		);
		$parsed_observer_filters = $get_observer_filters->invoke( null );
		oras_board_reports_assert_same( $parsed_observer_filters['pass_type'], 'daily', 'Observer pass-type request filter is parsed' );
		oras_board_reports_assert_same( $parsed_observer_filters['status'], 'today', 'Observer status request filter is parsed' );
		oras_board_reports_assert_same( $parsed_observer_filters['date_preset'], 'custom', 'Observer date-preset request filter is parsed' );
		oras_board_reports_assert_same( $parsed_observer_filters['after'], '2026-08-01', 'Valid Observer custom From date is retained' );
		oras_board_reports_assert_same( $parsed_observer_filters['before'], '', 'Invalid Observer custom To date is rejected' );
		oras_board_reports_assert_same( $parsed_observer_filters['search'], 'Board Buyer', 'Observer search is sanitized' );
		oras_board_reports_assert_same( $parsed_observer_filters['page'], 1, 'Observer page cannot be below one' );
		oras_board_reports_assert_same( $parsed_observer_filters['per_page'], 25, 'Unsupported Observer page size falls back safely' );

		$today_order_id = (string) $daily_order->get_id();
		$edge_dashboard_html = oras_board_reports_render_observer_dashboard( $edge_report, $default_observer_filters );
		oras_board_reports_assert( false !== strpos( $edge_dashboard_html, 'data-observer-today-order="' . $today_order_id . '"' ), 'Today list includes a valid Daily Observer pass' );
		oras_board_reports_assert( false === strpos( $edge_dashboard_html, 'data-observer-today-order="' . $unknown_booking_order->get_id() . '"' ), 'Today list excludes unknown booking status' );
		oras_board_reports_assert( false === strpos( $edge_dashboard_html, 'data-observer-today-order="' . $booking_cancelled_order->get_id() . '"' ), 'Today list excludes cancelled Daily booking' );
		oras_board_reports_assert( false !== strpos( $edge_dashboard_html, 'data-observer-annual-order="' . $annual_order->get_id() . '"' ), 'Active Annual verification includes Active pass' );
		oras_board_reports_assert( false !== strpos( $edge_dashboard_html, 'data-observer-annual-order="' . $expiring_order->get_id() . '"' ), 'Active Annual verification includes Expiring Soon pass' );
		oras_board_reports_assert( false === strpos( $edge_dashboard_html, 'data-observer-annual-order="' . $expired_order->get_id() . '"' ), 'Active Annual verification excludes Expired pass' );
		oras_board_reports_assert( false !== strpos( $edge_dashboard_html, 'data-oras-observer-annual-search' ), 'Active Annual verification includes instant search' );
		oras_board_reports_assert( false !== strpos( $edge_dashboard_html, 'aria-live="polite" data-oras-observer-annual-status' ), 'Active Annual verification includes an accessible live count' );
		oras_board_reports_assert( false !== strpos( $edge_dashboard_html, 'data-observer-annual-order="' . $missing_holder_order->get_id() . '"' ) && false !== strpos( $edge_dashboard_html, 'Not recorded' ), 'Missing purchaser name renders Not recorded' );

		preg_match( '/href="([^"]+)"[^>]*data-oras-observer-print/', $edge_dashboard_html, $print_url_match );
		$print_url = isset( $print_url_match[1] ) ? html_entity_decode( $print_url_match[1], ENT_QUOTES ) : '';
		parse_str( (string) wp_parse_url( $print_url, PHP_URL_QUERY ), $print_url_args );
		oras_board_reports_assert( false !== strpos( $edge_dashboard_html, esc_html__( "Print Today's List", 'oras-tickets' ) ), 'Today list exposes the print action when valid observers exist' );
		oras_board_reports_assert( false !== strpos( (string) wp_parse_url( $print_url, PHP_URL_PATH ), '/wp-admin/admin-post.php' ), 'Today print action targets authenticated admin-post.php' );
		oras_board_reports_assert_same( $print_url_args['action'] ?? '', 'oras_board_reports_print_observers_today', 'Today print URL uses the dedicated action' );
		oras_board_reports_assert( isset( $print_url_args['_wpnonce'] ) && 1 === wp_verify_nonce( (string) $print_url_args['_wpnonce'], 'oras_board_reports_print_observers_today' ), 'Today print URL contains a valid dedicated nonce' );
		foreach ( array( 'rows', 'names', 'order_ids', 'oras_observer_search', 'oras_observer_page' ) as $untrusted_print_arg ) {
			oras_board_reports_assert( ! isset( $print_url_args[ $untrusted_print_arg ] ), 'Today print URL excludes browser-supplied report data: ' . $untrusted_print_arg );
		}

		$valid_alpha_later = array_merge(
			$daily_row,
			array(
				'purchaser_name' => 'Alpha Visitor',
				'order_id'       => 7001,
				'order_number'   => '7001',
				'quantity'       => 1,
				'valid_quantity' => 1,
				'email'          => 'alpha.private@example.org',
				'phone'          => '555-7001',
				'payment_method' => 'forbidden-gateway',
			)
		);
		$valid_alpha_first = array_merge( $valid_alpha_later, array( 'order_id' => 7000, 'order_number' => '7000' ) );
		$valid_partial = array_merge(
			$daily_row,
			array(
				'purchaser_name' => 'Zulu Visitor',
				'order_id'       => 7002,
				'order_number'   => '7002',
				'quantity'       => 3,
				'valid_quantity' => 2,
				'refunded_quantity' => 1,
				'transaction_id' => 'forbidden-transaction',
			)
		);
		$valid_missing_name = array_merge(
			$daily_row,
			array(
				'purchaser_name' => '',
				'order_id'       => 7003,
				'order_number'   => '7003',
				'quantity'       => 1,
				'valid_quantity' => 1,
			)
		);
		$excluded_print_rows = array(
			array_merge( $daily_row, array( 'purchaser_name' => 'Upcoming Excluded', 'operational_status' => Observer_Pass_Report_Service::STATUS_UPCOMING ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Past Excluded', 'operational_status' => Observer_Pass_Report_Service::STATUS_PAST, 'is_valid' => false ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Unknown Excluded', 'booking_status' => 'awaiting-review', 'is_valid' => false ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Cancelled Excluded', 'operational_status' => Observer_Pass_Report_Service::STATUS_CANCELLED, 'is_valid' => false ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Refunded Excluded', 'operational_status' => Observer_Pass_Report_Service::STATUS_REFUNDED, 'is_valid' => false ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Failed Excluded', 'operational_status' => Observer_Pass_Report_Service::STATUS_FAILED, 'is_valid' => false ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Unpaid Excluded', 'operational_status' => Observer_Pass_Report_Service::STATUS_UNPAID, 'is_valid' => false ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Missing Date Excluded', 'operational_status' => Observer_Pass_Report_Service::STATUS_DATE_MISSING, 'is_valid' => false ) ),
			array_merge( $daily_row, array( 'purchaser_name' => 'Zero Quantity Excluded', 'valid_quantity' => 0 ) ),
			array_merge( $annual_row, array( 'purchaser_name' => 'Annual Excluded' ) ),
		);
		$print_builder = new ReflectionMethod( Board_Reports::class, 'build_observer_print_document' );
		$print_today = new DateTimeImmutable( '2026-08-29 12:00:00', new DateTimeZone( 'America/New_York' ) );
		$print_document = (string) $print_builder->invoke(
			null,
			array_merge( $excluded_print_rows, array( $valid_partial, $valid_alpha_later, $valid_missing_name, $valid_alpha_first ) ),
			$print_today
		);
		$print_text = html_entity_decode( wp_strip_all_tags( $print_document ), ENT_QUOTES | ENT_HTML5 );
		oras_board_reports_assert( 0 === strpos( $print_document, '<!doctype html>' ), 'Print output is a standalone HTML document' );
		oras_board_reports_assert( false !== strpos( $print_text, "Today's Daily Observers" ), 'Print output contains the operational title' );
		oras_board_reports_assert( false !== strpos( $print_document, wp_date( get_option( 'date_format' ), $print_today->getTimestamp(), $print_today->getTimezone() ) ), 'Print date uses the supplied WordPress-local instant' );
		foreach ( array( 'Alpha Visitor', 'Zulu Visitor', 'Not recorded', '#7000', '#7001', '#7002', '#7003' ) as $expected_print_value ) {
			oras_board_reports_assert( false !== strpos( $print_document, $expected_print_value ), 'Print output contains allowed operational value: ' . $expected_print_value );
		}
		oras_board_reports_assert( strpos( $print_document, '#7000' ) < strpos( $print_document, '#7001' ) && strpos( $print_document, '#7001' ) < strpos( $print_document, 'Zulu Visitor' ), 'Print rows sort by identity with stable order-ID tie-breaker' );
		oras_board_reports_assert( false !== strpos( $print_document, '<td class="quantity">2</td>' ), 'Partial quantity refund prints only remaining valid quantity' );
		oras_board_reports_assert( false !== strpos( $print_document, 'Total valid Daily passes: 5' ), 'Print total sums valid quantity rather than row count' );
		foreach ( array( 'Upcoming Excluded', 'Past Excluded', 'Unknown Excluded', 'Cancelled Excluded', 'Refunded Excluded', 'Failed Excluded', 'Unpaid Excluded', 'Missing Date Excluded', 'Zero Quantity Excluded', 'Annual Excluded' ) as $excluded_print_value ) {
			oras_board_reports_assert( false === strpos( $print_document, $excluded_print_value ), 'Print output excludes ineligible row: ' . $excluded_print_value );
		}
		foreach ( array( 'alpha.private@example.org', '555-7001', 'forbidden-gateway', 'forbidden-transaction', 'wpadminbar', 'oras-board-reports__tabs', 'Filter Observer Passes', 'Active Annual Observer Passes' ) as $private_print_value ) {
			oras_board_reports_assert( false === stripos( $print_document, $private_print_value ), 'Print output excludes private or unrelated content: ' . $private_print_value );
		}
		oras_board_reports_assert( false !== strpos( $print_document, '@media print' ) && false !== strpos( $print_document, 'window.print()' ), 'Print document provides print-safe CSS and an explicit print control' );
		$print_media_position = strpos( $print_document, '@media print' );
		$hidden_controls_position = false !== $print_media_position ? strpos( $print_document, '.observer-print__controls { display: none !important; }', $print_media_position ) : false;
		oras_board_reports_assert( false !== $hidden_controls_position, 'Print and Back controls are hidden by the print-media rule' );
		$empty_print_document = (string) $print_builder->invoke( null, array(), $print_today );
		oras_board_reports_assert( false !== strpos( $empty_print_document, 'No Daily Observers scheduled for today.' ), 'Empty Today print list renders a safe operational state' );
		$translate_not_recorded = static function ( string $translation, string $text, string $domain ): string {
			return 'oras-tickets' === $domain && 'Not recorded' === $text ? 'Nicht erfasst' : $translation;
		};
		add_filter( 'gettext', $translate_not_recorded, 10, 3 );
		try {
			$missing_order_document = (string) $print_builder->invoke(
				null,
				array( array_merge( $daily_row, array( 'purchaser_name' => 'Missing Order Visitor', 'order_number' => '' ) ) ),
				$print_today
			);
		} finally {
			remove_filter( 'gettext', $translate_not_recorded, 10 );
		}
		oras_board_reports_assert( false !== strpos( $missing_order_document, 'Nicht erfasst' ) && false === strpos( $missing_order_document, '#Nicht erfasst' ), 'Missing order fallback remains correct when translated' );

		wp_set_current_user( $subscriber_id );
		$subscriber_print_nonce = wp_create_nonce( 'oras_board_reports_print_observers_today' );
		$_GET = array( '_wpnonce' => $subscriber_print_nonce );
		$_POST = array();
		$_REQUEST = $_GET;
		$unauthorized_print_error = oras_board_reports_capture_wp_die(
			static function (): void {
				Board_Reports::handle_print_observers_today();
			}
		);
		oras_board_reports_assert( false !== strpos( $unauthorized_print_error, 'do not have permission' ), 'Unauthorized print request with a valid nonce is rejected' );
		$_GET = array( '_wpnonce' => 'invalid' );
		$_REQUEST = $_GET;
		$authorization_order_error = oras_board_reports_capture_wp_die(
			static function (): void {
				Board_Reports::handle_print_observers_today();
			}
		);
		oras_board_reports_assert( false !== strpos( $authorization_order_error, 'do not have permission' ), 'Print capability is checked before nonce validation' );

		wp_set_current_user( $admin_id );
		$_GET = array( '_wpnonce' => 'invalid' );
		$_REQUEST = $_GET;
		$invalid_nonce_error = oras_board_reports_capture_wp_die(
			static function (): void {
				Board_Reports::handle_print_observers_today();
			}
		);
		oras_board_reports_assert( '' !== $invalid_nonce_error, 'Authorized print request with invalid nonce is rejected' );
		$_GET = array();
		$_REQUEST = array();
		$missing_nonce_error = oras_board_reports_capture_wp_die(
			static function (): void {
				Board_Reports::handle_print_observers_today();
			}
		);
		oras_board_reports_assert( '' !== $missing_nonce_error, 'Authorized print request with missing nonce is rejected' );

		$prepare_print = new ReflectionMethod( Board_Reports::class, 'prepare_observer_print_response' );
		$fresh_print_today = current_datetime()->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );
		$fresh_print_order = oras_board_reports_create_order( $daily_product_id, 1 );
		$fresh_print_order->set_billing_first_name( '' );
		$fresh_print_order->set_billing_last_name( '' );
		$fresh_print_order->set_billing_email( 'fresh.private@example.org' );
		$fresh_print_order->save();
		oras_board_reports_set_daily_dates(
			$fresh_print_order,
			$fresh_print_today->format( 'Y-m-d' ),
			$fresh_print_today->modify( '+1 day' )->format( 'Y-m-d' )
		);
		$created_orders[] = $fresh_print_order;
		$_GET = array(
			'_wpnonce' => wp_create_nonce( 'oras_board_reports_print_observers_today' ),
			'rows'     => 'Injected Visitor',
			'order_id' => '999999',
		);
		$_REQUEST = $_GET;
		$prepared_print_response = $prepare_print->invoke( null );
		oras_board_reports_assert_same( $prepared_print_response['status'], 200, 'Authorized user with valid nonce receives a print response' );
		oras_board_reports_assert(
			false !== strpos( $prepared_print_response['document'], 'Not recorded' )
			&& false !== strpos( $prepared_print_response['document'], '#' . $fresh_print_order->get_order_number() ),
			'Print request obtains a fresh unfiltered Observer snapshot with a safe missing-identity fallback'
		);
		oras_board_reports_assert( false === strpos( $prepared_print_response['document'], 'fresh.private@example.org' ), 'Service-produced print rows never substitute private email as identity' );
		oras_board_reports_assert( false === strpos( $prepared_print_response['document'], 'Injected Visitor' ) && false === strpos( $prepared_print_response['document'], '999999' ), 'Query-string values cannot inject arbitrary print rows' );

		$print_scan_failure = static function ( array $ids ): array {
			throw new RuntimeException( 'Intentional private print failure' );
		};
		add_filter( 'oras_tickets_observer_daily_product_ids', $print_scan_failure, 999 );
		$_GET = array( '_wpnonce' => wp_create_nonce( 'oras_board_reports_print_observers_today' ) );
		$_REQUEST = $_GET;
		try {
			$failed_print_response = $prepare_print->invoke( null );
		} finally {
			remove_filter( 'oras_tickets_observer_daily_product_ids', $print_scan_failure, 999 );
		}
		oras_board_reports_assert_same( $failed_print_response['status'], 503, 'Observer print service failure fails closed with an unavailable response' );
		oras_board_reports_assert( false !== strpos( $failed_print_response['document'], 'Observer Pass reporting is currently unavailable.' ), 'Observer print service failure shows a generic safe message' );
		oras_board_reports_assert( false === strpos( $failed_print_response['document'], 'Intentional private print failure' ), 'Observer print failure does not expose exception details' );
		$_POST = $original_post;
		$_REQUEST = $original_request;
		wp_set_current_user( $admin_id );

		$empty_quick_lists_report = $edge_report;
		$empty_quick_lists_report['today_rows'] = array();
		$empty_quick_lists_report['active_annual_rows'] = array();
		$empty_quick_lists_html = oras_board_reports_render_observer_dashboard( $empty_quick_lists_report, $default_observer_filters );
		oras_board_reports_assert( false !== strpos( $empty_quick_lists_html, 'No Daily Observers scheduled for today.' ), 'Today quick list has a specific empty state' );
		oras_board_reports_assert( false === strpos( $empty_quick_lists_html, 'data-oras-observer-print' ), 'Today quick list omits the print action when there are no valid observers' );
		oras_board_reports_assert( false !== strpos( $empty_quick_lists_html, 'No active Annual Observer Passes found.' ), 'Active Annual quick list has a specific empty state' );

		$expiring_dashboard_html = oras_board_reports_render_observer_dashboard(
			$expiring_report,
			array_merge( $default_observer_filters, array( 'status' => 'expiring_soon' ) )
		);
		oras_board_reports_assert_same( substr_count( $expiring_dashboard_html, 'data-observer-row=' ), count( $expiring_report['rows'] ), 'Status filter controls main-table rows' );
		$today_dashboard_html = oras_board_reports_render_observer_dashboard(
			$today_report,
			array_merge( $default_observer_filters, array( 'date_preset' => 'today' ) )
		);
		oras_board_reports_assert_same( substr_count( $today_dashboard_html, 'data-observer-row=' ), count( $today_report['rows'] ), 'Date preset controls main-table rows' );
		$custom_dashboard_html = oras_board_reports_render_observer_dashboard(
			$custom_report,
			array_merge(
				$default_observer_filters,
				array(
					'date_preset' => 'custom',
					'after'       => '2026-09-01',
					'before'      => '2026-09-30',
				)
			)
		);
		oras_board_reports_assert_same( substr_count( $custom_dashboard_html, 'data-observer-row=' ), count( $custom_report['rows'] ), 'Custom date range controls main-table rows' );
		$order_dashboard_html = oras_board_reports_render_observer_dashboard(
			$order_report,
			array_merge( $default_observer_filters, array( 'search' => (string) $daily_order->get_order_number() ) )
		);
		oras_board_reports_assert_same( substr_count( $order_dashboard_html, 'data-observer-row=' ), 1, 'Search controls main-table rows' );
		oras_board_reports_assert( false !== strpos( $order_dashboard_html, (string) $daily_order->get_order_number() ), 'Search result shows matching order number' );

		$main_table_report = $observer_report;
		$main_table_report['rows'] = array( $annual_row, $daily_row );
		$main_table_html = oras_board_reports_render_observer_dashboard( $main_table_report, $default_observer_filters );
		oras_board_reports_assert( false !== strpos( $main_table_html, 'data-pass-type="annual"' ) && false !== strpos( $main_table_html, '2027-08-01' ), 'Main table renders Annual expiration' );
		oras_board_reports_assert( false !== strpos( $main_table_html, 'data-pass-type="daily"' ) && false !== strpos( $main_table_html, '2026-08-28' ) && false !== strpos( $main_table_html, '2026-08-29' ), 'Main table renders Daily observing range' );
		foreach ( array( 'Purchaser / Passholder', 'Pass Type', 'Source', 'Valid Date / Expiration', 'Qty', 'Status' ) as $column_label ) {
			oras_board_reports_assert( false !== strpos( $main_table_html, $column_label ), 'Main table contains column ' . $column_label );
		}
		foreach ( array( 'Purchase Date', 'Order', 'Details' ) as $column_label ) {
			oras_board_reports_assert( false === strpos( $main_table_html, '<th scope="col">' . $column_label . '</th>' ), 'Main table omits detail column ' . $column_label );
		}
		oras_board_reports_assert( false !== strpos( $main_table_html, '<dialog id="oras-observer-' ), 'Main table rows render native detail dialogs' );
		oras_board_reports_assert( false !== strpos( $main_table_html, 'data-observer-dialog-trigger=' ), 'Main table rows are keyboard-activatable dialog triggers' );
		foreach ( array( 'Email', 'Phone', 'WooCommerce status', 'Booking status', 'Validity' ) as $detail_label ) {
			oras_board_reports_assert( false !== strpos( $main_table_html, $detail_label ), 'Observer dialog contains ' . $detail_label );
		}
		oras_board_reports_assert( false === strpos( $main_table_html, 'post.php?post=' ), 'Observer details do not link to WooCommerce order editing' );
		oras_board_reports_assert( false === stripos( $main_table_html, 'payment_method' ), 'Observer details exclude payment method fields' );
		oras_board_reports_assert( false === strpos( $main_table_html, 'forbidden-transaction' ) && false === strpos( $main_table_html, 'forbidden-stripe-intent' ), 'Observer details exclude transaction metadata' );

		$state_report = $observer_report;
		$state_report['rows'] = array( $partial_row, $fully_refunded_row, $cancelled_row, $failed_row, $dollar_refund_row, $unknown_booking_row );
		$state_dashboard_html = oras_board_reports_render_observer_dashboard( $state_report, $default_observer_filters );
		foreach ( array( 'Refunded', 'Cancelled', 'Failed' ) as $explicit_status ) {
			oras_board_reports_assert( false !== strpos( $state_dashboard_html, '>' . $explicit_status . '<' ), 'Historical state is visibly explicit: ' . $explicit_status );
		}
		oras_board_reports_assert( false !== strpos( $state_dashboard_html, '1 of 2 valid' ), 'Attributable partial refund displays valid quantity' );
		oras_board_reports_assert( false !== strpos( $state_dashboard_html, 'data-observer-order="' . $dollar_refund_order->get_id() . '"' ) && false !== strpos( $state_dashboard_html, '>1<' ), 'Dollar-only refund leaves visible valid quantity unchanged' );
		oras_board_reports_assert( false !== strpos( $state_dashboard_html, 'Today — Unconfirmed' ), 'Unknown Daily booking status is visibly invalid or unconfirmed' );

		$continuous_observer_html = oras_board_reports_render_observer_dashboard(
			$after_pagination_report,
			array_merge(
				$default_observer_filters,
				array(
					'page'     => 99,
					'per_page' => 25,
				)
			)
		);
		oras_board_reports_assert_same(
			substr_count( $continuous_observer_html, 'data-observer-row=' ),
			count( $after_pagination_report['rows'] ),
			'Observer roster renders every filtered row without pagination'
		);
		oras_board_reports_assert(
			false === strpos( $continuous_observer_html, 'Page 1 of ' )
			&& false === strpos( $continuous_observer_html, '>Previous</a>' )
			&& false === strpos( $continuous_observer_html, '>Next</a>' ),
			'Observer roster omits pagination navigation'
		);
		$combined_filters = array_merge(
			$default_observer_filters,
			array(
				'pass_type' => 'annual',
				'status'    => 'active',
				'search'    => 'Board Buyer',
			)
		);
		$combined_filter_report = $observer_service->get_report( $combined_filters );
		$combined_filter_html = oras_board_reports_render_observer_dashboard( $combined_filter_report, $combined_filters );
		oras_board_reports_assert_same(
			substr_count( $combined_filter_html, 'data-observer-row=' ),
			count( $combined_filter_report['rows'] ),
			'Continuous Observer roster respects combined filters'
		);

		$empty_filtered_report = $observer_report;
		$empty_filtered_report['rows'] = array();
		$empty_filtered_html = oras_board_reports_render_observer_dashboard( $empty_filtered_report, array_merge( $default_observer_filters, array( 'search' => 'no-match' ) ) );
		oras_board_reports_assert( false !== strpos( $empty_filtered_html, 'No Observer Passes match these filters.' ), 'Empty filtered result has a useful message' );

		$empty_observer_html = oras_board_reports_render_observer_dashboard(
			array(
				'available' => true,
				'all_rows'  => array(),
				'rows'      => array(),
			),
			$default_observer_filters
		);
		oras_board_reports_assert( false !== strpos( $empty_observer_html, 'No Observer Pass records found.' ), 'Empty Observer data renders a safe board-facing state' );

		$observer_scan_failure = static function ( array $ids ): array {
			throw new RuntimeException( 'Intentional Observer Pass integration failure' );
		};
		add_filter( 'oras_tickets_observer_annual_product_ids', $observer_scan_failure, 99 );
		$_GET = array( 'oras_board_tab' => 'observer_passes' );
		try {
			$failed_observer_html = do_shortcode( '[oras_board_reports]' );
		} finally {
			remove_filter( 'oras_tickets_observer_annual_product_ids', $observer_scan_failure, 99 );
		}
		oras_board_reports_assert( false !== strpos( $failed_observer_html, 'Observer Pass reporting is currently unavailable.' ), 'Reporting-service failure fails closed with a safe board-facing state' );
		oras_board_reports_assert( false === stripos( $failed_observer_html, 'Intentional Observer Pass integration failure' ), 'Observer failure does not expose exception details' );
	} finally {
		$_GET = $original_get;
		$_POST = $original_post;
		$_REQUEST = $original_request;
		global $wpdb;
		foreach ( $created_attention_ids as $attention_id ) {
			$wpdb->delete( Event_Question_Attention_Store::table_name(), array( 'id' => $attention_id ), array( '%d' ) );
		}
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
