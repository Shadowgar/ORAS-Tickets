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

function oras_board_reports_run_checks(): void {
	if ( ! defined( 'ORAS_TICKETS_DIR' ) ) {
		oras_board_reports_fail( 'ORAS_TICKETS_DIR not defined. Ensure oras-tickets plugin is active.' );
	}

	require_once ORAS_TICKETS_DIR . 'includes/Capabilities.php';
	require_once ORAS_TICKETS_DIR . 'includes/Frontend/Board_Reports.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Contact_Normalizer.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Exporter.php';
	require_once ORAS_TICKETS_DIR . 'includes/Reporting/Board_Report_Service.php';

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
		$created_posts[] = $ticket_product_id;
		$created_posts[] = $observer_product_id;
		$created_posts[] = $merch_product_id;

		$ticket_order = oras_board_reports_create_order( $ticket_product_id, 2 );
		$ticket_item = current( $ticket_order->get_items( 'line_item' ) );
		if ( ! $ticket_item instanceof WC_Order_Item_Product ) {
			oras_board_reports_fail( 'Ticket order line item missing' );
		}
		$ticket_item->update_meta_data( '_oras_ticket_event_id', (int) $event_id );
		$ticket_item->update_meta_data( '_oras_ticket_index', '0' );
		$ticket_item->update_meta_data( '_oras_ticket_name', 'General Admission' );
		$ticket_item->save();
		$created_posts[] = (int) $ticket_order->get_id();

		$observer_order = oras_board_reports_create_order( $observer_product_id, 1 );
		$merch_order = oras_board_reports_create_order( $merch_product_id, 3 );
		$created_posts[] = (int) $observer_order->get_id();
		$created_posts[] = (int) $merch_order->get_id();

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

		$payload = wp_json_encode( array( $ticket_rows, $observer_rows, $merch_rows ) );
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
