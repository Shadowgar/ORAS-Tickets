<?php

use ORAS\Tickets\Frontend\Board_Reports;
use ORAS\Tickets\Import\Legacy_Membership_Csv_Importer;
use ORAS\Tickets\Storage\Legacy_Membership_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Legacy_Import_Check_Exception extends RuntimeException {}
final class Oras_Legacy_Import_Redirect_Exception extends RuntimeException {}
final class Oras_Legacy_Import_Wp_Die_Exception extends RuntimeException {}

function oras_legacy_import_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new Oras_Legacy_Import_Check_Exception( $message );
	}
	echo 'PASS: ' . $message . "\n";
}

function oras_legacy_import_assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new Oras_Legacy_Import_Check_Exception( $message . ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) );
	}
	echo 'PASS: ' . $message . "\n";
}

/** @param callable():void $callback */
function oras_legacy_import_capture_redirect( callable $callback ): string {
	$filter = static function ( string $location ): string {
		throw new Oras_Legacy_Import_Redirect_Exception( $location );
	};
	add_filter( 'wp_redirect', $filter );
	try {
		$callback();
	} catch ( Oras_Legacy_Import_Redirect_Exception $error ) {
		return $error->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filter );
	}

	throw new Oras_Legacy_Import_Check_Exception( 'Expected redirect was not issued' );
}

/** @param callable():void $callback */
function oras_legacy_import_capture_wp_die( callable $callback ): string {
	$filter = static function (): callable {
		return static function ( $message ): void {
			throw new Oras_Legacy_Import_Wp_Die_Exception( wp_strip_all_tags( is_scalar( $message ) ? (string) $message : '' ) );
		};
	};
	add_filter( 'wp_die_handler', $filter );
	try {
		$callback();
	} catch ( Oras_Legacy_Import_Wp_Die_Exception $error ) {
		return $error->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $filter );
	}

	throw new Oras_Legacy_Import_Check_Exception( 'Expected WordPress rejection was not issued' );
}

/** @param array<int,array<string,mixed>> $rows */
function oras_legacy_import_find_classification( array $rows, string $classification ): array {
	foreach ( $rows as $row ) {
		if ( $classification === ( $row['classification'] ?? '' ) ) {
			return $row;
		}
	}
	throw new Oras_Legacy_Import_Check_Exception( 'Missing preview classification: ' . $classification );
}

function oras_legacy_import_temp_csv( string $contents ): string {
	$path = wp_tempnam( 'oras-legacy-import.csv' );
	if ( ! is_string( $path ) || false === file_put_contents( $path, $contents ) ) {
		throw new Oras_Legacy_Import_Check_Exception( 'Could not create CSV fixture' );
	}

	return $path;
}

function oras_legacy_import_run_checks(): void {
	oras_legacy_import_assert( class_exists( Legacy_Membership_Csv_Importer::class ), 'Legacy membership CSV importer is available' );
	Legacy_Membership_Store::register_post_type();
	Board_Reports::register();
	oras_legacy_import_assert( false !== has_action( 'admin_post_oras_board_reports_preview_legacy_memberships' ), 'Authenticated preview action is registered' );
	oras_legacy_import_assert( false !== has_action( 'admin_post_oras_board_reports_commit_legacy_memberships' ), 'Authenticated commit action is registered' );
	oras_legacy_import_assert( false !== has_action( 'admin_post_oras_board_reports_cancel_legacy_membership_import' ), 'Authenticated cancel action is registered' );
	oras_legacy_import_assert( false === has_action( 'admin_post_nopriv_oras_board_reports_preview_legacy_memberships' ), 'No unauthenticated preview action is registered' );
	oras_legacy_import_assert( false === has_action( 'admin_post_nopriv_oras_board_reports_commit_legacy_memberships' ), 'No unauthenticated commit action is registered' );
	oras_legacy_import_assert( false === has_action( 'admin_post_nopriv_oras_board_reports_cancel_legacy_membership_import' ), 'No unauthenticated cancel action is registered' );

	$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	oras_legacy_import_assert( ! empty( $admin_ids ), 'Administrator fixture exists' );
	$admin_id = (int) $admin_ids[0];
	$viewer_id = wp_create_user( 'legacy_import_viewer_' . wp_generate_password( 6, false ), wp_generate_password( 20 ), 'legacy.import.viewer.' . wp_generate_password( 5, false ) . '@example.org' );
	oras_legacy_import_assert( is_int( $viewer_id ) && $viewer_id > 0, 'View-only fixture user created' );
	$viewer = new WP_User( $viewer_id );
	$viewer->set_role( 'subscriber' );
	$viewer->add_cap( 'oras_tickets_view_board_dashboard' );

	$created_ids = array();
	$temp_files = array();
	$original_get = $_GET;
	$original_post = $_POST;
	$original_files = $_FILES;
	$original_request = $_REQUEST;
	try {
		$existing_id = Legacy_Membership_Store::create(
			array(
				'member_name'      => 'Existing Legacy',
				'email'            => 'existing@example.org',
				'end_date'         => '2027-01-01',
				'status'           => 'active',
				'paypal_reference' => 'DUP-REF',
			),
			$admin_id
		);
		oras_legacy_import_assert( is_int( $existing_id ) && $existing_id > 0, 'Existing legacy duplicate fixture created' );
		$created_ids[] = $existing_id;

		$parser_csv = "Member Name,Email,Start Date,End Date,Status,PayPal Reference,Notes,Billing Address\n"
			. "New Member,NEW@EXAMPLE.ORG,2026-01-01,2027-01-01,active,NEW-REF,Safe note,Private ignored\n"
			. "=Formula Member,formula@example.org,,2027-02-01,active,FORMULA-REF,,Ignored\n"
			. "Duplicate Reference,other@example.org,,2027-03-01,active,DUP-REF,,Ignored\n"
			. "Duplicate Email,existing@example.org,,2027-04-01,active,OTHER-REF,,Ignored\n"
			. "Website Match,website@example.org,,2027-05-01,active,WEB-REF,,Ignored\n"
			. "Possible Website Name,,,2027-06-01,active,NAME-REF,,Ignored\n"
			. "Invalid Date,invalid@example.org,,not-a-date,active,BAD-DATE,,Ignored\n"
			. "Missing End,missing@example.org,,,active,MISSING-END,,Ignored\n";
		$parser_path = oras_legacy_import_temp_csv( $parser_csv );
		$temp_files[] = $parser_path;
		$importer = new Legacy_Membership_Csv_Importer(
			array(
				array( 'member_name' => 'Website Match', 'email' => 'website@example.org', 'user_id' => 20 ),
				array( 'member_name' => 'Possible Website Name', 'email' => 'other.website@example.org', 'user_id' => 21 ),
			),
			Legacy_Membership_Store::query()
		);
		$preview = $importer->preview_file( $parser_path );
		oras_legacy_import_assert( ! is_wp_error( $preview ), 'Allowed friendly CSV headers are parsed' );
		$rows = $preview['rows'];
		oras_legacy_import_assert_same( count( $rows ), 8, 'Parser returns every non-empty bounded row' );
		$new_row = oras_legacy_import_find_classification( $rows, Legacy_Membership_Csv_Importer::CLASS_NEW );
		oras_legacy_import_assert_same( $new_row['record']['email'], 'new@example.org', 'Parser normalizes email case' );
		oras_legacy_import_assert( ! isset( $new_row['record']['billing_address'] ), 'Unsupported billing fields are never retained' );
		$formula_rows = array_values( array_filter( $rows, static fn( array $row ): bool => 3 === (int) $row['row_number'] ) );
		oras_legacy_import_assert_same( $formula_rows[0]['display']['member_name'], "'=Formula Member", 'Preview neutralizes spreadsheet-formula display values' );
		oras_legacy_import_assert_same( oras_legacy_import_find_classification( $rows, Legacy_Membership_Csv_Importer::CLASS_EXACT_EMAIL )['default_approved'], false, 'Exact website email match requires explicit approval' );
		oras_legacy_import_assert_same( oras_legacy_import_find_classification( $rows, Legacy_Membership_Csv_Importer::CLASS_POSSIBLE_NAME )['default_approved'], false, 'Name-only website match remains advisory' );
		$duplicate_rows = array_values( array_filter( $rows, static fn( array $row ): bool => Legacy_Membership_Csv_Importer::CLASS_DUPLICATE === ( $row['classification'] ?? '' ) ) );
		oras_legacy_import_assert_same( count( $duplicate_rows ), 2, 'Existing reference and email duplicates are both classified' );
		$review_rows = array_values( array_filter( $rows, static fn( array $row ): bool => Legacy_Membership_Csv_Importer::CLASS_REVIEW === ( $row['classification'] ?? '' ) ) );
		oras_legacy_import_assert_same( count( $review_rows ), 2, 'Missing and invalid required dates require review' );

		$native_existing_id = Legacy_Membership_Store::create(
			array(
				'member_name'      => 'Native Existing',
				'email'            => 'native.existing@example.org',
				'end_date'         => '2026-09-15',
				'status'           => 'active',
				'paypal_reference' => 'I-NATIVE-UPDATE',
			),
			$admin_id
		);
		oras_legacy_import_assert( is_int( $native_existing_id ) && $native_existing_id > 0, 'Existing native PayPal profile fixture created' );
		$created_ids[] = $native_existing_id;
		$native_csv = "\xEF\xBB\xBFRH,Active Subscriptions Report,Generated 2026-09-01\n"
			. "FH,Account,ORAS\n"
			. "CH,Profile ID,Description,Payer Name,Payer Email,Profile Status,Date Last Paid,Next Bill Date,Gross Amount,Currency\n"
			. "SB,I-NATIVE-UPDATE,Annual membership,Native Existing Updated,native.existing@example.org,Active,08/31/2026,\"Sep 30, 2026\",99.00,USD\n"
			. "SB,I-NATIVE-DUP-1,Annual membership,Duplicate Payer One,shared.payer@example.org,Active,08/15/2026,09/15/2026,99.00,USD\n"
			. "SB,I-NATIVE-DUP-2,Annual membership,Duplicate Payer Two,shared.payer@example.org,Suspended,08/16/2026,09/16/2026,199.00,USD\n"
			. "SF,3\nSC,3\nRF,3\nRC,3\nFF,End of report\n";
		$native_path = oras_legacy_import_temp_csv( $native_csv );
		$temp_files[] = $native_path;
		$native_preview = ( new Legacy_Membership_Csv_Importer( array(), Legacy_Membership_Store::query() ) )->preview_file( $native_path );
		oras_legacy_import_assert( ! is_wp_error( $native_preview ), 'Native PayPal report metadata is skipped and the CH row supplies column names' );
		oras_legacy_import_assert_same( $native_preview['total'], 3, 'Only native PayPal SB subscription rows enter the preview' );
		$native_rows = $native_preview['rows'];
		$native_update = array_values( array_filter( $native_rows, static fn( array $row ): bool => 'I-NATIVE-UPDATE' === ( $row['record']['paypal_reference'] ?? '' ) ) )[0];
		oras_legacy_import_assert_same( $native_update['classification'], 'existing_profile_update', 'Existing Profile ID is classified as an update' );
		oras_legacy_import_assert_same( $native_update['record']['member_name'], 'Native Existing Updated', 'Payer Name maps to member name' );
		oras_legacy_import_assert_same( $native_update['record']['email'], 'native.existing@example.org', 'Payer Email maps to normalized email' );
		oras_legacy_import_assert_same( $native_update['record']['start_date'], '2026-08-31', 'Date Last Paid maps to a normalized date' );
		oras_legacy_import_assert_same( $native_update['record']['end_date'], '2026-09-30', 'Next Bill Date maps to the next-renewal date' );
		oras_legacy_import_assert_same( $native_update['record']['notes'], 'Annual membership', 'Description maps to notes' );
		oras_legacy_import_assert( ! isset( $native_update['record']['gross_amount'], $native_update['record']['currency'] ), 'Native PayPal financial fields are ignored' );
		$native_duplicate_email_rows = array_values( array_filter( $native_rows, static fn( array $row ): bool => 'shared.payer@example.org' === ( $row['record']['email'] ?? '' ) ) );
		oras_legacy_import_assert_same( count( $native_duplicate_email_rows ), 2, 'Distinct Profile IDs sharing an email remain visible in preview' );
		oras_legacy_import_assert_same( array_unique( array_column( $native_duplicate_email_rows, 'classification' ) ), array( Legacy_Membership_Csv_Importer::CLASS_REVIEW ), 'Duplicate-email Profile IDs are flagged for review' );
		oras_legacy_import_assert_same( array_unique( array_column( $native_duplicate_email_rows, 'default_approved' ) ), array( false ), 'Duplicate-email Profile IDs are never approved automatically' );
		oras_legacy_import_assert_same( $native_duplicate_email_rows[1]['record']['status'], 'inactive', 'Suspended PayPal profiles normalize to inactive status' );
		$native_preview_token = Legacy_Membership_Csv_Importer::store_preview( $admin_id, $native_preview );
		wp_set_current_user( $admin_id );
		$_GET = array( 'oras_board_tab' => 'memberships', 'oras_legacy_import_token' => $native_preview_token );
		$native_preview_html = do_shortcode( '[oras_board_reports]' );
		oras_legacy_import_assert( false !== strpos( $native_preview_html, 'Existing Profile Update' ), 'Native Profile ID updates have a clear preview classification' );
		oras_legacy_import_assert( false !== strpos( $native_preview_html, 'Expiration / Next Renewal' ), 'Native Next Bill Date is presented as a next-renewal date' );
		Legacy_Membership_Csv_Importer::delete_preview( $admin_id, $native_preview_token );
		$native_update_result = Legacy_Membership_Csv_Importer::commit_preview( $native_preview, array( $native_update['row_token'] ), $admin_id );
		oras_legacy_import_assert_same( $native_update_result['created'], 0, 'Repeat Profile ID import does not create a new record' );
		oras_legacy_import_assert_same( $native_update_result['updated'], 1, 'Repeat Profile ID import updates the existing record' );
		$native_matches = array_values( array_filter( Legacy_Membership_Store::query(), static fn( array $record ): bool => 'I-NATIVE-UPDATE' === ( $record['paypal_reference'] ?? '' ) ) );
		oras_legacy_import_assert_same( count( $native_matches ), 1, 'Repeat Profile ID import leaves exactly one stored record' );
		oras_legacy_import_assert_same( $native_matches[0]['id'], $native_existing_id, 'Repeat Profile ID import preserves the stored record identity' );
		oras_legacy_import_assert_same( $native_matches[0]['end_date'], '2026-09-30', 'Repeat Profile ID import refreshes the next-renewal date' );
		$_GET = array(
			'oras_board_tab'            => 'memberships',
			'oras_legacy_import_notice' => 'imported',
			'oras_legacy_imported'       => 0,
			'oras_legacy_updated'        => 1,
			'oras_legacy_skipped'        => 0,
			'oras_legacy_errors'         => 0,
		);
		oras_legacy_import_assert( false !== strpos( do_shortcode( '[oras_board_reports]' ), '1 updated' ), 'Import completion notice reports Profile ID updates' );

		$bad_headers = oras_legacy_import_temp_csv( "Full Name,Email\nMissing Mapping,x@example.org\n" );
		$temp_files[] = $bad_headers;
		oras_legacy_import_assert( is_wp_error( $importer->preview_file( $bad_headers ) ), 'CSV without required allowed headers is rejected' );

		wp_set_current_user( $viewer_id );
		$_GET = array( 'oras_board_tab' => 'memberships' );
		$viewer_html = do_shortcode( '[oras_board_reports]' );
		oras_legacy_import_assert( false === strpos( $viewer_html, 'name="legacy_membership_csv"' ), 'View-only user does not receive CSV import controls' );
		$_POST = array( '_wpnonce' => wp_create_nonce( 'oras_board_reports_preview_legacy_memberships' ) );
		$_REQUEST = $_POST;
		$denied = oras_legacy_import_capture_wp_die( static function (): void { Board_Reports::handle_preview_legacy_memberships(); } );
		oras_legacy_import_assert( '' !== $denied, 'View-only user cannot preview legacy imports' );

		wp_set_current_user( $admin_id );
		$_POST = array( '_wpnonce' => 'invalid' );
		$_REQUEST = $_POST;
		$nonce_denied = oras_legacy_import_capture_wp_die( static function (): void { Board_Reports::handle_preview_legacy_memberships(); } );
		oras_legacy_import_assert( '' !== $nonce_denied, 'Preview rejects a bad nonce' );

		$_POST = array( '_wpnonce' => wp_create_nonce( 'oras_board_reports_preview_legacy_memberships' ) );
		$_REQUEST = $_POST;
		$_FILES = array(
			'legacy_membership_csv' => array(
				'name'     => 'legacy.txt',
				'type'     => 'text/plain',
				'tmp_name' => $parser_path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $parser_path ),
			),
		);
		$type_redirect = oras_legacy_import_capture_redirect( static function (): void { Board_Reports::handle_preview_legacy_memberships(); } );
		oras_legacy_import_assert( false !== strpos( $type_redirect, 'oras_legacy_import_notice=error' ), 'Preview rejects a non-CSV filename' );

		$_FILES['legacy_membership_csv']['name'] = 'legacy.csv';
		$_FILES['legacy_membership_csv']['size'] = Legacy_Membership_Csv_Importer::MAX_UPLOAD_BYTES + 1;
		$size_redirect = oras_legacy_import_capture_redirect( static function (): void { Board_Reports::handle_preview_legacy_memberships(); } );
		oras_legacy_import_assert( false !== strpos( $size_redirect, 'oras_legacy_import_notice=error' ), 'Preview rejects oversized uploads' );

		$handler_csv = "member_name,email,end_date,status,paypal_reference\nHandler One,handler.one@example.org,2027-01-01,active,HANDLER-1\nHandler Two,handler.two@example.org,2027-02-01,active,HANDLER-2\n";
		$handler_path = oras_legacy_import_temp_csv( $handler_csv );
		$temp_files[] = $handler_path;
		$_FILES['legacy_membership_csv'] = array(
			'name'     => 'legacy.csv',
			'type'     => 'text/csv',
			'tmp_name' => $handler_path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $handler_path ),
		);
		$upload_filter = static fn(): bool => true;
		add_filter( 'oras_tickets_legacy_import_is_uploaded_file', $upload_filter );
		try {
			$preview_redirect = oras_legacy_import_capture_redirect( static function (): void { Board_Reports::handle_preview_legacy_memberships(); } );
		} finally {
			remove_filter( 'oras_tickets_legacy_import_is_uploaded_file', $upload_filter );
		}
		parse_str( (string) wp_parse_url( $preview_redirect, PHP_URL_QUERY ), $preview_args );
		$token = sanitize_key( (string) ( $preview_args['oras_legacy_import_token'] ?? '' ) );
		oras_legacy_import_assert( '' !== $token, 'Successful preview redirects with a random preview token' );
		$stored_preview = Legacy_Membership_Csv_Importer::get_preview( $admin_id, $token );
		oras_legacy_import_assert( is_array( $stored_preview ) && 2 === count( $stored_preview['rows'] ), 'Preview stores normalized rows in a per-user transient' );
		oras_legacy_import_assert_same( Legacy_Membership_Csv_Importer::get_preview( $viewer_id, $token ), null, 'Another user cannot access the preview transient' );
		oras_legacy_import_assert( false === strpos( wp_json_encode( $stored_preview ), 'Billing Address' ), 'Preview transient contains no raw CSV headers or contents' );

		$_GET = array( 'oras_board_tab' => 'memberships', 'oras_legacy_import_token' => $token );
		$preview_html = do_shortcode( '[oras_board_reports]' );
		oras_legacy_import_assert( false !== strpos( $preview_html, 'Legacy PayPal Import Preview' ), 'Membership managers can render the stored preview' );
		oras_legacy_import_assert( false !== strpos( $preview_html, 'Handler One' ), 'Preview renders normalized membership fields' );

		$_POST = array(
			'_wpnonce'                => wp_create_nonce( 'oras_board_reports_cancel_legacy_membership_import' ),
			'legacy_import_token'     => $token,
		);
		$_REQUEST = $_POST;
		$cancel_redirect = oras_legacy_import_capture_redirect( static function (): void { Board_Reports::handle_cancel_legacy_membership_import(); } );
		oras_legacy_import_assert( false !== strpos( $cancel_redirect, 'oras_legacy_import_notice=cancelled' ), 'Cancel redirects with a safe notice' );
		oras_legacy_import_assert_same( Legacy_Membership_Csv_Importer::get_preview( $admin_id, $token ), null, 'Cancel immediately deletes preview data' );

		$commit_preview = ( new Legacy_Membership_Csv_Importer( array(), Legacy_Membership_Store::query() ) )->preview_file( $handler_path );
		$commit_token = Legacy_Membership_Csv_Importer::store_preview( $admin_id, $commit_preview );
		$approved = array_map( static fn( array $row ): string => (string) $row['row_token'], $commit_preview['rows'] );
		$late_duplicate_id = Legacy_Membership_Store::create(
			array( 'member_name' => 'Late Duplicate', 'email' => 'handler.two@example.org', 'end_date' => '2027-02-01', 'status' => 'active' ),
			$admin_id
		);
		oras_legacy_import_assert( is_int( $late_duplicate_id ) && $late_duplicate_id > 0, 'Commit-time duplicate fixture created' );
		$created_ids[] = $late_duplicate_id;
		$_POST = array(
			'_wpnonce'            => wp_create_nonce( 'oras_board_reports_commit_legacy_memberships' ),
			'legacy_import_token' => $commit_token,
			'approved_rows'       => $approved,
		);
		$_REQUEST = $_POST;
		$commit_redirect = oras_legacy_import_capture_redirect( static function (): void { Board_Reports::handle_commit_legacy_memberships(); } );
		oras_legacy_import_assert( false !== strpos( $commit_redirect, 'oras_legacy_imported=1' ), 'Commit reports the successfully imported row count' );
		oras_legacy_import_assert( false !== strpos( $commit_redirect, 'oras_legacy_skipped=1' ), 'Commit reports a duplicate skipped after preview' );
		oras_legacy_import_assert_same( Legacy_Membership_Csv_Importer::get_preview( $admin_id, $commit_token ), null, 'Successful commit immediately deletes preview data' );
		$handler_one = array_values( array_filter( Legacy_Membership_Store::query(), static fn( array $record ): bool => 'handler.one@example.org' === ( $record['email'] ?? '' ) ) );
		oras_legacy_import_assert_same( count( $handler_one ), 1, 'Commit creates only the approved non-duplicate normalized row' );
		$created_ids[] = (int) $handler_one[0]['id'];
	} finally {
		$_GET = $original_get;
		$_POST = $original_post;
		$_FILES = $original_files;
		$_REQUEST = $original_request;
		wp_set_current_user( 0 );
		foreach ( array_unique( array_map( 'absint', $created_ids ) ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		wp_delete_user( $viewer_id );
		foreach ( $temp_files as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}
}

oras_legacy_import_run_checks();
