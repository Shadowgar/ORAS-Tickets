<?php

namespace ORAS\Tickets\Reporting;

use ORAS\Tickets\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Observer_Pass_Report_Service {

	public const PASS_ALL    = 'all';
	public const PASS_ANNUAL = 'annual';
	public const PASS_DAILY  = 'daily';

	public const STATUS_ACTIVE        = 'active';
	public const STATUS_EXPIRING_SOON = 'expiring_soon';
	public const STATUS_EXPIRED       = 'expired';
	public const STATUS_TODAY         = 'today';
	public const STATUS_UPCOMING      = 'upcoming';
	public const STATUS_PAST          = 'past';
	public const STATUS_REFUNDED      = 'refunded';
	public const STATUS_CANCELLED     = 'cancelled';
	public const STATUS_FAILED        = 'failed';
	public const STATUS_UNPAID        = 'unpaid';
	public const STATUS_DATE_MISSING  = 'date_missing';

	private const ORDER_STATUSES = array(
		'completed',
		'processing',
		'on-hold',
		'pending',
		'refunded',
		'cancelled',
		'failed',
	);

	private \DateTimeImmutable $today;

	public function __construct( ?\DateTimeImmutable $today = null ) {
		$this->today = ( $today ?? current_datetime() )->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );
	}

	/**
	 * @param array<string,mixed> $filters Report filters.
	 * @return array<string,mixed>
	 */
	public function get_report( array $filters = array() ): array {
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
			return $this->failure_report();
		}

		try {
			$normalized_filters = $this->normalize_filters( $filters );
			$all_rows           = $this->sort_rows( $this->get_rows(), self::PASS_ALL );
			$rows               = $this->sort_rows( $this->filter_rows( $all_rows, $normalized_filters ), $normalized_filters['pass_type'] );
		} catch ( \Throwable $error ) {
			Logger::instance()->log( 'Observer Pass report scan failed: ' . get_class( $error ) );

			return $this->failure_report();
		}

		return array(
			'available'          => true,
			'error'              => '',
			'all_rows'           => $all_rows,
			'rows'               => $rows,
			'summary'            => $this->build_summary( $all_rows ),
			'today_rows'         => array_values(
				array_filter(
					$all_rows,
					static function ( array $row ): bool {
						return ! empty( $row['is_valid'] ) && self::PASS_DAILY === $row['pass_type'] && self::STATUS_TODAY === $row['operational_status'];
					}
				)
			),
			'active_annual_rows' => array_values(
				array_filter(
					$all_rows,
					static function ( array $row ): bool {
						return ! empty( $row['is_valid'] ) && self::PASS_ANNUAL === $row['pass_type'];
					}
				)
			),
		);
	}

	/**
	 * @param array<string,mixed> $filters Raw report filters.
	 * @return array{pass_type:string,status:string,search:string,date_preset:string,after:string,before:string}
	 */
	private function normalize_filters( array $filters ): array {
		$pass_type = sanitize_key( (string) ( $filters['pass_type'] ?? self::PASS_ALL ) );
		if ( ! in_array( $pass_type, array( self::PASS_ALL, self::PASS_ANNUAL, self::PASS_DAILY ), true ) ) {
			$pass_type = self::PASS_ALL;
		}

		$status = sanitize_key( (string) ( $filters['status'] ?? self::PASS_ALL ) );
		$allowed_statuses = array(
			self::PASS_ALL,
			self::STATUS_ACTIVE,
			self::STATUS_EXPIRING_SOON,
			self::STATUS_EXPIRED,
			self::STATUS_TODAY,
			self::STATUS_UPCOMING,
			self::STATUS_PAST,
			self::STATUS_REFUNDED,
			self::STATUS_CANCELLED,
			self::STATUS_FAILED,
			self::STATUS_UNPAID,
			self::STATUS_DATE_MISSING,
			'refunded_cancelled',
		);
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = self::PASS_ALL;
		}

		$date_preset = sanitize_key( (string) ( $filters['date_preset'] ?? self::PASS_ALL ) );
		if ( ! in_array( $date_preset, array( self::PASS_ALL, 'today', 'next_7', 'this_month', 'this_year', 'custom' ), true ) ) {
			$date_preset = self::PASS_ALL;
		}

		$after  = '';
		$before = '';
		if ( 'today' === $date_preset ) {
			$after  = $this->today->format( 'Y-m-d' );
			$before = $after;
		} elseif ( 'next_7' === $date_preset ) {
			$after  = $this->today->modify( '+1 day' )->format( 'Y-m-d' );
			$before = $this->today->modify( '+7 days' )->format( 'Y-m-d' );
		} elseif ( 'this_month' === $date_preset ) {
			$after  = $this->today->modify( 'first day of this month' )->format( 'Y-m-d' );
			$before = $this->today->modify( 'last day of this month' )->format( 'Y-m-d' );
		} elseif ( 'this_year' === $date_preset ) {
			$after  = $this->today->format( 'Y-01-01' );
			$before = $this->today->format( 'Y-12-31' );
		} elseif ( 'custom' === $date_preset ) {
			$after_date  = $this->parse_date( sanitize_text_field( (string) ( $filters['after'] ?? '' ) ) );
			$before_date = $this->parse_date( sanitize_text_field( (string) ( $filters['before'] ?? '' ) ) );
			$after       = null !== $after_date ? $after_date->format( 'Y-m-d' ) : '';
			$before      = null !== $before_date ? $before_date->format( 'Y-m-d' ) : '';
		}

		return array(
			'pass_type'   => $pass_type,
			'status'      => $status,
			'search'      => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
			'date_preset' => $date_preset,
			'after'       => $after,
			'before'      => $before,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Normalized rows.
	 * @param array{pass_type:string,status:string,search:string,date_preset:string,after:string,before:string} $filters Normalized filters.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows( array $rows, array $filters ): array {
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $filters ): bool {
					if ( self::PASS_ALL !== $filters['pass_type'] && $filters['pass_type'] !== ( $row['pass_type'] ?? '' ) ) {
						return false;
					}

					$row_status = (string) ( $row['operational_status'] ?? '' );
					if ( 'refunded_cancelled' === $filters['status'] ) {
						if ( ! in_array( $row_status, array( self::STATUS_REFUNDED, self::STATUS_CANCELLED ), true ) ) {
							return false;
						}
					} elseif ( self::PASS_ALL !== $filters['status'] && $filters['status'] !== $row_status ) {
						return false;
					}

					if ( ! $this->row_matches_search( $row, $filters['search'] ) ) {
						return false;
					}

					return $this->row_matches_date_range( $row, $filters['after'], $filters['before'] );
				}
			)
		);
	}

	/**
	 * @param array<string,mixed> $row Normalized row.
	 */
	private function row_matches_search( array $row, string $search ): bool {
		$needle = strtolower( trim( $search ) );
		if ( '' === $needle ) {
			return true;
		}

		$holder_names = is_array( $row['holder_names'] ?? null ) ? implode( ' ', array_map( 'strval', $row['holder_names'] ) ) : '';
		$haystack     = strtolower(
			implode(
				' ',
				array(
					(string) ( $row['purchaser_name'] ?? '' ),
					$holder_names,
					(string) ( $row['email'] ?? '' ),
					(string) ( $row['order_number'] ?? '' ),
				)
			)
		);

		return false !== strpos( $haystack, $needle );
	}

	/**
	 * @param array<string,mixed> $row Normalized row.
	 */
	private function row_matches_date_range( array $row, string $after, string $before ): bool {
		if ( '' === $after && '' === $before ) {
			return true;
		}

		if ( self::PASS_ANNUAL === ( $row['pass_type'] ?? '' ) ) {
			$date = (string) ( $row['expiration_date'] ?? '' );
			if ( '' === $date ) {
				return false;
			}

			return ( '' === $after || $date >= $after ) && ( '' === $before || $date <= $before );
		}

		$start = (string) ( $row['valid_start'] ?? '' );
		$end   = (string) ( $row['last_valid_date'] ?? '' );
		if ( '' === $start || '' === $end ) {
			return false;
		}

		return ( '' === $after || $end >= $after ) && ( '' === $before || $start <= $before );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Normalized rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function sort_rows( array $rows, string $pass_type ): array {
		usort(
			$rows,
			function ( array $left, array $right ) use ( $pass_type ): int {
				$left_rank  = $this->get_sort_rank( $left, $pass_type );
				$right_rank = $this->get_sort_rank( $right, $pass_type );
				if ( $left_rank !== $right_rank ) {
					return $left_rank <=> $right_rank;
				}

				if ( self::PASS_DAILY === $pass_type && 1 === $left_rank ) {
					$date_comparison = strcmp( (string) ( $left['valid_start'] ?? '' ), (string) ( $right['valid_start'] ?? '' ) );
				} elseif ( self::PASS_ANNUAL === $pass_type && $left_rank < 2 ) {
					$date_comparison = strcmp( (string) ( $left['expiration_date'] ?? '' ), (string) ( $right['expiration_date'] ?? '' ) );
				} elseif ( 2 === $left_rank ) {
					$date_comparison = strcmp( $this->get_operational_sort_date( $right ), $this->get_operational_sort_date( $left ) );
				} else {
					$date_comparison = strcmp( $this->get_operational_sort_date( $left ), $this->get_operational_sort_date( $right ) );
				}

				if ( 0 !== $date_comparison ) {
					return $date_comparison;
				}

				$order_comparison = (int) ( $right['order_id'] ?? 0 ) <=> (int) ( $left['order_id'] ?? 0 );
				if ( 0 !== $order_comparison ) {
					return $order_comparison;
				}

				return (int) ( $right['item_id'] ?? 0 ) <=> (int) ( $left['item_id'] ?? 0 );
			}
		);

		return $rows;
	}

	/**
	 * @param array<string,mixed> $row Normalized row.
	 */
	private function get_sort_rank( array $row, string $pass_type ): int {
		$status = (string) ( $row['operational_status'] ?? '' );
		if ( self::PASS_ANNUAL === $pass_type ) {
			if ( self::STATUS_ACTIVE === $status ) {
				return 0;
			}

			return self::STATUS_EXPIRING_SOON === $status ? 1 : 2;
		}

		if ( self::PASS_DAILY === $pass_type ) {
			if ( self::STATUS_TODAY === $status ) {
				return 0;
			}

			return self::STATUS_UPCOMING === $status ? 1 : 2;
		}

		if ( ! empty( $row['is_valid'] ) && self::STATUS_UPCOMING !== $status ) {
			return 0;
		}

		return ! empty( $row['is_valid'] ) ? 1 : 2;
	}

	/**
	 * @param array<string,mixed> $row Normalized row.
	 */
	private function get_operational_sort_date( array $row ): string {
		if ( self::PASS_ANNUAL === ( $row['pass_type'] ?? '' ) ) {
			$expiration_date = (string) ( $row['expiration_date'] ?? '' );

			return '' !== $expiration_date ? $expiration_date : (string) ( $row['purchase_date'] ?? '' );
		}

		$valid_start = (string) ( $row['valid_start'] ?? '' );

		return '' !== $valid_start ? $valid_start : (string) ( $row['purchase_date'] ?? '' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_rows(): array {
		$rows     = array();
		$page     = 1;
		$per_page = 50;

		do {
			$orders = wc_get_orders(
				array(
					'limit'   => $per_page,
					'page'    => $page,
					'status'  => self::ORDER_STATUSES,
					'orderby' => 'date ID',
					'order'   => 'DESC',
				)
			);

			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				foreach ( $order->get_items( 'line_item' ) as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Product ) {
						continue;
					}

					$pass_type = $this->get_pass_type( $item );
					if ( '' === $pass_type ) {
						continue;
					}

					$rows[] = $this->normalize_row( $order, $item, $pass_type );
				}
			}

			$count = count( $orders );
			++$page;
		} while ( $count === $per_page );

		return $rows;
	}

	private function get_pass_type( \WC_Order_Item_Product $item ): string {
		$annual_ids = $this->get_filtered_ids( 'oras_tickets_observer_annual_product_ids', array( 3219 ) );
		$daily_ids  = $this->get_filtered_ids( 'oras_tickets_observer_daily_product_ids', array( 2494 ) );
		$product_ids = array_filter(
			array(
				(int) $item->get_product_id(),
				(int) $item->get_variation_id(),
			)
		);

		if ( array_intersect( $product_ids, $annual_ids ) ) {
			return self::PASS_ANNUAL;
		}

		if ( array_intersect( $product_ids, $daily_ids ) ) {
			return self::PASS_DAILY;
		}

		$product = $item->get_product();
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$slug         = sanitize_title( (string) $product->get_slug() );
		$annual_slugs = $this->get_filtered_slugs( 'oras_tickets_observer_annual_product_slugs', array( 'annual-observer-pass' ) );
		$daily_slugs  = $this->get_filtered_slugs( 'oras_tickets_observer_daily_product_slugs', array( 'daily-observer-pass' ) );

		if ( in_array( $slug, $annual_slugs, true ) ) {
			return self::PASS_ANNUAL;
		}

		return in_array( $slug, $daily_slugs, true ) ? self::PASS_DAILY : '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function normalize_row( \WC_Order $order, \WC_Order_Item_Product $item, string $pass_type ): array {
		$contact       = Contact_Normalizer::from_order( $order );
		$purchase_date = $this->get_local_order_date( $order );
		$valid_start   = null;
		$valid_checkout = null;
		$last_valid_date = null;
		$expiration_date = null;

		if ( self::PASS_ANNUAL === $pass_type && null !== $purchase_date ) {
			$expiration_date = $this->get_annual_expiration( $purchase_date );
		}

		if ( self::PASS_DAILY === $pass_type ) {
			$valid_start    = $this->parse_date( (string) $item->get_meta( '_wapbk_booking_date', true ) );
			$valid_checkout = $this->parse_date( (string) $item->get_meta( '_wapbk_checkout_date', true ) );
			if ( null !== $valid_checkout ) {
				$last_valid_date = $valid_checkout->modify( '-1 day' );
			}
		}

		$quantity            = max( 1, (int) $item->get_quantity() );
		$refunded_quantity   = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
		$valid_quantity      = max( 0, $quantity - $refunded_quantity );
		$booking_status      = sanitize_key( (string) $item->get_meta( '_wapbk_booking_status', true ) );
		$operational         = $this->get_operational_state(
			$order,
			$pass_type,
			$booking_status,
			$valid_quantity,
			$expiration_date,
			$valid_start,
			$valid_checkout
		);
		$holder_name = trim( $contact['first_name'] . ' ' . $contact['last_name'] );

		return array(
			'product_id'         => (int) $item->get_product_id(),
			'item_id'            => (int) $item->get_id(),
			'item_label'         => (string) $item->get_name(),
			'pass_type'          => $pass_type,
			'name'               => $contact['name'],
			'purchaser_name'     => $contact['name'],
			'email'              => $contact['email'],
			'phone'              => $contact['phone'],
			'address_summary'    => $contact['address_summary'],
			'holder_label'       => 'Purchaser/Passholder',
			'holder_names'       => '' !== $holder_name ? array( $holder_name ) : array(),
			'purchase_date'      => null !== $purchase_date ? $purchase_date->format( 'Y-m-d' ) : '',
			'order_date'         => null !== $purchase_date ? $purchase_date->format( 'Y-m-d' ) : '',
			'expiration_date'    => null !== $expiration_date ? $expiration_date->format( 'Y-m-d' ) : '',
			'valid_start'        => null !== $valid_start ? $valid_start->format( 'Y-m-d' ) : '',
			'valid_checkout'     => null !== $valid_checkout ? $valid_checkout->format( 'Y-m-d' ) : '',
			'last_valid_date'    => null !== $last_valid_date ? $last_valid_date->format( 'Y-m-d' ) : '',
			'quantity'           => $quantity,
			'valid_quantity'     => $valid_quantity,
			'refunded_quantity'  => $refunded_quantity,
			'order_id'           => (int) $order->get_id(),
			'order_number'       => (string) $order->get_order_number(),
			'order_status'       => (string) $order->get_status(),
			'booking_status'     => $booking_status,
			'operational_status' => $operational['status'],
			'is_valid'           => $operational['is_valid'],
		);
	}

	private function get_annual_expiration( \DateTimeImmutable $purchase_date ): \DateTimeImmutable {
		$local_date = $purchase_date->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );
		$next_year  = (int) $local_date->format( 'Y' ) + 1;
		$month      = (int) $local_date->format( 'n' );
		$day        = (int) $local_date->format( 'j' );

		if ( 2 === $month && 29 === $day && ! checkdate( 2, 29, $next_year ) ) {
			return $local_date->setDate( $next_year, 3, 1 );
		}

		return $local_date->setDate( $next_year, $month, $day );
	}

	/**
	 * @param \DateTimeImmutable|null $expiration_date Annual expiration.
	 * @param \DateTimeImmutable|null $valid_start Daily inclusive start.
	 * @param \DateTimeImmutable|null $valid_checkout Daily exclusive checkout.
	 * @return array{status:string,is_valid:bool}
	 */
	private function get_operational_state(
		\WC_Order $order,
		string $pass_type,
		string $booking_status,
		int $valid_quantity,
		?\DateTimeImmutable $expiration_date,
		?\DateTimeImmutable $valid_start,
		?\DateTimeImmutable $valid_checkout
	): array {
		$order_status = (string) $order->get_status();

		if ( self::STATUS_REFUNDED === $order_status || $valid_quantity <= 0 ) {
			return array(
				'status'   => self::STATUS_REFUNDED,
				'is_valid' => false,
			);
		}

		if ( self::STATUS_CANCELLED === $order_status || in_array( $booking_status, array( 'cancelled', 'canceled' ), true ) ) {
			return array(
				'status'   => self::STATUS_CANCELLED,
				'is_valid' => false,
			);
		}

		if ( self::STATUS_FAILED === $order_status ) {
			return array(
				'status'   => self::STATUS_FAILED,
				'is_valid' => false,
			);
		}

		if ( ! $order->is_paid() ) {
			return array(
				'status'   => self::STATUS_UNPAID,
				'is_valid' => false,
			);
		}

		if ( self::PASS_ANNUAL === $pass_type ) {
			return $this->get_annual_state( $expiration_date );
		}

		return $this->get_daily_state( $booking_status, $valid_start, $valid_checkout );
	}

	/**
	 * @return array{status:string,is_valid:bool}
	 */
	private function get_annual_state( ?\DateTimeImmutable $expiration_date ): array {
		if ( null === $expiration_date ) {
			return array(
				'status'   => self::STATUS_DATE_MISSING,
				'is_valid' => false,
			);
		}

		if ( $this->today >= $expiration_date ) {
			return array(
				'status'   => self::STATUS_EXPIRED,
				'is_valid' => false,
			);
		}

		$status = $this->today >= $expiration_date->modify( '-30 days' ) ? self::STATUS_EXPIRING_SOON : self::STATUS_ACTIVE;

		return array(
			'status'   => $status,
			'is_valid' => true,
		);
	}

	/**
	 * @return array{status:string,is_valid:bool}
	 */
	private function get_daily_state(
		string $booking_status,
		?\DateTimeImmutable $valid_start,
		?\DateTimeImmutable $valid_checkout
	): array {
		if ( null === $valid_start || null === $valid_checkout || $valid_start >= $valid_checkout ) {
			return array(
				'status'   => self::STATUS_DATE_MISSING,
				'is_valid' => false,
			);
		}

		$is_confirmed = in_array( $booking_status, array( 'confirmed', 'paid' ), true );
		if ( $valid_start <= $this->today && $this->today < $valid_checkout ) {
			$status = self::STATUS_TODAY;
		} elseif ( $valid_start > $this->today ) {
			$status = self::STATUS_UPCOMING;
		} else {
			$status = self::STATUS_PAST;
		}

		return array(
			'status'   => $status,
			'is_valid' => $is_confirmed && self::STATUS_PAST !== $status,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Normalized rows.
	 * @return array{active_annual_count:int,daily_today_count:int,daily_next_7_days_count:int,daily_this_month_count:int}
	 */
	private function build_summary( array $rows ): array {
		$summary = array(
			'active_annual_count'     => 0,
			'daily_today_count'       => 0,
			'daily_next_7_days_count' => 0,
			'daily_this_month_count'  => 0,
		);
		$last_upcoming_date = $this->today->modify( '+7 days' )->format( 'Y-m-d' );
		$last_month_date    = $this->today->modify( 'last day of this month' )->format( 'Y-m-d' );

		foreach ( $rows as $row ) {
			$quantity = (int) ( $row['valid_quantity'] ?? 0 );
			if ( ! empty( $row['is_valid'] ) && self::PASS_ANNUAL === ( $row['pass_type'] ?? '' ) ) {
				$summary['active_annual_count'] += $quantity;
			}

			if ( ! empty( $row['is_valid'] ) && self::STATUS_TODAY === ( $row['operational_status'] ?? '' ) ) {
				$summary['daily_today_count'] += $quantity;
			}

			if (
				! empty( $row['is_valid'] )
				&& self::STATUS_UPCOMING === ( $row['operational_status'] ?? '' )
				&& (string) ( $row['valid_start'] ?? '' ) <= $last_upcoming_date
			) {
				$summary['daily_next_7_days_count'] += $quantity;
			}

			if (
				! empty( $row['is_valid'] )
				&& self::STATUS_UPCOMING === ( $row['operational_status'] ?? '' )
				&& (string) ( $row['valid_start'] ?? '' ) <= $last_month_date
			) {
				$summary['daily_this_month_count'] += $quantity;
			}
		}

		return $summary;
	}

	private function get_local_order_date( \WC_Order $order ): ?\DateTimeImmutable {
		$date = $order->get_date_created();
		if ( null === $date ) {
			return null;
		}

		return ( new \DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( wp_timezone() );
	}

	private function parse_date( string $value ): ?\DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}

		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();

		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	/**
	 * @param int[] $defaults Default product IDs.
	 * @return int[]
	 */
	private function get_filtered_ids( string $hook, array $defaults ): array {
		$values = apply_filters( $hook, $defaults );
		if ( ! is_array( $values ) ) {
			return $defaults;
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
	}

	/**
	 * @param string[] $defaults Default product slugs.
	 * @return string[]
	 */
	private function get_filtered_slugs( string $hook, array $defaults ): array {
		$values = apply_filters( $hook, $defaults );
		if ( ! is_array( $values ) ) {
			return $defaults;
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_title', $values ) ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function failure_report(): array {
		return array(
			'available'          => false,
			'error'              => __( 'Observer Pass reporting is temporarily unavailable.', 'oras-tickets' ),
			'all_rows'           => array(),
			'rows'               => array(),
			'summary'            => array(
				'active_annual_count'     => 0,
				'daily_today_count'       => 0,
				'daily_next_7_days_count' => 0,
				'daily_this_month_count'  => 0,
			),
			'today_rows'         => array(),
			'active_annual_rows' => array(),
		);
	}
}
