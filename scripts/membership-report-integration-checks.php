<?php

use ORAS\Tickets\Reporting\Membership_Report_Service;
use ORAS\Tickets\Storage\Legacy_Membership_Store;

if ( ! function_exists( 'pmpro_getSubscriptionForUser' ) ) {
	/** @return object|null */
	function pmpro_getSubscriptionForUser( int $user_id ) {
		return $GLOBALS['oras_membership_subscription_fixtures'][ $user_id ] ?? null;
	}
}

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

/** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
function oras_membership_find_person_by_email( array $rows, string $email ): array {
	foreach ( $rows as $row ) {
		if ( strtolower( (string) ( $row['email'] ?? '' ) ) === strtolower( $email ) ) return $row;
	}
	throw new Oras_Membership_Report_Check_Exception( 'Normalized person not found for ' . $email );
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
			array( 'renewing_member', 'Renewing Member', 'renewing@example.org' ),
			array( 'recurring_member', 'Recurring Member', 'recurring@example.org' ),
			array( 'no_expiration_member', 'No Expiration Member', 'noexpiration@example.org' ),
		);
		foreach ( $user_specs as $spec ) {
			$user_id = wp_create_user( $spec[0] . '_' . $suffix, wp_generate_password( 20 ), $suffix . '.' . $spec[2] );
			oras_membership_assert( is_int( $user_id ) && $user_id > 0, 'Website member fixture user created' );
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $spec[1] ) );
			$created_users[] = $user_id;
		}
		update_user_meta( $created_users[0], 'billing_phone', '555-0123' );
		update_user_meta( $created_users[0], 'billing_address_1', '123 WooCommerce Street' );
		update_user_meta( $created_users[0], 'billing_address_2', 'Suite 4' );
		update_user_meta( $created_users[0], 'billing_city', 'Oil City' );
		update_user_meta( $created_users[0], 'billing_state', 'PA' );
		update_user_meta( $created_users[0], 'billing_postcode', '16301' );
		update_user_meta( $created_users[0], 'billing_country', 'US' );
		update_user_meta( $created_users[0], 'pmpro_bphone', '555-0999' );
		update_user_meta( $created_users[0], 'pmpro_baddress1', '123 PMPro Street' );
		update_user_meta( $created_users[0], 'share', 'Yes' );
		update_user_meta( $created_users[0], 'astro_league', 'No' );
		update_user_meta( $created_users[0], 'texting', '555-0112' );
		update_user_meta( $created_users[0], 'committees', array( 'education', 'fund' ) );
		update_user_meta( $created_users[0], 'family', "Jane Doe\n555-0113" );
		$GLOBALS['oras_membership_subscription_fixtures'] = array(
			$created_users[4] => (object) array( 'next_payment_date' => '2026-10-01 00:00:00' ),
			$created_users[5] => (object) array(),
		);

		$website_specs = array(
			array( $created_users[0], 1, 'active', '2026-01-01 00:00:00', '2027-01-01 00:00:00' ),
			array( $created_users[1], 2, 'active', '2026-01-01 00:00:00', '2026-09-15 00:00:00' ),
			array( $created_users[2], 2, 'active', '2025-01-01 00:00:00', '2026-08-31 00:00:00' ),
			array( $created_users[3], 2, 'inactive', '2025-01-01 00:00:00', '2027-01-01 00:00:00' ),
			array( $created_users[4], 1, 'active', '2026-01-01 00:00:00', '0000-00-00 00:00:00' ),
			array( $created_users[5], 1, 'active', '2026-01-01 00:00:00', '0000-00-00 00:00:00' ),
			array( $created_users[6], 1, 'active', '2026-01-01 00:00:00', '0000-00-00 00:00:00' ),
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
		foreach ( array( 2, 2, 1 ) as $history_level ) {
			$wpdb->insert( $memberships_table, array( 'user_id' => $created_users[0], 'membership_id' => $history_level, 'status' => 'inactive', 'startdate' => '2024-01-01 00:00:00', 'enddate' => '2025-01-01 00:00:00' ), array( '%d', '%d', '%s', '%s', '%s' ) );
		}
		$wpdb->insert( $memberships_table, array( 'user_id' => 0, 'membership_id' => 1, 'status' => 'inactive', 'startdate' => '2024-01-01 00:00:00', 'enddate' => '2025-01-01 00:00:00' ), array( '%d', '%d', '%s', '%s', '%s' ) );

		$legacy_specs = array(
			array( 'Legacy Exact', $suffix . '.exactmatch@example.org', '2027-02-01', 0 ),
			array( 'Exact Member', '', '2027-03-01', 0 ),
			array( 'Linked Legacy', 'linked@example.org', '2027-04-01', $created_users[1] ),
			array( 'Expired Legacy', 'legacy.expired@example.org', '2026-08-31', 0 ),
			array( 'Legacy Exact Two', $suffix . '.exactmatch@example.org', '2027-03-01', 0 ),
			array( 'Same Name', 'same.one@example.org', '2027-03-01', 0 ),
			array( 'same name', 'same.two@example.org', '2027-03-01', 0 ),
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
		$all_people = $service->get_report( array( 'roster_scope' => 'all_people' ) );
		oras_membership_assert_same( $report['available'], true, 'Unified membership report is available with legacy and PMPro-compatible sources' );
		oras_membership_assert_same( $report['filters']['roster_scope'], 'current', 'Membership roster defaults to Current Members person scope' );
		oras_membership_assert_same( $report['website_available'], true, 'PMPro-compatible website source is detected' );
		oras_membership_assert_same( count( $all_people['all_rows'] ), 11, 'Website and legacy records normalize to one roster person per identity' );

		$website_active = oras_membership_find_row( $all_people['all_rows'], 'website_legacy', $membership_ids[0] );
		oras_membership_assert_same( $website_active['username'], 'exact_member_' . $suffix, 'Website row includes the WordPress username' );
		oras_membership_assert_same( $website_active['level_name'], 'Family', 'Website row includes the PMPro-compatible level name' );
		oras_membership_assert_same( $website_active['operational_status'], 'active', 'Future active website membership is Active' );
		oras_membership_assert_same( $website_active['phone'], '555-0999', 'Website row gives existing PMPro billing phone precedence over WooCommerce profile data' );
		oras_membership_assert_same( $website_active['address_summary'], '123 PMPro Street Suite 4 Oil City, PA 16301 US', 'Website row uses PMPro billing address with WooCommerce user-profile fallback fields' );
		oras_membership_assert_same( $website_active['membership_questions'], array(
			array( 'label' => 'Is it okay to share your Demographics with members?', 'value' => 'Yes' ),
			array( 'label' => 'Do you have a Astro league membership?', 'value' => 'No' ),
			array( 'label' => 'Can we send you text messages?', 'value' => '555-0112' ),
			array( 'label' => 'Are you interested in joining an ORAS committee?', 'value' => 'Education and Outreach Committee, Fund Development Committee' ),
			array( 'label' => 'Do you have additional family contact information?', 'value' => "Jane Doe\n555-0113" ),
		), 'Website row exposes only the configured membership-question answers with readable committee labels' );
		oras_membership_assert_same( $website_active['membership_date_state'], 'expires', 'Fixed PMPro end dates are presented as expiration dates' );
		oras_membership_assert_same( count( $website_active['membership_history'] ), 3, 'One current PMPro person retains three historical relationships' );
		oras_membership_assert_same( $website_active['level_name'], 'Family', 'Historical PMPro levels do not override the current level' );
		oras_membership_assert_same( count( $website_active['legacy_paypal_records'] ), 2, 'Two PayPal Profile IDs remain attached as distinct records' );
		oras_membership_assert_same( $website_active['legacy_paypal_records'][0]['paypal_reference'] !== $website_active['legacy_paypal_records'][1]['paypal_reference'], true, 'PayPal Profile IDs are not collapsed' );
		oras_membership_assert_same( count( array_filter( $all_people['all_rows'], static fn( array $row ): bool => 'same name' === strtolower( (string) ( $row['member_name'] ?? '' ) ) ) ), 2, 'Name-only similarity never merges people' );
		oras_membership_assert_same( count( $report['orphan_pmpro_rows'] ), 1, 'Unresolved PMPro history is diagnostic-only' );
		oras_membership_assert_same( $website_active['source_label'], 'Website / PMPro + Legacy PayPal', 'Cross-source person labels both systems' );
		oras_membership_assert_same( count( $service->get_report( array( 'roster_scope' => 'former' ) )['rows'] ), 3, 'Former scope contains people without a current source' );
		oras_membership_assert_same( count( $report['rows'] ), 8, 'Current scope excludes former people' );
		oras_membership_assert_same( count( $all_people['rows'] ), 11, 'All scope contains normalized people, not source rows' );
		oras_membership_assert_same( $report['summary']['current_unique_members'], 8, 'Current Unique Members counts normalized people' );
		oras_membership_assert_same( $report['summary']['website_active_count'], 5, 'Website active summary is source-specific' );
		oras_membership_assert_same( $report['summary']['legacy_active_count'], 6, 'Legacy active summary is source-specific' );
		oras_membership_assert_same( $report['summary']['matched_across_sources'], 2, 'Matched Across Both Sources counts normalized people' );
		oras_membership_assert_same( count( $service->get_report( array( 'search' => 'exact_member_' . $suffix ) )['rows'] ), 1, 'Search includes aggregate website usernames' );
		$page = $service->get_report( array( 'page' => 2, 'per_page' => 3 ) );
		oras_membership_assert_same( count( $page['rows'] ), 8, 'Membership report returns the complete current person list without pagination' );
		oras_membership_assert_same( $page['pagination']['total_pages'], 1, 'Membership report reports one continuous list page' );
		oras_membership_assert_same( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted_memberships}" ), $before_membership_count, 'Membership reporting performs no PMPro-compatible writes' );
	} finally {
		unset( $GLOBALS['oras_membership_subscription_fixtures'] );
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
