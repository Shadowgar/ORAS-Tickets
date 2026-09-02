<?php

use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Storage\Legacy_Membership_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Membership_Frontend_Check_Exception extends RuntimeException {}
final class Oras_Membership_Frontend_Redirect_Exception extends RuntimeException {}
final class Oras_Membership_Frontend_Wp_Die_Exception extends RuntimeException {}

function oras_membership_frontend_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new Oras_Membership_Frontend_Check_Exception( $message );
	}
	echo 'PASS: ' . $message . "\n";
}

function oras_membership_frontend_assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new Oras_Membership_Frontend_Check_Exception( $message . ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) );
	}
	echo 'PASS: ' . $message . "\n";
}

/** @param callable():void $callback */
function oras_membership_frontend_capture_redirect( callable $callback ): string {
	$filter = static function ( string $location ): string {
		throw new Oras_Membership_Frontend_Redirect_Exception( $location );
	};
	add_filter( 'wp_redirect', $filter );
	try {
		$callback();
	} catch ( Oras_Membership_Frontend_Redirect_Exception $error ) {
		return $error->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filter );
	}

	throw new Oras_Membership_Frontend_Check_Exception( 'Expected redirect was not issued' );
}

/** @param callable():void $callback */
function oras_membership_frontend_capture_wp_die( callable $callback ): string {
	$filter = static function (): callable {
		return static function ( $message ): void {
			throw new Oras_Membership_Frontend_Wp_Die_Exception( wp_strip_all_tags( is_scalar( $message ) ? (string) $message : '' ) );
		};
	};
	add_filter( 'wp_die_handler', $filter );
	try {
		$callback();
	} catch ( Oras_Membership_Frontend_Wp_Die_Exception $error ) {
		return $error->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $filter );
	}

	throw new Oras_Membership_Frontend_Check_Exception( 'Expected WordPress rejection was not issued' );
}

function oras_membership_frontend_run_checks(): void {
	Legacy_Membership_Store::register_post_type();
	Board_Reports::register();
	oras_membership_frontend_assert( false !== has_action( 'admin_post_oras_board_reports_save_legacy_membership' ), 'Legacy membership save action is registered' );
	oras_membership_frontend_assert( false === has_action( 'admin_post_nopriv_oras_board_reports_save_legacy_membership' ), 'Legacy membership save has no unauthenticated action' );

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	oras_membership_frontend_assert( ! empty( $admin_ids ), 'Administrator fixture exists' );
	$admin_id = (int) $admin_ids[0];
	$viewer_id = wp_create_user( 'membership_viewer_' . wp_generate_password( 6, false ), wp_generate_password( 20 ), 'membership.viewer.' . wp_generate_password( 5, false ) . '@example.org' );
	oras_membership_frontend_assert( is_int( $viewer_id ) && $viewer_id > 0, 'View-only fixture user created' );
	$viewer = new WP_User( $viewer_id );
	$viewer->set_role( 'subscriber' );
	$viewer->add_cap( 'oras_tickets_view_board_dashboard' );

	$created_ids = array();
	$original_get = $_GET;
	$original_post = $_POST;
	$original_request = $_REQUEST;
	try {
		$legacy_id = Legacy_Membership_Store::create(
			array(
				'member_name'      => 'Legacy Front Member',
				'email'            => 'legacy.front@example.org',
				'start_date'       => '2026-01-01',
				'end_date'         => '2027-01-01',
				'status'           => 'active',
				'paypal_reference' => 'PAYPAL-FRONT',
				'notes'            => 'Frontend fixture',
			),
			$admin_id
		);
		oras_membership_frontend_assert( is_int( $legacy_id ) && $legacy_id > 0, 'Legacy membership fixture created' );
		$created_ids[] = $legacy_id;

		wp_set_current_user( $viewer_id );
		$_GET = array( 'oras_board_tab' => 'memberships' );
		$viewer_html = do_shortcode( '[oras_board_reports]' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'Memberships' ), 'View-only Board Reports user can open Memberships' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'Legacy Front Member' ), 'View-only user sees legacy PayPal members' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'Legacy PayPal' ), 'Membership roster identifies the legacy source' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'Active Memberships' ), 'Membership roster includes operational summary cards' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'name="oras_membership_source"' ), 'Membership roster includes source filtering' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'name="oras_membership_status"' ), 'Membership roster includes status filtering' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'name="oras_membership_account_link"' ), 'Membership roster includes account-link filtering' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'name="oras_membership_search"' ), 'Membership roster includes search' );
		foreach ( array( 'Member', 'Source', 'Level', 'Status' ) as $column_label ) {
			oras_membership_frontend_assert( false !== strpos( $viewer_html, '>' . $column_label . '<' ), 'Membership roster contains compact column ' . $column_label );
		}
		foreach ( array( 'Phone', 'Address', 'Start', 'Expiration / Renewal', 'Account Link', 'Details' ) as $column_label ) {
			oras_membership_frontend_assert( false === strpos( $viewer_html, '<th>' . $column_label . '</th>' ), 'Membership roster omits detail column ' . $column_label );
		}
		oras_membership_frontend_assert( false !== strpos( $viewer_html, '<dialog id="oras-membership-legacy_paypal-' ), 'Membership roster renders a native member detail dialog' );
		oras_membership_frontend_assert( false !== strpos( $viewer_html, 'data-membership-dialog-trigger=' ), 'Membership rows are keyboard-activatable dialog triggers' );
		foreach ( array( 'Email', 'Phone', 'Address', 'Membership level', 'Membership status', 'Start date', 'Expiration / next renewal date', 'Website-account linkage', 'PayPal Profile ID' ) as $detail_label ) {
			oras_membership_frontend_assert( false !== strpos( $viewer_html, $detail_label ), 'Membership dialog retains detail ' . $detail_label );
		}
		oras_membership_frontend_assert( false === strpos( $viewer_html, 'name="oras_membership_per_page"' ), 'Membership roster does not render a pagination page-size selector' );
		oras_membership_frontend_assert( false === strpos( $viewer_html, 'aria-label="Membership pages"' ), 'Membership roster does not render pagination controls' );
		oras_membership_frontend_assert( false === strpos( $viewer_html, '<form class="oras-board-reports__event-shell"' ), 'Memberships is a global report without an event selector' );
		oras_membership_frontend_assert( false === strpos( $viewer_html, 'name="legacy_member_name"' ), 'View-only user does not receive membership write controls' );
		oras_membership_frontend_assert( false === strpos( $viewer_html, 'Import Legacy PayPal Memberships' ), 'View-only user does not receive membership import controls' );

		$_POST = array(
			'_wpnonce'          => wp_create_nonce( 'oras_board_reports_save_legacy_membership' ),
			'legacy_member_name'=> 'Unauthorized Member',
			'legacy_end_date'   => '2027-01-01',
		);
		$_REQUEST = $_POST;
		$denied = oras_membership_frontend_capture_wp_die(
			static function (): void {
				Board_Reports::handle_save_legacy_membership();
			}
		);
		oras_membership_frontend_assert( '' !== $denied, 'View-only user cannot write legacy memberships' );

		wp_set_current_user( $admin_id );
		$_GET = array( 'oras_board_tab' => 'memberships' );
		$admin_html = do_shortcode( '[oras_board_reports]' );
		oras_membership_frontend_assert( false !== strpos( $admin_html, 'Add Legacy PayPal Membership' ), 'Membership manager sees the frontend add control' );
		oras_membership_frontend_assert( false !== strpos( $admin_html, 'name="legacy_member_name"' ), 'Membership manager receives the frontend form' );
		oras_membership_frontend_assert( false !== strpos( $admin_html, '<summary>Add Legacy PayPal Membership</summary>' ), 'Membership manager receives a collapsed legacy add panel' );
		oras_membership_frontend_assert( false !== strpos( $admin_html, '<summary>Import Legacy PayPal Memberships</summary>' ), 'Membership manager receives a collapsed Legacy PayPal import panel' );
		oras_membership_frontend_assert( strpos( $admin_html, 'Observer Pass Records' ) === false || strpos( $admin_html, 'Import Legacy PayPal Memberships' ) > strpos( $admin_html, 'Memberships' ), 'Membership import remains within the Memberships report' );

		$_POST = array(
			'_wpnonce'           => 'invalid',
			'legacy_member_name' => 'Bad Nonce',
			'legacy_end_date'    => '2027-01-01',
		);
		$_REQUEST = $_POST;
		$nonce_denied = oras_membership_frontend_capture_wp_die(
			static function (): void {
				Board_Reports::handle_save_legacy_membership();
			}
		);
		oras_membership_frontend_assert( '' !== $nonce_denied, 'Legacy membership write rejects a bad nonce' );

		$before_create = count( Legacy_Membership_Store::query() );
		$_POST = array(
			'_wpnonce'               => wp_create_nonce( 'oras_board_reports_save_legacy_membership' ),
			'legacy_member_name'     => '  Frontend Legacy Member  ',
			'legacy_email'           => 'FRONTEND.LEGACY@EXAMPLE.ORG',
			'legacy_start_date'      => '2026-02-01',
			'legacy_end_date'        => '2027-02-01',
			'legacy_status'          => 'active',
			'legacy_paypal_reference'=> 'PAYPAL-CREATE',
			'legacy_linked_user_id'  => '0',
			'legacy_notes'           => "Frontend create\nNo payment data",
			'redirect_to'            => home_url( '/board-reports/?oras_board_tab=memberships' ),
		);
		$_REQUEST = $_POST;
		$create_redirect = oras_membership_frontend_capture_redirect(
			static function (): void {
				Board_Reports::handle_save_legacy_membership();
			}
		);
		oras_membership_frontend_assert( false !== strpos( $create_redirect, 'oras_legacy_membership_notice=created' ), 'Successful frontend create redirects with a safe notice' );
		$after_create_records = Legacy_Membership_Store::query();
		oras_membership_frontend_assert_same( count( $after_create_records ), $before_create + 1, 'Frontend create persists one legacy membership' );
		$matching = array_values(
			array_filter(
				$after_create_records,
				static fn( array $record ): bool => 'frontend.legacy@example.org' === ( $record['email'] ?? '' )
			)
		);
		oras_membership_frontend_assert_same( count( $matching ), 1, 'Frontend-created membership is identifiable by normalized email' );
		$created = $matching[0];
		$created_ids[] = (int) $created['id'];
		oras_membership_frontend_assert_same( $created['member_name'], 'Frontend Legacy Member', 'Frontend create sanitizes the member name' );

		$_POST['legacy_membership_id'] = (string) $created['id'];
		$_POST['legacy_member_name'] = 'Edited Legacy Member';
		$_POST['legacy_linked_user_id'] = (string) $viewer_id;
		$_POST['legacy_transitioned'] = '1';
		$_POST['_wpnonce'] = wp_create_nonce( 'oras_board_reports_save_legacy_membership' );
		$_REQUEST = $_POST;
		$edit_redirect = oras_membership_frontend_capture_redirect(
			static function (): void {
				Board_Reports::handle_save_legacy_membership();
			}
		);
		oras_membership_frontend_assert( false !== strpos( $edit_redirect, 'oras_legacy_membership_notice=updated' ), 'Successful frontend edit redirects with a safe notice' );
		$edited = Legacy_Membership_Store::get( (int) $created['id'] );
		oras_membership_frontend_assert_same( $edited['member_name'], 'Edited Legacy Member', 'Frontend edit updates the existing legacy membership' );
		oras_membership_frontend_assert_same( $edited['linked_user_id'], $viewer_id, 'Frontend edit links an explicit WordPress user' );
		oras_membership_frontend_assert_same( $edited['transitioned'], true, 'Frontend edit records an explicit transition' );
	} finally {
		$_GET = $original_get;
		$_POST = $original_post;
		$_REQUEST = $original_request;
		wp_set_current_user( 0 );
		foreach ( array_unique( array_map( 'absint', $created_ids ) ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		wp_delete_user( $viewer_id );
	}
}

oras_membership_frontend_run_checks();
