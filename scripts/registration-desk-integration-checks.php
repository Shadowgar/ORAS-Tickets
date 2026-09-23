<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed -- Guarded integration fixtures intentionally share one executable.
/**
 * Disposable WordPress/WooCommerce/TEC integration checks for Registration Desk M1A.
 *
 * @package ORAS\Tickets
 */

use ORAS\Tickets\Capabilities;
use ORAS\Tickets\Domain\Event_Offering_Resolver;
use ORAS\Tickets\Registration_Desk\Attendee_Store;
use ORAS\Tickets\Registration_Desk\Attendance_Store;
use ORAS\Tickets\Registration_Desk\Audit_Store;
use ORAS\Tickets\Registration_Desk\Config;
use ORAS\Tickets\Registration_Desk\Coverage_Store;
use ORAS\Tickets\Registration_Desk\Event_Catalog;
use ORAS\Tickets\Registration_Desk\Event_Roster_Service;
use ORAS\Tickets\Registration_Desk\Event_Stats_Service;
use ORAS\Tickets\Registration_Desk\Manager_Access;
use ORAS\Tickets\Registration_Desk\Membership_Credit_Service;
use ORAS\Tickets\Registration_Desk\Membership_Offering_Resolver;
use ORAS\Tickets\Registration_Desk\Offline_Membership_Store;
use ORAS\Tickets\Registration_Desk\Projection_Service;
use ORAS\Tickets\Registration_Desk\Recovery_Service;
use ORAS\Tickets\Registration_Desk\Registration_Store;
use ORAS\Tickets\Registration_Desk\Schema;
use ORAS\Tickets\Registration_Desk\Service;
use ORAS\Tickets\Registration_Desk\Station_Session;
use ORAS\Tickets\Registration_Desk\Training_Context;
use ORAS\Tickets\Registration_Desk\Training_Service;
use ORAS\Tickets\Registration_Desk\Training_Snapshot_Service;
use ORAS\Tickets\Registration_Desk\Training_Store;
use ORAS\Tickets\Reporting\Board_Report_Service;
use ORAS\Tickets\Reporting\Membership_Report_Service;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( ! function_exists( 'pmpro_getLevel' ) ) {
	/** Disposable PMPro level fixture for the guarded integration runtime. */
	function pmpro_getLevel( int $level_id ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Matches PMPro's public API.
		$levels = get_option( 'oras_registration_desk_test_pmpro_levels', array() );

		return is_array( $levels ) && isset( $levels[ $level_id ] ) ? (object) $levels[ $level_id ] : false;
	}
}
if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
	/** @return array<int,object> */
	function pmpro_getAllLevels( bool $include_hidden = false, bool $use_cache = true ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Matches PMPro's public API.
		unset( $include_hidden, $use_cache );
		$levels = get_option( 'oras_registration_desk_test_pmpro_levels', array() );

		return array_map( static fn( array $level ): object => (object) $level, is_array( $levels ) ? array_values( $levels ) : array() );
	}
}
if ( ! function_exists( 'pmpro_url' ) ) {
	/** Disposable PMPro URL fixture. */
	function pmpro_url( string $page, string $query = '' ): string {
		return home_url( '/membership-account/membership-' . sanitize_key( $page ) . '/' . $query );
	}
}
if ( ! class_exists( 'PMPro_Discount_Code' ) ) {
	/** Minimal disposable PMPro credit model used only by the guarded test database. */
	// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- Matches PMPro's public API.
	class PMPro_Discount_Code {
		public int $id = 0;
		public string $code = '';
		public string $starts = '';
		public string $expires = '';
		public int $uses = 0;
		/** @var array<int,array<string,mixed>> */
		public array $levels = array();

		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}

		public function save(): object {
			if ( $this->id <= 0 ) {
				$this->id = absint( get_option( 'oras_registration_desk_test_pmpro_credit_id', 1000 ) ) + 1;
				update_option( 'oras_registration_desk_test_pmpro_credit_id', $this->id, false );
			}

			return $this;
		}
	}
}

/** Fail without leaking fixture secrets. */
function oras_desk_integration_fail( string $message ): void {
	WP_CLI::error( 'Registration Desk integration failure: ' . $message );
}

/** Report one qualified assertion. */
function oras_desk_integration_pass( string $message ): void {
	WP_CLI::log( 'PASS: ' . $message );
}

/** @param mixed $actual @param mixed $expected */
function oras_desk_integration_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		oras_desk_integration_fail( $message . ' (expected ' . wp_json_encode( $expected ) . ', received ' . wp_json_encode( $actual ) . ')' );
	}
	oras_desk_integration_pass( $message );
}

/** @param mixed $value */
function oras_desk_integration_true( $value, string $message ): void {
	if ( true !== $value ) {
		oras_desk_integration_fail( $message );
	}
	oras_desk_integration_pass( $message );
}

/** @param mixed $value */
function oras_desk_integration_error( $value, string $code, string $message ): void {
	if ( ! is_wp_error( $value ) || $code !== $value->get_error_code() ) {
		oras_desk_integration_fail( $message . ' (expected ' . $code . ')' );
	}
	oras_desk_integration_pass( $message );
}

/** Require the runner-established database and transport boundary. */
function oras_desk_integration_guard(): void {
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
		|| $expected_url !== get_option( 'siteurl' )
		|| ! defined( 'ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE' )
		|| ! ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE
	) {
		oras_desk_integration_fail( 'disposable database or transport guard identity is not established.' );
	}
}

/** @param mixed $value @return mixed */
function oras_desk_integration_canonicalize( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		ksort( $value, SORT_STRING );
	}
	foreach ( $value as $key => $item ) {
		$value[ $key ] = oras_desk_integration_canonicalize( $item );
	}
	return $value;
}

/** @param mixed $value */
function oras_desk_integration_hash( $value ): string {
	return hash( 'sha256', (string) wp_json_encode( oras_desk_integration_canonicalize( $value ) ) );
}

/** Create one synthetic TEC event without using production-specific names. */
function oras_desk_integration_event( string $run, string $suffix, string $start_date, string $end_date ): int {
	$timezone  = new DateTimeZone( 'America/New_York' );
	$start_utc = ( new DateTimeImmutable( $start_date . ' 00:00:00', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	$end_utc   = ( new DateTimeImmutable( $end_date . ' 23:59:59', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	$event_id = wp_insert_post(
		array(
			'post_type'   => 'tribe_events',
			'post_status' => 'publish',
			'post_title'  => 'Desk fixture ' . $run . ' ' . $suffix,
			'meta_input'  => array(
				'_EventStartDate'    => $start_date . ' 00:00:00',
				'_EventEndDate'      => $end_date . ' 23:59:59',
				'_EventStartDateUTC' => $start_utc,
				'_EventEndDateUTC'   => $end_utc,
				'_EventTimezone'     => 'America/New_York',
			),
		)
	);
	if ( is_wp_error( $event_id ) || (int) $event_id <= 0 ) {
		oras_desk_integration_fail( 'synthetic event creation failed.' );
	}
	return (int) $event_id;
}

/** Create one synthetic product. */
function oras_desk_integration_product( string $run, string $suffix ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Desk fixture ' . $run . ' ' . $suffix );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_regular_price( '20.00' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 100 );
	$product_id = (int) $product->save();
	if ( $product_id <= 0 ) {
		oras_desk_integration_fail( 'synthetic product creation failed.' );
	}
	return $product_id;
}

/** @return array{order_id:int,item_id:int} */
function oras_desk_integration_order( int $product_id, int $source_event_id, int $quantity, string $status, string $run, string $suffix ): array {
	$order = wc_create_order();
	if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
		oras_desk_integration_fail( 'synthetic order creation failed.' );
	}
	$order->set_billing_first_name( 'Purchaser' );
	$order->set_billing_last_name( $suffix );
	$order->set_billing_email( strtolower( $suffix ) . '-' . $run . '@example.test' );
	$order->set_billing_phone( '814-555-01' . str_pad( (string) ( strlen( $suffix ) % 100 ), 2, '0', STR_PAD_LEFT ) );
	$item = new WC_Order_Item_Product();
	$item->set_product_id( $product_id );
	$item->set_quantity( $quantity );
	$item->set_subtotal( 20 * $quantity );
	$item->set_total( 20 * $quantity );
	$item->add_meta_data( '_oras_ticket_event_id', (string) $source_event_id, true );
	$item->add_meta_data( '_oras_ticket_index', '99', true );
	$item->add_meta_data( '_oras_ticket_name', 'Historical label ignored by desk', true );
	$order->add_item( $item );
	$order->calculate_totals( false );
	$order->save();
	$order->set_status( $status );
	$order->save();
	$item_ids = array_keys( $order->get_items( 'line_item' ) );
	return array(
		'order_id' => (int) $order->get_id(),
		'item_id'  => (int) reset( $item_ids ),
	);
}

/** @param array<int,array<string,mixed>> $options @return array<string,mixed> */
function oras_desk_integration_save_config( int $event_id, array $options, bool $enabled = true ): array {
	$current = Config::get_event_config( $event_id );
	$result  = Config::save_event_config(
		$event_id,
		array(
			'enabled' => $enabled,
			'options' => $options,
		),
		(int) $current['revision']
	);
	if ( is_wp_error( $result ) ) {
		oras_desk_integration_fail( 'versioned event configuration could not be saved: ' . $result->get_error_code() );
	}
	return $result;
}

/** Snapshot one Woo order including notes, items, and metadata. */
function oras_desk_integration_order_snapshot( int $order_id ): array {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return array();
	}
	$items = array();
	foreach ( $order->get_items( array( 'line_item', 'refund', 'fee', 'shipping', 'coupon' ) ) as $item_id => $item ) {
		$items[ $item_id ] = array(
			'data' => $item->get_data(),
			'meta' => array_map(
				static fn( $meta ) => array(
					'key'   => $meta->key,
					'value' => $meta->value,
				),
				$item->get_meta_data()
			),
		);
	}
	$notes = array_map(
		static fn( $note ) => array(
			'content' => $note->content,
			'type'    => $note->customer_note,
		),
		wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'limit'    => -1,
			)
		)
	);
	return array(
		'data'  => $order->get_data(),
		'meta'  => array_map(
			static fn( $meta ) => array(
				'key'   => $meta->key,
				'value' => $meta->value,
			),
			$order->get_meta_data()
		),
		'items' => $items,
		'notes' => $notes,
	);
}

/** @return array<int,array<string,mixed>> */
function oras_desk_integration_http_evidence( string $scope = '' ): array {
	return array_values(
		array_filter(
			(array) get_option( 'oras_registration_desk_test_http_log', array() ),
			static function ( $row ) use ( $scope ): bool {
				return is_array( $row )
					&& ! in_array( (string) ( $row['host'] ?? '' ), array( 'localhost', '127.0.0.1', '::1' ), true )
					&& $scope === (string) ( $row['test_scope'] ?? '' );
			}
		)
	);
}

/** Capture protected live surfaces after fixture setup. */
function oras_desk_integration_protected_snapshot( array $context, bool $include_live_reports = false ): array {
	global $wpdb;
	$tables = Schema::table_names();
	$orders = array();
	foreach ( $context['order_ids'] as $order_id ) {
		$orders[ $order_id ] = oras_desk_integration_order_snapshot( (int) $order_id );
	}
	$products = array();
	foreach ( $context['product_ids'] as $product_id ) {
		$product = wc_get_product( (int) $product_id );
		$products[ $product_id ] = array(
			'data' => $product ? $product->get_data() : array(),
			'meta' => get_post_meta( (int) $product_id ),
		);
	}
	$user_ids = array_map( 'intval', $context['user_ids'] );
	$ids_sql  = implode( ',', $user_ids );
	$users    = $wpdb->get_results( "SELECT * FROM {$wpdb->users} WHERE ID IN ({$ids_sql}) ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are fixture integers.
	$usermeta = $wpdb->get_results( "SELECT * FROM {$wpdb->usermeta} WHERE user_id IN ({$ids_sql}) AND meta_key <> 'session_tokens' ORDER BY umeta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are fixture integers; login-session state is separately permitted.
	$scheduled = array();
	$actions_table = $wpdb->prefix . 'actionscheduler_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table ) ) === $actions_table ) {
		$scheduled = $wpdb->get_results( "SELECT hook,status,args,group_id FROM {$actions_table} WHERE hook LIKE 'oras_tickets_qbo_%' ORDER BY action_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed prefixed test table.
	}
	$membership_counts = array();
	foreach ( array( 'wc_user_membership', 'shop_subscription' ) as $post_type ) {
		$membership_counts[ $post_type ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed core table.
	}
	$pmpro_table = $wpdb->prefix . 'pmpro_memberships_users';
	$pmpro_rows  = array();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pmpro_table ) ) === $pmpro_table ) {
		$pmpro_rows = $wpdb->get_results( "SELECT * FROM {$pmpro_table} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed prefixed test table.
	}
	$membership_counts['pmpro'] = count( $pmpro_rows );
	$live_desk_rows = array();
	foreach ( array( 'registrations', 'attendees', 'attendance', 'audit', 'offline_memberships' ) as $table_key ) {
		$table = $tables[ $table_key ];
		$live_desk_rows[ $table_key ] = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned test table.
	}
	$stats_event_id = (int) ( $context['synthetic_ticketed_a']['event_id'] ?? $context['event_id'] ?? 0 );
	$order_query = wc_get_orders(
		array(
			'limit'    => 1,
			'paginate' => true,
			'return'   => 'ids',
		)
	);
	$global_counts = array(
		'orders'      => is_object( $order_query ) && isset( $order_query->total ) ? (int) $order_query->total : -1,
		'order_items' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed prefixed test table.
		'products'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed core table.
		'users'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed core table.
	);
	$snapshot = array(
		'orders'        => oras_desk_integration_hash( $orders ),
		'products'      => oras_desk_integration_hash( $products ),
		'users'         => oras_desk_integration_hash( array( $users, $usermeta ) ),
		'memberships'   => oras_desk_integration_hash( array( $membership_counts, $pmpro_rows ) ),
		'qbo_actions'   => oras_desk_integration_hash( $scheduled ),
		'mail_log'      => oras_desk_integration_hash( get_option( 'oras_registration_desk_test_mail_log', array() ) ),
		'write_log'     => oras_desk_integration_hash( get_option( 'oras_registration_desk_test_write_log', array() ) ),
		'global_counts' => oras_desk_integration_hash( $global_counts ),
	);
	if ( $include_live_reports ) {
		$snapshot['live_desk']    = oras_desk_integration_hash( $live_desk_rows );
		$snapshot['event_stats']  = oras_desk_integration_hash( ( new Event_Stats_Service() )->for_event( $stats_event_id, (string) ( $context['today'] ?? '' ) ) );
		$snapshot['board_totals'] = oras_desk_integration_hash( ( new Board_Report_Service() )->get_event_statistics( $stats_event_id ) );
	}

	return $snapshot;
}

/** Locate an expected callback class on a hook. */
function oras_desk_integration_hook_has_class( string $hook, string $class_name ): bool {
	global $wp_filter;
	if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
		return false;
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;
			if ( is_array( $function ) && is_object( $function[0] ) && $function[0] instanceof $class_name ) {
				return true;
			}
		}
	}
	return false;
}

/** Temporarily remove callbacks owned by one class and return their hook descriptors. */
function oras_desk_integration_suspend_hook_class( string $hook, string $class_name ): array {
	global $wp_filter;
	$removed = array();
	if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
		return $removed;
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;
			if ( is_array( $function ) && is_object( $function[0] ) && $function[0] instanceof $class_name ) {
				$accepted_args = (int) ( $callback['accepted_args'] ?? 1 );
				remove_action( $hook, $function, (int) $priority );
				$removed[] = array( $function, (int) $priority, $accepted_args );
			}
		}
	}
	return $removed;
}

/** Restore callbacks returned by oras_desk_integration_suspend_hook_class(). */
function oras_desk_integration_restore_hook_class( string $hook, array $callbacks ): void {
	foreach ( $callbacks as $callback ) {
		add_action( $hook, $callback[0], $callback[1], $callback[2] );
	}
}

/** Build a Service context from a verified station payload. */
function oras_desk_integration_context( int $user_id, int $event_id, array $config, string $token, string $request_uuid ): array {
	$station = Station_Session::validate( $token, $user_id, $event_id, (int) $config['revision'] );
	if ( is_wp_error( $station ) ) {
		oras_desk_integration_fail( 'station context validation failed.' );
	}
	return array(
		'event_id'        => $event_id,
		'config_revision' => (int) $config['revision'],
		'actor_user_id'   => $user_id,
		'station_uuid'    => (string) $station['station_uuid'],
		'operator_label'  => (string) $station['operator_label'],
		'request_uuid'    => $request_uuid,
	);
}

/** Submit a walk-in through the registered REST route used by the kiosk. */
function oras_desk_integration_rest_walk_in( string $station_token, string $request_uuid, array $payload ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/oras-tickets/v1/registration-desk/registrations/walk-in' );
	$request->set_header( 'X-ORAS-Desk-Station', $station_token );
	$request->set_header( 'X-ORAS-Desk-Request', $request_uuid );
	$request->set_body_params( $payload );

	return rest_do_request( $request );
}

/** Fetch the desk presentation of current offerings through REST. */
function oras_desk_integration_rest_offerings( string $station_token ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/oras-tickets/v1/registration-desk/offerings' );
	$request->set_header( 'X-ORAS-Desk-Station', $station_token );

	return rest_do_request( $request );
}

/** Assert one successful nonfinancial registration result from the real REST route. */
function oras_desk_integration_assert_walk_in_success( WP_REST_Response $response, string $payment, int $arrival_count, string $message ): array {
	$data = $response->get_data();
	oras_desk_integration_same( $response->get_status(), 200, $message . ' returns HTTP 200' );
	oras_desk_integration_same( $data['historical_result']['registration']['payment_assertion'] ?? '', $payment, $message . ' records the selected operational payment assertion' );
	oras_desk_integration_same( count( $data['historical_result']['attendees'] ?? array() ), $arrival_count, $message . ' creates the expected attendee slots' );
	oras_desk_integration_same( count( $data['historical_result']['attendance'] ?? array() ), $arrival_count, $message . ' checks in the expected arrivals today' );

	return $data;
}

/** Count all transaction-owned rows for one request after a failed finalization. */
function oras_desk_integration_walk_in_request_counts( int $event_id, string $request_uuid, string $email ): array {
	global $wpdb;
	$tables = Schema::table_names();
	$registration_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['registrations']} WHERE event_id = %d AND source_email = %s", $event_id, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	if ( empty( $registration_ids ) ) {
		$attendees = 0;
		$attendance = 0;
	} else {
		$id_sql = implode( ',', array_map( 'absint', $registration_ids ) );
		$attendees = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['attendees']} WHERE registration_id IN ({$id_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are query-derived integers.
		$attendance = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['attendance']} WHERE attendee_id IN (SELECT id FROM {$tables['attendees']} WHERE registration_id IN ({$id_sql}))" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are query-derived integers.
	}

	return array(
		'registrations' => count( $registration_ids ),
		'attendees'     => $attendees,
		'attendance'    => $attendance,
		'audit'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit']} WHERE request_uuid = %s", $request_uuid ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	);
}

/** Exercise the full kiosk REST finalization contract, rollback, and replay behavior. */
function oras_desk_integration_walk_in_rest_contract( array $context ): void {
	global $wpdb;
	wp_set_current_user( (int) $context['desk_id'] );
	$event_id = (int) $context['event_id'];
	$config   = Config::get_event_config( $event_id );
	$token    = Station_Session::issue( (int) $context['desk_id'], $event_id, (int) $config['revision'], 'REST Walk-In' );
	$offerings_response = oras_desk_integration_rest_offerings( $token );
	oras_desk_integration_same( $offerings_response->get_status(), 200, 'walk-in REST contract loads current offerings through the kiosk endpoint' );
	$offering = $offerings_response->get_data()['items'][0] ?? array();
	oras_desk_integration_same( $offering['desk_admission_state'] ?? '', 'admitting_today', 'current event offering is explicitly admitting today' );

	$payload_for = static function ( string $suffix, string $payment, array $selected, array $attendees = array() ) use ( $context ): array {
		return array(
			'first_name'             => 'REST',
			'last_name'              => $suffix,
			'email'                  => 'rest-' . strtolower( $suffix ) . '-' . $context['run'] . '@example.test',
			'phone'                  => '814-555-' . str_pad( (string) ( hexdec( substr( md5( $suffix ), 0, 4 ) ) % 10000 ), 4, '0', STR_PAD_LEFT ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5 -- Stable synthetic phone suffix only.
			'address_1'              => '',
			'address_2'              => '',
			'city'                   => '',
			'state'                  => '',
			'postcode'               => '',
			'option_uuid'            => (string) $selected['option_uuid'],
			'offering_fingerprint'   => (string) $selected['offering_fingerprint'],
			'valid_local_date'       => 'one_day' === (string) ( $selected['validity_type'] ?? '' ) ? (string) $selected['valid_local_date'] : '',
			'payment_assertion'      => $payment,
			'additional_attendees'   => $attendees,
			'duplicate_acknowledged' => false,
		);
	};

	$card_request = wp_generate_uuid4();
	$card_payload = $payload_for( 'Card', 'paid_card', $offering );
	$card = oras_desk_integration_assert_walk_in_success( oras_desk_integration_rest_walk_in( $token, $card_request, $card_payload ), 'paid_card', 1, 'individual Card walk-in through REST' );
	$card_replay = oras_desk_integration_rest_walk_in( $token, $card_request, $card_payload );
	oras_desk_integration_same( $card_replay->get_status(), 200, 'lost successful response retry returns HTTP 200' );
	oras_desk_integration_same( $card_replay->get_data()['replayed'] ?? false, true, 'lost successful response retry reuses the recorded result' );
	oras_desk_integration_same(
		oras_desk_integration_walk_in_request_counts( $event_id, $card_request, (string) $card_payload['email'] ),
		array(
			'registrations' => 1,
			'attendees'     => 1,
			'attendance'    => 1,
			'audit'         => 1,
		),
		'same-request retry creates exactly one operational row set'
	);

	foreach ( array(
		'Cash'   => 'paid_cash',
		'Check'  => 'paid_check',
		'Unpaid' => 'unpaid',
	) as $suffix => $payment ) {
		oras_desk_integration_assert_walk_in_success( oras_desk_integration_rest_walk_in( $token, wp_generate_uuid4(), $payload_for( $suffix, $payment, $offering ) ), $payment, 1, 'individual ' . $suffix . ' walk-in through REST' );
	}

	$ticketed_event = (int) $context['synthetic_ticketed_a']['event_id'];
	$ticketed_config = Config::get_event_config( $ticketed_event );
	$ticketed_token = Station_Session::issue( (int) $context['desk_id'], $ticketed_event, (int) $ticketed_config['revision'], 'REST Coverage' );
	$ticketed_response = oras_desk_integration_rest_offerings( $ticketed_token );
	oras_desk_integration_same( $ticketed_response->get_status(), 200, 'family and one-day REST fixture loads current canonical offerings' );
	$ticketed = $ticketed_response->get_data()['items'] ?? array();
	$by_key = array_column( $ticketed, null, 'ticket_key' );
	$family_payload = $payload_for(
		'Family',
		'paid_card',
		$by_key['synthetic-a-2'],
		array(
			array(
				'first_name' => 'Family',
				'last_name'  => 'Two',
			),
			array(
				'first_name' => 'Family',
				'last_name'  => 'Three',
			),
		)
	);
	oras_desk_integration_assert_walk_in_success( oras_desk_integration_rest_walk_in( $ticketed_token, wp_generate_uuid4(), $family_payload ), 'paid_card', 3, 'family selected-arrivals walk-in through REST' );
	$day_payload = $payload_for( 'OneDay', 'paid_cash', $by_key['synthetic-a-4'] );
	oras_desk_integration_assert_walk_in_success( oras_desk_integration_rest_walk_in( $ticketed_token, wp_generate_uuid4(), $day_payload ), 'paid_cash', 1, 'one-day valid-today walk-in through REST' );

	$past_event = (int) $context['walk_in_past']['event_id'];
	$past_config = Config::get_event_config( $past_event );
	$past_token = Station_Session::issue( (int) $context['desk_id'], $past_event, (int) $past_config['revision'], 'REST Past Event' );
	$past_response = oras_desk_integration_rest_offerings( $past_token );
	oras_desk_integration_same( $past_response->get_status(), 409, 'station held open after the event end date is blocked through REST' );
	oras_desk_integration_same( $past_response->get_data()['code'] ?? '', 'oras_desk_station_event_ended', 'station held across midnight is forced to choose another event' );
	$canonical_past = Event_Offering_Resolver::desk_offerings( $past_event, $past_config )[0];
	$past_context = oras_desk_integration_context( (int) $context['desk_id'], $past_event, $past_config, $past_token, wp_generate_uuid4() );
	$wrong_date = ( new Service() )->create_walk_in( $payload_for( 'WrongDate', 'paid_card', $canonical_past ), $past_context );
	oras_desk_integration_error( $wrong_date, 'oras_desk_wrong_date', 'final service validation remains authoritative behind the station-ended guard' );

	oras_desk_integration_true( null === Event_Catalog::find( $past_event ), 'past one-day event is excluded from station event selection' );
	oras_desk_integration_true( null !== Event_Catalog::find( (int) $context['catalog_fixture']['running_event_id'] ), 'currently running multi-day event remains eligible for station selection' );
	oras_desk_integration_true( null !== Event_Catalog::find( (int) $context['catalog_fixture']['future_event_id'] ), 'future current-year event remains eligible for station selection' );
	$board_event_ids = array_map( static fn( WP_Post $event ): int => (int) $event->ID, ( new Board_Report_Service() )->get_events() );
	oras_desk_integration_true( in_array( $past_event, $board_event_ids, true ), 'past event remains available in Board Reports' );

	$stale_offering = $by_key['synthetic-a-1'];
	$ticket_config = get_post_meta( $ticketed_event, '_oras_tickets_v1', true );
	$ticket_config['tickets']['synthetic-a-1']['name'] = 'Changed after review';
	update_post_meta( $ticketed_event, '_oras_tickets_v1', $ticket_config );
	$offering_changed = oras_desk_integration_rest_walk_in( $ticketed_token, wp_generate_uuid4(), $payload_for( 'OfferingChanged', 'paid_card', $stale_offering ) );
	oras_desk_integration_same( $offering_changed->get_status(), 409, 'offering changed before submit returns HTTP 409 through REST' );
	oras_desk_integration_same( $offering_changed->get_data()['code'] ?? '', 'oras_desk_offering_changed', 'offering fingerprint drift is not weakened by the kiosk fix' );
	$ticket_config['tickets']['synthetic-a-1']['name'] = 'General Admission';
	update_post_meta( $ticketed_event, '_oras_tickets_v1', $ticket_config );

	wp_set_current_user( (int) $context['admin_id'] );
	$config_changed = Config::save_event_config(
		$ticketed_event,
		array(
			'enabled'      => (bool) $ticketed_config['enabled'],
			'ticket_rules' => $ticketed_config['ticket_rules'],
			'entitlements' => $ticketed_config['entitlements'],
		),
		(int) $ticketed_config['revision']
	);
	oras_desk_integration_true( is_array( $config_changed ), 'config-change fixture advances the canonical station revision' );
	wp_set_current_user( (int) $context['desk_id'] );
	$config_rejected = oras_desk_integration_rest_walk_in( $ticketed_token, wp_generate_uuid4(), $payload_for( 'ConfigChanged', 'paid_card', $stale_offering ) );
	oras_desk_integration_same( $config_rejected->get_status(), 401, 'config changed before submit invalidates the station through REST' );
	oras_desk_integration_same( $config_rejected->get_data()['code'] ?? '', 'oras_desk_station_config_changed', 'stale config revision is rejected before mutation' );

	$tables = Schema::table_names();
	$faults = array(
		'registration' => array(
			'table'  => $tables['registrations'],
			'code'   => 'oras_desk_registration_create_failed',
			'status' => 500,
		),
		'attendee'     => array(
			'table'  => $tables['attendees'],
			'code'   => 'oras_desk_attendee_create_failed',
			'status' => 409,
		),
		'attendance'   => array(
			'table'  => $tables['attendance'],
			'code'   => 'oras_desk_attendance_create_failed',
			'status' => 500,
		),
		'audit'        => array(
			'table'  => $tables['audit'],
			'code'   => 'oras_desk_audit_persist_failed',
			'status' => 500,
		),
	);
	foreach ( $faults as $fault => $definition ) {
		$request_uuid = wp_generate_uuid4();
		$fault_payload = $payload_for( 'Fault' . ucfirst( $fault ), 'paid_card', $offering );
		$trigger_name = $wpdb->prefix . 'oras_walkin_' . $fault;
		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed disposable trigger name.
		$condition = 'audit' === $fault ? "IF NEW.request_uuid = '" . esc_sql( $request_uuid ) . "' THEN " : '';
		$end_condition = 'audit' === $fault ? ' END IF;' : '';
		$created = $wpdb->query( "CREATE TRIGGER {$trigger_name} BEFORE INSERT ON {$definition['table']} FOR EACH ROW BEGIN {$condition}SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic walk-in {$fault} failure';{$end_condition} END" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed disposable fixture identifiers.
		oras_desk_integration_same( $created, true, 'forced ' . $fault . ' failure trigger is installed in the disposable database' );
		$prior_suppression = $wpdb->suppress_errors( true );
		$fault_response = oras_desk_integration_rest_walk_in( $token, $request_uuid, $fault_payload );
		$wpdb->suppress_errors( $prior_suppression );
		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed disposable trigger name.
		oras_desk_integration_same( $fault_response->get_status(), $definition['status'], 'forced ' . $fault . ' failure returns its declared HTTP status through REST' );
		oras_desk_integration_same( $fault_response->get_data()['code'] ?? '', $definition['code'], 'forced ' . $fault . ' failure returns its exact persistence code' );
		oras_desk_integration_same(
			oras_desk_integration_walk_in_request_counts( $event_id, $request_uuid, (string) $fault_payload['email'] ),
			array(
				'registrations' => 0,
				'attendees'     => 0,
				'attendance'    => 0,
				'audit'         => 0,
			),
			'forced ' . $fault . ' failure rolls back every operational row'
		);
		$retry = oras_desk_integration_rest_walk_in( $token, $request_uuid, $fault_payload );
		oras_desk_integration_assert_walk_in_success( $retry, 'paid_card', 1, 'same request succeeds after forced ' . $fault . ' fault is removed' );
	}
	unset( $card );
}

/** Exercise listener-only discovery and deterministic administrator recovery. */
function oras_desk_integration_discovery( string $run, int $event_id, int $other_id, int $product_id, array $config ): void {
	$store      = new Registration_Store();
	$projection = new Projection_Service();
	$coverage   = new Coverage_Store();
	$failure_filter = static function ( $error, int $candidate_event, array $evidence ) use ( $event_id ) {
		if ( $candidate_event === $event_id && str_contains( (string) ( $evidence['contact_name'] ?? '' ), 'ListenerFailure' ) ) {
			return new WP_Error( 'oras_desk_test_projection_failure', 'Synthetic listener failure.' );
		}
		return $error;
	};
	add_filter( 'oras_registration_desk_projection_failure', $failure_filter, 10, 3 );
	$listener_source = oras_desk_integration_order( $product_id, $event_id, 1, 'pending', $run, 'ListenerFailure' );
	$listener_order  = wc_get_order( $listener_source['order_id'] );
	$listener_order->update_status( 'on-hold' );
	remove_filter( 'oras_registration_desk_projection_failure', $failure_filter, 10 );
	$failed_coverage = $coverage->get( $event_id, (int) $config['revision'] );
	oras_desk_integration_same( $failed_coverage['status'], 'failed', 'listener projection failure is contained and recorded as incomplete coverage' );
	$failure_identity = (string) $failed_coverage['unresolved_failure']['identity'];

	$unrelated = oras_desk_integration_order( $product_id, $other_id, 1, 'pending', $run, 'UnrelatedDiscovery' );
	$unrelated_order = wc_get_order( $unrelated['order_id'] );
	$unrelated_order->update_status( 'processing' );
	oras_desk_integration_same( $coverage->get( $event_id, (int) $config['revision'] )['unresolved_failure']['identity'], $failure_identity, 'unrelated listener success does not clear an unresolved failure' );

	$listener_order->update_status( 'processing' );
	$source_key = implode( ':', array( 'woo', $listener_source['order_id'], $listener_source['item_id'], 1 ) );
	$listener_row = $store->find_by_source_key( $event_id, $source_key );
	oras_desk_integration_true( is_array( $listener_row ) && 'active' === $listener_row['status'], 'pending source becomes searchable and active through the registered status listener' );
	$listener_uuid = (string) $listener_row['registration_uuid'];
	$listener_order->update_status( 'completed' );
	$listener_repeat = $store->find_by_source_key( $event_id, $source_key );
	oras_desk_integration_same( $listener_repeat['registration_uuid'], $listener_uuid, 'repeated processing and completed transitions preserve one registration identity' );
	oras_desk_integration_true( count( ( new Service() )->search( $event_id, 'ListenerFailure' ) ) >= 1, 'newly eligible listener source is available to desk search without a broad scan' );

	$first = $projection->reconcile_page( $event_id, $config, '', 1 );
	if ( is_wp_error( $first ) ) {
		oras_desk_integration_fail( 'first recovery page failed: ' . $first->get_error_code() );
	}
	oras_desk_integration_same( $first['scanned_orders'], 1, 'recovery reports source orders scanned independently of event matches' );
	oras_desk_integration_true( true === $first['has_more'] && '' !== $first['continuation'], 'nonfinal recovery returns an opaque continuation' );
	$tampered = substr( $first['continuation'], 0, -1 ) . ( str_ends_with( $first['continuation'], 'A' ) ? 'B' : 'A' );
	oras_desk_integration_error( $projection->reconcile_page( $event_id, $config, $tampered, 1 ), 'oras_desk_recovery_cursor_invalid', 'tampered recovery continuation fails closed' );

	oras_desk_integration_order( $product_id, $other_id, 1, 'completed', $run, 'SnapshotChange' );
	oras_desk_integration_error( $projection->reconcile_page( $event_id, $config, $first['continuation'], 1 ), 'oras_desk_recovery_snapshot_changed', 'source snapshot change rejects an old continuation instead of claiming completion' );
	oras_desk_integration_same( $coverage->get( $event_id, (int) $config['revision'] )['status'], 'failed', 'snapshot interruption leaves failed coverage state' );
	$recovery_failure_source = oras_desk_integration_order( $product_id, $event_id, 1, 'completed', $run, 'RecoveryFailure' );
	$recovery_filter = static function ( $error, int $candidate_event, array $evidence ) use ( $event_id, $recovery_failure_source ) {
		if ( $candidate_event === $event_id && (int) ( $evidence['order_id'] ?? 0 ) === $recovery_failure_source['order_id'] ) {
			return new WP_Error( 'oras_desk_test_recovery_failure', 'Synthetic recovery failure.' );
		}
		return $error;
	};
	add_filter( 'oras_registration_desk_projection_failure', $recovery_filter, 10, 3 );
	$retry_continuation = '';
	$recovery_error = null;
	for ( $attempt = 0; $attempt < 100; ++$attempt ) {
		$attempt_result = $projection->reconcile_page( $event_id, $config, $retry_continuation, 100 );
		if ( is_wp_error( $attempt_result ) ) {
			$recovery_error = $attempt_result;
			break;
		}
		$retry_continuation = (string) $attempt_result['continuation'];
		if ( ! $attempt_result['has_more'] ) {
			break;
		}
	}
	oras_desk_integration_error( $recovery_error, 'oras_desk_test_recovery_failure', 'recovery page failure is contained without advancing its cursor' );
	$failed_recovery_state = $coverage->get( $event_id, (int) $config['revision'] );
	oras_desk_integration_same( $failed_recovery_state['continuation'], $retry_continuation, 'failed recovery persists the exact retry continuation' );
	remove_filter( 'oras_registration_desk_projection_failure', $recovery_filter, 10 );
	$retry_result = $projection->reconcile_page( $event_id, $config, $retry_continuation, 100 );
	if ( is_wp_error( $retry_result ) ) {
		oras_desk_integration_fail( 'same-cursor recovery retry failed: ' . $retry_result->get_error_code() );
	}
	oras_desk_integration_true( empty( $retry_result['coverage']['unresolved_failure'] ), 'successful same-cursor retry clears only its matching recovery failure' );

	$continuation = '';
	$saw_empty_nonfinal = false;
	$pages = 0;
	$page_limit = max( 500, (int) ( $retry_result['source_orders'] ?? 0 ) + 10 );
	do {
		$page = $projection->reconcile_page( $event_id, $config, $continuation, 1 );
		if ( is_wp_error( $page ) ) {
			oras_desk_integration_fail( 'recovery retry failed: ' . $page->get_error_code() );
		}
		$saw_empty_nonfinal = $saw_empty_nonfinal || ( 0 === $page['matching_items'] && true === $page['has_more'] );
		$continuation = (string) $page['continuation'];
		++$pages;
		if ( $pages > $page_limit ) {
			oras_desk_integration_fail( 'recovery exceeded its bounded fixture page count.' );
		}
	} while ( $page['has_more'] );
	oras_desk_integration_true( $saw_empty_nonfinal, 'recovery continues through a nonfinal source page with zero event matches' );
	oras_desk_integration_same( $page['coverage']['status'], 'complete', 'final successful recovery page publishes complete coverage' );
	oras_desk_integration_same( $page['coverage']['snapshot_count'], $page['source_orders'], 'complete coverage retains its source snapshot count' );
	$post_recovery = oras_desk_integration_order( $product_id, $event_id, 1, 'pending', $run, 'AfterRecovery' );
	$post_recovery_order = wc_get_order( $post_recovery['order_id'] );
	$post_recovery_order->update_status( 'completed' );
	$post_recovery_key = implode( ':', array( 'woo', $post_recovery['order_id'], $post_recovery['item_id'], 1 ) );
	$post_recovery_row = $store->find_by_source_key( $event_id, $post_recovery_key );
	oras_desk_integration_true( is_array( $post_recovery_row ) && 'active' === $post_recovery_row['status'], 'qualifying order completed after recovery is discovered by the listener' );
	$post_recovery_coverage = $coverage->get( $event_id, (int) $config['revision'] );
	oras_desk_integration_same( $post_recovery_coverage['status'], 'complete', 'successful post-recovery listener discovery preserves complete coverage' );
	oras_desk_integration_same( $post_recovery_coverage['snapshot_highest_id'], $post_recovery['order_id'], 'listener advances the completed source snapshot to the new order' );
}

/** Exercise canonical offering drift and accountless RSVP capacity/waitlist behavior. */
function oras_desk_integration_canonical_offerings_and_rsvp( array $context ): void {
	$fixture  = $context['offering_fixture'];
	$event_id = (int) $fixture['event_id'];
	$config   = Config::get_event_config( $event_id );
	wp_set_current_user( (int) $context['desk_id'] );
	$roster_token = Station_Session::issue( (int) $context['desk_id'], $event_id, (int) $config['revision'], 'Roster Types' );
	$roster_type_labels = static function () use ( $roster_token ): array {
		$request = new WP_REST_Request( 'GET', '/oras-tickets/v1/registration-desk/roster' );
		$request->set_header( 'X-ORAS-Desk-Station', $roster_token );
		$request->set_param( 'limit', 10 );
		$response = rest_do_request( $request );
		if ( 200 !== $response->get_status() ) {
			oras_desk_integration_fail( 'canonical roster filter request failed.' );
		}

		return array_column( (array) ( $response->get_data()['registration_types'] ?? array() ), 'label' );
	};
	$assert_aligned = static function ( string $message ) use ( $event_id, $config ): array {
		$public = array_values( array_filter( Event_Offering_Resolver::resolve_for_event( $event_id ), static fn( array $offering ): bool => ! empty( $offering['visible'] ) ) );
		$desk   = Event_Offering_Resolver::desk_offerings( $event_id, $config );
		$public_projection = array_map( static fn( array $offering ): array => array( $offering['ticket_key'], $offering['name'], $offering['price'], $offering['selectable'] ), $public );
		$desk_projection   = array_map( static fn( array $offering ): array => array( $offering['ticket_key'], $offering['name'], $offering['price'], $offering['selectable'] ), $desk );
		oras_desk_integration_same( $desk_projection, $public_projection, $message );
		$method = new ReflectionMethod( \ORAS\Tickets\Frontend\Tickets_Display::class, 'render_form_html' );
		$html   = (string) $method->invoke( \ORAS\Tickets\Frontend\Tickets_Display::instance(), $event_id );
		foreach ( $public as $offering ) {
			oras_desk_integration_true( str_contains( $html, 'data-oras-ticket-key="' . esc_attr( (string) $offering['ticket_key'] ) . '"' ) && str_contains( $html, esc_html( (string) $offering['name'] ) ), $message . ' renders the same public identity and name' );
		}

		return $desk;
	};

	$initial = $assert_aligned( 'public and desk begin with the same canonical offering' );
	oras_desk_integration_same( count( $initial ), 1, 'initial event exposes one canonical ticket' );
	oras_desk_integration_same( $roster_type_labels(), array( 'Canonical Alpha' ), 'roster type filter begins from the same canonical offering' );
	$envelope = get_post_meta( $event_id, '_oras_tickets_v1', true );
	$envelope['tickets']['ticket-b'] = array(
		'ticket_key'      => 'ticket-b',
		'name'            => 'Canonical Beta',
		'price'           => '15.00',
		'price_phases'    => array(),
		'capacity'        => 100,
		'sale_start'      => '',
		'sale_end'        => '',
		'description'     => 'Added canonical offering',
		'attendance_mode' => 'virtual',
		'hide_sold_out'   => false,
	);
	update_post_meta( $event_id, '_oras_tickets_v1', $envelope );
	update_post_meta(
		$event_id,
		'_oras_tickets_woo_map_v1',
		array(
			0 => (int) $fixture['product_a'],
			1 => (int) $fixture['product_b'],
		)
	);
	oras_desk_integration_same( count( $assert_aligned( 'ticket addition flows to public and desk together' ) ), 2, 'added ticket appears without desk reconfiguration' );
	oras_desk_integration_same( $roster_type_labels(), array( 'Canonical Alpha', 'Canonical Beta' ), 'ticket addition flows to roster type choices' );
	$envelope['tickets']['ticket-a']['name'] = 'Canonical Alpha Renamed';
	update_post_meta( $event_id, '_oras_tickets_v1', $envelope );
	$renamed = $assert_aligned( 'ticket rename flows to public and desk together' );
	oras_desk_integration_same( $renamed[0]['name'], 'Canonical Alpha Renamed', 'desk reads the current canonical ticket name' );
	oras_desk_integration_same( $roster_type_labels(), array( 'Canonical Alpha Renamed', 'Canonical Beta' ), 'ticket rename flows to roster type choices' );
	$stale_token = Station_Session::issue( (int) $context['desk_id'], $event_id, (int) $config['revision'], 'Stale Offering' );
	$stale_context = oras_desk_integration_context( (int) $context['desk_id'], $event_id, $config, $stale_token, wp_generate_uuid4() );
	$stale_result = ( new Service() )->create_walk_in(
		array(
			'first_name'           => 'Stale',
			'last_name'            => 'Choice',
			'email'                => 'stale-choice@example.test',
			'phone'                => '814-555-0901',
			'option_uuid'          => $initial[0]['option_uuid'],
			'offering_fingerprint' => $initial[0]['offering_fingerprint'],
			'valid_local_date'     => '',
			'payment_assertion'    => 'paid_card',
			'additional_attendees' => array(),
		),
		$stale_context
	);
	oras_desk_integration_error( $stale_result, 'oras_desk_offering_changed', 'stale renamed ticket cannot be registered without reviewing current details' );
	$envelope['tickets']['ticket-a']['sale_start'] = gmdate( 'Y-m-d H:i', time() + HOUR_IN_SECONDS );
	update_post_meta( $event_id, '_oras_tickets_v1', $envelope );
	$upcoming = $assert_aligned( 'future sale window hides the ticket from public and desk choices' );
	oras_desk_integration_true( ! in_array( 'ticket-a', array_column( $upcoming, 'ticket_key' ), true ), 'ticket remains unavailable until its canonical sale window opens' );
	$envelope['tickets']['ticket-a']['sale_start'] = gmdate( 'Y-m-d H:i', time() - HOUR_IN_SECONDS );
	update_post_meta( $event_id, '_oras_tickets_v1', $envelope );
	$opened = $assert_aligned( 'opened sale window restores the ticket to public and desk choices' );
	oras_desk_integration_true( in_array( 'ticket-a', array_column( $opened, 'ticket_key' ), true ), 'ticket becomes available when its canonical sale window opens' );
	$phase_start = gmdate( 'Y-m-d H:i', time() - HOUR_IN_SECONDS );
	$phase_end   = gmdate( 'Y-m-d H:i', time() + HOUR_IN_SECONDS );
	$envelope['tickets']['ticket-a']['price_phases'] = array(
		array(
			'key'   => 'door',
			'label' => 'Door',
			'price' => '45.00',
			'start' => $phase_start,
			'end'   => $phase_end,
		),
	);
	update_post_meta( $event_id, '_oras_tickets_v1', $envelope );
	$phased = $assert_aligned( 'active pricing phase flows to public and desk together' );
	oras_desk_integration_same( array( $phased[0]['price'], $phased[0]['phase_key'] ), array( '45.00', 'door' ), 'desk uses the canonical effective price resolver' );
	$product_a = wc_get_product( (int) $fixture['product_a'] );
	$product_a->set_stock_quantity( 0 );
	$product_a->set_stock_status( 'outofstock' );
	$product_a->save();
	$stocked = $assert_aligned( 'stock change flows to public and desk together' );
	oras_desk_integration_true( false === $stocked[0]['selectable'] && 'sold_out' === $stocked[0]['availability'], 'sold-out ticket remains visible but cannot be selected' );
	$product_a->set_stock_quantity( 100 );
	$product_a->set_stock_status( 'instock' );
	$product_a->save();
	$envelope['tickets']['ticket-a']['sale_end'] = gmdate( 'Y-m-d H:i', time() - HOUR_IN_SECONDS );
	update_post_meta( $event_id, '_oras_tickets_v1', $envelope );
	$closed = $assert_aligned( 'closed sale window flows to public and desk together' );
	oras_desk_integration_true( ! in_array( 'ticket-a', array_column( $closed, 'ticket_key' ), true ), 'ended ticket is unavailable in both current-choice lists' );
	$before_unrelated = Event_Offering_Resolver::desk_offerings( $event_id, $config );
	$unrelated = get_post_meta( (int) $fixture['unrelated_event_id'], '_oras_tickets_v1', true );
	$unrelated['tickets']['unrelated']['name'] = 'Changed unrelated ticket';
	update_post_meta( (int) $fixture['unrelated_event_id'], '_oras_tickets_v1', $unrelated );
	oras_desk_integration_same( Event_Offering_Resolver::desk_offerings( $event_id, $config ), $before_unrelated, 'unrelated event ticket changes do not affect selected-event choices' );
	oras_desk_integration_true( ! in_array( (int) $fixture['unrelated_product'], array_column( $closed, 'product_id' ), true ), 'cross-event entitlement never appears as a walk-in ticket' );
	$beta = array_values( array_filter( $closed, static fn( array $offering ): bool => 'ticket-b' === $offering['ticket_key'] ) )[0];
	unset( $envelope['tickets']['ticket-b'], $envelope['tickets']['ticket-a']['sale_end'] );
	$envelope['tickets']['ticket-a']['price_phases'] = array();
	update_post_meta( $event_id, '_oras_tickets_v1', $envelope );
	update_post_meta( $event_id, '_oras_tickets_woo_map_v1', array( 0 => (int) $fixture['product_a'] ) );
	$removed = $assert_aligned( 'ticket removal flows to public and desk together' );
	oras_desk_integration_true( ! in_array( 'ticket-b', array_column( $removed, 'ticket_key' ), true ), 'removed canonical ticket is no longer a walk-in choice' );
	$removed_context = oras_desk_integration_context( (int) $context['desk_id'], $event_id, $config, $stale_token, wp_generate_uuid4() );
	$removed_result = ( new Service() )->create_walk_in(
		array(
			'first_name'           => 'Removed',
			'last_name'            => 'Choice',
			'email'                => 'removed-choice@example.test',
			'phone'                => '814-555-0902',
			'option_uuid'          => $beta['option_uuid'],
			'offering_fingerprint' => $beta['offering_fingerprint'],
			'valid_local_date'     => '',
			'payment_assertion'    => 'paid_card',
			'additional_attendees' => array(),
		),
		$removed_context
	);
	oras_desk_integration_error( $removed_result, 'oras_desk_option_invalid', 'removed ticket cannot be submitted from a stale walk-in screen' );

	$service     = new Service();
	$user_before = count_users()['total_users'];
	$rsvp_cases  = array(
		'available' => (int) $context['rsvp_fixture']['available_event_id'],
		'waitlist'  => (int) $context['rsvp_fixture']['waitlist_event_id'],
		'full'      => (int) $context['rsvp_fixture']['full_event_id'],
	);
	$sequence = 0;
	$create = static function ( int $rsvp_event_id, string $name ) use ( &$sequence, $context, $service ) {
		++$sequence;
		$config = Config::get_event_config( $rsvp_event_id );
		$token  = Station_Session::issue( (int) $context['desk_id'], $rsvp_event_id, (int) $config['revision'], 'RSVP Operator' );
		$desk_context = oras_desk_integration_context( (int) $context['desk_id'], $rsvp_event_id, $config, $token, wp_generate_uuid4() );
		return $service->create_walk_in(
			array(
				'first_name'           => $name,
				'last_name'            => 'RSVP',
				'email'                => strtolower( $name ) . '-' . $sequence . '@example.test',
				'phone'                => '814-555-' . str_pad( (string) $sequence, 4, '0', STR_PAD_LEFT ),
				'option_uuid'          => Event_Offering_Resolver::option_uuid( $rsvp_event_id, 'rsvp' ),
				'valid_local_date'     => '',
				'payment_assertion'    => 'rsvp',
				'additional_attendees' => array(),
			),
			$desk_context
		);
	};
	$available_state = \ORAS\Tickets\Registration_Desk\RSVP_Capacity::state( $rsvp_cases['available'] );
	oras_desk_integration_same( $available_state['public_count'], 1, 'public RSVP contributes to shared effective capacity' );
	$admitted = $create( $rsvp_cases['available'], 'Admitted' );
	oras_desk_integration_true( is_array( $admitted ) && 'rsvp_registered_and_checked_in' === $admitted['historical_result']['result'] && 1 === count( $admitted['historical_result']['attendance'] ), 'RSVP-only event with capacity creates an accountless admitted check-in' );
	oras_desk_integration_same( \ORAS\Tickets\Registration_Desk\RSVP_Capacity::state( $rsvp_cases['available'] )['effective_count'], 2, 'desk RSVP contributes to the same effective capacity' );
	$desk_waitlist = $create( $rsvp_cases['available'], 'DeskFull' );
	oras_desk_integration_true( is_array( $desk_waitlist ) && 'rsvp_waitlisted' === $desk_waitlist['historical_result']['result'] && empty( $desk_waitlist['historical_result']['attendance'] ), 'shared capacity prevents a second desk RSVP from overbooking' );
	$desk_waitlist_detail = $service->detail( $rsvp_cases['available'], (string) $desk_waitlist['historical_result']['registration']['registration_uuid'] );
	oras_desk_integration_same( $desk_waitlist_detail['admission']['state'] ?? '', 'waitlisted', 'desk RSVP waitlist detail has one authoritative waitlisted state' );
	oras_desk_integration_same( $desk_waitlist_detail['admission']['check_in_allowed'] ?? null, false, 'waitlisted RSVP detail never offers check-in' );
	$waitlisted = $create( $rsvp_cases['waitlist'], 'Waitlisted' );
	oras_desk_integration_true( is_array( $waitlisted ) && 'rsvp_waitlisted' === $waitlisted['historical_result']['result'] && empty( $waitlisted['historical_result']['attendance'] ), 'full RSVP-only event uses its enabled waitlist without check-in' );
	$refused = $create( $rsvp_cases['full'], 'Refused' );
	oras_desk_integration_error( $refused, 'oras_desk_rsvp_full', 'full RSVP-only event without waitlist refuses the registration clearly' );
	oras_desk_integration_same( count_users()['total_users'], $user_before, 'accountless desk RSVP and waitlist create no WordPress attendee account' );
	$rsvp_roster = ( new Event_Roster_Service() )->get( $rsvp_cases['available'], array( 'limit' => 25 ) );
	oras_desk_integration_same( $rsvp_roster['mode'], 'rsvp', 'RSVP-only event uses RSVP roster mode without fabricated tickets' );
	oras_desk_integration_same( count( $rsvp_roster['items'] ), 3, 'RSVP-only roster combines website, admitted desk, and waitlisted desk records' );
	oras_desk_integration_same( count( ( new Event_Roster_Service() )->get( $rsvp_cases['available'], array( 'status' => 'admitted' ) )['items'] ), 2, 'RSVP admitted filter covers website and accountless admitted records' );
	oras_desk_integration_same( count( ( new Event_Roster_Service() )->get( $rsvp_cases['available'], array( 'status' => 'waitlist' ) )['items'] ), 1, 'RSVP waitlist filter returns only non-admitted records' );
	oras_desk_integration_same( count( ( new Event_Roster_Service() )->get( $rsvp_cases['available'], array( 'status' => 'checked_in' ) )['items'] ), 1, 'RSVP checked-in filter uses actual Registration Desk attendance' );
	$rsvp_config = Config::get_event_config( $rsvp_cases['available'] );
	$rsvp_token  = Station_Session::issue( (int) $context['desk_id'], $rsvp_cases['available'], (int) $rsvp_config['revision'], 'RSVP Roster' );
	$rsvp_checkin = new WP_REST_Request( 'POST', '/oras-tickets/v1/registration-desk/roster/rsvp/' . (int) $context['member_id'] . '/check-in' );
	$rsvp_checkin->set_header( 'X-ORAS-Desk-Station', $rsvp_token );
	$rsvp_checkin->set_header( 'X-ORAS-Desk-Request', wp_generate_uuid4() );
	$rsvp_checkin->set_body_params( array( 'attendance_local_date' => $context['today'] ) );
	$rsvp_response = rest_do_request( $rsvp_checkin );
	oras_desk_integration_same( $rsvp_response->get_status(), 200, 'admitted website RSVP can be checked in from the same safe roster detail flow' );
	oras_desk_integration_same( count_users()['total_users'], $user_before, 'website RSVP roster check-in creates no attendee WordPress account' );
	oras_desk_integration_same( count( ( new Event_Roster_Service() )->get( $rsvp_cases['available'], array( 'status' => 'checked_in' ) )['items'] ), 2, 'website RSVP check-in participates in the shared roster attendance state' );
	oras_desk_integration_true( Event_Offering_Resolver::has_canonical_tickets( (int) $context['event_id'] ), 'event with tickets and RSVP uses canonical ticket precedence' );
	oras_desk_integration_same( ( new Event_Roster_Service() )->get( (int) $context['synthetic_ticketed_a']['event_id'], array() )['mode'], 'tickets', 'tickets plus RSVP event keeps canonical ticket roster mode' );
}

/** Exercise the event-scoped roster with enough people to require kiosk pagination. */
function oras_desk_integration_event_roster( array $context ): void {
	$event_id = (int) $context['synthetic_ticketed_a']['event_id'];
	$config   = Config::get_event_config( $event_id );
	$offerings = Event_Offering_Resolver::desk_offerings( $event_id, $config );
	$labels    = array_column( $offerings, 'label' );
	oras_desk_integration_same( $labels, array( 'General Admission', 'Family Pass', 'Student Pass', 'Single-Day Pass' ), 'ticketed roster filters use only the selected event canonical types' );

	$last_names = array(
		'Zulu',
		'Alpha',
		'Yankee',
		'Bravo',
		'Xray',
		'Charlie',
		'Whiskey',
		'Delta',
		'Victor',
		'Echo',
		'Uniform',
		'Foxtrot',
		'Tango',
		'Golf',
		'Sierra',
		'Hotel',
		'Romeo',
		'India',
		'Quebec',
		'Juliet',
		'Papa',
		'Kilo',
		'Oscar',
		'Lima',
		'November',
		'Mike',
		'SearchTarget',
	);
	$registrations = new Registration_Store();
	$attendees     = new Attendee_Store();
	$attendance    = new Attendance_Store();
	$rows_by_name  = array();
	$type_counts   = array();
	$walk_in_count = 0;
	$checked_count = 0;
	foreach ( $last_names as $index => $last_name ) {
		$offering = $offerings[ $index % count( $offerings ) ];
		$label    = 0 === $index ? 'Historic General Admission' : (string) $offering['label'];
		$source   = 0 === $index % 3 ? 'walk_in' : 'complimentary';
		$record   = $registrations->create_manual(
			array(
				'event_id'          => $event_id,
				'option_uuid'       => (string) $offering['option_uuid'],
				'source_type'       => $source,
				'first_name'        => 'Roster',
				'last_name'         => $last_name,
				'email'             => 'roster-' . $index . '@example.test',
				'phone'             => sprintf( '814-555-10%02d', $index ),
				'classification'    => (string) $offering['classification'],
				'validity_type'     => (string) $offering['validity_type'],
				'valid_local_date'  => 'one_day' === (string) $offering['validity_type'] ? (string) $context['today'] : '',
				'payment_assertion' => 'walk_in' === $source ? 'paid_cash' : 'complimentary',
				'config_revision'   => (int) $config['revision'],
				'evidence'          => array(
					'mailing_address' => array(
						'address_1' => '100 Test Lane',
						'city'      => 'Erie',
						'state'     => 'PA',
						'postcode'  => '16501',
					),
					'offering'        => array( 'label' => $label ),
				),
			)
		);
		if ( is_wp_error( $record ) ) {
			oras_desk_integration_fail( 'synthetic roster registration failed: ' . $record->get_error_code() );
		}
		$attendee = $attendees->confirm_slot( (int) $record['id'], ( 'family' === $record['classification'] ? 'family' : 'individual' ) . '-1', 'Roster', $last_name );
		if ( is_wp_error( $attendee ) ) {
			oras_desk_integration_fail( 'synthetic roster attendee failed: ' . $attendee->get_error_code() );
		}
		if ( $index < 6 ) {
			$check_in = $attendance->check_in( $event_id, (int) $attendee['id'], (string) $context['today'], (int) $context['desk_id'], wp_generate_uuid4(), 'Roster Fixture' );
			if ( is_wp_error( $check_in ) ) {
				oras_desk_integration_fail( 'synthetic roster attendance failed: ' . $check_in->get_error_code() );
			}
			++$checked_count;
		}
		$name = 'Roster ' . $last_name;
		$rows_by_name[ $name ] = $record;
		$type_counts[ (string) $offering['option_uuid'] ] = ( $type_counts[ (string) $offering['option_uuid'] ] ?? 0 ) + 1;
		if ( 'walk_in' === $source ) {
			++$walk_in_count;
		}
	}

	$other_record = $registrations->create_manual(
		array(
			'event_id'          => (int) $context['synthetic_ticketed_b']['event_id'],
			'option_uuid'       => (string) Event_Offering_Resolver::desk_offerings( (int) $context['synthetic_ticketed_b']['event_id'], Config::get_event_config( (int) $context['synthetic_ticketed_b']['event_id'] ) )[0]['option_uuid'],
			'source_type'       => 'walk_in',
			'first_name'        => 'Unrelated',
			'last_name'         => 'Registrant',
			'email'             => 'unrelated-roster@example.test',
			'phone'             => '814-555-9999',
			'classification'    => 'individual',
			'validity_type'     => 'full_event',
			'payment_assertion' => 'paid_card',
			'config_revision'   => (int) Config::get_event_config( (int) $context['synthetic_ticketed_b']['event_id'] )['revision'],
			'evidence'          => array( 'offering' => array( 'label' => 'Basic' ) ),
		)
	);
	oras_desk_integration_true( is_array( $other_record ), 'unrelated-event roster fixture is created independently' );

	$roster = new Event_Roster_Service();
	$first  = $roster->get( $event_id, array( 'limit' => 10 ) );
	$second = $roster->get(
		$event_id,
		array(
			'limit'  => 10,
			'offset' => $first['next_offset'],
		)
	);
	$third  = $roster->get(
		$event_id,
		array(
			'limit'  => 10,
			'offset' => $second['next_offset'],
		)
	);
	$all    = array_merge( $first['items'], $second['items'], $third['items'] );
	oras_desk_integration_same( count( $all ), count( $last_names ), 'ticketed roster opens with the full event population across bounded pages' );
	oras_desk_integration_true( true === $first['has_more'] && true === $second['has_more'] && false === $third['has_more'], 'Show More pagination reports each remaining roster page honestly' );
	oras_desk_integration_same( count( array_unique( array_column( $all, 'registration_uuid' ) ) ), count( $all ), 'Show More pagination does not repeat registrations' );
	$actual_names = array_column( $all, 'name' );
	$expected_names = $actual_names;
	usort(
		$expected_names,
		static function ( string $left, string $right ): int {
			$left_parts  = preg_split( '/\s+/', strtolower( $left ) );
			$right_parts = preg_split( '/\s+/', strtolower( $right ) );
			$left_parts  = is_array( $left_parts ) ? $left_parts : array( '' );
			$right_parts = is_array( $right_parts ) ? $right_parts : array( '' );
			return array( (string) end( $left_parts ), (string) reset( $left_parts ) ) <=> array( (string) end( $right_parts ), (string) reset( $right_parts ) );
		}
	);
	oras_desk_integration_same( $actual_names, $expected_names, 'ticketed roster is alphabetical by last name then first name' );
	oras_desk_integration_true( ! in_array( 'Unrelated Registrant', $actual_names, true ), 'unrelated event registrations never leak into the selected event roster' );
	oras_desk_integration_true( ! array_key_exists( 'email', $all[0] ) && ! array_key_exists( 'address', $all[0] ), 'volunteer roster omits manager-only contact fields' );
	oras_desk_integration_true( str_starts_with( (string) $all[0]['phone'], '814-555-' ), 'volunteer roster includes the required usable phone number' );
	oras_desk_integration_same(
		count(
			$roster->get(
				$event_id,
				array(
					'status' => 'checked_in',
					'limit'  => 50,
				)
			)['items']
		),
		$checked_count,
		'Checked In Today filter uses actual attendance'
	);
	oras_desk_integration_same(
		count(
			$roster->get(
				$event_id,
				array(
					'status' => 'not_checked_in',
					'limit'  => 50,
				)
			)['items']
		),
		count( $last_names ) - $checked_count,
		'Not Checked In filter excludes today attendance'
	);
	oras_desk_integration_same(
		count(
			$roster->get(
				$event_id,
				array(
					'status' => 'walk_ins',
					'limit'  => 50,
				)
			)['items']
		),
		$walk_in_count,
		'Walk-Ins filter uses registration source without changing statistics'
	);
	$first_type = (string) $offerings[0]['option_uuid'];
	oras_desk_integration_same(
		count(
			$roster->get(
				$event_id,
				array(
					'option_uuid' => $first_type,
					'limit'       => 50,
				)
			)['items']
		),
		$type_counts[ $first_type ],
		'one dynamic canonical registration type narrows the full roster'
	);
	$search = $roster->get(
		$event_id,
		array(
			'q'     => 'SearchTarget',
			'limit' => 10,
		)
	);
	oras_desk_integration_same( array_column( $search['items'], 'name' ), array( 'Roster SearchTarget' ), 'roster search queries the full event rather than the visible page' );
	$historic = array_values( array_filter( $all, static fn( array $item ): bool => 'Roster Zulu' === $item['name'] ) );
	oras_desk_integration_same( $historic[0]['registration_type'] ?? '', 'Historic General Admission', 'historical registration label remains honest when current canonical wording differs' );

	wp_set_current_user( (int) $context['desk_id'] );
	$station_token = Station_Session::issue( (int) $context['desk_id'], $event_id, (int) $config['revision'], 'Roster Authorization' );
	$detail_uuid   = (string) $rows_by_name['Roster Alpha']['registration_uuid'];
	$detail_route  = '/oras-tickets/v1/registration-desk/registrations/' . $detail_uuid;
	$volunteer_request = new WP_REST_Request( 'GET', $detail_route );
	$volunteer_request->set_header( 'X-ORAS-Desk-Station', $station_token );
	$volunteer_data = rest_do_request( $volunteer_request )->get_data();
	oras_desk_integration_true( ! isset( $volunteer_data['manager_detail'] ) && ! isset( $volunteer_data['editable_registration'] ), 'normal volunteer cannot retrieve manager-only roster detail through the API' );
	oras_desk_integration_true( ! str_contains( (string) ( $volunteer_data['registration']['contact_email'] ?? '' ), 'roster-1@' ), 'normal volunteer detail does not expose the full email address' );
	oras_desk_integration_true( true === Manager_Access::set_pin( '4826' ), 'disposable kiosk manager PIN is configured for manager-detail qualification' );
	$station = Station_Session::validate( $station_token, (int) $context['desk_id'], $event_id, (int) $config['revision'] );
	$manager_token = Manager_Access::unlock( '4826', $station, 'integration-roster' );
	if ( is_wp_error( $manager_token ) ) {
		oras_desk_integration_fail( 'manager roster unlock failed: ' . $manager_token->get_error_code() );
	}
	$manager_request = new WP_REST_Request( 'GET', $detail_route );
	$manager_request->set_header( 'X-ORAS-Desk-Station', $station_token );
	$manager_request->set_header( 'X-ORAS-Desk-Manager', $manager_token );
	$manager_data = rest_do_request( $manager_request )->get_data();
	oras_desk_integration_same( $manager_data['manager_detail']['email'] ?? '', 'roster-1@example.test', 'manager roster detail exposes full contact only after server-side PIN validation' );
	oras_desk_integration_same( $manager_data['manager_detail']['mailing_address']['address_1'] ?? '', '100 Test Lane', 'manager roster detail includes collected mailing address' );
	oras_desk_integration_same( $manager_data['manager_detail']['source_type'] ?? '', 'complimentary', 'manager roster detail includes registration source' );
	oras_desk_integration_true( isset( $manager_data['manager_detail']['audit_history'] ), 'manager roster detail includes correction and audit history' );
	oras_desk_integration_true( isset( $manager_data['manager_detail']['admission_diagnostics']['operational_registration'] ), 'manager roster detail includes readable current admission diagnostics' );
	oras_desk_integration_true( ! isset( $manager_data['admission']['_diagnostics'] ) && ! isset( $manager_data['admission']['_error_code'] ) && ! isset( $manager_data['admission']['_option'] ), 'registration detail API never exposes internal admission fields to the browser' );
}

/**
 * Exercise paid-but-not-found recovery and the audited manual exception.
 *
 * @return array<int,int> Woo order IDs created as protected fixtures.
 */
function oras_desk_integration_paid_not_found_recovery( array $context ): array {
	global $wpdb;
	$event_id = (int) $context['event_id'];
	$config   = Config::get_event_config( $event_id );
	$product  = (int) $context['product_ids'][0];
	$run      = (string) $context['run'];
	$listener_callbacks = oras_desk_integration_suspend_hook_class( 'woocommerce_order_status_changed', \ORAS\Tickets\Registration_Desk\Source_Change_Listener::class );
	$sources  = array(
		'missing'        => oras_desk_integration_order( $product, $event_id, 1, 'completed', $run, 'RecoveryMissing' ),
		'cancelled'      => oras_desk_integration_order( $product, $event_id, 1, 'cancelled', $run, 'RecoveryCancelled' ),
		'wrong_event'    => oras_desk_integration_order( (int) $context['offering_fixture']['unrelated_product'], (int) $context['offering_fixture']['unrelated_event_id'], 1, 'completed', $run, 'RecoveryWrongEvent' ),
		'canonical_only' => oras_desk_integration_order( $product, $event_id, 1, 'completed', $run, 'RecoveryCanonicalOnly' ),
		'cross_event'    => oras_desk_integration_order( (int) $context['offering_fixture']['unrelated_product'], (int) $context['offering_fixture']['unrelated_event_id'], 1, 'completed', $run, 'RecoveryCrossEvent' ),
	);
	oras_desk_integration_restore_hook_class( 'woocommerce_order_status_changed', $listener_callbacks );
	$order_ids = array_values( array_map( static fn( array $source ): int => (int) $source['order_id'], $sources ) );
	$recovery  = new Recovery_Service();
	$missing_email = 'recoverymissing-' . strtolower( $run ) . '@example.test';
	$search    = $recovery->search( $event_id, $missing_email, $config );
	oras_desk_integration_true( is_array( $search ) && 1 === count( $search['items'] ), 'manager recovery searches canonical Woo sources by purchaser name' );
	$missing = $search['items'][0];
	oras_desk_integration_same( $missing['event_access'], 'valid', 'eligible selected-event source is clearly valid for recovery' );
	oras_desk_integration_same( $missing['projected'], false, 'paid-but-not-found result is identified before synchronization' );
	$order_search = $recovery->search( $event_id, (string) $sources['missing']['order_id'], $config );
	oras_desk_integration_true( is_array( $order_search ) && in_array( (int) $sources['missing']['order_id'], array_map( 'intval', array_column( $order_search['items'], 'order_id' ) ), true ), 'manager recovery searches canonical Woo sources by order reference' );

	$source_before = oras_desk_integration_order_snapshot( (int) $sources['missing']['order_id'] );
	$sync = $recovery->sync( $event_id, (int) $sources['missing']['order_id'], (int) $sources['missing']['item_id'], $config );
	oras_desk_integration_true( is_array( $sync ) && 'registration_synchronized' === $sync['result'], 'one eligible canonical source synchronizes into the desk roster' );
	$registration_uuid = (string) $sync['registration_uuid'];
	$volunteer_results = ( new Service() )->search( $event_id, $missing_email );
	oras_desk_integration_true( '' !== $registration_uuid && in_array( $registration_uuid, array_column( $volunteer_results, 'registration_uuid' ), true ), 'synchronized canonical source becomes searchable through the normal volunteer path' );

	$token = Station_Session::issue( (int) $context['desk_id'], $event_id, (int) $config['revision'], 'Recovery Manager' );
	$check_context = oras_desk_integration_context( (int) $context['desk_id'], $event_id, $config, $token, wp_generate_uuid4() );
	$checked = ( new Service() )->check_in(
		$registration_uuid,
		array(
			'attendance_local_date' => (string) $context['today'],
			'explicit_unpaid'       => false,
			'arrivals'              => array(
				array(
					'slot_key'   => 'individual-1',
					'first_name' => 'Recovery',
					'last_name'  => 'Missing',
				),
			),
		),
		$check_context
	);
	oras_desk_integration_true( is_array( $checked ) && 'checked_in' === $checked['historical_result']['result'], 'recovered canonical registration uses the ordinary separate check-in action' );
	$registration = ( new Registration_Store() )->find_by_uuid( $registration_uuid );
	$tables       = Schema::table_names();
	$attendee_count_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['attendees']} WHERE registration_id = %d", $registration['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	$audit_count_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit']} WHERE registration_uuid = %s", $registration_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	$repeat = $recovery->sync( $event_id, (int) $sources['missing']['order_id'], (int) $sources['missing']['item_id'], $config );
	oras_desk_integration_same( $repeat['registration_uuid'] ?? '', $registration_uuid, 'repeated recovery synchronization preserves registration identity' );
	oras_desk_integration_same( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['attendees']} WHERE registration_id = %d", $registration['id'] ) ), $attendee_count_before, 'repeated recovery synchronization preserves attendee history' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	oras_desk_integration_same( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit']} WHERE registration_uuid = %s", $registration_uuid ) ), $audit_count_before, 'repeated recovery synchronization preserves audit history' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	oras_desk_integration_same( oras_desk_integration_hash( oras_desk_integration_order_snapshot( (int) $sources['missing']['order_id'] ) ), oras_desk_integration_hash( $source_before ), 'recovery does not mutate the Woo order, payment, notes, or line items' );

	$cancelled = $recovery->search( $event_id, 'recoverycancelled-' . strtolower( $run ) . '@example.test', $config );
	oras_desk_integration_same( $cancelled['items'][0]['event_access'] ?? '', 'not_valid', 'cancelled website order is shown as not valid for admission' );
	oras_desk_integration_error( $recovery->sync( $event_id, (int) $sources['cancelled']['order_id'], (int) $sources['cancelled']['item_id'], $config ), 'oras_desk_recovery_not_valid', 'cancelled website order cannot be synchronized as valid access' );
	$wrong = $recovery->search( $event_id, 'recoverywrongevent-' . strtolower( $run ) . '@example.test', $config );
	oras_desk_integration_same( $wrong['items'][0]['event_access'] ?? '', 'not_valid', 'unrelated event ticket is not valid without an explicit entitlement' );
	oras_desk_integration_error( $recovery->sync( $event_id, (int) $sources['wrong_event']['order_id'], (int) $sources['wrong_event']['item_id'], $config ), 'oras_desk_recovery_not_valid', 'unrelated event ticket cannot be synchronized into the selected event' );

	$cross_event_id = (int) $context['offering_fixture']['event_id'];
	$cross_config   = Config::get_event_config( $cross_event_id );
	$cross = $recovery->search( $cross_event_id, 'recoverycrossevent-' . strtolower( $run ) . '@example.test', $cross_config );
	oras_desk_integration_true( 'valid' === ( $cross['items'][0]['event_access'] ?? '' ) && true === ( $cross['items'][0]['cross_event'] ?? false ), 'explicit cross-event entitlement is identified as valid without becoming a walk-in product' );
	$cross_sync = $recovery->sync( $cross_event_id, (int) $sources['cross_event']['order_id'], (int) $sources['cross_event']['item_id'], $cross_config );
	oras_desk_integration_true( is_array( $cross_sync ) && '' !== (string) $cross_sync['registration_uuid'], 'explicit cross-event entitlement can be synchronized for existing-registration admission' );

	wp_set_current_user( (int) $context['desk_id'] );
	$unauthorized = new WP_REST_Request( 'GET', '/oras-tickets/v1/registration-desk/manager/recovery' );
	$unauthorized->set_header( 'X-ORAS-Desk-Station', $token );
	$unauthorized->set_param( 'q', $missing_email );
	$denied = rest_do_request( $unauthorized );
	oras_desk_integration_true( in_array( $denied->get_status(), array( 401, 403 ), true ), 'normal volunteer cannot invoke canonical recovery without manager PIN qualification' );
	$station = Station_Session::validate( $token, (int) $context['desk_id'], $event_id, (int) $config['revision'] );
	$manager_token = Manager_Access::unlock( '4826', $station, 'integration-recovery' );
	if ( is_wp_error( $manager_token ) ) {
		oras_desk_integration_fail( 'manager recovery unlock failed: ' . $manager_token->get_error_code() );
	}
	$authorized = new WP_REST_Request( 'GET', '/oras-tickets/v1/registration-desk/manager/recovery' );
	$authorized->set_header( 'X-ORAS-Desk-Station', $token );
	$authorized->set_header( 'X-ORAS-Desk-Manager', $manager_token );
	$authorized->set_param( 'q', $missing_email );
	oras_desk_integration_same( rest_do_request( $authorized )->get_status(), 200, 'manager PIN qualification authorizes canonical recovery search' );

	$offering = Event_Offering_Resolver::desk_offerings( $event_id, $config )[0];
	$service  = new Service();
	$users_before = count_users()['total_users'];
	$manual_payload = array(
		'first_name'           => 'Verified',
		'last_name'            => 'Exception',
		'email'                => 'verified-exception-' . $run . '@example.test',
		'phone'                => '814-555-7070',
		'option_uuid'          => (string) $offering['option_uuid'],
		'offering_fingerprint' => (string) $offering['offering_fingerprint'],
		'reason'               => 'Reviewed the attendee confirmation receipt.',
		'proof_acknowledged'   => true,
		'additional_attendees' => array(),
	);
	$manual_context = oras_desk_integration_context( (int) $context['desk_id'], $event_id, $config, $token, wp_generate_uuid4() );
	$manual = $service->create_manager_verified( $manual_payload, $manual_context );
	oras_desk_integration_true( is_array( $manual ) && 'manager_verified_recorded' === $manual['historical_result']['result'], 'manager can record the bounded audited exception after reviewing proof' );
	$manual_registration = $manual['historical_result']['registration'];
	oras_desk_integration_same( $manual_registration['source_type'], 'manager_verified_manual', 'manual exception retains the honest Manager Verified source' );
	oras_desk_integration_same( count( $manual['historical_result']['attendance'] ), 0, 'manager verified registration does not imply attendance or immediate admission' );
	oras_desk_integration_same( count_users()['total_users'], $users_before, 'manager verified recovery creates no attendee WordPress account' );
	$manual_audit = ( new Audit_Store() )->for_registration( (string) $manual_registration['registration_uuid'] );
	oras_desk_integration_true( 1 === count( $manual_audit ) && 'create_manager_verified_manual' === $manual_audit[0]['operation'], 'manager verified exception records the operator action and audit reason' );
	$roster = ( new Event_Roster_Service() )->get( $event_id, array( 'q' => 'Verified Exception' ) );
	oras_desk_integration_same( $roster['items'][0]['source_type'] ?? '', 'manager_verified_manual', 'event roster reports Manager Verified source honestly' );

	$duplicate_context = oras_desk_integration_context( (int) $context['desk_id'], $event_id, $config, $token, wp_generate_uuid4() );
	oras_desk_integration_error( $service->create_manager_verified( $manual_payload, $duplicate_context ), 'oras_desk_verified_duplicate', 'exact desk contact duplicate blocks a second manager verified record without name-only merging' );
	$canonical_payload = $manual_payload;
	$canonical_payload['first_name'] = 'Canonical';
	$canonical_payload['last_name']  = 'Blocked';
	$canonical_payload['email']      = 'recoverycanonicalonly-' . strtolower( $run ) . '@example.test';
	$canonical_payload['phone']      = '';
	$canonical_context = oras_desk_integration_context( (int) $context['desk_id'], $event_id, $config, $token, wp_generate_uuid4() );
	oras_desk_integration_error( $service->create_manager_verified( $canonical_payload, $canonical_context ), 'oras_desk_verified_duplicate', 'exact canonical Woo contact blocks manual creation and directs the manager to synchronize' );

	return $order_ids;
}

/** @param array<string,mixed> $context @return array<string,mixed> */
function oras_desk_integration_membership_workflow( array $context ): array {
	global $wpdb, $pmpro_levels;
	$level = array(
		'id'                => 701,
		'name'              => 'Fixture Annual Membership',
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
	$disabled = array_merge(
		$level,
		array(
			'id'   => 702,
			'name' => 'Not Offered at Events',
		)
	);
	if ( defined( 'PMPRO_VERSION' ) ) {
		$level_table = $wpdb->prefix . 'pmpro_membership_levels';
		$insert_level = static function ( array $values ) use ( $wpdb, $level_table ): int {
			$written = $wpdb->insert(
				$level_table,
				array(
					'name'              => (string) $values['name'],
					'description'       => 'Guarded Registration Desk fixture',
					'confirmation'      => '',
					'initial_payment'   => (string) $values['initial_payment'],
					'billing_amount'    => (string) $values['billing_amount'],
					'cycle_number'      => (int) $values['cycle_number'],
					'cycle_period'      => (string) $values['cycle_period'],
					'billing_limit'     => (int) $values['billing_limit'],
					'trial_amount'      => (string) $values['trial_amount'],
					'trial_limit'       => (int) $values['trial_limit'],
					'allow_signups'     => 1,
					'expiration_number' => (int) $values['expiration_number'],
					'expiration_period' => (string) $values['expiration_period'],
				)
			);
			if ( false === $written ) {
				oras_desk_integration_fail( 'synthetic PMPro membership level creation failed.' );
			}

			return (int) $wpdb->insert_id;
		};
		$level_id = $insert_level( $level );
		$disabled_id = $insert_level( $disabled );
	} else {
		$level_id = 701;
		$disabled_id = 702;
		$level['id'] = $level_id;
		$disabled['id'] = $disabled_id;
		update_option(
			'oras_registration_desk_test_pmpro_levels',
			array(
				$level_id    => $level,
				$disabled_id => $disabled,
			),
			false
		);
	}
	update_option(
		Config::MEMBERSHIP_MAPPINGS_OPTION,
		Config::normalize_membership_mappings(
			array(
				array(
					'level_id'           => $level_id,
					'event_sale_enabled' => true,
				),
			)
		),
		false
	);
	$offerings = Config::get_membership_offerings();
	oras_desk_integration_same( count( $offerings ), 1, 'desk exposes only administrator-enabled canonical membership levels' );
	oras_desk_integration_same( $offerings[0]['display_name'], 'Fixture Annual Membership', 'membership name is resolved from canonical PMPro data' );
	oras_desk_integration_same( $offerings[0]['price'], '35.00', 'membership reference price is resolved from canonical PMPro data' );
	oras_desk_integration_true( null === Config::membership_mapping( $disabled_id ), 'disabled membership level is unavailable for a new event sale' );

	$level['name'] = 'Renamed Fixture Membership';
	$level['initial_payment'] = '42.00';
	if ( defined( 'PMPRO_VERSION' ) ) {
		$wpdb->update(
			$wpdb->prefix . 'pmpro_membership_levels',
			array(
				'name'            => $level['name'],
				'initial_payment' => $level['initial_payment'],
			),
			array( 'id' => $level_id )
		);
		unset( $pmpro_levels[ $level_id ] );
		wp_cache_flush();
	} else {
		$level['id'] = $level_id;
		update_option(
			'oras_registration_desk_test_pmpro_levels',
			array(
				$level_id    => $level,
				$disabled_id => $disabled,
			),
			false
		);
	}
	$renamed = Membership_Offering_Resolver::resolve( $level_id );
	oras_desk_integration_same( $renamed['display_name'], 'Renamed Fixture Membership', 'canonical membership rename reaches the desk without reconfiguration' );
	oras_desk_integration_same( $renamed['price'], '42.00', 'canonical membership price change reaches the desk without reconfiguration' );

	$service = new Membership_Credit_Service();
	$config = Config::get_event_config( (int) $context['event_id'] );
	$token = Station_Session::issue( (int) $context['desk_id'], (int) $context['event_id'], (int) $config['revision'], 'Membership Volunteer' );
	$users_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed core table in guarded disposable database.
	$orders_before = count(
		wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
			)
		)
	);
	$cash_context = oras_desk_integration_context( (int) $context['desk_id'], (int) $context['event_id'], $config, $token, wp_generate_uuid4() );
	$cash = $service->create(
		array(
			'first_name'     => 'Cash',
			'last_name'      => 'Member',
			'email'          => 'cash-member-' . $context['run'] . '@example.test',
			'phone'          => '814-555-0171',
			'level_id'       => $level_id,
			'payment_method' => 'cash',
		),
		$cash_context
	);
	oras_desk_integration_true( is_array( $cash ) && 'sent' === $cash['email_status'], 'volunteer cash membership creates one pending activation and sends its email' );
	$cash_replay = $service->create(
		array(
			'first_name'     => 'Cash',
			'last_name'      => 'Member',
			'email'          => 'cash-member-' . $context['run'] . '@example.test',
			'phone'          => '814-555-0171',
			'level_id'       => $level_id,
			'payment_method' => 'cash',
		),
		$cash_context
	);
	oras_desk_integration_same( $cash_replay['activation_uuid'], $cash['activation_uuid'], 'membership retry reuses the original pending activation' );
	oras_desk_integration_same( $cash_replay['credit_code'], $cash['credit_code'], 'membership retry never creates a second credit code' );
	wp_set_current_user( (int) $context['desk_id'] );
	$request = new WP_REST_Request( 'POST', '/oras-tickets/v1/registration-desk/memberships' );
	$request->set_header( 'X-ORAS-Desk-Station', $token );
	$request->set_header( 'X-ORAS-Desk-Request', (string) $cash_context['request_uuid'] );
	$request->set_body_params(
		array(
			'first_name'     => 'Cash',
			'last_name'      => 'Member',
			'email'          => 'cash-member-' . $context['run'] . '@example.test',
			'phone'          => '814-555-0171',
			'level_id'       => $level_id,
			'payment_method' => 'cash',
		)
	);
	$response = rest_do_request( $request );
	oras_desk_integration_true( 200 === $response->get_status() && $cash['activation_uuid'] === ( $response->get_data()['activation_uuid'] ?? '' ), 'normal desk volunteer may record a legitimate membership through REST' );
	wp_set_current_user( (int) $context['member_id'] );
	$denied = rest_do_request( $request );
	oras_desk_integration_true( in_array( $denied->get_status(), array( 401, 403 ), true ), 'ordinary website member cannot use the desk membership mutation' );
	wp_set_current_user( (int) $context['desk_id'] );

	$check_context = oras_desk_integration_context( (int) $context['desk_id'], (int) $context['event_id'], $config, $token, wp_generate_uuid4() );
	$check = $service->create(
		array(
			'first_name'     => 'Check',
			'last_name'      => 'Member',
			'email'          => 'check-member-' . $context['run'] . '@example.test',
			'phone'          => '814-555-0172',
			'level_id'       => $level_id,
			'payment_method' => 'check',
		),
		$check_context
	);
	oras_desk_integration_true( is_array( $check ) && 'check' === $check['payment_method'], 'volunteer check membership uses the same pending activation workflow' );
	$card_context = oras_desk_integration_context( (int) $context['desk_id'], (int) $context['event_id'], $config, $token, wp_generate_uuid4() );
	$card_payload = array(
		'first_name'     => 'Card',
		'last_name'      => 'Member',
		'email'          => 'card-member-' . $context['run'] . '@example.test',
		'phone'          => '814-555-0173',
		'level_id'       => $level_id,
		'payment_method' => 'card',
	);
	$card = $service->create( $card_payload, $card_context );
	oras_desk_integration_true( is_array( $card ) && 'card' === $card['payment_method'] && 'sent' === $card['email_status'], 'card membership records a pending activation and sends its email' );
	$card_replay = $service->create( $card_payload, $card_context );
	oras_desk_integration_same( $card_replay['activation_uuid'], $card['activation_uuid'], 'card retry reuses its activation' );
	oras_desk_integration_same( $card_replay['credit_code'], $card['credit_code'], 'card retry reuses its credit' );
	oras_desk_integration_same( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), $users_before, 'membership recording creates no WordPress attendee account' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed core table in guarded disposable database.
	oras_desk_integration_same(
		count(
			wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		),
		$orders_before,
		'membership method recording creates no Woo order'
	);

	$corrected = $service->correct_contact(
		(string) $cash['activation_uuid'],
		array(
			'first_name' => 'Cash',
			'last_name'  => 'Corrected',
			'email'      => 'cash-corrected-' . $context['run'] . '@example.test',
			'phone'      => '814-555-0199',
		)
	);
	oras_desk_integration_true( is_array( $corrected ) && $corrected['credit_code'] === $cash['credit_code'], 'manager contact correction preserves the existing activation credit' );
	$resent = $service->resend( (string) $cash['activation_uuid'] );
	oras_desk_integration_true( is_array( $resent ) && $resent['credit_code'] === $cash['credit_code'], 'manager resend preserves the existing one-time credit' );
	$rows = ( new Offline_Membership_Store() )->for_event( (int) $context['event_id'] );
	oras_desk_integration_same( count( $rows ), 3, 'cash, check, card, and retries produce exactly three pending membership records' );
	$card_row = ( new Offline_Membership_Store() )->find_activation( (string) $card['activation_uuid'] );
	oras_desk_integration_same( (int) $card_row['email_attempts'], 1, 'card retry does not send a second activation email' );
	$membership_report = ( new Membership_Report_Service() )->get_report(
		array(
			'roster_scope' => Membership_Report_Service::ROSTER_ALL,
			'origin_event' => (int) $context['event_id'],
		)
	);
	$reported_card = array_values( array_filter( $membership_report['rows'], static fn( array $row ): bool => (string) $row['email'] === $card_payload['email'] ) );
	oras_desk_integration_true( 1 === count( $reported_card ) && 'Card' === $reported_card[0]['recorded_method_label'] && (int) $context['event_id'] === (int) $reported_card[0]['origin_event_id'], 'membership reporting shows one Card activation with its origin event' );

	return array(
		'cash_activation'  => (string) $cash['activation_uuid'],
		'check_activation' => (string) $check['activation_uuid'],
		'level_id'         => $level_id,
	);
}

/** Prove the complete training lifecycle is durable and isolated from every live reporting surface. */
function oras_desk_integration_training_workflow( array $context ): void {
	global $wpdb;
	$event_id    = (int) $context['synthetic_ticketed_a']['event_id'];
	$config      = Config::get_event_config( $event_id );
	$event       = Event_Catalog::find_any( $event_id );
	$offerings   = Training_Service::canonical_offerings( $event_id, $config );
	$memberships = Training_Service::canonical_membership_offerings();
	if ( ! is_array( $event ) || count( $offerings ) < 4 || empty( $memberships ) ) {
		oras_desk_integration_fail( 'training fixtures do not expose the required event and membership configuration.' );
	}

	wp_set_current_user( (int) $context['desk_id'] );
	$issued = Station_Session::issue_training( (int) $context['desk_id'], $event_id, (int) $config['revision'], 'Training Volunteer', (string) $event['start_date'] );
	if ( is_wp_error( $issued ) ) {
		oras_desk_integration_fail( 'training station token could not be issued.' );
	}
	$station       = $issued['payload'];
	$training_uuid = wp_generate_uuid4();
	$store         = new Training_Store();
	$snapshot      = new Training_Snapshot_Service();
	$tables        = Schema::table_names();
	$before_rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['training_sessions']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned test table.
	$live_before   = oras_desk_integration_protected_snapshot( $context, true );
	$captured_state = $snapshot->capture( $event_id, $training_uuid, $offerings, (string) $event['start_date'] );
	if ( is_wp_error( $captured_state ) ) {
		oras_desk_integration_fail( 'current event roster could not be copied into training: ' . $captured_state->get_error_code() );
	}
	$created = $store->create(
		array(
			'training_uuid'        => $training_uuid,
			'station_uuid'         => (string) $station['station_uuid'],
			'user_id'              => (int) $station['user_id'],
			'wp_session'           => (string) $station['wp_session'],
			'event_id'             => $event_id,
			'config_revision'      => (int) $config['revision'],
			'simulated_local_date' => (string) $event['start_date'],
		),
		$captured_state
	);
	if ( is_wp_error( $created ) ) {
		oras_desk_integration_fail( 'training session could not be created: ' . $created->get_error_code() );
	}
	/* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned test table. */
	oras_desk_integration_same( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['training_sessions']}" ), $before_rows + 1, 'training start creates exactly one isolated station row' );
	oras_desk_integration_same( (string) $created['simulated_local_date'], (string) $event['start_date'], 'training defaults to the selected event start date' );
	$current_roster = ( new Event_Roster_Service() )->get(
		$event_id,
		array(
			'status' => 'everyone',
			'limit'  => 50,
		)
	);
	oras_desk_integration_same( count( $created['state']['registrations'] ), count( $current_roster['items'] ), 'training roster snapshots every current event registration' );
	$current_names = array_column( $current_roster['items'], 'name' );
	$snapshot_names = array_column( array_values( $created['state']['registrations'] ), 'contact_name' );
	sort( $current_names );
	sort( $snapshot_names );
	oras_desk_integration_same( $snapshot_names, $current_names, 'training roster preserves the current event roster identities' );

	$resolved = Training_Context::validate_binding( $station, $created, $config, $event );
	oras_desk_integration_true( is_array( $resolved ), 'training context is bound to its station, event, configuration revision, and simulated date' );
	$changed_config = $config;
	$changed_config['revision'] = (int) $config['revision'] + 1;
	oras_desk_integration_error( Training_Context::validate_binding( $station, $created, $changed_config, $event ), 'oras_desk_training_config_changed', 'training fails closed when the event configuration revision changes' );

	$live_issued = Station_Session::issue( (int) $context['desk_id'], $event_id, (int) $config['revision'], 'Live Volunteer' );
	$live_station = Station_Session::validate( $live_issued, (int) $context['desk_id'], $event_id, (int) $config['revision'] );
	oras_desk_integration_true( is_array( $live_station ) && true === ( new Training_Context( $store ) )->assert_station_live( $live_station ), 'a second station remains independently live while Training Mode is active' );
	$live_route = oras_desk_integration_rest_offerings( (string) $issued['token'] );
	oras_desk_integration_true( 409 === $live_route->get_status() && 'oras_desk_training_live_route_forbidden' === ( $live_route->get_data()['code'] ?? '' ), 'a training token cannot be reused against a live Registration Desk endpoint' );

	$operation_context = static function ( array $row ) use ( $config, $event, $offerings, $memberships, $training_uuid ): array {
		return array(
			'training_uuid'                  => $training_uuid,
			'config_revision'                => (int) $row['config_revision'],
			'current_config_revision'        => (int) $config['revision'],
			'simulated_local_date'           => (string) $row['simulated_local_date'],
			'event_start_date'               => (string) $event['start_date'],
			'event_end_date'                 => (string) $event['end_date'],
			'canonical_offerings'            => $offerings,
			'canonical_membership_offerings' => $memberships,
		);
	};
	$mutate = static function ( callable $transition ) use ( $store, $station ): array {
		$row    = $store->find_for_station( (string) $station['station_uuid'] );
		$result = is_array( $row ) ? $store->mutate( (string) $station['station_uuid'], (int) $row['record_version'], $transition ) : new WP_Error( 'missing', 'missing' );
		if ( is_wp_error( $result ) ) {
			oras_desk_integration_fail( 'training transition failed: ' . $result->get_error_code() );
		}
		return $result;
	};

	$seeded = array_values( $created['state']['registrations'] );
	$individual = null;
	$family     = null;
	foreach ( $seeded as $registration ) {
		if ( 'full_event' !== (string) ( $registration['validity_type'] ?? '' ) ) {
			continue;
		}
		if ( 'family' === (string) ( $registration['classification'] ?? '' ) ) {
			$family = $registration;
		} elseif ( null === $individual ) {
			$individual = $registration;
		}
	}
	if ( ! is_array( $individual ) || ! is_array( $family ) ) {
		oras_desk_integration_fail( 'training fixtures require full-event individual and family registrations.' );
	}
	$check_in = static function ( array $registration, int $count ) use ( $mutate, $operation_context ): array {
		$attendee_uuids = array_slice( array_column( $registration['attendees'], 'attendee_uuid' ), 0, $count );
		$arrivals = array();
		for ( $index = count( $attendee_uuids ); $index < $count; ++$index ) {
			$arrivals[] = array(
				'slot_key'   => 'practice-family-' . ( $index + 1 ),
				'first_name' => 'Practice',
				'last_name'  => 'Family ' . ( $index + 1 ),
			);
		}
		$payload = array(
			'request_uuid'      => wp_generate_uuid4(),
			'registration_uuid' => (string) $registration['registration_uuid'],
			'attendee_uuids'    => $attendee_uuids,
			'arrivals'          => $arrivals,
		);
		return $mutate(
			static function ( array $state, array $row ) use ( $payload, $operation_context ) {
				return Training_Service::check_in_state( $state, $payload, $operation_context( $row ) );
			}
		);
	};
	$check_in( $individual, 1 );
	$check_in( $family, 2 );
	$row_on_first_date = $store->find_for_station( (string) $station['station_uuid'] );
	oras_desk_integration_same( Training_Service::stats( $row_on_first_date['state'], (string) $row_on_first_date['simulated_local_date'] )['today']['actual_people'], 3, 'individual and selected family attendees check in on the first simulated date' );

	$second_date = (string) $event['end_date'];
	$changed = $store->change_date( (string) $station['station_uuid'], (int) $row_on_first_date['record_version'], $second_date );
	if ( is_wp_error( $changed ) ) {
		oras_desk_integration_fail( 'training date could not be changed.' );
	}
	$reissued = Station_Session::reissue_training( $station, $second_date );
	if ( is_wp_error( $reissued ) ) {
		oras_desk_integration_fail( 'training station could not be rebound to the changed date.' );
	}
	$station = $reissued['payload'];
	$row_on_second_date = $store->find_for_station( (string) $station['station_uuid'] );
	$second_roster = Training_Service::roster( $row_on_second_date['state'], array(), $second_date );
	$checked_now = array_filter( $second_roster['items'], static fn( array $item ): bool => true === ( $item['checked_in_today'] ?? false ) );
	oras_desk_integration_same( count( $checked_now ), 0, 'changing the training date preserves prior attendance without counting it on the new date' );
	oras_desk_integration_same( Training_Service::stats( $row_on_second_date['state'], $second_date )['event_total']['attendance_by_day'][ (string) $event['start_date'] ] ?? 0, 3, 'prior-date check-ins remain in training attendance history' );
	$outside_date = ( new DateTimeImmutable( $second_date ) )->modify( '+1 day' )->format( 'Y-m-d' );
	oras_desk_integration_true( ! Training_Context::is_event_date( $outside_date, (string) $event['start_date'], (string) $event['end_date'] ), 'a simulated date outside the event range is rejected' );

	$walk_in = static function ( array $offering, string $payment, bool $family_walk_in = false ) use ( $mutate, $operation_context, $context ): array {
		$payload = array(
			'request_uuid'         => wp_generate_uuid4(),
			'option_uuid'          => (string) $offering['option_uuid'],
			'offering_fingerprint' => (string) $offering['offering_fingerprint'],
			'payment_assertion'    => $payment,
			'contact_name'         => 'Practice ' . ucwords( str_replace( '_', ' ', $payment ) ),
			'email'                => 'training-' . $payment . '-' . $context['run'] . '@example.invalid',
			'phone'                => '555-0199',
			'attendees'            => $family_walk_in ? array( array( 'name' => 'Practice Adult' ), array( 'name' => 'Practice Child' ) ) : array( array( 'name' => 'Practice Attendee' ) ),
		);
		return $mutate(
			static function ( array $state, array $row ) use ( $payload, $operation_context ) {
				return Training_Service::walk_in_state( $state, $payload, $operation_context( $row ) );
			}
		);
	};
	$individual_offering = current( array_filter( $offerings, static fn( array $offering ): bool => 'individual' === (string) $offering['classification'] && 'full_event' === (string) $offering['validity_type'] ) );
	$family_offering     = current( array_filter( $offerings, static fn( array $offering ): bool => 'family' === (string) $offering['classification'] && 'full_event' === (string) $offering['validity_type'] ) );
	foreach ( array( 'paid_card', 'paid_cash', 'paid_check', 'unpaid' ) as $payment ) {
		$walk_in( 'paid_cash' === $payment ? $family_offering : $individual_offering, $payment, 'paid_cash' === $payment );
	}
	oras_desk_integration_same( count( Training_Service::member_lookup( $store->find_for_station( (string) $station['station_uuid'] )['state'], 'Morgan' ) ), 0, 'training member search does not invent event-roster people as members' );
	foreach ( array( 'card', 'cash', 'check' ) as $payment_method ) {
		$payload = array(
			'request_uuid'   => wp_generate_uuid4(),
			'level_id'       => (int) $memberships[0]['level_id'],
			'payment_method' => $payment_method,
			'contact_name'   => 'Practice Membership ' . ucfirst( $payment_method ),
			'email'          => 'training-membership-' . $payment_method . '-' . $context['run'] . '@example.invalid',
		);
		$mutate(
			static function ( array $state, array $row ) use ( $payload, $operation_context ) {
				return Training_Service::record_membership_state( $state, $payload, $operation_context( $row ) );
			}
		);
	}
	$worked = $store->find_for_station( (string) $station['station_uuid'] );
	$stats  = Training_Service::stats( $worked['state'], $second_date );
	$baseline_stats = Training_Service::stats( $created['state'], (string) $created['simulated_local_date'] );
	oras_desk_integration_same( $stats['event_total']['walk_in_registrations'], $baseline_stats['event_total']['walk_in_registrations'] + 4, 'training stats include the copied roster and all training walk-ins' );
	oras_desk_integration_same(
		$stats['event_total']['payment_assertions'],
		array(
			'paid_card'  => 1,
			'paid_cash'  => 1,
			'paid_check' => 1,
			'unpaid'     => 1,
		),
		'training stats preserve each payment-method practice assertion without payment'
	);
	oras_desk_integration_same( $stats['memberships']['total'], 3, 'shared membership choices support card, cash, and check simulations' );

	$reset_state = $snapshot->capture( $event_id, $training_uuid, $offerings, $second_date );
	if ( is_wp_error( $reset_state ) ) {
		oras_desk_integration_fail( 'current event roster could not be refreshed during reset.' );
	}
	$reset = $store->reset( (string) $station['station_uuid'], (int) $worked['record_version'], $reset_state );
	if ( is_wp_error( $reset ) ) {
		oras_desk_integration_fail( 'training data reset failed.' );
	}
	$reset_row = $store->find_for_station( (string) $station['station_uuid'] );
	oras_desk_integration_same( count( $reset_row['state']['registrations'] ), count( $current_roster['items'] ), 'reset refreshes the complete current event roster snapshot' );
	oras_desk_integration_same( array( count( $reset_row['state']['attendance'] ), count( $reset_row['state']['memberships'] ), count( $reset_row['state']['history'] ) ), array( 0, 0, 0 ), 'reset clears only the mutable training dataset' );

	oras_desk_integration_true( $store->delete_for_station( (string) $station['station_uuid'] ), 'ending Training Mode removes its isolated station row' );
	oras_desk_integration_same( $store->find_for_station( (string) $station['station_uuid'] ), null, 'ended training session cannot be restored' );
	/* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned test table. */
	oras_desk_integration_same( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['training_sessions']}" ), $before_rows, 'training lifecycle leaves no residual training row' );
	$ended_live_route = oras_desk_integration_rest_offerings( (string) $issued['token'] );
	oras_desk_integration_true( 409 === $ended_live_route->get_status(), 'an ended training token still cannot be reused for live operations' );

	$live_after = oras_desk_integration_protected_snapshot( $context, true );
	foreach ( $live_before as $surface => $hash ) {
		oras_desk_integration_same( $live_after[ $surface ], $hash, 'complete training lifecycle leaves live ' . $surface . ' unchanged' );
	}
	oras_desk_integration_pass( 'training check-in, family check-in, walk-ins, payment practice, membership, statistics, date change, reset, and end remain isolated from live data' );
}

/** Prepare fixtures and run all single-connection checks. */
function oras_desk_integration_prepare(): void {
	global $wpdb;
	update_option( 'timezone_string', 'America/New_York', false );
	Schema::install();
	$tables_first = Schema::table_names();
	Schema::install();
	oras_desk_integration_true( Schema::tables_exist(), 'repeat-safe schema setup leaves all six tables present' );
	oras_desk_integration_true( Schema::verify_transactional_tables(), 'all six desk tables use InnoDB' );
	oras_desk_integration_same( count( $tables_first ), 6, 'schema owns four registration tables, one pending-membership table, and one isolated training table' );

	$run        = strtolower( wp_generate_password( 8, false, false ) );
	$today      = wp_date( 'Y-m-d', null, wp_timezone() );
	$yesterday  = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS, wp_timezone() );
	$tomorrow   = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, wp_timezone() );
	$event_id   = oras_desk_integration_event( $run, 'active', $today, $today );
	update_post_meta( $event_id, '_oras_rsvp_v1', array( 'enabled' => true ) );
	$other_id   = oras_desk_integration_event( $run, 'other', $today, $today );
	$past_id    = oras_desk_integration_event( $run, 'past', $yesterday, $yesterday );
	$config_fail_id = oras_desk_integration_event( $run, 'config-failure', $today, $today );
	$config_race_id = oras_desk_integration_event( $run, 'config-race', $today, $today );
	$activation_race_a = oras_desk_integration_event( $run, 'activation-race-a', $today, $today );
	$activation_race_b = oras_desk_integration_event( $run, 'activation-race-b', $today, $today );
	$admin_id   = wp_create_user( 'desk-admin-' . $run, wp_generate_password(), 'desk-admin-' . $run . '@example.test' );
	$desk_id    = wp_create_user( 'desk-staff-' . $run, wp_generate_password(), 'desk-staff-' . $run . '@example.test' );
	$member_id  = wp_create_user( 'desk-member-' . $run, wp_generate_password(), 'desk-member-' . $run . '@example.test' );
	if ( is_wp_error( $admin_id ) || is_wp_error( $desk_id ) || is_wp_error( $member_id ) ) {
		oras_desk_integration_fail( 'synthetic users could not be created.' );
	}
	( new WP_User( $admin_id ) )->set_role( 'administrator' );
	Capabilities::reconcile_roles();
	( new WP_User( $desk_id ) )->set_role( Capabilities::REGISTRATION_DESK_ROLE );
	( new WP_User( $member_id ) )->set_role( 'subscriber' );
	wp_set_current_user( (int) $admin_id );

	$product_individual = oras_desk_integration_product( $run, 'individual' );
	$product_family     = oras_desk_integration_product( $run, 'family' );
	$product_day        = oras_desk_integration_product( $run, 'one-day' );
	$product_ambiguous  = oras_desk_integration_product( $run, 'ambiguous' );
	$product_unknown    = oras_desk_integration_product( $run, 'unclassified' );
	$product_remap      = oras_desk_integration_product( $run, 'remap' );
	update_post_meta(
		$event_id,
		'_oras_tickets_v1',
		array(
			'schema'  => 1,
			'tickets' => array(
				'canonical-individual' => array(
					'ticket_key'      => 'canonical-individual',
					'name'            => 'Canonical Individual',
					'price'           => '20.00',
					'price_phases'    => array(),
					'capacity'        => 100,
					'sale_start'      => '',
					'sale_end'        => '',
					'description'     => 'Canonical integration offering',
					'attendance_mode' => 'onsite',
					'hide_sold_out'   => false,
				),
			),
		)
	);
	update_post_meta( $event_id, '_oras_tickets_woo_map_v1', array( 0 => $product_individual ) );
	update_post_meta( $product_individual, '_oras_ticket_event_id', $event_id );
	update_post_meta( $product_individual, '_oras_ticket_index', 0 );
	$options = array(
		array(
			'option_uuid'           => '11111111-1111-4111-8111-111111111111',
			'label'                 => 'Individual',
			'available_for_new'     => true,
			'existing_access_valid' => true,
			'classification'        => 'individual',
			'validity_type'         => 'full_event',
			'source_product_ids'    => array( $product_individual, $product_remap ),
		),
		array(
			'option_uuid'           => '22222222-2222-4222-8222-222222222222',
			'label'                 => 'Family',
			'available_for_new'     => true,
			'existing_access_valid' => true,
			'classification'        => 'family',
			'validity_type'         => 'full_event',
			'source_product_ids'    => array( $product_family ),
			'max_attendees'         => 4,
		),
		array(
			'option_uuid'           => '33333333-3333-4333-8333-333333333333',
			'label'                 => 'One day',
			'available_for_new'     => true,
			'existing_access_valid' => true,
			'classification'        => 'individual',
			'validity_type'         => 'one_day',
			'valid_local_date'      => $today,
			'source_product_ids'    => array( $product_day ),
		),
		array(
			'option_uuid'           => '44444444-4444-4444-8444-444444444444',
			'label'                 => 'Ambiguous A',
			'available_for_new'     => true,
			'existing_access_valid' => true,
			'classification'        => 'individual',
			'validity_type'         => 'full_event',
			'source_product_ids'    => array( $product_ambiguous ),
		),
		array(
			'option_uuid'           => '55555555-5555-4555-8555-555555555555',
			'label'                 => 'Ambiguous B',
			'available_for_new'     => true,
			'existing_access_valid' => true,
			'classification'        => 'individual',
			'validity_type'         => 'full_event',
			'source_product_ids'    => array( $product_ambiguous ),
		),
		array(
			'option_uuid'           => '66666666-6666-4666-8666-666666666666',
			'label'                 => 'Unclassified',
			'available_for_new'     => false,
			'existing_access_valid' => true,
			'classification'        => 'unclassified',
			'validity_type'         => 'unclassified',
			'source_product_ids'    => array( $product_unknown ),
		),
	);
	$config = oras_desk_integration_save_config( $event_id, $options );
	oras_desk_integration_save_config( $past_id, array( $options[0] ) );
	$offering_event_id   = oras_desk_integration_event( $run, 'canonical-offerings', $today, $today );
	$unrelated_event_id  = oras_desk_integration_event( $run, 'unrelated-offerings', $today, $today );
	$offering_product_a  = oras_desk_integration_product( $run, 'canonical-a' );
	$offering_product_b  = oras_desk_integration_product( $run, 'canonical-b' );
	$unrelated_product   = oras_desk_integration_product( $run, 'unrelated-ticket' );
	$canonical_ticket = static fn( string $key, string $name, string $price ): array => array(
		'ticket_key'      => $key,
		'name'            => $name,
		'price'           => $price,
		'price_phases'    => array(),
		'capacity'        => 100,
		'sale_start'      => '',
		'sale_end'        => '',
		'description'     => 'Canonical offering description',
		'attendance_mode' => 'onsite',
		'hide_sold_out'   => false,
	);
	$walk_in_past_id      = oras_desk_integration_event( $run, 'walk-in-past', $yesterday, $yesterday );
	$walk_in_past_product = oras_desk_integration_product( $run, 'walk-in-past-ticket' );
	update_post_meta(
		$walk_in_past_id,
		'_oras_tickets_v1',
		array(
			'schema'  => 1,
			'tickets' => array( 'walk-in-past' => $canonical_ticket( 'walk-in-past', 'Past Canonical Ticket', '20.00' ) ),
		)
	);
	update_post_meta( $walk_in_past_id, '_oras_tickets_woo_map_v1', array( 0 => $walk_in_past_product ) );
	$walk_in_past_config = Config::save_event_config(
		$walk_in_past_id,
		array(
			'enabled'      => true,
			'ticket_rules' => array(
				array(
					'ticket_key'     => 'walk-in-past',
					'classification' => 'individual',
					'validity_type'  => 'full_event',
					'max_attendees'  => 1,
				),
			),
			'entitlements' => array(),
		),
		0
	);
	if ( is_wp_error( $walk_in_past_config ) ) {
		oras_desk_integration_fail( 'past canonical walk-in fixture configuration failed.' );
	}
	$running_event_id = oras_desk_integration_event( $run, 'running-multi-day', $yesterday, wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, wp_timezone() ) );
	$future_event_id  = oras_desk_integration_event( $run, 'future-current-year', wp_date( 'Y-m-d', time() + ( 5 * DAY_IN_SECONDS ), wp_timezone() ), wp_date( 'Y-m-d', time() + ( 5 * DAY_IN_SECONDS ), wp_timezone() ) );
	update_post_meta( $running_event_id, '_oras_rsvp_v1', array( 'enabled' => true ) );
	update_post_meta( $future_event_id, '_oras_rsvp_v1', array( 'enabled' => true ) );

	$original_timezone = (string) get_option( 'timezone_string', '' );
	update_option( 'timezone_string', 'Pacific/Pago_Pago', false );
	$western_date = wp_date( 'Y-m-d', null, wp_timezone() );
	$timezone_event_id = oras_desk_integration_event( $run, 'site-timezone-boundary', $western_date, $western_date );
	update_post_meta( $timezone_event_id, '_oras_rsvp_v1', array( 'enabled' => true ) );
	$timezone_row = Event_Catalog::find_any( $timezone_event_id );
	oras_desk_integration_true( is_array( $timezone_row ) && Event_Catalog::is_available_on( $timezone_row, $western_date ), 'site-local western date keeps an event ending today eligible for the kiosk catalog' );
	update_option( 'timezone_string', 'Pacific/Kiritimati', false );
	$eastern_date = wp_date( 'Y-m-d', null, wp_timezone() );
	oras_desk_integration_true( $eastern_date > $western_date, 'timezone fixture crosses a site-local calendar-date boundary' );
	oras_desk_integration_true( ! Event_Catalog::is_available_on( $timezone_row, $eastern_date ), 'site timezone controls whether the event end date has passed' );
	update_option( 'timezone_string', $original_timezone, false );
	update_post_meta(
		$offering_event_id,
		'_oras_tickets_v1',
		array(
			'schema'  => 1,
			'tickets' => array( 'ticket-a' => $canonical_ticket( 'ticket-a', 'Canonical Alpha', '30.00' ) ),
		)
	);
	update_post_meta( $offering_event_id, '_oras_tickets_woo_map_v1', array( 0 => $offering_product_a ) );
	update_post_meta(
		$unrelated_event_id,
		'_oras_tickets_v1',
		array(
			'schema'  => 1,
			'tickets' => array( 'unrelated' => $canonical_ticket( 'unrelated', 'Unrelated Ticket', '80.00' ) ),
		)
	);
	update_post_meta( $unrelated_event_id, '_oras_tickets_woo_map_v1', array( 0 => $unrelated_product ) );
	$offering_config = Config::save_event_config(
		$offering_event_id,
		array(
			'enabled'      => true,
			'ticket_rules' => array(),
			'entitlements' => array(
				array(
					'entitlement_uuid'  => wp_generate_uuid4(),
					'source_event_id'   => $unrelated_event_id,
					'source_product_id' => $unrelated_product,
					'classification'    => 'individual',
					'validity_type'     => 'full_event',
				),
			),
		),
		0
	);
	if ( is_wp_error( $offering_config ) ) {
		oras_desk_integration_fail( 'canonical offering configuration failed.' );
	}
	$synthetic_a_id = oras_desk_integration_event( $run, 'synthetic-ticketed-a', $today, $tomorrow );
	$synthetic_a_products = array();
	$synthetic_a_tickets  = array();
	$synthetic_a_names    = array( 'General Admission', 'Family Pass', 'Student Pass', 'Single-Day Pass' );
	foreach ( $synthetic_a_names as $index => $name ) {
		$key = 'synthetic-a-' . ( $index + 1 );
		$synthetic_a_products[] = oras_desk_integration_product( $run, $key );
		$synthetic_a_tickets[ $key ] = $canonical_ticket( $key, $name, (string) ( 10 + $index * 5 ) . '.00' );
	}
	update_post_meta(
		$synthetic_a_id,
		'_oras_tickets_v1',
		array(
			'schema'  => 1,
			'tickets' => $synthetic_a_tickets,
		)
	);
	update_post_meta( $synthetic_a_id, '_oras_tickets_woo_map_v1', $synthetic_a_products );
	update_post_meta(
		$synthetic_a_id,
		'_oras_rsvp_v1',
		array(
			'enabled'          => true,
			'capacity'         => 100,
			'waitlist_enabled' => true,
		)
	);
	$synthetic_a_config = Config::save_event_config(
		$synthetic_a_id,
		array(
			'enabled'      => true,
			'ticket_rules' => array(
				array(
					'ticket_key'     => 'synthetic-a-1',
					'classification' => 'individual',
					'max_attendees'  => 1,
					'validity_type'  => 'full_event',
				),
				array(
					'ticket_key'     => 'synthetic-a-2',
					'classification' => 'family',
					'max_attendees'  => 5,
					'validity_type'  => 'full_event',
				),
				array(
					'ticket_key'     => 'synthetic-a-3',
					'classification' => 'individual',
					'max_attendees'  => 1,
					'validity_type'  => 'full_event',
				),
				array(
					'ticket_key'       => 'synthetic-a-4',
					'classification'   => 'individual',
					'max_attendees'    => 1,
					'validity_type'    => 'one_day',
					'valid_local_date' => $today,
				),
			),
			'entitlements' => array(),
		),
		0
	);
	if ( is_wp_error( $synthetic_a_config ) ) {
		oras_desk_integration_fail( 'synthetic ticketed event A configuration failed.' );
	}
	$synthetic_b_id = oras_desk_integration_event( $run, 'synthetic-ticketed-many', $today, $today );
	$synthetic_b_products = array();
	$synthetic_b_tickets  = array();
	foreach ( array( 'Basic', 'Premium', 'Virtual', 'Exhibitor', 'Workshop', 'Weekend', 'Guest' ) as $index => $name ) {
		$key = 'synthetic-b-' . ( $index + 1 );
		$synthetic_b_products[] = oras_desk_integration_product( $run, $key );
		$synthetic_b_tickets[ $key ] = $canonical_ticket( $key, $name, (string) ( 20 + $index * 5 ) . '.00' );
	}
	update_post_meta(
		$synthetic_b_id,
		'_oras_tickets_v1',
		array(
			'schema'  => 1,
			'tickets' => $synthetic_b_tickets,
		)
	);
	update_post_meta( $synthetic_b_id, '_oras_tickets_woo_map_v1', $synthetic_b_products );
	$synthetic_b_config = Config::save_event_config(
		$synthetic_b_id,
		array(
			'enabled'      => true,
			'ticket_rules' => array(),
			'entitlements' => array(),
		),
		0
	);
	if ( is_wp_error( $synthetic_b_config ) ) {
		oras_desk_integration_fail( 'synthetic ticketed event B configuration failed.' );
	}
	oras_desk_integration_same( count( Event_Offering_Resolver::desk_offerings( $synthetic_a_id, $synthetic_a_config ) ), 4, 'synthetic ticketed event A exposes four canonical offerings' );
	oras_desk_integration_same( count( Event_Offering_Resolver::desk_offerings( $synthetic_b_id, $synthetic_b_config ) ), 7, 'synthetic ticketed event B exposes enough canonical offerings for the large picker' );
	$rsvp_available_id = oras_desk_integration_event( $run, 'rsvp-available', $today, $today );
	$rsvp_waitlist_id  = oras_desk_integration_event( $run, 'rsvp-waitlist', $today, $today );
	$rsvp_full_id      = oras_desk_integration_event( $run, 'rsvp-full', $today, $today );
	foreach ( array(
		$rsvp_available_id => true,
		$rsvp_waitlist_id  => true,
		$rsvp_full_id      => false,
	) as $rsvp_event_id => $waitlist_enabled ) {
		update_post_meta(
			$rsvp_event_id,
			'_oras_rsvp_v1',
			array(
				'enabled'          => true,
				'capacity'         => $rsvp_event_id === $rsvp_available_id ? 2 : 1,
				'waitlist_enabled' => $waitlist_enabled,
				'open_at'          => '',
				'close_at'         => '',
			)
		);
		$rsvp_config = Config::save_event_config(
			$rsvp_event_id,
			array(
				'enabled'      => true,
				'ticket_rules' => array(),
				'entitlements' => array(),
			),
			0
		);
		if ( is_wp_error( $rsvp_config ) ) {
			oras_desk_integration_fail( 'RSVP fixture configuration failed.' );
		}
		update_user_meta( (int) $member_id, '_oras_rsvp_event_' . $rsvp_event_id, 'yes' );
		update_user_meta( (int) $member_id, '_oras_rsvp_event_' . $rsvp_event_id . '_attendance_mode', 'onsite' );
		update_user_meta(
			(int) $member_id,
			'_oras_rsvp_event_' . $rsvp_event_id . '_contact',
			array(
				'first_name' => 'Website',
				'last_name'  => 'RSVP',
				'email'      => 'website-rsvp-' . $run . '@example.test',
				'phone'      => '814-555-0199',
			)
		);
	}
	oras_desk_integration_true( true === Config::set_active_event_id( $event_id ), 'administrator selects the active event' );
	$first_combined = Config::save_and_activate(
		$other_id,
		array(
			'enabled' => true,
			'options' => array( $options[0] ),
		),
		0
	);
	oras_desk_integration_true( is_array( $first_combined ) && 1 === $first_combined['revision'] && $other_id === Config::get_active_event_id(), 'first configuration and activation commit atomically' );
	Config::set_active_event_id( $event_id );
	$meta_failure = static fn() => new WP_Error( 'oras_desk_test_meta_write_failed', 'Synthetic meta write failure.' );
	add_filter( 'oras_registration_desk_config_meta_write_error', $meta_failure );
	$failed_meta = Config::save_and_activate(
		$config_fail_id,
		array(
			'enabled' => true,
			'options' => array( $options[0] ),
		),
		0
	);
	remove_filter( 'oras_registration_desk_config_meta_write_error', $meta_failure );
	oras_desk_integration_error( $failed_meta, 'oras_desk_test_meta_write_failed', 'forced configuration write failure is reported' );
	oras_desk_integration_same( Config::get_event_config( $config_fail_id )['revision'], 0, 'failed configuration write leaves durable revision unchanged' );
	oras_desk_integration_same( Config::get_active_event_id(), $event_id, 'failed configuration write leaves active event unchanged' );
	$active_failure = static fn() => new WP_Error( 'oras_desk_test_active_write_failed', 'Synthetic active option failure.' );
	add_filter( 'oras_registration_desk_config_active_write_error', $active_failure );
	$failed_active = Config::save_and_activate(
		$config_fail_id,
		array(
			'enabled' => true,
			'options' => array( $options[0] ),
		),
		0
	);
	remove_filter( 'oras_registration_desk_config_active_write_error', $active_failure );
	oras_desk_integration_error( $failed_active, 'oras_desk_test_active_write_failed', 'forced active-event write failure is reported' );
	oras_desk_integration_same( Config::get_event_config( $config_fail_id )['revision'], 0, 'active-event failure rolls back the configuration write and refreshes cache' );
	oras_desk_integration_same( Config::get_active_event_id(), $event_id, 'active-event failure leaves the prior active event visible after rollback' );

	$orders = array(
		'concurrent'         => oras_desk_integration_order( $product_individual, $event_id, 1, 'processing', $run, 'Concurrent' ),
		'atomic'             => oras_desk_integration_order( $product_individual, $event_id, 1, 'processing', $run, 'Atomic' ),
		'completed'          => oras_desk_integration_order( $product_individual, $event_id, 1, 'completed', $run, 'Completed' ),
		'on_hold'            => oras_desk_integration_order( $product_individual, $event_id, 1, 'on-hold', $run, 'OnHold' ),
		'cancelled'          => oras_desk_integration_order( $product_individual, $event_id, 1, 'processing', $run, 'Cancelled' ),
		'stale_cancelled'    => oras_desk_integration_order( $product_individual, $event_id, 1, 'processing', $run, 'StaleCancelled' ),
		'late_cancelled'     => oras_desk_integration_order( $product_individual, $event_id, 1, 'processing', $run, 'LateCancelled' ),
		'refunded'           => oras_desk_integration_order( $product_individual, $event_id, 1, 'refunded', $run, 'Refunded' ),
		'family'             => oras_desk_integration_order( $product_family, $event_id, 1, 'completed', $run, 'Family' ),
		'one_day'            => oras_desk_integration_order( $product_day, $event_id, 1, 'completed', $run, 'OneDay' ),
		'ambiguous'          => oras_desk_integration_order( $product_ambiguous, $event_id, 1, 'completed', $run, 'Ambiguous' ),
		'unclassified'       => oras_desk_integration_order( $product_unknown, $event_id, 1, 'completed', $run, 'Unknown' ),
		'cross_event'        => oras_desk_integration_order( $product_individual, $other_id, 1, 'completed', $run, 'CrossEvent' ),
		'partial'            => oras_desk_integration_order( $product_individual, $event_id, 2, 'completed', $run, 'Partial' ),
		'past'               => oras_desk_integration_order( $product_individual, $past_id, 1, 'completed', $run, 'Past' ),
		'walk_in_past_board' => oras_desk_integration_order( $walk_in_past_product, $walk_in_past_id, 1, 'completed', $run, 'PastBoard' ),
		'quantity'           => oras_desk_integration_order( $product_individual, $event_id, 2, 'processing', $run, 'Quantity' ),
		'remap'              => oras_desk_integration_order( $product_remap, $event_id, 1, 'processing', $run, 'Remap' ),
	);
	$refund = wc_create_refund(
		array(
			'order_id'       => $orders['partial']['order_id'],
			'amount'         => 20,
			'reason'         => 'Synthetic partial-refund fixture',
			'refund_payment' => false,
			'restock_items'  => false,
			'line_items'     => array(
				$orders['partial']['item_id'] => array(
					'qty'          => 1,
					'refund_total' => 20,
					'refund_tax'   => array(),
				),
			),
		)
	);
	if ( is_wp_error( $refund ) ) {
		oras_desk_integration_fail( 'partial refund fixture failed: ' . $refund->get_error_message() );
	}

	oras_desk_integration_discovery( $run, $event_id, $other_id, $product_individual, $config );

	$projector = new Projection_Service();
	$projected = array();
	foreach ( $orders as $key => $source ) {
		$is_walk_in_past = 'walk_in_past_board' === $key;
		$target_config = 'past' === $key ? Config::get_event_config( $past_id ) : ( $is_walk_in_past ? $walk_in_past_config : $config );
		$target_event  = 'past' === $key ? $past_id : ( $is_walk_in_past ? $walk_in_past_id : $event_id );
		$result = $projector->reconcile_source( $target_event, $source['order_id'], $source['item_id'], $target_config );
		if ( is_wp_error( $result ) ) {
			oras_desk_integration_fail( 'projection failed for ' . $key . ': ' . $result->get_error_code() );
		}
		$projected[ $key ] = $result;
	}
	oras_desk_integration_same( $projected['concurrent']['resolution']['resolution'], 'supported', 'direct individual source resolves through immutable event and product evidence' );
	oras_desk_integration_same( $projected['family']['resolution']['resolution'], 'supported', 'explicitly configured family source is supported' );
	oras_desk_integration_same( $projected['family']['resolution']['max_attendees'], 4, 'family source retains its explicit attendee ceiling' );
	oras_desk_integration_same( $projected['one_day']['resolution']['resolution'], 'supported', 'explicitly configured one-day source is supported' );
	oras_desk_integration_same( $projected['one_day']['resolution']['valid_local_date'], $today, 'one-day source retains its explicit local date' );
	oras_desk_integration_same( $projected['ambiguous']['resolution']['resolution'], 'review_required', 'conflicting mappings require review' );
	oras_desk_integration_same( $projected['unclassified']['resolution']['resolution'], 'review_required', 'unclassified source requires review' );
	oras_desk_integration_same( $projected['cross_event']['resolution']['resolution'], 'review_required', 'cross-event source is not inferred into the active event' );
	oras_desk_integration_same( $projected['partial']['resolution']['eligibility'], 'review_required', 'partial-refund unit ambiguity requires review' );
	$quantity_unit_two = $projected['quantity']['registrations'][1];
	$quantity_order = wc_get_order( $orders['quantity']['order_id'] );
	$quantity_item  = $quantity_order->get_item( $orders['quantity']['item_id'] );
	$quantity_item->set_quantity( 1 );
	$quantity_item->save();
	$quantity_order->calculate_totals( false );
	$quantity_order->save();
	oras_desk_integration_pass( 'quantity fixture reduced from two source units to one after projection' );

	$registration_store = new Registration_Store();
	$concurrent_row = $projected['concurrent']['registrations'][0];
	$repeat = $projector->reconcile_source( $event_id, $orders['concurrent']['order_id'], $orders['concurrent']['item_id'], $config );
	oras_desk_integration_same( $repeat['registrations'][0]['registration_uuid'], $concurrent_row['registration_uuid'], 'source projection is repeat-safe and preserves registration identity' );
	$service = new Service();
	oras_desk_integration_true( count( $service->search( $event_id, 'Concurrent' ) ) >= 1, 'event-scoped operational search finds the supported registration' );
	oras_desk_integration_same( count( $service->search( $event_id, 'Zznoresult' ) ), 0, 'alphabetic search with no match does not become a wildcard phone search' );
	oras_desk_integration_error( $service->detail( $other_id, $concurrent_row['registration_uuid'] ), 'oras_desk_registration_missing', 'object access cannot cross the active event boundary' );
	$valid_detail = $service->detail( $event_id, (string) $concurrent_row['registration_uuid'] );
	oras_desk_integration_same( $valid_detail['admission']['state'] ?? '', 'eligible', 'valid live source and active desk record have one eligible detail state' );
	oras_desk_integration_true( true === ( $valid_detail['admission']['selection_allowed'] ?? false ) && true === ( $valid_detail['admission']['check_in_allowed'] ?? false ), 'eligible detail permits both attendee selection and check-in' );
	$refunded_detail = $service->detail( $event_id, (string) $projected['refunded']['registrations'][0]['registration_uuid'] );
	oras_desk_integration_same( $refunded_detail['admission']['state'] ?? '', 'revoked', 'refunded live source cannot appear registration-valid' );
	$past_detail = $service->detail( $past_id, (string) $projected['past']['registrations'][0]['registration_uuid'] );
	oras_desk_integration_same( $past_detail['admission']['state'] ?? '', 'wrong_day', 'registration detail identifies an event that is not valid today' );
	oras_desk_integration_same( $past_detail['admission']['check_in_allowed'] ?? null, false, 'wrong-day detail does not offer check-in' );

	$cancel_order = wc_get_order( $orders['cancelled']['order_id'] );
	$cancel_order->set_status( 'cancelled' );
	$cancel_order->save();
	oras_desk_integration_pass( 'cancellation fixture changed after projection/search and before desk admission' );
	$listener_callbacks = oras_desk_integration_suspend_hook_class( 'woocommerce_order_status_changed', \ORAS\Tickets\Registration_Desk\Source_Change_Listener::class );
	$stale_cancel_order = wc_get_order( $orders['stale_cancelled']['order_id'] );
	$stale_cancel_order->set_status( 'cancelled' );
	$stale_cancel_order->save();
	oras_desk_integration_restore_hook_class( 'woocommerce_order_status_changed', $listener_callbacks );
	$stale_registration = $registration_store->find_by_uuid( (string) $projected['stale_cancelled']['registrations'][0]['registration_uuid'] );
	oras_desk_integration_same( $stale_registration['status'] ?? '', 'active', 'synthetic contradiction retains an active stored projection after the live source is cancelled' );
	$stale_detail = $service->detail( $event_id, (string) $stale_registration['registration_uuid'] );
	oras_desk_integration_same( $stale_detail['admission']['state'] ?? '', 'revoked', 'detail uses live source cancellation as the authoritative admission state' );
	oras_desk_integration_same( $stale_detail['admission']['selection_allowed'] ?? null, false, 'live-invalid detail does not permit attendee selection' );
	oras_desk_integration_same( $stale_detail['admission']['check_in_allowed'] ?? null, false, 'live-invalid detail does not offer check-in' );

	$now = gmdate( 'Y-m-d H:i:s' );
	$source_null_uuid = wp_generate_uuid4();
	$inserted = $wpdb->insert(
		$tables_first['registrations'],
		array(
			'registration_uuid'     => $source_null_uuid,
			'event_id'              => $event_id,
			'option_uuid'           => '11111111-1111-4111-8111-111111111111',
			'source_type'           => 'complimentary',
			'source_key'            => null,
			'source_order_id'       => null,
			'source_order_item_id'  => null,
			'source_unit_number'    => null,
			'classification'        => 'individual',
			'status'                => 'active',
			'source_status'         => '',
			'source_contact_name'   => '',
			'source_email'          => '',
			'source_phone'          => '',
			'search_name'           => 'future fixture',
			'search_email'          => '',
			'search_phone'          => '',
			'coverage_type'         => 'individual',
			'validity_type'         => 'full_event',
			'valid_local_date'      => null,
			'payment_assertion'     => null,
			'source_evidence'       => '{}',
			'source_checked_at_utc' => null,
			'config_revision'       => (int) $config['revision'],
			'record_version'        => 1,
			'created_at_utc'        => $now,
			'updated_at_utc'        => $now,
		)
	);
	oras_desk_integration_same( $inserted, 1, 'source-null registration is accepted without a fake order' );

	wp_set_current_user( (int) $desk_id );
	$token_one = Station_Session::issue( (int) $desk_id, $event_id, (int) $config['revision'], 'Operator One' );
	$token_two = Station_Session::issue( (int) $desk_id, $event_id, (int) $config['revision'], 'Operator Two' );
	$station_one = Station_Session::validate( $token_one, (int) $desk_id, $event_id, (int) $config['revision'] );
	$station_two = Station_Session::validate( $token_two, (int) $desk_id, $event_id, (int) $config['revision'] );
	oras_desk_integration_true( ! is_wp_error( $station_one ) && ! is_wp_error( $station_two ) && $station_one['station_uuid'] !== $station_two['station_uuid'], 'two devices retain independent station identities under one shared login' );
	oras_desk_integration_same( $station_one['operator_label'], 'Operator One', 'first device retains its operator label' );
	oras_desk_integration_same( $station_two['operator_label'], 'Operator Two', 'second device retains its operator label' );

	rest_get_server();
	$station_request = new WP_REST_Request( 'POST', '/oras-tickets/v1/registration-desk/station' );
	$station_request->set_param( 'operator_label', 'REST Operator' );
	$station_request->set_param( 'event_id', $event_id );
	$station_response = rest_do_request( $station_request );
	oras_desk_integration_same( $station_response->get_status(), 200, 'restricted desk account can bootstrap a station without an existing station token' );
	$bypass_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users' ) );
	oras_desk_integration_same( $bypass_response->get_data()['code'] ?? '', 'oras_desk_route_forbidden', 'restricted account cannot bypass into a non-desk REST endpoint' );
	wp_set_current_user( (int) $member_id );
	$denied_response = rest_do_request( $station_request );
	oras_desk_integration_true( 401 === $denied_response->get_status() || 403 === $denied_response->get_status(), 'ordinary subscriber cannot use the desk endpoint directly' );
	wp_set_current_user( (int) $desk_id );
	$reverse_request = new WP_REST_Request( 'POST', '/oras-tickets/v1/registration-desk/registrations/' . wp_generate_uuid4() . '/attendees/' . wp_generate_uuid4() . '/reverse' );
	$reverse_denied = rest_do_request( $reverse_request );
	oras_desk_integration_true( 401 === $reverse_denied->get_status() || 403 === $reverse_denied->get_status(), 'desk role cannot invoke the administrator reversal endpoint' );
	$blocked_detail_request = new WP_REST_Request( 'GET', '/oras-tickets/v1/registration-desk/registrations/' . (string) $stale_registration['registration_uuid'] );
	$blocked_detail_request->set_header( 'X-ORAS-Desk-Station', $token_one );
	$blocked_detail_data = rest_do_request( $blocked_detail_request )->get_data();
	oras_desk_integration_same( $blocked_detail_data['admission']['state'] ?? '', 'revoked', 'volunteer detail API returns the normalized live-invalid state' );
	oras_desk_integration_true( ! str_contains( wp_json_encode( $blocked_detail_data ), 'Historical label ignored by desk' ), 'volunteer detail API removes known internal historical wording' );
	oras_desk_integration_true( ! isset( $blocked_detail_data['admission']['_diagnostics'] ) && ! isset( $blocked_detail_data['admission']['_error_code'] ) && ! isset( $blocked_detail_data['admission']['_option'] ), 'volunteer detail API omits manager-only admission internals' );

	$context = array(
		'run'                  => $run,
		'today'                => $today,
		'event_id'             => $event_id,
		'other_event_id'       => $other_id,
		'past_event_id'        => $past_id,
		'admin_id'             => (int) $admin_id,
		'desk_id'              => (int) $desk_id,
		'member_id'            => (int) $member_id,
		'user_ids'             => array( (int) $admin_id, (int) $desk_id, (int) $member_id ),
		'product_ids'          => array_merge( array( $product_individual, $product_family, $product_day, $product_ambiguous, $product_unknown, $product_remap, $walk_in_past_product ), $synthetic_a_products, $synthetic_b_products ),
		'order_ids'            => array_values( array_map( static fn( $source ) => $source['order_id'], $orders ) ),
		'orders'               => $orders,
		'projected'            => array_map( static fn( $result ) => $result['registrations'][0]['registration_uuid'], $projected ),
		'quantity_unit_two'    => $quantity_unit_two['registration_uuid'],
		'options'              => $options,
		'config_race'          => array(
			'event_id'     => $config_race_id,
			'activation_a' => $activation_race_a,
			'activation_b' => $activation_race_b,
		),
		'token_one'            => $token_one,
		'token_two'            => $token_two,
		'offering_fixture'     => array(
			'event_id'           => $offering_event_id,
			'unrelated_event_id' => $unrelated_event_id,
			'product_a'          => $offering_product_a,
			'product_b'          => $offering_product_b,
			'unrelated_product'  => $unrelated_product,
		),
		'rsvp_fixture'         => array(
			'available_event_id' => $rsvp_available_id,
			'waitlist_event_id'  => $rsvp_waitlist_id,
			'full_event_id'      => $rsvp_full_id,
		),
		'synthetic_ticketed_a' => array(
			'event_id'    => $synthetic_a_id,
			'product_ids' => $synthetic_a_products,
		),
		'synthetic_ticketed_b' => array(
			'event_id'    => $synthetic_b_id,
			'product_ids' => $synthetic_b_products,
		),
		'walk_in_past'         => array(
			'event_id'   => $walk_in_past_id,
			'product_id' => $walk_in_past_product,
		),
		'catalog_fixture'      => array(
			'running_event_id'  => $running_event_id,
			'future_event_id'   => $future_event_id,
			'timezone_event_id' => $timezone_event_id,
		),
	);
	oras_desk_integration_canonical_offerings_and_rsvp( $context );
	oras_desk_integration_event_roster( $context );
	$context['order_ids'] = array_merge( $context['order_ids'], oras_desk_integration_paid_not_found_recovery( $context ) );
	$context['membership_fixture'] = oras_desk_integration_membership_workflow( $context );
	oras_desk_integration_training_workflow( $context );
	$config = Config::get_event_config( $event_id );
	$late_uuid = (string) $context['projected']['late_cancelled'];
	$late_open_detail = $service->detail( $event_id, $late_uuid );
	oras_desk_integration_same( $late_open_detail['admission']['state'] ?? '', 'eligible', 'registration is eligible when its detail page first opens' );
	$listener_callbacks = oras_desk_integration_suspend_hook_class( 'woocommerce_order_status_changed', \ORAS\Tickets\Registration_Desk\Source_Change_Listener::class );
	$late_order = wc_get_order( $orders['late_cancelled']['order_id'] );
	$late_order->set_status( 'cancelled' );
	$late_order->save();
	oras_desk_integration_restore_hook_class( 'woocommerce_order_status_changed', $listener_callbacks );
	$late_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	$late_payload = array(
		'attendance_local_date' => $today,
		'explicit_unpaid'       => false,
		'arrivals'              => array(
			array(
				'slot_key'   => 'individual-1',
				'first_name' => 'Late',
				'last_name'  => 'Cancellation',
			),
		),
	);
	oras_desk_integration_error( $service->check_in( $late_uuid, $late_payload, $late_context ), 'oras_desk_not_eligible', 'final submission revalidates and refuses a source cancelled after detail loaded' );
	$late_refreshed_detail = $service->detail( $event_id, $late_uuid );
	oras_desk_integration_same( $late_refreshed_detail['admission']['state'] ?? '', 'revoked', 'detail refresh reflects the authoritative cancellation after final refusal' );
	$context['baseline']              = oras_desk_integration_protected_snapshot( $context );
	$context['prepare_http_baseline'] = oras_desk_integration_hash( oras_desk_integration_http_evidence( 'phase:prepare' ) );
	update_option( 'oras_registration_desk_integration_context', $context, false );
	oras_desk_integration_pass( 'protected commerce, account, integration, and transport baseline captured after fixture-only mutations' );
	oras_desk_integration_walk_in_rest_contract( $context );

	$config = Config::get_event_config( $event_id );
	$desk_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	$payload = array(
		'first_name'            => 'Actual',
		'last_name'             => 'Attendee',
		'attendance_local_date' => $today,
		'explicit_unpaid'       => false,
	);
	$quantity_two_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	oras_desk_integration_error( $service->confirm_and_check_in( $context['quantity_unit_two'], $payload, $quantity_two_context ), 'oras_desk_source_unit_invalid', 'source unit above the current quantity is rejected before refresh' );
	$quantity_one_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	$quantity_one_result = $service->confirm_and_check_in( $context['projected']['quantity'], $payload, $quantity_one_context );
	oras_desk_integration_true( is_array( $quantity_one_result ) && 'checked_in' === $quantity_one_result['historical_result']['result'], 'remaining source unit stays admissible after quantity reduction' );
	$quantity_refresh = $projector->reconcile_source( $event_id, $orders['quantity']['order_id'], $orders['quantity']['item_id'], $config );
	if ( is_wp_error( $quantity_refresh ) ) {
		oras_desk_integration_fail( 'quantity refresh failed: ' . $quantity_refresh->get_error_code() );
	}
	$quantity_two_after = $registration_store->find_by_uuid( $context['quantity_unit_two'] );
	oras_desk_integration_same( $quantity_two_after['status'], 'revoked', 'refresh revokes excess projected units without deleting or renumbering them' );
	$quantity_two_retry = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	oras_desk_integration_error( $service->confirm_and_check_in( $context['quantity_unit_two'], array_merge( $payload, array( 'explicit_unpaid' => true ) ), $quantity_two_retry ), 'oras_desk_registration_inactive', 'revoked stored unit blocks explicit-unpaid admission after refresh' );
	$family_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	$family_result = $service->check_in(
		$context['projected']['family'],
		array(
			'attendance_local_date' => $today,
			'explicit_unpaid'       => false,
			'arrivals'              => array(
				array(
					'slot_key'   => 'family-1',
					'first_name' => 'Family',
					'last_name'  => 'Primary',
				),
				array(
					'slot_key'   => 'family-2',
					'first_name' => '',
					'last_name'  => '',
				),
			),
		),
		$family_context
	);
	oras_desk_integration_true( is_array( $family_result ) && 2 === count( $family_result['historical_result']['attendance'] ), 'family check-in records only the two actual arrivals with stable slots' );
	$one_day_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	$one_day_result = $service->check_in(
		$context['projected']['one_day'],
		array(
			'attendance_local_date' => $today,
			'explicit_unpaid'       => false,
			'arrivals'              => array(
				array(
					'slot_key'   => 'individual-1',
					'first_name' => 'One',
					'last_name'  => 'Day',
				),
			),
		),
		$one_day_context
	);
	oras_desk_integration_true( is_array( $one_day_result ) && 'checked_in' === $one_day_result['historical_result']['result'], 'one-day registration admits an actual arrival on its configured date' );
	$atomic_request = wp_generate_uuid4();
	$atomic_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, $atomic_request );
	$atomic_registration = $registration_store->find_by_uuid( $context['projected']['atomic'] );
	$trigger_name = $wpdb->prefix . 'oras_desk_test_audit_fail';
	$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified disposable fixture trigger.
	$trigger_sql = "CREATE TRIGGER {$trigger_name} BEFORE INSERT ON {$tables_first['audit']} FOR EACH ROW BEGIN IF NEW.request_uuid = '" . esc_sql( $atomic_request ) . "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic audit failure'; END IF; END";
	if ( false === $wpdb->query( $trigger_sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified disposable fixture SQL.
		oras_desk_integration_fail( 'could not install the synthetic audit-failure trigger.' );
	}
	$prior_suppression = $wpdb->suppress_errors( true );
	$atomic_result = $service->confirm_and_check_in(
		$context['projected']['atomic'],
		array(
			'first_name'            => 'Atomic',
			'last_name'             => 'Rollback',
			'attendance_local_date' => $today,
			'explicit_unpaid'       => false,
		),
		$atomic_context
	);
	$wpdb->suppress_errors( $prior_suppression );
	$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified disposable fixture trigger.
	oras_desk_integration_error( $atomic_result, 'oras_desk_audit_persist_failed', 'nonduplicate audit failure returns a persistence error rather than a duplicate conflict' );
	$atomic_attendees = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables_first['attendees']} WHERE registration_id = %d", $atomic_registration['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	$atomic_attendance = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables_first['attendance']} WHERE attendee_id IN (SELECT id FROM {$tables_first['attendees']} WHERE registration_id = %d)", $atomic_registration['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk tables.
	$atomic_audits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables_first['audit']} WHERE request_uuid = %s", $atomic_request ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	oras_desk_integration_same( array( $atomic_attendees, $atomic_attendance ), array( 0, 0 ), 'attendee and attendance mutation roll back when audit persistence fails' );
	oras_desk_integration_same( $atomic_audits, 0, 'failed atomic operation leaves no partial audit result' );
	$atomic_retry = $service->confirm_and_check_in(
		$context['projected']['atomic'],
		array(
			'first_name'            => 'Atomic',
			'last_name'             => 'Rollback',
			'attendance_local_date' => $today,
			'explicit_unpaid'       => false,
		),
		$atomic_context
	);
	oras_desk_integration_true( is_array( $atomic_retry ) && 'checked_in' === $atomic_retry['historical_result']['result'], 'identical request UUID succeeds after the nonduplicate audit fault is removed' );
	$completed = $service->confirm_and_check_in( $context['projected']['completed'], $payload, $desk_context );
	oras_desk_integration_true( is_array( $completed ) && 'checked_in' === $completed['historical_result']['result'], 'actual arriving individual is confirmed and checked in' );
	$checked_detail = $service->detail( $event_id, (string) $context['projected']['completed'] );
	oras_desk_integration_same( $checked_detail['admission']['state'] ?? '', 'already_checked_in', 'checked-in registration detail has one checked-in state' );
	oras_desk_integration_same( $checked_detail['admission']['check_in_allowed'] ?? null, false, 'already-checked-in detail offers no duplicate action' );
	$replay = $service->confirm_and_check_in( $context['projected']['completed'], $payload, $desk_context );
	oras_desk_integration_true( is_array( $replay ) && true === $replay['replayed'], 'same request and binding returns its recorded result' );
	$changed_payload = $payload;
	$changed_payload['last_name'] = 'Different';
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['completed'], $changed_payload, $desk_context ), 'oras_desk_request_conflict', 'conflicting reuse of a request identifier fails safely' );
	$walk_in_payload = array(
		'first_name'           => 'Walkin',
		'last_name'            => 'Visitor',
		'email'                => 'walkin-' . $context['run'] . '@example.test',
		'phone'                => '814-555-0201',
		'option_uuid'          => Event_Offering_Resolver::desk_offerings( $event_id, $config )[0]['option_uuid'],
		'offering_fingerprint' => Event_Offering_Resolver::desk_offerings( $event_id, $config )[0]['offering_fingerprint'],
		'valid_local_date'     => '',
		'payment_assertion'    => 'paid_card',
		'additional_attendees' => array(),
	);
	$walk_in_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	$walk_in = $service->create_walk_in( $walk_in_payload, $walk_in_context );
	oras_desk_integration_true( is_array( $walk_in ) && 'paid_card' === $walk_in['historical_result']['registration']['payment_assertion'], 'walk-in records a volunteer payment statement without creating an order' );
	$walk_in_replay = $service->create_walk_in( $walk_in_payload, $walk_in_context );
	oras_desk_integration_true( is_array( $walk_in_replay ) && true === $walk_in_replay['replayed'], 'walk-in lost-response retry returns the recorded result without duplicate attendance' );
	$walk_in_registration_uuid = (string) $walk_in['historical_result']['registration']['registration_uuid'];
	$duplicate_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	oras_desk_integration_error(
		$service->create_walk_in(
			array_merge(
				$walk_in_payload,
				array(
					'first_name' => 'Possible',
					'last_name'  => 'Duplicate',
				)
			),
			$duplicate_context
		),
		'oras_desk_possible_duplicate',
		'exact email or phone match warns without automatically merging people'
	);

	$on_hold_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	$on_hold_payload = array(
		'first_name'            => 'Unpaid',
		'last_name'             => 'Arrival',
		'attendance_local_date' => $today,
		'explicit_unpaid'       => false,
	);
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['on_hold'], $on_hold_payload, $on_hold_context ), 'oras_desk_unpaid_confirmation_required', 'on-hold source requires explicit unpaid admission' );
	$on_hold_payload['explicit_unpaid'] = true;
	$on_hold_context['request_uuid'] = wp_generate_uuid4();
	$on_hold_result = $service->confirm_and_check_in( $context['projected']['on_hold'], $on_hold_payload, $on_hold_context );
	oras_desk_integration_true( is_array( $on_hold_result ) && true === $on_hold_result['historical_result']['explicit_unpaid'], 'explicit unpaid admission records intent without marking the source paid' );

	$cancel_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['cancelled'], $payload, $cancel_context ), 'oras_desk_registration_inactive', 'stored revoked state after source cancellation blocks admission' );
	$partial_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['partial'], $payload, $partial_context ), 'oras_desk_registration_inactive', 'stored needs-review state after partial refund blocks admission' );
	$date_payload = $payload;
	$date_payload['attendance_local_date'] = $yesterday;
	$date_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config, $token_one, wp_generate_uuid4() );
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['concurrent'], $date_payload, $date_context ), 'oras_desk_date_changed', 'request spanning a site-local date boundary fails instead of substituting a date' );

	$attendance = $completed['current_attendance'];
	$attendee_uuid = $completed['historical_result']['attendee_uuid'];
	wp_set_current_user( (int) $admin_id );
	$admin_token = Station_Session::issue( (int) $admin_id, $event_id, (int) $config['revision'], 'Administrator' );
	$admin_context = oras_desk_integration_context( (int) $admin_id, $event_id, $config, $admin_token, wp_generate_uuid4() );
	$walk_in_row = $registration_store->find_by_uuid( $walk_in_registration_uuid );
	$correction_payload = array_merge(
		$walk_in_payload,
		array(
			'phone'                   => '814-555-0299',
			'expected_record_version' => (int) $walk_in_row['record_version'],
		)
	);
	$corrected = $service->correct_registration( $walk_in_registration_uuid, $correction_payload, $admin_context );
	oras_desk_integration_true( is_array( $corrected ) && '814-555-0299' === $corrected['historical_result']['registration']['source_phone'], 'administrator correction updates only the desk registration and records an audit' );
	$admin_context['request_uuid'] = wp_generate_uuid4();
	$reverse = $service->reverse(
		$context['projected']['completed'],
		$attendee_uuid,
		array(
			'attendance_local_date'   => $today,
			'expected_record_version' => (int) $attendance['record_version'],
			'reason'                  => 'Synthetic correction',
		),
		$admin_context
	);
	oras_desk_integration_true( is_array( $reverse ) && 'reversed' === $reverse['current_attendance']['state'], 'administrator reversal records reason and guarded version' );
	$stale_context = $admin_context;
	$stale_context['request_uuid'] = wp_generate_uuid4();
	oras_desk_integration_error(
		$service->reverse(
			$context['projected']['completed'],
			$attendee_uuid,
			array(
				'attendance_local_date'   => $today,
				'expected_record_version' => 1,
				'reason'                  => 'Stale retry',
			),
			$stale_context
		),
		'oras_desk_attendance_stale',
		'stale reversal version fails safely'
	);
	wp_set_current_user( (int) $desk_id );
	$post_reversal_replay = $service->confirm_and_check_in( $context['projected']['completed'], $payload, $desk_context );
	oras_desk_integration_true( true === $post_reversal_replay['replayed'] && 'reversed' === $post_reversal_replay['current_attendance']['state'], 'replay returns historical success with current reversed state' );

	$before_detail = $service->detail( $event_id, $context['projected']['completed'] );
	$before_attendee_uuid = $before_detail['attendees'][0]['attendee_uuid'];
	$before_audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables_first['audit']} WHERE registration_uuid = %s", $context['projected']['completed'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	$projector->reconcile_source( $event_id, $orders['completed']['order_id'], $orders['completed']['item_id'], $config );
	$after_detail = $service->detail( $event_id, $context['projected']['completed'] );
	$after_audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables_first['audit']} WHERE registration_uuid = %s", $context['projected']['completed'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	oras_desk_integration_same( $after_detail['attendees'][0]['attendee_uuid'], $before_attendee_uuid, 'projection rebuild preserves confirmed attendee identity' );
	oras_desk_integration_same( $after_audit_count, $before_audit_count, 'projection rebuild preserves audit history' );

	wp_set_current_user( (int) $admin_id );
	$remapped_options = $options;
	$remapped_options[0]['source_product_ids'] = array( $product_individual );
	$remapped_options[] = array(
		'option_uuid'           => '77777777-7777-4777-8777-777777777777',
		'label'                 => 'Remapped individual',
		'available_for_new'     => true,
		'existing_access_valid' => true,
		'classification'        => 'individual',
		'validity_type'         => 'full_event',
		'source_product_ids'    => array( $product_remap ),
	);
	$config_remapped = oras_desk_integration_save_config( $event_id, $remapped_options );
	wp_set_current_user( (int) $desk_id );
	$remap_token = Station_Session::issue( (int) $desk_id, $event_id, (int) $config_remapped['revision'], 'Remap Check' );
	$remap_context = oras_desk_integration_context( (int) $desk_id, $event_id, $config_remapped, $remap_token, wp_generate_uuid4() );
	$remap_detail = $service->detail( $event_id, (string) $context['projected']['remap'] );
	oras_desk_integration_same( $remap_detail['admission']['state'] ?? '', 'manager_review_required', 'changed option mapping produces only a manager-review detail state' );
	oras_desk_integration_same( $remap_detail['admission']['selection_allowed'] ?? null, false, 'mapping-review detail has no selectable attendee' );
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['remap'], $payload, $remap_context ), 'oras_desk_source_option_changed', 'fresh option remap rejects the stored option before projection refresh' );
	$remap_refresh = $projector->reconcile_source( $event_id, $orders['remap']['order_id'], $orders['remap']['item_id'], $config_remapped );
	if ( is_wp_error( $remap_refresh ) ) {
		oras_desk_integration_fail( 'remapped source refresh failed: ' . $remap_refresh->get_error_code() );
	}
	$remap_after = $registration_store->find_by_uuid( $context['projected']['remap'] );
	oras_desk_integration_same( $remap_after['status'], 'needs_review', 'projection refresh retains the original registration and marks a remapped option for review' );
	$remap_context['request_uuid'] = wp_generate_uuid4();
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['remap'], $payload, $remap_context ), 'oras_desk_registration_inactive', 'remapped needs-review registration remains blocked after refresh' );
	wp_set_current_user( (int) $admin_id );
	$config = oras_desk_integration_save_config( $event_id, $options );
	$disabled_options = $options;
	$disabled_options[0]['available_for_new'] = false;
	$config_disabled = oras_desk_integration_save_config( $event_id, $disabled_options );
	$disabled_projection = $projector->reconcile_source( $event_id, $orders['concurrent']['order_id'], $orders['concurrent']['item_id'], $config_disabled );
	oras_desk_integration_same( $disabled_projection['resolution']['eligibility'], 'eligible', 'disabling new registration does not revoke existing access' );
	$revoked_options = $disabled_options;
	$revoked_options[0]['existing_access_valid'] = false;
	$config_revoked = oras_desk_integration_save_config( $event_id, $revoked_options );
	$revoked_projection = $projector->reconcile_source( $event_id, $orders['concurrent']['order_id'], $orders['concurrent']['item_id'], $config_revoked );
	oras_desk_integration_same( $revoked_projection['resolution']['eligibility'], 'eligible', 'legacy desk availability flags cannot revoke canonical same-event ticket access' );
	$old_station_result = Station_Session::validate( $token_one, (int) $desk_id, $event_id, (int) $config_revoked['revision'] );
	oras_desk_integration_error( $old_station_result, 'oras_desk_station_config_changed', 'configuration revision invalidates an open station request' );
	$config_final = oras_desk_integration_save_config( $event_id, $options );
	$projector->reconcile_source( $event_id, $orders['concurrent']['order_id'], $orders['concurrent']['item_id'], $config_final );
	wp_set_current_user( (int) $desk_id );
	$event_token = Station_Session::issue( (int) $desk_id, $event_id, (int) $config_final['revision'], 'Event Switch' );
	wp_set_current_user( (int) $admin_id );
	Config::set_active_event_id( $other_id );
	wp_set_current_user( (int) $desk_id );
	oras_desk_integration_error( Station_Session::validate( $event_token, (int) $desk_id, $other_id, 0 ), 'oras_desk_station_event_changed', 'active-event change invalidates an open station request' );
	wp_set_current_user( (int) $admin_id );
	Config::set_active_event_id( $past_id );
	$past_config = Config::get_event_config( $past_id );
	wp_set_current_user( (int) $desk_id );
	$past_token = Station_Session::issue( (int) $desk_id, $past_id, (int) $past_config['revision'], 'Past Event' );
	$past_context = oras_desk_integration_context( (int) $desk_id, $past_id, $past_config, $past_token, wp_generate_uuid4() );
	oras_desk_integration_error( $service->confirm_and_check_in( $context['projected']['past'], $payload, $past_context ), 'oras_desk_wrong_date', 'today outside the event date range is rejected with no grace period' );
	wp_set_current_user( (int) $admin_id );
	Config::set_active_event_id( $event_id );
	wp_set_current_user( (int) $desk_id );

	$concurrency_token_one = Station_Session::issue( (int) $desk_id, $event_id, (int) $config_final['revision'], 'Concurrent One' );
	$concurrency_token_two = Station_Session::issue( (int) $desk_id, $event_id, (int) $config_final['revision'], 'Concurrent Two' );
	$context['concurrency'] = array(
		'config_revision'   => (int) $config_final['revision'],
		'registration_uuid' => $context['projected']['concurrent'],
		'token_one'         => $concurrency_token_one,
		'token_two'         => $concurrency_token_two,
		'request_one'       => wp_generate_uuid4(),
		'request_two'       => wp_generate_uuid4(),
	);
	$after_prepare = oras_desk_integration_protected_snapshot( $context );
	foreach ( $context['baseline'] as $surface => $hash ) {
		oras_desk_integration_same( $after_prepare[ $surface ], $hash, 'prepare-phase desk operations leave ' . $surface . ' unchanged' );
	}
	oras_desk_integration_same( oras_desk_integration_hash( oras_desk_integration_http_evidence( 'phase:prepare' ) ), $context['prepare_http_baseline'], 'prepare-phase desk operations perform no external HTTP' );
	update_option( 'oras_registration_desk_integration_context', $context, false );
	oras_desk_integration_pass( 'single-connection qualification complete; independent workers are prepared' );
}

/** Establish a new isolation baseline after authenticated HTTP control requests. */
function oras_desk_integration_reset_baseline(): void {
	$context = get_option( 'oras_registration_desk_integration_context', array() );
	if ( ! is_array( $context ) || empty( $context['event_id'] ) ) {
		oras_desk_integration_fail( 'prepared context is missing before baseline reset.' );
	}
	$context['baseline'] = oras_desk_integration_protected_snapshot( $context );
	update_option( 'oras_registration_desk_integration_context', $context, false );
	oras_desk_integration_pass( 'protected baseline reset after authenticated dispatcher controls' );
}

/** Verify concurrent results, side-effect isolation, and the normal checkout control. */
function oras_desk_integration_finish(): void {
	global $wpdb;
	$context = get_option( 'oras_registration_desk_integration_context', array() );
	if ( ! is_array( $context ) || empty( $context['concurrency'] ) ) {
		oras_desk_integration_fail( 'prepared concurrency context is missing.' );
	}
	$finish_http_baseline = oras_desk_integration_hash( oras_desk_integration_http_evidence( 'phase:finish' ) );
	$tables = Schema::table_names();
	$registration = ( new Registration_Store() )->find_by_uuid( $context['concurrency']['registration_uuid'] );
	$attendee_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['attendees']} WHERE registration_id = %d", $registration['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	$attendance_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['attendance']} WHERE event_id = %d AND attendance_local_date = %s AND attendee_id IN (SELECT id FROM {$tables['attendees']} WHERE registration_id = %d)", $context['event_id'], $context['today'], $registration['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk tables.
	$audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit']} WHERE request_uuid IN (%s,%s)", $context['concurrency']['request_one'], $context['concurrency']['request_two'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed desk table.
	oras_desk_integration_same( $attendee_count, 1, 'independent concurrent confirmations produce one stable attendee slot' );
	oras_desk_integration_same( $attendance_count, 1, 'database uniqueness permits one daily attendance row under concurrency' );
	oras_desk_integration_same( $audit_count, 2, 'both concurrent request identifiers have deterministic audit results' );

	wp_set_current_user( (int) $context['desk_id'] );
	$request = new WP_REST_Request( 'POST', '/oras-tickets/v1/registration-desk/registrations/' . $context['concurrency']['registration_uuid'] . '/confirm-and-check-in' );
	$request->set_header( 'X-ORAS-Desk-Station', $context['concurrency']['token_one'] );
	$request->set_header( 'X-ORAS-Desk-Request', $context['concurrency']['request_one'] );
	$request->set_body_params(
		array(
			'first_name'            => 'Concurrent',
			'last_name'             => 'Arrival',
			'attendance_local_date' => $context['today'],
			'explicit_unpaid'       => false,
		)
	);
	$replay = rest_do_request( $request );
	oras_desk_integration_true( 200 === $replay->get_status() && true === ( $replay->get_data()['replayed'] ?? false ), 'lost-response retry replays one concurrent request through the authenticated REST contract' );
	wp_set_current_user( (int) $context['member_id'] );
	$unauthorized_replay = rest_do_request( $request );
	oras_desk_integration_true( 401 === $unauthorized_replay->get_status() || 403 === $unauthorized_replay->get_status(), 'knowing a request UUID does not authorize replay' );

	$after = oras_desk_integration_protected_snapshot( $context );
	foreach ( $context['baseline'] as $surface => $hash ) {
		oras_desk_integration_same( $after[ $surface ], $hash, 'desk operations leave ' . $surface . ' unchanged' );
	}
	oras_desk_integration_same( oras_desk_integration_hash( oras_desk_integration_http_evidence( 'phase:finish' ) ), $finish_http_baseline, 'finish-phase desk operations perform no external HTTP' );

	oras_desk_integration_true( oras_desk_integration_hook_has_class( 'woocommerce_checkout_create_order_line_item', 'ORAS\\Tickets\\Commerce\\Woo\\Product_Sync' ), 'normal checkout item-snapshot integration remains registered' );
	oras_desk_integration_true( oras_desk_integration_hook_has_class( 'woocommerce_order_status_completed', 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption' ), 'normal paid-order capacity integration remains registered' );
	oras_desk_integration_true( oras_desk_integration_hook_has_class( 'woocommerce_order_status_completed', 'ORAS\\Tickets\\Integrations\\QuickBooks\\Sync_Orchestrator' ), 'normal completed-order QuickBooks integration remains registered' );

	$control_product = wc_get_product( (int) $context['product_ids'][0] );
	$control_item = new WC_Order_Item_Product();
	$control_item->set_product( $control_product );
	$control_item->set_quantity( 1 );
	$control_item->set_subtotal( 20 );
	$control_item->set_total( 20 );
	$control_order = wc_create_order();
	do_action( 'woocommerce_checkout_create_order_line_item', $control_item, 'desk-control', array(), $control_order );
	oras_desk_integration_same( (int) $control_item->get_meta( '_oras_ticket_event_id', true ), (int) $context['event_id'], 'normal checkout control executes the existing ORAS item-snapshot hook' );
	$control_order->add_item( $control_item );
	$control_order->calculate_totals( false );
	$control_order->save();
	do_action( 'woocommerce_order_status_completed', (int) $control_order->get_id() );
	$control_order = wc_get_order( $control_order->get_id() );
	oras_desk_integration_same( (string) $control_order->get_meta( '_oras_capacity_consumed', true ), '1', 'normal paid-order control executes existing internal capacity behavior' );
	$qbo_actions = function_exists( 'as_get_scheduled_actions' ) ? as_get_scheduled_actions(
		array(
			'hook'     => 'oras_tickets_qbo_sync_order',
			'args'     => array( (int) $control_order->get_id() ),
			'per_page' => 20,
		)
	) : array();
	oras_desk_integration_same( count( $qbo_actions ), 0, 'QuickBooks dry-run control executes without queueing remote work' );
	oras_desk_integration_true( defined( 'ORAS_QBO_HTTP_BLOCK_ACTIVE' ) && ORAS_QBO_HTTP_BLOCK_ACTIVE, 'Intuit-specific HTTP blocker remains active during the checkout control' );

	unset( $context['token_one'], $context['token_two'], $context['concurrency']['token_one'], $context['concurrency']['token_two'] );
	update_option( 'oras_registration_desk_integration_context', $context, false );
	WP_CLI::success( 'Registration Desk WordPress/WooCommerce/TEC integration qualification passed.' );
}

oras_desk_integration_guard();
$phase = defined( 'ORAS_REGISTRATION_DESK_TEST_PHASE' ) ? ORAS_REGISTRATION_DESK_TEST_PHASE : '';
if ( 'prepare' === $phase ) {
	oras_desk_integration_prepare();
} elseif ( 'baseline' === $phase ) {
	oras_desk_integration_reset_baseline();
} elseif ( 'finish' === $phase ) {
	oras_desk_integration_finish();
} else {
	oras_desk_integration_fail( 'unknown integration phase.' );
}
