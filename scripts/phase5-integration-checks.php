<?php
/**
 * Phase 5 integration checks for RSVP/waitlist/attendees flows.
 *
 * Runs inside wp-env via:
 *   wp eval-file /tmp/oras-phase5-integration-checks.php
 */

use ORAS\Tickets\Bootstrap;
use ORAS\Tickets\Capabilities;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_filter(
		'pre_wp_mail',
		static function ( $short_circuit, $atts ) {
			return true;
		},
		10,
		2
	);
}

if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}

final class Oras_Phase5_Check_Exception extends RuntimeException {}
final class Oras_Phase5_Ajax_Exit extends RuntimeException {}

/**
 * @throws Oras_Phase5_Check_Exception
 */
function oras_phase5_fail( string $message ): void {
	throw new Oras_Phase5_Check_Exception( $message );
}

/**
 * @throws Oras_Phase5_Check_Exception
 */
function oras_phase5_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		oras_phase5_fail( $message );
	}

	echo 'PASS: ' . $message . "\n";
}

/**
 * @param mixed $actual
 * @param mixed $expected
 *
 * @throws Oras_Phase5_Check_Exception
 */
function oras_phase5_assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		oras_phase5_fail(
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
 * @throws Oras_Phase5_Check_Exception
 */
function oras_phase5_create_user( string $prefix, string $suffix ): int {
	$login = sanitize_user( $prefix . '_' . $suffix . '_' . wp_generate_password( 4, false ) );
	$email = $login . '@example.org';
	$pass  = wp_generate_password( 20, true, true );
	$id    = wp_create_user( $login, $pass, $email );

	if ( ! is_int( $id ) || $id <= 0 ) {
		oras_phase5_fail( 'Unable to create user: ' . $prefix );
	}

	return $id;
}

/**
 * @param callable $callback Handler callback.
 * @param array<string, mixed> $payload Request payload.
 *
 * @return array<string, mixed>
 * @throws Oras_Phase5_Check_Exception
 */
function oras_phase5_call_json_handler(
	callable $callback,
	array $payload,
	int $user_id,
	string $nonce_action = '',
	string $nonce_field = 'nonce'
): array {
	$handler = static function (): void {
		throw new Oras_Phase5_Ajax_Exit( 'wp_die' );
	};

	add_filter(
		'wp_die_handler',
		static function () use ( $handler ) {
			return $handler;
		}
	);
	add_filter(
		'wp_die_ajax_handler',
		static function () use ( $handler ) {
			return $handler;
		}
	);

	wp_set_current_user( $user_id );
	if ( '' !== $nonce_action ) {
		$payload[ $nonce_field ] = wp_create_nonce( $nonce_action );
	}
	$_POST    = $payload;
	$_GET     = array();
	$_REQUEST = $payload;

	ob_start();
	try {
		call_user_func( $callback );
	} catch ( Oras_Phase5_Ajax_Exit $e ) {
		// Expected for wp_send_json_*.
	}
	$raw = trim( (string) ob_get_clean() );

	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) || ! array_key_exists( 'success', $decoded ) ) {
		oras_phase5_fail( 'Handler did not return JSON response: ' . $raw );
	}

	return $decoded;
}

/**
 * @return array<int, array<string, mixed>>
 * @throws Oras_Phase5_Check_Exception
 */
function oras_phase5_get_filtered_attendees(
	Bootstrap $bootstrap,
	int $event_id,
	string $source_filter,
	string $ticket_status,
	bool $guests_only,
	string $search,
	bool $has_note_only
): array {
	$method = new ReflectionMethod( $bootstrap, 'get_filtered_attendees' );
	$method->setAccessible( true );
	$result = $method->invoke(
		$bootstrap,
		$event_id,
		$source_filter,
		$ticket_status,
		$guests_only,
		$search,
		$has_note_only
	);

	if ( ! is_array( $result ) ) {
		oras_phase5_fail( 'Filtered attendees did not return an array' );
	}

	/** @var array<int, array<string, mixed>> $result */
	return $result;
}

/**
 * @throws Oras_Phase5_Check_Exception
 */
function oras_phase5_run_checks(): void {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	require_once ABSPATH . 'wp-admin/includes/post.php';

	Capabilities::add_caps();
	Waitlist_Store::maybe_upgrade();

	register_post_type(
		'tribe_events',
		array(
			'label'  => 'Events',
			'public' => true,
		)
	);

	$admin_ids = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ids',
			'number' => 1,
		)
	);
	oras_phase5_assert( ! empty( $admin_ids ), 'Administrator user exists' );
	$admin_id = (int) $admin_ids[0];

	$suffix = gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );

	$created_users = array();
	$created_posts = array();

	try {
		$event_id = wp_insert_post(
			array(
				'post_title'  => 'ORAS Phase5 Checks ' . $suffix,
				'post_status' => 'publish',
				'post_type'   => 'tribe_events',
			)
		);
		oras_phase5_assert( is_int( $event_id ) && $event_id > 0, 'Fixture event created' );
		$created_posts[] = (int) $event_id;

		update_post_meta(
			$event_id,
			'_oras_rsvp_v1',
			array(
				'enabled'          => true,
				'capacity'         => 1,
				'waitlist_enabled' => true,
			)
		);

		$user_1 = oras_phase5_create_user( 'oras_p5_u1', $suffix );
		$user_2 = oras_phase5_create_user( 'oras_p5_u2', $suffix );
		$user_3 = oras_phase5_create_user( 'oras_p5_u3', $suffix );
		$user_4 = oras_phase5_create_user( 'oras_p5_u4', $suffix );

		$created_users[] = $user_1;
		$created_users[] = $user_2;
		$created_users[] = $user_3;
		$created_users[] = $user_4;

		$mail_short_circuit = static function () {
			return true;
		};
		add_filter( 'pre_wp_mail', $mail_short_circuit, 10, 2 );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'yes',
				'oras_ajax'       => '1',
			),
			$user_1,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		if ( empty( $response['success'] ) ) {
			oras_phase5_fail( 'User 1 YES RSVP raw response: ' . wp_json_encode( $response ) );
		}
		oras_phase5_assert_same( ! empty( $response['success'] ), true, 'User 1 YES RSVP succeeded' );
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'yes', 'User 1 status is yes' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'yes',
				'oras_ajax'       => '1',
			),
			$user_2,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( ! empty( $response['success'] ), true, 'User 2 YES fallback succeeded' );
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'waitlist', 'User 2 moved to waitlist when full' );
		oras_phase5_assert_same( Waitlist_Store::get_current_waitlist_status( $event_id, $user_2 ), 'waiting', 'Waitlist lifecycle marked waiting' );
		oras_phase5_assert_same( Waitlist_Store::count_waiting( $event_id ), 1, 'Waitlist count is 1 after join' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'leave_waitlist',
				'oras_ajax'       => '1',
			),
			$user_2,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( ! empty( $response['success'] ), true, 'User 2 leave waitlist succeeded' );
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'no', 'User 2 leave waitlist transitions to no' );
		oras_phase5_assert_same( Waitlist_Store::get_current_waitlist_status( $event_id, $user_2 ), 'left', 'Leave waitlist sets lifecycle left' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'waitlist',
				'oras_ajax'       => '1',
			),
			$user_2,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( ! empty( $response['success'] ), true, 'User 2 explicit waitlist join succeeded' );
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'waitlist', 'User 2 explicit waitlist join returns waitlist' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'no',
				'oras_ajax'       => '1',
			),
			$user_1,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( ! empty( $response['success'] ), true, 'User 1 NO revoke succeeded' );
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'none', 'User 1 NO revoke returns none' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'yes',
				'oras_ajax'       => '1',
			),
			$user_2,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( ! empty( $response['success'] ), true, 'User 2 YES after slot opened succeeded' );
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'yes', 'User 2 promoted to yes after capacity frees up' );
		oras_phase5_assert_same( Waitlist_Store::get_current_waitlist_status( $event_id, $user_2 ), 'promoted', 'User 2 waitlist lifecycle marked promoted' );
		oras_phase5_assert_same( Event_RSVP::yes_count( $event_id ), 1, 'RSVP yes_count matches expected after revoke/promotion flow' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'yes',
				'oras_ajax'       => '1',
			),
			$user_3,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'waitlist', 'User 3 waitlisted while event is full' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'no',
				'oras_ajax'       => '1',
			),
			$user_2,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'none', 'User 2 revoke frees capacity for admin promotion flow' );

		$promoted_user_id = Waitlist_Store::promote_next_waiting( $event_id, $admin_id, 'cli-check' );
		oras_phase5_assert_same( $promoted_user_id, $user_3, 'Waitlist promote_next_waiting promotes FIFO user' );
		update_user_meta( $user_3, '_oras_rsvp_event_' . $event_id, 'yes' );
		delete_user_meta( $user_3, '_oras_rsvp_event_' . $event_id . '_ts' );
		oras_phase5_assert_same( Waitlist_Store::get_current_waitlist_status( $event_id, $user_3 ), 'promoted', 'User 3 waitlist lifecycle marked promoted by admin path' );
		oras_phase5_assert_same( Event_RSVP::yes_count( $event_id ), 1, 'RSVP yes_count stable after admin promotion' );

		oras_phase5_assert( function_exists( 'wc_create_order' ), 'WooCommerce function wc_create_order is available' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Phase5 Check Ticket ' . $suffix );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '15.00' );
		$product->set_price( '15.00' );
		$product_id = $product->save();
		oras_phase5_assert( is_int( $product_id ) && $product_id > 0, 'Fixture Woo ticket product created' );
		$created_posts[] = (int) $product_id;

		update_post_meta( $event_id, '_oras_tickets_woo_map_v1', array( (int) $product_id ) );

		$order = wc_create_order(
			array(
				'customer_id' => $user_3,
			)
		);
		oras_phase5_assert( is_object( $order ), 'Fixture Woo order created' );
		$order->set_billing_first_name( 'Phase' );
		$order->set_billing_last_name( 'Check' );
		$order->set_billing_email( 'phase-check-' . $suffix . '@example.org' );
		$order->add_product( wc_get_product( $product_id ), 3 );
		$order->calculate_totals();
		$order->update_status( 'completed' );
		$order_id = (int) $order->get_id();
		oras_phase5_assert( $order_id > 0, 'Fixture Woo order persisted' );
		$created_posts[] = $order_id;

		$on_hold_order = wc_create_order(
			array(
				'customer_id' => $user_4,
			)
		);
		oras_phase5_assert( is_object( $on_hold_order ), 'Fixture Woo on-hold order created' );
		$on_hold_order->set_billing_first_name( 'Phase' );
		$on_hold_order->set_billing_last_name( 'OnHold' );
		$on_hold_order->set_billing_email( 'phase-onhold-' . $suffix . '@example.org' );
		$on_hold_order->add_product( wc_get_product( $product_id ), 1 );
		$on_hold_order->calculate_totals();
		$on_hold_order->update_status( 'on-hold' );
		$on_hold_order_id = (int) $on_hold_order->get_id();
		oras_phase5_assert( $on_hold_order_id > 0, 'Fixture Woo on-hold order persisted' );
		$created_posts[] = $on_hold_order_id;

		$bootstrap = Bootstrap::instance();
		$attendees = oras_phase5_get_filtered_attendees( $bootstrap, $event_id, 'tickets', 'completed', false, '', false );
		oras_phase5_assert_same( count( $attendees ), 3, 'Ticket attendee list expands quantity to 3 rows' );
		foreach ( $attendees as $row ) {
			oras_phase5_assert_same( (int) ( $row['order_id'] ?? 0 ), $order_id, 'Ticket attendee row is linked to expected order ID' );
		}

		$attendees_on_hold = oras_phase5_get_filtered_attendees( $bootstrap, $event_id, 'tickets', 'on-hold', false, '', false );
		oras_phase5_assert_same( count( $attendees_on_hold ), 1, 'Ticket attendee list includes on-hold orders when filtered by on-hold status' );
		oras_phase5_assert_same( (int) ( $attendees_on_hold[0]['order_id'] ?? 0 ), $on_hold_order_id, 'On-hold attendee row links to on-hold order ID' );

		$attendees_all_status = oras_phase5_get_filtered_attendees( $bootstrap, $event_id, 'tickets', 'all', false, '', false );
		$found_on_hold = false;
		foreach ( $attendees_all_status as $row ) {
			if ( (int) ( $row['order_id'] ?? 0 ) === $on_hold_order_id ) {
				$found_on_hold = true;
				break;
			}
		}
		oras_phase5_assert( $found_on_hold, 'Ticket attendee list includes on-hold orders when ticket status filter is all' );

		$note_response   = oras_phase5_call_json_handler(
			array( $bootstrap, 'handle_attendees_save_note' ),
			array(
				'event_id'     => (string) $event_id,
				'attendee_key' => 'u_' . $user_3,
				'note'         => 'Phase 5 note check',
			),
			$admin_id,
			'oras_rsvp_dashboard',
			'nonce'
		);
		oras_phase5_assert_same( ! empty( $note_response['success'] ), true, 'Attendees note save handler returns success' );

		$notes_envelope = get_post_meta( $event_id, '_oras_attendee_notes_v1', true );
		$saved_note     = is_array( $notes_envelope ) && isset( $notes_envelope['items']['u_' . $user_3 ]['note'] )
			? (string) $notes_envelope['items']['u_' . $user_3 ]['note']
			: '';
		oras_phase5_assert_same( $saved_note, 'Phase 5 note check', 'Attendees note is persisted in envelope' );

		$mail_response = oras_phase5_call_json_handler(
			array( $bootstrap, 'handle_attendees_send_email' ),
			array(
				'event_id'      => (string) $event_id,
				'source_filter' => 'all',
				'ticket_status' => 'all',
				'guests_only'   => '0',
				'has_note_only' => '0',
				'search'        => '',
				'subject'       => 'Phase 5 contract test',
				'message'       => 'Phase 5 messaging integration contract',
				'bcc'           => '1',
				'cc_me'         => '0',
			),
			$admin_id,
			'oras_rsvp_dashboard',
			'nonce'
		);

		oras_phase5_assert_same( ! empty( $mail_response['success'] ), true, 'Attendees messaging handler returns success' );
		oras_phase5_assert( (int) ( $mail_response['data']['recipients'] ?? 0 ) >= 1, 'Attendees messaging handler reports recipients' );
		oras_phase5_assert( (int) ( $mail_response['data']['chunks'] ?? 0 ) >= 1, 'Attendees messaging handler reports chunks' );

		$yes_users = get_users(
			array(
				'meta_key'   => '_oras_rsvp_event_' . $event_id,
				'meta_value' => 'yes',
				'fields'     => 'ids',
			)
		);
		oras_phase5_assert_same( count( $yes_users ), 1, 'RSVP YES export contract source count is expected' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'waitlist',
				'oras_ajax'       => '1',
			),
			$user_1,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'waitlist', 'User 1 joins waitlist for WAITLIST export contract check' );
		oras_phase5_assert_same( Waitlist_Store::count_waiting( $event_id ), 1, 'WAITLIST export contract source count is expected' );

		$queue_response = oras_phase5_call_json_handler(
			array( $bootstrap, 'handle_waitlist_queue_data' ),
			array(
				'event_id' => (string) $event_id,
			),
			$admin_id,
			'oras_rsvp_dashboard',
			'nonce'
		);
		oras_phase5_assert_same( ! empty( $queue_response['success'] ), true, 'Waitlist queue data handler returns success' );
		$queue_rows = isset( $queue_response['data']['queue'] ) && is_array( $queue_response['data']['queue'] ) ? $queue_response['data']['queue'] : array();
		oras_phase5_assert( count( $queue_rows ) >= 1, 'Waitlist queue data contains waiting users' );
		oras_phase5_assert_same( (int) ( $queue_rows[0]['user_id'] ?? 0 ), $user_1, 'Waitlist queue preserves FIFO ordering for first waiting user' );

		$remove_response = oras_phase5_call_json_handler(
			array( $bootstrap, 'handle_waitlist_remove_user' ),
			array(
				'event_id' => (string) $event_id,
				'user_id'  => (string) $user_1,
			),
			$admin_id,
			'oras_rsvp_dashboard',
			'nonce'
		);
		oras_phase5_assert_same( ! empty( $remove_response['success'] ), true, 'Manual waitlist remove handler returns success' );
		oras_phase5_assert_same( Waitlist_Store::get_current_waitlist_status( $event_id, $user_1 ), 'left', 'Manual waitlist remove updates lifecycle to left' );
		oras_phase5_assert_same( get_user_meta( $user_1, '_oras_rsvp_event_' . $event_id, true ), 'no', 'Manual waitlist remove updates RSVP status to no' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'yes',
				'oras_ajax'       => '1',
			),
			$user_1,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'waitlist', 'User 1 can rejoin waitlist after manual remove' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'no',
				'oras_ajax'       => '1',
			),
			$user_3,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'none', 'User 3 revoke frees slot for bulk promotion test' );

		$bulk_response = oras_phase5_call_json_handler(
			array( $bootstrap, 'handle_waitlist_bulk_promote' ),
			array(
				'event_id' => (string) $event_id,
				'count'    => '5',
			),
			$admin_id,
			'oras_rsvp_dashboard',
			'nonce'
		);
		oras_phase5_assert_same( ! empty( $bulk_response['success'] ), true, 'Bulk waitlist promote handler returns success' );
		oras_phase5_assert_same( (int) ( $bulk_response['data']['promoted_count'] ?? 0 ), 1, 'Bulk waitlist promote respects available capacity' );
		oras_phase5_assert_same( get_user_meta( $user_1, '_oras_rsvp_event_' . $event_id, true ), 'yes', 'Bulk waitlist promote updates RSVP status' );
		oras_phase5_assert_same( Waitlist_Store::get_current_waitlist_status( $event_id, $user_1 ), 'promoted', 'Bulk waitlist promote updates lifecycle state' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'yes',
				'oras_ajax'       => '1',
			),
			$user_4,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'waitlist', 'User 4 joins waitlist for manual promote test' );

		$response = oras_phase5_call_json_handler(
			array( Event_RSVP::class, 'handle_post' ),
			array(
				'event_id'        => (string) $event_id,
				'intent'          => 'no',
				'oras_ajax'       => '1',
			),
			$user_1,
			'oras_rsvp_' . $event_id,
			'oras_rsvp_nonce'
		);
		oras_phase5_assert_same( $response['data']['status'] ?? '', 'none', 'User 1 revoke frees slot for manual promote test' );

		$manual_promote_response = oras_phase5_call_json_handler(
			array( $bootstrap, 'handle_waitlist_promote_user' ),
			array(
				'event_id' => (string) $event_id,
				'user_id'  => (string) $user_4,
			),
			$admin_id,
			'oras_rsvp_dashboard',
			'nonce'
		);
		oras_phase5_assert_same( ! empty( $manual_promote_response['success'] ), true, 'Manual waitlist promote handler returns success' );
		oras_phase5_assert_same( get_user_meta( $user_4, '_oras_rsvp_event_' . $event_id, true ), 'yes', 'Manual waitlist promote updates RSVP status' );
		oras_phase5_assert_same( Waitlist_Store::get_current_waitlist_status( $event_id, $user_4 ), 'promoted', 'Manual waitlist promote updates lifecycle state' );

		$attendees_all = oras_phase5_get_filtered_attendees( $bootstrap, $event_id, 'all', 'all', false, '', false );
		oras_phase5_assert( count( $attendees_all ) >= 3, 'Attendees CSV export contract source has rows' );

		oras_phase5_assert( has_action( 'admin_post_oras_rsvp_export_yes' ) > 0, 'RSVP YES export action is registered' );
		oras_phase5_assert( has_action( 'admin_post_oras_rsvp_export_waitlist' ) > 0, 'RSVP WAITLIST export action is registered' );
		oras_phase5_assert( has_action( 'admin_post_oras_attendees_export_csv' ) > 0, 'Attendees export action is registered' );
		oras_phase5_assert( has_action( 'wp_ajax_oras_waitlist_queue_data' ) > 0, 'Waitlist queue AJAX action is registered' );
		oras_phase5_assert( has_action( 'wp_ajax_oras_waitlist_bulk_promote' ) > 0, 'Waitlist bulk promote AJAX action is registered' );
		oras_phase5_assert( has_action( 'wp_ajax_oras_waitlist_promote_user' ) > 0, 'Waitlist manual promote AJAX action is registered' );
		oras_phase5_assert( has_action( 'wp_ajax_oras_waitlist_remove_user' ) > 0, 'Waitlist remove AJAX action is registered' );
		oras_phase5_assert( has_action( 'wp_ajax_oras_attendees_send_email' ) > 0, 'Attendees messaging AJAX action is registered' );
		oras_phase5_assert( has_action( 'wp_ajax_oras_attendees_save_note' ) > 0, 'Attendees note AJAX action is registered' );

		echo "PASS: Phase 5 integration checks completed\n";
	} finally {
		wp_set_current_user( $admin_id );
		if ( isset( $mail_short_circuit ) ) {
			remove_filter( 'pre_wp_mail', $mail_short_circuit, 10 );
		}

		foreach ( $created_posts as $post_id ) {
			if ( $post_id > 0 ) {
				wp_delete_post( $post_id, true );
			}
		}

		foreach ( $created_users as $user_id ) {
			if ( $user_id > 0 ) {
				wp_delete_user( $user_id, $admin_id );
			}
		}
	}
}

try {
	oras_phase5_run_checks();
} catch ( Oras_Phase5_Check_Exception $e ) {
	fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" );
	exit( 1 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'FAIL: Unexpected exception: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
