<?php

use ORAS\Tickets\Reporting\Membership_Report_Service;
use ORAS\Tickets\Storage\Legacy_Membership_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Oras_Membership_Report_Check_Exception extends RuntimeException {}

function oras_membership_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new Oras_Membership_Report_Check_Exception( $message );
	}
	echo 'PASS: ' . $message . "\n";
}

function oras_membership_assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new Oras_Membership_Report_Check_Exception( $message . ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) );
	}
	echo 'PASS: ' . $message . "\n";
}

/** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
function oras_membership_find_row( array $rows, string $source, int $source_record_id ): array {
	foreach ( $rows as $row ) {
		if ( $source === ( $row['source'] ?? '' ) && $source_record_id === (int) ( $row['source_record_id'] ?? 0 ) ) {
			return $row;
		}
	}
	throw new Oras_Membership_Report_Check_Exception( 'Membership row not found: ' . $source . '/' . $source_record_id );
}

function oras_membership_run_checks(): void {
	if ( ! class_exists( Membership_Report_Service::class ) ) {
		throw new Oras_Membership_Report_Check_Exception( 'Membership Report service is unavailable' );
	}

	global $wpdb;
	if ( ! $wpdb instanceof wpdb ) {
		throw new Oras_Membership_Report_Check_Exception( 'WordPress database is unavailable' );
	}

	Legacy_Membership_Store::register_post_type();
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$memberships_table = $wpdb->prefix . 'oras_pmpro_mu_' . $suffix;
	$levels_table = $wpdb->prefix . 'oras_pmpro_levels_' . $suffix;
	$quoted_memberships = '`' . esc_sql( $memberships_table ) . '`';
	$quoted_levels = '`' . esc_sql( $levels_table ) . '`';
	$created_users = array();
	$created_legacy = array();

	try {
		$wpdb->query( "CREATE TABLE {$quoted_memberships} (id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, membership_id bigint unsigned NOT NULL, status varchar(32) NOT NULL, startdate datetime NULL, enddate datetime NULL, PRIMARY KEY (id))" );
		$wpdb->query( "CREATE TABLE {$quoted_levels} (id bigint unsigned NOT NULL, name varchar(255) NOT NULL, PRIMARY KEY (id))" );
		$wpdb->insert( $levels_table, array( 'id' => 1, 'name' => 'Family' ), array( '%d', '%s' ) );
		$wpdb->insert( $levels_table, array( 'id' => 2, 'name' => 'Individual' ), array( '%d', '%s' ) );

		$user_specs = array(
			array( 'exact_member', 'Exact Member', 'exactmatch@example.org' ),
			array( 'expiring_member', 'Expiring Member', 'expiring@example.org' ),
			array( 'expired_member', 'Expired Member', 'expired@example.org' ),
			array( 'inactive_member', 'Inactive Member', 'inactive@example.org' ),
		);
		foreach ( $user_specs as $spec ) {
			$user_id = wp_create_user( $spec[0] . '_' . $suffix, wp_generate_password( 20 ), $suffix . '.' . $spec[2] );
			oras_membership_assert( is_int( $user_id ) && $user_id > 0, 'Website member fixture user created' );
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $spec[1] ) );
			$created_users[] = $user_id;
		}

		$website_specs = array(
			array( $created_users[0], 1, 'active', '2026-01-01 00:00:00', '2027-01-01 00:00:00' ),
			array( $created_users[1], 2, 'active', '2026-01-01 00:00:00', '2026-09-15 00:00:00' ),
			array( $created_users[2], 2, 'active', '2025-01-01 00:00:00', '2026-08-31 00:00:00' ),
			array( $created_users[3], 2, 'inactive', '2025-01-01 00:00:00', '2027-01-01 00:00:00' ),
		);
		$membership_ids = array();
		foreach ( $website_specs as $spec ) {
			$wpdb->insert(
				$memberships_table,
				array( 'user_id' => $spec[0], 'membership_id' => $spec[1], 'status' => $spec[2], 'startdate' => $spec[3], 'enddate' => $spec[4] ),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			$membership_ids[] = (int) $wpdb->insert_id;
		}

		$legacy_specs = array(
			array( 'Legacy Exact', $suffix . '.exactmatch@example.org', '2027-02-01', 0 ),
			array( 'Exact Member', '', '2027-03-01', 0 ),
			array( 'Linked Legacy', 'linked@example.org', '2027-04-01', $created_users[1] ),
			array( 'Expired Legacy', 'legacy.expired@example.org', '2026-08-31', 0 ),
		);
		foreach ( $legacy_specs as $index => $spec ) {
			$legacy_id = Legacy_Membership_Store::create(
				array(
					'member_name'      => $spec[0],
					'email'            => $spec[1],
					'start_date'       => '2026-01-01',
					'end_date'         => $spec[2],
					'status'           => 'active',
					'paypal_reference' => 'fixture-' . $index,
					'linked_user_id'   => $spec[3],
				),
				1
			);
			oras_membership_assert( is_int( $legacy_id ) && $legacy_id > 0, 'Legacy membership fixture created' );
			$created_legacy[] = $legacy_id;
		}

		$before_membership_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted_memberships}" );
		$service = new Membership_Report_Service( new DateTimeImmutable( '2026-09-01 00:00:00', wp_timezone() ), $memberships_table, $levels_table );
		$report = $service->get_report();
		oras_membership_assert_same( $report['available'], true, 'Unified membership report is available with legacy and PMPro-compatible sources' );
		oras_membership_assert_same( $report['website_available'], true, 'PMPro-compatible website source is detected' );
		oras_membership_assert_same( count( $report['all_rows'] ), 8, 'Website and legacy membership rows are combined without merging' );

		$website_active = oras_membership_find_row( $report['all_rows'], 'website', $membership_ids[0] );
		oras_membership_assert_same( $website_active['username'], 'exact_member_' . $suffix, 'Website row includes the WordPress username' );
		oras_membership_assert_same( $website_active['level_name'], 'Family', 'Website row includes the PMPro-compatible level name' );
		oras_membership_assert_same( $website_active['operational_status'], 'active', 'Future active website membership is Active' );
		oras_membership_assert_same( oras_membership_find_row( $report['all_rows'], 'website', $membership_ids[1] )['operational_status'], 'expiring_soon', 'Website membership within 30 days is Expiring Soon' );
		oras_membership_assert_same( oras_membership_find_row( $report['all_rows'], 'website', $membership_ids[2] )['operational_status'], 'expired', 'Past website end date is Expired' );
		oras_membership_assert_same( oras_membership_find_row( $report['all_rows'], 'website', $membership_ids[3] )['operational_status'], 'inactive', 'Inactive PMPro source status remains Inactive' );

		$legacy_exact = oras_membership_find_row( $report['all_rows'], 'legacy_paypal', $created_legacy[0] );
		oras_membership_assert_same( $legacy_exact['match_type'], 'exact_email', 'Exact normalized legacy email receives a match indicator' );
		oras_membership_assert_same( $legacy_exact['matching_user_ids'], array( $created_users[0] ), 'Exact email indicator identifies the website account without linking it' );
		oras_membership_assert_same( $legacy_exact['linked_user_id'], 0, 'Exact email review indicator does not mutate legacy linkage' );
		$legacy_name = oras_membership_find_row( $report['all_rows'], 'legacy_paypal', $created_legacy[1] );
		oras_membership_assert_same( $legacy_name['match_type'], 'possible_name', 'Name-only legacy match remains advisory' );
		oras_membership_assert_same( oras_membership_find_row( $report['all_rows'], 'legacy_paypal', $created_legacy[2] )['account_link_status'], 'linked', 'Explicit legacy linkage is retained' );

		oras_membership_assert_same( $report['summary']['total_count'], 8, 'Membership summary counts all source records' );
		oras_membership_assert_same( $report['summary']['active_count'], 5, 'Active summary includes Active and Expiring Soon memberships' );
		oras_membership_assert_same( $report['summary']['website_active_count'], 2, 'Website active summary is source-specific' );
		oras_membership_assert_same( $report['summary']['legacy_active_count'], 3, 'Legacy active summary is source-specific' );
		oras_membership_assert_same( $report['summary']['exact_email_match_count'], 1, 'Summary counts exact-email review indicators' );
		oras_membership_assert_same( $report['summary']['possible_name_match_count'], 1, 'Summary counts possible name indicators' );

		oras_membership_assert_same( count( $service->get_report( array( 'source' => 'legacy_paypal' ) )['rows'] ), 4, 'Source filter isolates legacy memberships' );
		oras_membership_assert_same( count( $service->get_report( array( 'status' => 'expiring_soon' ) )['rows'] ), 1, 'Status filter isolates Expiring Soon memberships' );
		oras_membership_assert_same( count( $service->get_report( array( 'account_link' => 'exact_email' ) )['rows'] ), 1, 'Account-link filter isolates exact-email review rows' );
		oras_membership_assert_same( count( $service->get_report( array( 'search' => 'exact_member_' . $suffix ) )['rows'] ), 1, 'Search includes website usernames' );
		$page = $service->get_report( array( 'page' => 2, 'per_page' => 3 ) );
		oras_membership_assert_same( count( $page['rows'] ), 3, 'Membership report paginates normalized rows' );
		oras_membership_assert_same( $page['pagination']['total_pages'], 3, 'Membership pagination reports total pages' );
		oras_membership_assert_same( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted_memberships}" ), $before_membership_count, 'Membership reporting performs no PMPro-compatible writes' );
	} finally {
		foreach ( $created_legacy as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
		foreach ( $created_users as $user_id ) {
			wp_delete_user( (int) $user_id );
		}
		$wpdb->query( "DROP TABLE IF EXISTS {$quoted_memberships}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$quoted_levels}" );
	}
}

try {
	oras_membership_run_checks();
	echo "Membership report integration checks passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Membership report integration checks failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
