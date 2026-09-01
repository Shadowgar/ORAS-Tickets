<?php

namespace ORAS\Tickets\Reporting;

use ORAS\Tickets\Storage\Legacy_Membership_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Membership_Report_Service {
	public const SOURCE_ALL = 'all';
	public const SOURCE_WEBSITE = 'website';
	public const SOURCE_LEGACY = 'legacy_paypal';

	public const STATUS_ALL = 'all';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_EXPIRING_SOON = 'expiring_soon';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_INACTIVE = 'inactive';
	public const STATUS_CANCELLED = 'cancelled';

	public const LINK_ALL = 'all';
	public const LINK_LINKED = 'linked';
	public const LINK_UNLINKED = 'unlinked';
	public const LINK_EXACT_EMAIL = 'exact_email';
	public const LINK_POSSIBLE_NAME = 'possible_name';
	public const LINK_TRANSITIONED = 'transitioned';
	public const LINK_WEBSITE_ACCOUNT = 'website_account';

	private \DateTimeImmutable $today;
	private string $memberships_table;
	private string $levels_table;

	public function __construct( ?\DateTimeImmutable $today = null, string $memberships_table = '', string $levels_table = '' ) {
		global $wpdb;
		$this->today = ( $today ?? current_datetime() )->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );
		$prefix = $wpdb instanceof \wpdb ? $wpdb->prefix : '';
		$this->memberships_table = $this->valid_table_name( $memberships_table ) ? $memberships_table : $prefix . 'pmpro_memberships_users';
		$this->levels_table = $this->valid_table_name( $levels_table ) ? $levels_table : $prefix . 'pmpro_membership_levels';
	}

	/**
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,mixed>
	 */
	public function get_report( array $filters = array() ): array {
		$normalized = $this->normalize_filters( $filters );
		$website_available = $this->table_exists( $this->memberships_table );
		$website_rows = $website_available ? $this->get_website_rows() : array();
		$legacy_rows = $this->get_legacy_rows( $website_rows );
		$all_rows = $this->sort_rows( array_merge( $website_rows, $legacy_rows ) );
		$filtered_rows = $this->filter_rows( $all_rows, $normalized );
		$pagination = $this->paginate( $filtered_rows, $normalized['page'], $normalized['per_page'] );

		return array(
			'available'         => true,
			'website_available' => $website_available,
			'all_rows'          => $all_rows,
			'filtered_rows'     => $filtered_rows,
			'rows'              => $pagination['rows'],
			'summary'           => $this->build_summary( $all_rows ),
			'filters'           => $normalized,
			'pagination'        => array(
				'page'        => $pagination['page'],
				'per_page'    => $normalized['per_page'],
				'total'       => count( $filtered_rows ),
				'total_pages' => $pagination['total_pages'],
			),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function get_website_rows(): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}

		$memberships = $this->quote_table( $this->memberships_table );
		$users = $this->quote_table( $wpdb->users );
		$has_levels = $this->table_exists( $this->levels_table );
		$level_select = $has_levels ? "COALESCE(ml.name, CONCAT('Level #', mu.membership_id))" : "CONCAT('Level #', mu.membership_id)";
		$level_join = $has_levels ? ' LEFT JOIN ' . $this->quote_table( $this->levels_table ) . ' ml ON ml.id = mu.membership_id' : '';
		$sql = "SELECT mu.id, mu.user_id, mu.membership_id, mu.status, mu.startdate, mu.enddate, u.user_login, u.display_name, u.user_email, {$level_select} AS level_name FROM {$memberships} mu LEFT JOIN {$users} u ON u.ID = mu.user_id{$level_join} ORDER BY mu.id DESC";
		$raw_rows = $wpdb->get_results( $sql, 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Validated internal table identifiers; query has no values.
		$rows = array();
		foreach ( is_array( $raw_rows ) ? $raw_rows : array() as $raw ) {
			if ( is_array( $raw ) ) {
				$rows[] = $this->normalize_website_row( $raw );
			}
		}

		return $rows;
	}

	/** @param array<string,mixed> $raw @return array<string,mixed> */
	private function normalize_website_row( array $raw ): array {
		$source_status = sanitize_key( (string) ( $raw['status'] ?? '' ) );
		$start_date = $this->normalize_database_date( (string) ( $raw['startdate'] ?? '' ) );
		$end_date = $this->normalize_database_date( (string) ( $raw['enddate'] ?? '' ) );
		$user_id = absint( $raw['user_id'] ?? 0 );

		return array(
			'source'              => self::SOURCE_WEBSITE,
			'source_label'        => __( 'Website / PMPro', 'oras-tickets' ),
			'source_record_id'    => absint( $raw['id'] ?? 0 ),
			'user_id'             => $user_id,
			'linked_user_id'      => $user_id,
			'username'            => sanitize_user( (string) ( $raw['user_login'] ?? '' ) ),
			'member_name'         => sanitize_text_field( (string) ( $raw['display_name'] ?? '' ) ),
			'email'               => sanitize_email( (string) ( $raw['user_email'] ?? '' ) ),
			'level_id'            => absint( $raw['membership_id'] ?? 0 ),
			'level_name'          => sanitize_text_field( (string) ( $raw['level_name'] ?? '' ) ),
			'source_status'       => $source_status,
			'start_date'          => $start_date,
			'end_date'            => $end_date,
			'operational_status'  => $this->get_operational_status( $source_status, $end_date ),
			'account_link_status' => self::LINK_WEBSITE_ACCOUNT,
			'match_type'          => 'none',
			'matching_user_ids'   => array(),
			'paypal_reference'    => '',
			'transitioned'        => false,
			'notes'               => '',
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $website_rows Website source rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_legacy_rows( array $website_rows ): array {
		$email_users = array();
		$name_users = array();
		foreach ( $website_rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$email = $this->normalize_email( (string) ( $row['email'] ?? '' ) );
			$name = $this->normalize_name( (string) ( $row['member_name'] ?? '' ) );
			if ( $user_id > 0 && '' !== $email ) {
				$email_users[ $email ][] = $user_id;
			}
			if ( $user_id > 0 && '' !== $name ) {
				$name_users[ $name ][] = $user_id;
			}
		}

		$rows = array();
		foreach ( Legacy_Membership_Store::query() as $record ) {
			$email = $this->normalize_email( (string) ( $record['email'] ?? '' ) );
			$name_key = $this->normalize_name( (string) ( $record['member_name'] ?? '' ) );
			$linked_user_id = absint( $record['linked_user_id'] ?? 0 );
			$matching_user_ids = array();
			$match_type = 'none';
			$link_status = self::LINK_UNLINKED;
			if ( ! empty( $record['transitioned'] ) ) {
				$link_status = self::LINK_TRANSITIONED;
			} elseif ( $linked_user_id > 0 ) {
				$link_status = self::LINK_LINKED;
				$match_type = 'linked';
				$matching_user_ids = array( $linked_user_id );
			} elseif ( '' !== $email && ! empty( $email_users[ $email ] ) ) {
				$link_status = self::LINK_EXACT_EMAIL;
				$match_type = self::LINK_EXACT_EMAIL;
				$matching_user_ids = array_values( array_unique( array_map( 'absint', $email_users[ $email ] ) ) );
			} elseif ( '' !== $name_key && ! empty( $name_users[ $name_key ] ) ) {
				$link_status = self::LINK_POSSIBLE_NAME;
				$match_type = self::LINK_POSSIBLE_NAME;
				$matching_user_ids = array_values( array_unique( array_map( 'absint', $name_users[ $name_key ] ) ) );
			}

			$source_status = sanitize_key( (string) ( $record['status'] ?? '' ) );
			$end_date = (string) ( $record['end_date'] ?? '' );
			$rows[] = array(
				'source'              => self::SOURCE_LEGACY,
				'source_label'        => __( 'Legacy PayPal', 'oras-tickets' ),
				'source_record_id'    => absint( $record['id'] ?? 0 ),
				'user_id'             => 0,
				'linked_user_id'      => $linked_user_id,
				'username'            => '',
				'member_name'         => (string) ( $record['member_name'] ?? '' ),
				'email'               => (string) ( $record['email'] ?? '' ),
				'level_id'            => 0,
				'level_name'          => __( 'Legacy PayPal Membership', 'oras-tickets' ),
				'source_status'       => $source_status,
				'start_date'          => (string) ( $record['start_date'] ?? '' ),
				'end_date'            => $end_date,
				'operational_status'  => $this->get_operational_status( $source_status, $end_date ),
				'account_link_status' => $link_status,
				'match_type'          => $match_type,
				'matching_user_ids'   => $matching_user_ids,
				'paypal_reference'    => (string) ( $record['paypal_reference'] ?? '' ),
				'transitioned'        => ! empty( $record['transitioned'] ),
				'notes'               => (string) ( $record['notes'] ?? '' ),
			);
		}

		return $rows;
	}

	private function get_operational_status( string $source_status, string $end_date ): string {
		if ( in_array( $source_status, array( 'cancelled', 'canceled' ), true ) ) {
			return self::STATUS_CANCELLED;
		}
		if ( 'expired' === $source_status ) {
			return self::STATUS_EXPIRED;
		}
		if ( 'active' !== $source_status ) {
			return self::STATUS_INACTIVE;
		}

		$end = $this->parse_date( $end_date );
		if ( null === $end ) {
			return self::STATUS_ACTIVE;
		}
		if ( $end < $this->today ) {
			return self::STATUS_EXPIRED;
		}

		return $end <= $this->today->modify( '+30 days' ) ? self::STATUS_EXPIRING_SOON : self::STATUS_ACTIVE;
	}

	/** @param array<string,mixed> $filters @return array{source:string,status:string,account_link:string,search:string,page:int,per_page:int} */
	private function normalize_filters( array $filters ): array {
		$source = sanitize_key( (string) ( $filters['source'] ?? self::SOURCE_ALL ) );
		if ( ! in_array( $source, array( self::SOURCE_ALL, self::SOURCE_WEBSITE, self::SOURCE_LEGACY ), true ) ) {
			$source = self::SOURCE_ALL;
		}
		$status = sanitize_key( (string) ( $filters['status'] ?? self::STATUS_ALL ) );
		if ( ! in_array( $status, array( self::STATUS_ALL, self::STATUS_ACTIVE, self::STATUS_EXPIRING_SOON, self::STATUS_EXPIRED, self::STATUS_INACTIVE, self::STATUS_CANCELLED ), true ) ) {
			$status = self::STATUS_ALL;
		}
		$link = sanitize_key( (string) ( $filters['account_link'] ?? self::LINK_ALL ) );
		if ( ! in_array( $link, array( self::LINK_ALL, self::LINK_LINKED, self::LINK_UNLINKED, self::LINK_EXACT_EMAIL, self::LINK_POSSIBLE_NAME, self::LINK_TRANSITIONED, self::LINK_WEBSITE_ACCOUNT ), true ) ) {
			$link = self::LINK_ALL;
		}

		return array(
			'source'       => $source,
			'status'       => $status,
			'account_link' => $link,
			'search'       => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
			'page'         => max( 1, absint( $filters['page'] ?? 1 ) ),
			'per_page'     => min( 100, max( 1, absint( $filters['per_page'] ?? 25 ) ) ),
		);
	}

	/** @param array<int,array<string,mixed>> $rows @param array{source:string,status:string,account_link:string,search:string,page:int,per_page:int} $filters @return array<int,array<string,mixed>> */
	private function filter_rows( array $rows, array $filters ): array {
		$needle = strtolower( trim( $filters['search'] ) );

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $filters, $needle ): bool {
					if ( self::SOURCE_ALL !== $filters['source'] && $filters['source'] !== ( $row['source'] ?? '' ) ) {
						return false;
					}
					if ( self::STATUS_ALL !== $filters['status'] && $filters['status'] !== ( $row['operational_status'] ?? '' ) ) {
						return false;
					}
					if ( self::LINK_ALL !== $filters['account_link'] && $filters['account_link'] !== ( $row['account_link_status'] ?? '' ) ) {
						return false;
					}
					if ( '' === $needle ) {
						return true;
					}
					$haystack = strtolower(
						implode(
							' ',
							array(
								(string) ( $row['member_name'] ?? '' ),
								(string) ( $row['email'] ?? '' ),
								(string) ( $row['username'] ?? '' ),
								(string) ( $row['level_name'] ?? '' ),
								(string) ( $row['paypal_reference'] ?? '' ),
							)
						)
					);

					return false !== strpos( $haystack, $needle );
				}
			)
		);
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private function sort_rows( array $rows ): array {
		$ranks = array(
			self::STATUS_ACTIVE        => 0,
			self::STATUS_EXPIRING_SOON => 1,
			self::STATUS_EXPIRED       => 2,
			self::STATUS_INACTIVE      => 3,
			self::STATUS_CANCELLED     => 4,
		);
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $ranks ): int {
				$rank = ( $ranks[ (string) ( $left['operational_status'] ?? '' ) ] ?? 9 ) <=> ( $ranks[ (string) ( $right['operational_status'] ?? '' ) ] ?? 9 );
				if ( 0 !== $rank ) {
					return $rank;
				}
				$date = strcmp( (string) ( $left['end_date'] ?? '' ), (string) ( $right['end_date'] ?? '' ) );
				if ( 0 !== $date ) {
					return $date;
				}

				return strcasecmp( (string) ( $left['member_name'] ?? '' ), (string) ( $right['member_name'] ?? '' ) );
			}
		);

		return $rows;
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,int> */
	private function build_summary( array $rows ): array {
		$summary = array(
			'total_count'               => count( $rows ),
			'active_count'              => 0,
			'website_active_count'      => 0,
			'legacy_active_count'       => 0,
			'expiring_count'            => 0,
			'expired_count'             => 0,
			'linked_count'              => 0,
			'exact_email_match_count'   => 0,
			'possible_name_match_count' => 0,
		);
		foreach ( $rows as $row ) {
			$status = (string) ( $row['operational_status'] ?? '' );
			$is_active = in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_EXPIRING_SOON ), true );
			if ( $is_active ) {
				++$summary['active_count'];
				$key = self::SOURCE_WEBSITE === ( $row['source'] ?? '' ) ? 'website_active_count' : 'legacy_active_count';
				++$summary[ $key ];
			}
			if ( self::STATUS_EXPIRING_SOON === $status ) {
				++$summary['expiring_count'];
			}
			if ( self::STATUS_EXPIRED === $status ) {
				++$summary['expired_count'];
			}
			if ( self::LINK_LINKED === ( $row['account_link_status'] ?? '' ) ) {
				++$summary['linked_count'];
			}
			if ( self::LINK_EXACT_EMAIL === ( $row['match_type'] ?? '' ) ) {
				++$summary['exact_email_match_count'];
			}
			if ( self::LINK_POSSIBLE_NAME === ( $row['match_type'] ?? '' ) ) {
				++$summary['possible_name_match_count'];
			}
		}

		return $summary;
	}

	/** @param array<int,array<string,mixed>> $rows @return array{rows:array<int,array<string,mixed>>,page:int,total_pages:int} */
	private function paginate( array $rows, int $page, int $per_page ): array {
		$total_pages = max( 1, (int) ceil( count( $rows ) / $per_page ) );
		$page = min( $page, $total_pages );

		return array(
			'rows'        => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'page'        => $page,
			'total_pages' => $total_pages,
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb || ! $this->valid_table_name( $table ) ) {
			return false;
		}

		return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function valid_table_name( string $table ): bool {
		return '' !== $table && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table );
	}

	private function quote_table( string $table ): string {
		return '`' . $table . '`';
	}

	private function normalize_database_date( string $value ): string {
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return '';
		}

		return $this->parse_date( substr( $value, 0, 10 ) ) instanceof \DateTimeImmutable ? substr( $value, 0, 10 ) : '';
	}

	private function parse_date( string $value ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();

		return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private function normalize_email( string $email ): string {
		return strtolower( sanitize_email( trim( $email ) ) );
	}

	private function normalize_name( string $name ): string {
		$name = strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $name ) ) ?? '' ) );

		return preg_replace( '/[^a-z0-9]+/', '', $name ) ?? '';
	}
}
