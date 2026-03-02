<?php
/**
 * Phase 3 reporting integration checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /tmp/oras-phase3-reporting-checks.php
 */

use ORAS\Tickets\Admin\Admin_Menu;
use ORAS\Tickets\Admin\Pages\Reports_Page;
use ORAS\Tickets\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Phase3_Check_Exception extends RuntimeException {}
final class Oras_Phase3_Wp_Die extends RuntimeException {
	/** @var int */
	public $response_code;

	public function __construct( string $message, int $response_code = 0 ) {
		parent::__construct( $message );
		$this->response_code = $response_code;
	}
}

/**
 * @throws Oras_Phase3_Check_Exception
 */
function oras_phase3_fail( string $message ): void {
	throw new Oras_Phase3_Check_Exception( $message );
}

/**
 * @throws Oras_Phase3_Check_Exception
 */
function oras_phase3_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		oras_phase3_fail( $message );
	}

	echo 'PASS: ' . $message . "\n";
}

/**
 * @throws Oras_Phase3_Check_Exception
 */
function oras_phase3_capture_wp_die( callable $callback ): array {
	$die_handler = static function ( $message, $title = '', $args = array() ): void {
		$response = 0;
		if ( is_array( $args ) && isset( $args['response'] ) ) {
			$response = (int) $args['response'];
		}

		$clean_message = is_scalar( $message ) ? wp_strip_all_tags( (string) $message ) : 'wp_die';
		throw new Oras_Phase3_Wp_Die( trim( $clean_message ), $response );
	};

	add_filter(
		'wp_die_handler',
		static function () use ( $die_handler ) {
			return $die_handler;
		}
	);
	add_filter(
		'wp_die_ajax_handler',
		static function () use ( $die_handler ) {
			return $die_handler;
		}
	);

	$result = array(
		'died'     => false,
		'message'  => '',
		'response' => 0,
	);

	ob_start();
	try {
		call_user_func( $callback );
	} catch ( Oras_Phase3_Wp_Die $e ) {
		$result['died']     = true;
		$result['message']  = $e->getMessage();
		$result['response'] = (int) $e->response_code;
	}
	$output = (string) ob_get_clean();
	if ( $output !== '' ) {
		$result['output'] = $output;
	}

	return $result;
}

/**
 * @throws Oras_Phase3_Check_Exception
 */
function oras_phase3_create_user( string $prefix, string $suffix ): int {
	$login = sanitize_user( $prefix . '_' . $suffix . '_' . wp_generate_password( 4, false ) );
	$email = $login . '@example.org';
	$pass  = wp_generate_password( 20, true, true );
	$id    = wp_create_user( $login, $pass, $email );

	if ( ! is_int( $id ) || $id <= 0 ) {
		oras_phase3_fail( 'Unable to create user: ' . $prefix );
	}

	return $id;
}

/**
 * @throws Oras_Phase3_Check_Exception
 */
function oras_phase3_run_checks(): void {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	Capabilities::add_caps();

	$admin_ids = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ids',
			'number' => 1,
		)
	);
	oras_phase3_assert( ! empty( $admin_ids ), 'Administrator user exists' );
	$admin_id = (int) $admin_ids[0];

	$suffix        = gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
	$created_users = array();

	try {
		$subscriber_id  = oras_phase3_create_user( 'oras_p3_subscriber', $suffix );
		$created_users[] = $subscriber_id;
		wp_update_user(
			array(
				'ID'   => $subscriber_id,
				'role' => 'subscriber',
			)
		);

		$admin = get_userdata( $admin_id );
		oras_phase3_assert( $admin instanceof WP_User, 'Administrator user object is available' );
		oras_phase3_assert( $admin->has_cap( 'oras_tickets_view_reports' ), 'Administrator has reports view capability' );
		oras_phase3_assert( $admin->has_cap( 'oras_tickets_export_reports' ), 'Administrator has reports export capability' );

		$subscriber = get_userdata( $subscriber_id );
		oras_phase3_assert( $subscriber instanceof WP_User, 'Subscriber user object is available' );
		oras_phase3_assert( ! $subscriber->has_cap( 'oras_tickets_view_reports' ), 'Subscriber does not have reports view capability' );
		oras_phase3_assert( ! $subscriber->has_cap( 'oras_tickets_export_reports' ), 'Subscriber does not have reports export capability' );

		$admin_menu = new Admin_Menu();
		$admin_menu->register();
		do_action( 'admin_menu' );

		oras_phase3_assert( has_action( 'admin_post_oras_tickets_export_csv' ) > 0, 'Reports export admin-post action is registered' );

		$reports_page = new Reports_Page();

		wp_set_current_user( $subscriber_id );
		$render_denied = oras_phase3_capture_wp_die(
			static function () use ( $reports_page ): void {
				$reports_page->render();
			}
		);
		oras_phase3_assert( ! empty( $render_denied['died'] ), 'Reports render denies unauthorized user' );
		oras_phase3_assert( (int) ( $render_denied['response'] ?? 0 ) === 403, 'Reports render denial uses HTTP 403' );

		wp_set_current_user( $admin_id );
		$render_admin = oras_phase3_capture_wp_die(
			static function () use ( $reports_page ): void {
				$reports_page->render();
			}
		);
		oras_phase3_assert( empty( $render_admin['died'] ), 'Reports render succeeds for administrator' );
		$render_output = isset( $render_admin['output'] ) ? (string) $render_admin['output'] : '';
		oras_phase3_assert( strpos( $render_output, 'ORAS Tickets — Reports' ) !== false, 'Reports render includes page title output' );
		oras_phase3_assert( strpos( $render_output, 'name="oras_tickets_reports_nonce"' ) !== false, 'Reports render includes export nonce field' );

		wp_set_current_user( $subscriber_id );
		$_POST = array(
			'oras_tickets_reports_nonce' => wp_create_nonce( 'oras_tickets_reports' ),
		);
		$export_denied = oras_phase3_capture_wp_die(
			static function () use ( $admin_menu ): void {
				$admin_menu->handle_export_csv();
			}
		);
		oras_phase3_assert( ! empty( $export_denied['died'] ), 'Reports export blocks unauthorized user' );
		oras_phase3_assert( (int) ( $export_denied['response'] ?? 0 ) === 403, 'Reports export unauthorized response is 403' );

		wp_set_current_user( $admin_id );
		$_POST = array(
			'oras_tickets_reports_nonce' => 'invalid',
		);
		$export_invalid_nonce = oras_phase3_capture_wp_die(
			static function () use ( $admin_menu ): void {
				$admin_menu->handle_export_csv();
			}
		);
		oras_phase3_assert( ! empty( $export_invalid_nonce['died'] ), 'Reports export rejects invalid nonce' );
		oras_phase3_assert( (int) ( $export_invalid_nonce['response'] ?? 0 ) === 400, 'Reports export invalid nonce response is 400' );

		$_POST = array(
			'oras_tickets_reports_nonce' => wp_create_nonce( 'oras_tickets_reports' ),
			'oras_tickets_statuses'      => array( 'processing', 'completed' ),
			'oras_tickets_range'         => 'last_30',
			'csv_scope'                  => 'detail',
			'oras_tickets_event_id'      => '0',
		);
		$export_no_event = oras_phase3_capture_wp_die(
			static function () use ( $admin_menu ): void {
				$admin_menu->handle_export_csv();
			}
		);
		oras_phase3_assert( empty( $export_no_event['died'] ), 'Reports export detail request with event_id=0 exits safely without wp_die' );

		echo "PASS: Phase 3 reporting integration checks completed\n";
	} finally {
		wp_set_current_user( $admin_id );
		$_POST = array();
		$_GET  = array();

		foreach ( $created_users as $user_id ) {
			if ( $user_id > 0 ) {
				wp_delete_user( $user_id, $admin_id );
			}
		}
	}
}

try {
	oras_phase3_run_checks();
} catch ( Oras_Phase3_Check_Exception $e ) {
	fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" );
	exit( 1 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'FAIL: Unexpected exception: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

