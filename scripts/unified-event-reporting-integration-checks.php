<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Guarded disposable integration executable.
/**
 * Unified event reporting integration checks.
 *
 * Runs inside the verified disposable wp-env instance via the companion shell
 * runner. Fixtures are removed before the script exits.
 */

use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Registration_Desk\Attendance_Store;
use ORAS\Tickets\Registration_Desk\Attendee_Store;
use ORAS\Tickets\Registration_Desk\Offline_Membership_Store;
use ORAS\Tickets\Registration_Desk\Registration_Store;
use ORAS\Tickets\Registration_Desk\Schema;
use ORAS\Tickets\Reporting\Board_Report_Service;
use ORAS\Tickets\Reporting\Membership_Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Unified_Event_Report_Check_Exception extends RuntimeException {}

function oras_unified_event_report_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new Oras_Unified_Event_Report_Check_Exception( $message );
	}

	echo 'PASS: ' . $message . "\n";
}

function oras_unified_event_report_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new Oras_Unified_Event_Report_Check_Exception(
			$message . ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual )
		);
	}

	echo 'PASS: ' . $message . "\n";
}

/** @return array<string,mixed> */
function oras_unified_event_report_row( array $rows, callable $matches, string $message ): array {
	foreach ( $rows as $row ) {
		if ( $matches( $row ) ) {
			return $row;
		}
	}

	throw new Oras_Unified_Event_Report_Check_Exception( $message );
}

function oras_unified_event_reporting_run(): void {
	global $wpdb;
	Schema::maybe_upgrade();
	Board_Reports::register();
	$keep_fixture = '1' === (string) get_option( 'oras_unified_event_reporting_keep_fixture', '0' );
	delete_option( 'oras_unified_event_reporting_keep_fixture' );

	$admin_ids = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ids',
			'number' => 1,
		)
	);
	oras_unified_event_report_assert( ! empty( $admin_ids ), 'Administrator fixture exists' );
	$admin_id = (int) $admin_ids[0];
	$suffix   = gmdate( 'YmdHis' ) . '-' . wp_rand( 1000, 9999 );
	$today    = wp_date( 'Y-m-d', null, wp_timezone() );
	$event_id = 0;
	$product_id = 0;
	$order = null;
	$registration_ids = array();
	$membership_id = 0;
	$page_id = 0;
	$fixture_ready = false;
	$original_get = $_GET;

	try {
		$event_id = wp_insert_post(
			array(
				'post_type'   => 'tribe_events',
				'post_status' => 'publish',
				'post_title'  => 'Unified Event Reporting ' . $suffix,
			)
		);
		oras_unified_event_report_assert( is_int( $event_id ) && $event_id > 0, 'Disposable event created' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Basic Ticket' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product_id = $product->save();
		oras_unified_event_report_assert( is_int( $product_id ) && $product_id > 0, 'Canonical Basic Ticket product created' );

		$order = wc_create_order();
		oras_unified_event_report_assert( $order instanceof WC_Order, 'Website ticket order created' );
		$order->set_billing_first_name( 'Website' );
		$order->set_billing_last_name( 'Attendee' );
		$order->set_billing_email( 'website.' . $suffix . '@example.test' );
		$order->set_billing_phone( '814-555-0101' );
		$item_id = $order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->update_status( 'completed' );
		$item = $order->get_item( $item_id );
		oras_unified_event_report_assert( $item instanceof WC_Order_Item_Product, 'Website ticket line item created' );
		$item->update_meta_data( '_oras_ticket_event_id', $event_id );
		$item->update_meta_data( '_oras_ticket_name', 'Basic Ticket' );
		$item->update_meta_data( '_oras_ticket_attendance_mode', 'onsite' );
		$item->save();

		$registrations = new Registration_Store();
		$attendees     = new Attendee_Store();
		$attendance    = new Attendance_Store();
		$website = $registrations->create_manual(
			array(
				'event_id'          => $event_id,
				'option_uuid'       => wp_generate_uuid4(),
				'source_type'       => 'online',
				'classification'    => 'individual',
				'validity_type'     => 'full_event',
				'payment_assertion' => '',
				'first_name'        => 'Website',
				'last_name'         => 'Attendee',
				'email'             => 'website.' . $suffix . '@example.test',
				'phone'             => '814-555-0101',
				'config_revision'   => 1,
				'evidence'          => array(
					'item_label' => 'Basic Ticket',
					'offering'   => array(
						'label'         => 'Basic Ticket',
						'max_attendees' => 1,
					),
				),
			)
		);
		oras_unified_event_report_assert( is_array( $website ), 'Website registration projection created' );
		$registration_ids[] = (int) $website['id'];
		$tables = Schema::table_names();
		$wpdb->update(
			$tables['registrations'],
			array(
				'source_key'           => 'woo:' . $order->get_id() . ':' . $item_id . ':1',
				'source_order_id'      => $order->get_id(),
				'source_order_item_id' => $item_id,
				'source_unit_number'   => 1,
			),
			array( 'id' => (int) $website['id'] )
		);
		$website_attendee = $attendees->confirm_slot( (int) $website['id'], 'individual-1', 'Website', 'Attendee' );
		oras_unified_event_report_assert( is_array( $website_attendee ), 'Website attendee projection created' );

		$walk_in = $registrations->create_manual(
			array(
				'event_id'          => $event_id,
				'option_uuid'       => wp_generate_uuid4(),
				'source_type'       => 'walk_in',
				'classification'    => 'individual',
				'validity_type'     => 'full_event',
				'payment_assertion' => 'paid_card',
				'first_name'        => 'Walkin',
				'last_name'         => 'Attendee',
				'email'             => 'walkin.' . $suffix . '@example.test',
				'phone'             => '814-555-0102',
				'config_revision'   => 1,
				'evidence'          => array(
					'offering' => array(
						'label'         => 'Basic Ticket',
						'max_attendees' => 1,
					),
				),
			)
		);
		oras_unified_event_report_assert( is_array( $walk_in ), 'Registration Desk walk-in created' );
		$registration_ids[] = (int) $walk_in['id'];
		$walk_in_attendee = $attendees->confirm_slot( (int) $walk_in['id'], 'individual-1', 'Walkin', 'Attendee' );
		oras_unified_event_report_assert( is_array( $walk_in_attendee ), 'Walk-in attendee created' );
		$checked_in = $attendance->check_in( $event_id, (int) $walk_in_attendee['id'], $today, $admin_id, wp_generate_uuid4(), 'Board Test' );
		oras_unified_event_report_assert( is_array( $checked_in ), 'Walk-in checked in today' );

		$family = $registrations->create_manual(
			array(
				'event_id'          => $event_id,
				'option_uuid'       => wp_generate_uuid4(),
				'source_type'       => 'walk_in',
				'classification'    => 'family',
				'validity_type'     => 'full_event',
				'payment_assertion' => 'paid_cash',
				'first_name'        => 'Family',
				'last_name'         => 'Registrant',
				'email'             => 'family.' . $suffix . '@example.test',
				'phone'             => '814-555-0103',
				'config_revision'   => 1,
				'evidence'          => array(
					'offering' => array(
						'label'         => 'Family Registration',
						'max_attendees' => 4,
					),
				),
			)
		);
		oras_unified_event_report_assert( is_array( $family ), 'Family registration created' );
		$registration_ids[] = (int) $family['id'];
		oras_unified_event_report_assert( is_array( $attendees->confirm_slot( (int) $family['id'], 'family-1', 'Family', 'Guest' ) ), 'Named family attendee created' );
		oras_unified_event_report_assert( is_array( $attendees->confirm_slot( (int) $family['id'], 'family-2' ) ), 'Unnamed family attendee created' );

		$membership = ( new Offline_Membership_Store() )->create(
			array(
				'activation_uuid'  => wp_generate_uuid4(),
				'request_uuid'     => wp_generate_uuid4(),
				'event_id'         => $event_id,
				'first_name'       => 'Offline',
				'last_name'        => 'Member',
				'email'            => 'offline.' . $suffix . '@example.test',
				'normalized_email' => 'offline.' . $suffix . '@example.test',
				'phone'            => '814-555-0104',
				'level_id'         => 1,
				'level_name'       => 'Individual',
				'reference_price'  => '25.00',
				'checkout_url'     => 'https://example.test/membership/',
				'payment_method'   => 'check',
				'credit_code'      => 'TEST-' . strtoupper( wp_generate_password( 8, false, false ) ),
				'discount_code_id' => null,
				'status'           => 'pending',
				'email_status'     => 'sent',
				'email_attempts'   => 1,
				'expires_at_utc'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'linked_user_id'   => null,
				'actor_user_id'    => $admin_id,
				'station_uuid'     => wp_generate_uuid4(),
				'operator_label'   => 'Board Test',
			)
		);
		oras_unified_event_report_assert( is_array( $membership ), 'Offline event membership created' );
		$membership_id = (int) $membership['id'];

		$orders_before = count(
			wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
		$report = ( new Board_Report_Service() )->get_event_report( $event_id );
		oras_unified_event_report_same( count( $report['tickets'] ), 3, 'Tickets contain one website and two on-site issuance rows' );
		$website_ticket = oras_unified_event_report_row( $report['tickets'], static fn( array $row ): bool => 'Website' === ( $row['source'] ?? '' ), 'Website Basic Ticket row missing' );
		$walk_in_ticket = oras_unified_event_report_row( $report['tickets'], static fn( array $row ): bool => 'Walkin Attendee' === ( $row['name'] ?? '' ), 'Walk-in Basic Ticket row missing' );
		oras_unified_event_report_same( $website_ticket['item_label'], 'Basic Ticket', 'Website row uses canonical ticket label' );
		oras_unified_event_report_same( (int) $website_ticket['order_id'], $order->get_id(), 'Website row preserves its real Woo order' );
		oras_unified_event_report_same( $walk_in_ticket['source'], 'On-site / Registration Desk', 'Walk-in row exposes a human source label' );
		oras_unified_event_report_same( (int) $walk_in_ticket['order_id'], 0, 'Walk-in row has no fabricated Woo order' );
		oras_unified_event_report_same( $walk_in_ticket['payment_assertion_label'], 'Card', 'Walk-in row exposes the operational payment assertion' );

		oras_unified_event_report_same( count( $report['roster'] ), 4, 'Roster is people-oriented across website, walk-in, and family attendees' );
		oras_unified_event_report_row( $report['roster'], static fn( array $row ): bool => 'Website Attendee' === ( $row['name'] ?? '' ), 'Website attendee missing from roster' );
		$roster_walk_in = oras_unified_event_report_row( $report['roster'], static fn( array $row ): bool => 'Walkin Attendee' === ( $row['name'] ?? '' ), 'Walk-in attendee missing from roster' );
		oras_unified_event_report_same( $roster_walk_in['attendance_status'], 'Checked in today', 'Walk-in check-in state is visible' );
		oras_unified_event_report_same( count( array_filter( $report['roster'], static fn( array $row ): bool => 'family' === ( $row['classification'] ?? '' ) ) ), 2, 'Family registration preserves both attendee slots' );
		oras_unified_event_report_assert( 4 === count( array_unique( wp_list_pluck( $report['roster'], 'identity' ) ) ), 'Roster uses stable attendee identities rather than name/email deduplication' );

		$overview = $report['overview'];
		oras_unified_event_report_same( $overview['total_registrations'], 3, 'Overview counts canonical registrations once' );
		oras_unified_event_report_same( $overview['people_registered'], 4, 'Overview counts actual covered people' );
		oras_unified_event_report_same( $overview['website_registrations'], 1, 'Overview counts website registrations' );
		oras_unified_event_report_same( $overview['walk_in_registrations'], 2, 'Overview counts on-site registrations' );
		oras_unified_event_report_same( $overview['checked_in_today'], 1, 'Overview uses Registration Desk attendance truth' );
		oras_unified_event_report_same( $overview['event_memberships'], 1, 'Overview includes event-originated membership count' );
		oras_unified_event_report_same(
			count(
				wc_get_orders(
					array(
						'limit'  => -1,
						'return' => 'ids',
					)
				)
			),
			$orders_before,
			'Unified reporting creates no Woo order for walk-ins'
		);

		$membership_report = ( new Membership_Report_Service() )->get_report(
			array(
				'roster_scope' => Membership_Report_Service::ROSTER_ALL,
				'origin_event' => $event_id,
			)
		);
		oras_unified_event_report_same( count( $membership_report['rows'] ), 1, 'Origin Event filter returns the event membership' );
		$membership_row = $membership_report['rows'][0];
		oras_unified_event_report_same( $membership_row['source_label'], 'On-site / Registration Desk', 'Membership exposes the Registration Desk source label' );
		oras_unified_event_report_same( $membership_row['origin_event_title'], get_the_title( $event_id ), 'Membership exposes its origin event' );
		oras_unified_event_report_same( $membership_row['recorded_method_label'], 'Check', 'Membership exposes the recorded method' );
		oras_unified_event_report_same( $membership_row['operational_status'], Membership_Report_Service::STATUS_PENDING_ACTIVATION, 'Membership exposes pending activation state' );

		wp_set_current_user( $admin_id );
		$event_tabs = array( 'overview', 'ticket_sales', 'rsvps', 'attention', 'communications', 'attendees' );
		foreach ( $event_tabs as $tab ) {
			$_GET = array(
				'oras_board_tab'      => $tab,
				'oras_board_event_id' => $event_id,
			);
			$html = do_shortcode( '[oras_board_reports]' );
			$tabs_position = strpos( $html, 'class="oras-board-reports__tabs"' );
			$selector_position = strpos( $html, 'class="oras-board-reports__event-shell"' );
			oras_unified_event_report_assert( false !== $tabs_position && false !== $selector_position && $tabs_position < $selector_position, 'Tabs precede the event selector on ' . $tab );
		}
		foreach ( array( 'observer_passes', 'memberships' ) as $tab ) {
			$_GET = array(
				'oras_board_tab'      => $tab,
				'oras_board_event_id' => $event_id,
			);
			$html = do_shortcode( '[oras_board_reports]' );
			oras_unified_event_report_assert( strpos( $html, 'class="oras-board-reports__tabs"' ) < strpos( $html, '<h3>' ), 'Tabs precede tab content on ' . $tab );
		}

		$_GET = array(
			'oras_board_tab'      => 'event_registration_attendance',
			'oras_board_event_id' => $event_id,
		);
		$legacy_html = do_shortcode( '[oras_board_reports]' );
		oras_unified_event_report_assert( 1 === preg_match( '/aria-current="page"[^>]*>\s*Event Overview\s*<\/a>/', $legacy_html ), 'Legacy attendance URL falls back to Event Overview' );
		oras_unified_event_report_assert( false === strpos( $legacy_html, '>Event Registration &amp; Attendance<' ), 'Attendance implementation tab is no longer visible' );
		oras_unified_event_report_assert( false !== strpos( $legacy_html, '>Tickets<' ) && false === strpos( $legacy_html, '>Sales<' ), 'Sales tab is renamed Tickets' );

		if ( $keep_fixture ) {
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Unified Board Reports Fixture',
					'post_name'    => 'unified-board-reports-fixture',
					'post_content' => '[oras_board_reports]',
				)
			);
			oras_unified_event_report_assert( is_int( $page_id ) && $page_id > 0, 'Browser fixture page created' );
			$url = add_query_arg(
				array(
					'oras_board_tab'      => 'overview',
					'oras_board_event_id' => $event_id,
				),
				get_permalink( $page_id )
			);
			echo 'BROWSER_FIXTURE_EVENT_ID=' . $event_id . "\n";
			echo 'BROWSER_FIXTURE_URL=' . esc_url_raw( $url ) . "\n";
			$fixture_ready = true;
		}
	} finally {
		$_GET = $original_get;
		wp_set_current_user( 0 );
		if ( $keep_fixture && $fixture_ready ) {
			return;
		}
		if ( $membership_id > 0 ) {
			$wpdb->delete( Schema::table_names()['offline_memberships'], array( 'id' => $membership_id ), array( '%d' ) );
		}
		foreach ( array_reverse( $registration_ids ) as $registration_id ) {
			$attendee_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table_names()['attendees'] . ' WHERE registration_id = %d', $registration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed plugin-owned test table.
			foreach ( array_map( 'absint', $attendee_ids ) as $attendee_id ) {
				$wpdb->delete( Schema::table_names()['attendance'], array( 'attendee_id' => $attendee_id ), array( '%d' ) );
			}
			$wpdb->delete( Schema::table_names()['attendees'], array( 'registration_id' => $registration_id ), array( '%d' ) );
			$wpdb->delete( Schema::table_names()['registrations'], array( 'id' => $registration_id ), array( '%d' ) );
		}
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
		if ( $product_id > 0 ) {
			wp_delete_post( $product_id, true );
		}
		if ( $event_id > 0 ) {
			wp_delete_post( $event_id, true );
		}
		if ( $page_id > 0 ) {
			wp_delete_post( $page_id, true );
		}
	}
}

try {
	oras_unified_event_reporting_run();
	echo "Unified event reporting integration checks passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Unified event reporting integration checks failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
