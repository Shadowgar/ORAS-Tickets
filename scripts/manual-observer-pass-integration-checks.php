<?php

use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Reporting\Observer_Pass_Report_Service;
use ORAS\Tickets\Storage\Manual_Observer_Pass_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Manual_Pass_Check_Exception extends RuntimeException {}
final class Oras_Manual_Pass_Redirect_Exception extends RuntimeException {}
final class Oras_Manual_Pass_Wp_Die_Exception extends RuntimeException {}

function oras_manual_pass_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new Oras_Manual_Pass_Check_Exception( $message );
	}
	echo 'PASS: ' . $message . "\n";
}

function oras_manual_pass_assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new Oras_Manual_Pass_Check_Exception( $message . ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) );
	}
	echo 'PASS: ' . $message . "\n";
}

/** @param callable():void $callback */
function oras_manual_pass_capture_redirect( callable $callback ): string {
	$filter = static function ( string $location ): string {
		throw new Oras_Manual_Pass_Redirect_Exception( $location );
	};
	add_filter( 'wp_redirect', $filter );
	try {
		$callback();
	} catch ( Oras_Manual_Pass_Redirect_Exception $error ) {
		return $error->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filter );
	}

	throw new Oras_Manual_Pass_Check_Exception( 'Expected redirect was not issued' );
}

/** @param callable():void $callback */
function oras_manual_pass_capture_wp_die( callable $callback ): string {
	$filter = static function (): callable {
		return static function ( $message ): void {
			throw new Oras_Manual_Pass_Wp_Die_Exception( wp_strip_all_tags( is_scalar( $message ) ? (string) $message : '' ) );
		};
	};
	add_filter( 'wp_die_handler', $filter );
	try {
		$callback();
	} catch ( Oras_Manual_Pass_Wp_Die_Exception $error ) {
		return $error->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $filter );
	}

	throw new Oras_Manual_Pass_Check_Exception( 'Expected WordPress rejection was not issued' );
}

function oras_manual_pass_run_checks(): void {
	if ( ! class_exists( Manual_Observer_Pass_Store::class ) ) {
		throw new Oras_Manual_Pass_Check_Exception( 'Manual Observer Pass store is unavailable' );
	}

	Manual_Observer_Pass_Store::register_post_type();
	Board_Reports::register();
	oras_manual_pass_assert( post_type_exists( Manual_Observer_Pass_Store::POST_TYPE ), 'Manual Observer Pass post type is registered' );
	oras_manual_pass_assert( false !== has_action( 'admin_post_oras_board_reports_save_manual_observer_pass' ), 'Manual Observer Pass save action is registered' );
	oras_manual_pass_assert( false === has_action( 'admin_post_nopriv_oras_board_reports_save_manual_observer_pass' ), 'Manual Observer Pass save has no unauthenticated action' );

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	oras_manual_pass_assert( ! empty( $admin_ids ), 'Administrator fixture exists' );
	$admin_id = (int) $admin_ids[0];
	$viewer_id = wp_create_user( 'manual_pass_viewer_' . wp_generate_password( 6, false ), wp_generate_password( 20 ), 'manual.viewer.' . wp_generate_password( 5, false ) . '@example.org' );
	oras_manual_pass_assert( is_int( $viewer_id ) && $viewer_id > 0, 'View-only fixture user created' );
	$viewer = new WP_User( $viewer_id );
	$viewer->set_role( 'subscriber' );
	$viewer->add_cap( 'oras_tickets_view_board_dashboard' );

	$created_ids = array();
	$original_get = $_GET;
	$original_post = $_POST;
	$original_request = $_REQUEST;
	try {
		$manual_id = Manual_Observer_Pass_Store::create(
			array(
				'holder_names'   => array( 'John O’Hara', 'Kelly O’Hara' ),
				'quantity'       => 1,
				'email'          => 'ohara@example.org',
				'start_date'     => '2026-05-25',
				'source'         => 'cash',
				'linked_user_id' => 0,
				'notes'          => 'Offline fixture',
				'record_state'   => 'active',
			),
			$admin_id
		);
		oras_manual_pass_assert( is_int( $manual_id ) && $manual_id > 0, 'Manual Annual fixture created without WooCommerce order or user' );
		$created_ids[] = $manual_id;

		$today = new DateTimeImmutable( '2026-09-01 00:00:00', wp_timezone() );
		$report = ( new Observer_Pass_Report_Service( $today ) )->get_report();
		$manual_rows = array_values(
			array_filter(
				$report['all_rows'],
				static fn( array $row ): bool => 'manual' === ( $row['source'] ?? '' ) && $manual_id === (int) ( $row['source_record_id'] ?? 0 )
			)
		);
		oras_manual_pass_assert_same( count( $manual_rows ), 1, 'Manual Annual record is merged into the Observer report' );
		$manual_row = $manual_rows[0];
		oras_manual_pass_assert_same( $manual_row['holder_names'], array( 'John O’Hara', 'Kelly O’Hara' ), 'Merged row preserves multiple holder names' );
		oras_manual_pass_assert_same( $manual_row['valid_quantity'], 1, 'Merged row uses explicit quantity rather than holder count' );
		oras_manual_pass_assert_same( $manual_row['expiration_date'], '2027-05-25', 'Merged row uses centralized Annual expiration' );
		oras_manual_pass_assert_same( $manual_row['operational_status'], Observer_Pass_Report_Service::STATUS_ACTIVE, 'Merged manual Annual pass receives normal active status' );
		oras_manual_pass_assert_same( $manual_row['order_id'], 0, 'Merged manual pass has no fabricated WooCommerce order' );
		oras_manual_pass_assert_same( $report['summary']['active_annual_count'], 1, 'Manual quantity contributes to Active Annual summary' );
		oras_manual_pass_assert_same( count( ( new Observer_Pass_Report_Service( $today ) )->get_report( array( 'search' => 'Kelly O’Hara' ) )['rows'] ), 1, 'Annual verification search finds an additional manual holder' );
		oras_manual_pass_assert_same( count( ( new Observer_Pass_Report_Service( $today ) )->get_report( array( 'source' => 'manual' ) )['rows'] ), 1, 'Manual source filter returns the manual record' );

		wp_set_current_user( $viewer_id );
		$_GET = array( 'oras_board_tab' => 'observer_passes' );
		$viewer_html = do_shortcode( '[oras_board_reports]' );
		oras_manual_pass_assert( false !== strpos( $viewer_html, 'John O’Hara' ), 'View-only Board Reports user sees manual passholders' );
		oras_manual_pass_assert( false !== strpos( $viewer_html, 'Manual / Offline' ), 'Observer Pass UI displays the manual source' );
		oras_manual_pass_assert( false === strpos( $viewer_html, 'name="manual_holder_names"' ), 'View-only user does not receive manual pass write controls' );

		$_POST = array(
			'_wpnonce'           => wp_create_nonce( 'oras_board_reports_save_manual_observer_pass' ),
			'manual_holder_names'=> "Unauthorized Holder",
			'manual_quantity'    => '1',
			'manual_start_date'  => '2026-09-01',
			'manual_source'      => 'other',
		);
		$_REQUEST = $_POST;
		$denied = oras_manual_pass_capture_wp_die(
			static function (): void {
				Board_Reports::handle_save_manual_observer_pass();
			}
		);
		oras_manual_pass_assert( '' !== $denied, 'View-only user cannot write manual Observer Passes' );

		wp_set_current_user( $admin_id );
		$_GET = array( 'oras_board_tab' => 'observer_passes' );
		$admin_html = do_shortcode( '[oras_board_reports]' );
		oras_manual_pass_assert( false !== strpos( $admin_html, 'Add Manual Annual Pass' ), 'Observer Pass manager sees the frontend add control' );
		oras_manual_pass_assert( false !== strpos( $admin_html, 'name="manual_holder_names"' ), 'Observer Pass manager receives the frontend form' );

		$_POST = array(
			'_wpnonce'           => 'invalid',
			'manual_holder_names'=> "Bad Nonce",
			'manual_quantity'    => '1',
			'manual_start_date'  => '2026-09-01',
			'manual_source'      => 'other',
		);
		$_REQUEST = $_POST;
		$nonce_denied = oras_manual_pass_capture_wp_die(
			static function (): void {
				Board_Reports::handle_save_manual_observer_pass();
			}
		);
		oras_manual_pass_assert( '' !== $nonce_denied, 'Manual Observer Pass write rejects a bad nonce' );

		$before_create = count( Manual_Observer_Pass_Store::query() );
		$_POST = array(
			'_wpnonce'            => wp_create_nonce( 'oras_board_reports_save_manual_observer_pass' ),
			'manual_holder_names' => "Front Holder One\nFront Holder Two",
			'manual_quantity'     => '2',
			'manual_email'        => 'front@example.org',
			'manual_start_date'   => '2026-08-15',
			'manual_source'       => 'check',
			'manual_record_state' => 'active',
			'manual_notes'        => 'Frontend create',
			'redirect_to'         => home_url( '/board-reports/?oras_board_tab=observer_passes' ),
		);
		$_REQUEST = $_POST;
		$create_redirect = oras_manual_pass_capture_redirect(
			static function (): void {
				Board_Reports::handle_save_manual_observer_pass();
			}
		);
		oras_manual_pass_assert( false !== strpos( $create_redirect, 'oras_manual_pass_notice=created' ), 'Successful frontend create redirects with a safe notice' );
		$after_create_records = Manual_Observer_Pass_Store::query();
		oras_manual_pass_assert_same( count( $after_create_records ), $before_create + 1, 'Frontend create persists one manual pass' );
		$matching_created_records = array_values(
			array_filter(
				$after_create_records,
				static fn( array $record ): bool => 'front@example.org' === ( $record['email'] ?? '' )
			)
		);
		oras_manual_pass_assert_same( count( $matching_created_records ), 1, 'Frontend-created pass is identifiable without relying on timestamp ordering' );
		$created_record = $matching_created_records[0];
		$created_ids[] = (int) $created_record['id'];
		oras_manual_pass_assert_same( $created_record['holder_names'], array( 'Front Holder One', 'Front Holder Two' ), 'Frontend create sanitizes and preserves holder names' );

		$_POST['manual_pass_id'] = (string) $created_record['id'];
		$_POST['manual_holder_names'] = 'Edited Holder';
		$_POST['manual_quantity'] = '3';
		$_POST['_wpnonce'] = wp_create_nonce( 'oras_board_reports_save_manual_observer_pass' );
		$_REQUEST = $_POST;
		$edit_redirect = oras_manual_pass_capture_redirect(
			static function (): void {
				Board_Reports::handle_save_manual_observer_pass();
			}
		);
		oras_manual_pass_assert( false !== strpos( $edit_redirect, 'oras_manual_pass_notice=updated' ), 'Successful frontend edit redirects with a safe notice' );
		$edited = Manual_Observer_Pass_Store::get( (int) $created_record['id'] );
		oras_manual_pass_assert_same( $edited['holder_names'], array( 'Edited Holder' ), 'Frontend edit updates the existing manual pass' );
		oras_manual_pass_assert_same( $edited['quantity'], 3, 'Frontend edit updates explicit quantity' );
	} finally {
		$_GET = $original_get;
		$_POST = $original_post;
		$_REQUEST = $original_request;
		wp_set_current_user( 0 );
		foreach ( array_unique( array_map( 'absint', $created_ids ) ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		wp_delete_user( (int) $viewer_id );
	}
}

try {
	oras_manual_pass_run_checks();
	echo "Manual Observer Pass integration checks passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Manual Observer Pass integration checks failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
