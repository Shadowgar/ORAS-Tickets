<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Reporting\Membership_Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only, deliberately small membership view for desk volunteers. */
final class Member_Lookup_Service {
	/** @return array<int,array<string,mixed>> */
	public function search( string $query ): array {
		$needle = strtolower( trim( $query ) );
		if ( strlen( $needle ) < 2 ) {
			return array();
		}
		$results = array();
		$seen_emails = array();
		$report = ( new Membership_Report_Service() )->get_report( array( 'search' => $needle, 'roster_scope' => Membership_Report_Service::ROSTER_ALL ) );
		foreach ( is_array( $report['rows'] ?? null ) ? $report['rows'] : array() as $row ) {
			$name  = (string) ( $row['member_name'] ?? '' );
			$email = strtolower( (string) ( $row['email'] ?? '' ) );
			if ( false === stripos( $name, $needle ) && false === stripos( $email, $needle ) ) {
				continue;
			}
			$seen_emails[ $email ] = true;
			$results[] = array(
				'name'       => $name,
				'level_name' => (string) ( $row['level_name'] ?? '' ),
				'status'     => strtoupper( str_replace( '_', ' ', (string) ( $row['operational_status'] ?? 'not_found' ) ) ),
				'expiration' => (string) ( $row['end_date'] ?? '' ),
			);
		}
		foreach ( ( new Offline_Membership_Store() )->search( $needle ) as $pending ) {
			$email = strtolower( (string) $pending['email'] );
			if ( 'redeemed' === (string) $pending['status'] && isset( $seen_emails[ $email ] ) ) {
				continue;
			}
			$status = (string) $pending['status'];
			if ( 'pending' === $status && (string) $pending['expires_at_utc'] < Store::utc_now() ) {
				$status = 'expired';
			}
			$results[] = array(
				'name'       => trim( (string) $pending['first_name'] . ' ' . (string) $pending['last_name'] ),
				'level_name' => (string) $pending['level_name'],
				'status'     => 'pending' === $status ? 'PENDING ONLINE ACTIVATION' : strtoupper( $status ),
				'expiration' => '',
			);
		}

		return array_slice( $results, 0, 50 );
	}
}
