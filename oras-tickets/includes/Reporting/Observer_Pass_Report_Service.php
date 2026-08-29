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
		$this->today = ( $today ?? current_datetime() )->setTime( 0, 0, 0 );
	}

	/**
	 * @param array<string,mixed> $filters Report filters.
	 * @return array<string,mixed>
	 */
	public function get_report( array $filters = array() ): array {
		unset( $filters );

		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
			return $this->failure_report();
		}

		try {
			$rows = $this->get_rows();
		} catch ( \Throwable $error ) {
			Logger::instance()->log( 'Observer Pass report scan failed: ' . get_class( $error ) );

			return $this->failure_report();
		}

		return array(
			'available'          => true,
			'error'              => '',
			'all_rows'           => $rows,
			'rows'               => $rows,
			'summary'            => array(
				'active_annual' => 0,
				'daily_today'   => 0,
				'daily_next_7'  => 0,
				'revenue_ytd'   => 0.0,
			),
			'today_rows'         => array(),
			'active_annual_rows' => array(),
		);
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
					'orderby' => 'date',
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
			$expiration_date = $purchase_date->modify( '+1 year' )->format( 'Y-m-d' );
		}

		if ( self::PASS_DAILY === $pass_type ) {
			$valid_start    = $this->parse_date( (string) $item->get_meta( '_wapbk_booking_date', true ) );
			$valid_checkout = $this->parse_date( (string) $item->get_meta( '_wapbk_checkout_date', true ) );
			if ( null !== $valid_checkout ) {
				$last_valid_date = $valid_checkout->modify( '-1 day' );
			}
		}

		$quantity = max( 1, (int) $item->get_quantity() );

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
			'holder_names'       => array( $contact['name'] ),
			'purchase_date'      => null !== $purchase_date ? $purchase_date->format( 'Y-m-d' ) : '',
			'order_date'         => null !== $purchase_date ? $purchase_date->format( 'Y-m-d' ) : '',
			'expiration_date'    => $expiration_date ?? '',
			'valid_start'        => null !== $valid_start ? $valid_start->format( 'Y-m-d' ) : '',
			'valid_checkout'     => null !== $valid_checkout ? $valid_checkout->format( 'Y-m-d' ) : '',
			'last_valid_date'    => null !== $last_valid_date ? $last_valid_date->format( 'Y-m-d' ) : '',
			'quantity'           => $quantity,
			'valid_quantity'     => $quantity,
			'refunded_quantity'  => 0,
			'order_id'           => (int) $order->get_id(),
			'order_number'       => (string) $order->get_order_number(),
			'order_status'       => (string) $order->get_status(),
			'booking_status'     => sanitize_key( (string) $item->get_meta( '_wapbk_booking_status', true ) ),
			'operational_status' => '',
			'is_valid'           => false,
			'net_revenue'        => (float) $item->get_total(),
		);
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
				'active_annual' => 0,
				'daily_today'   => 0,
				'daily_next_7'  => 0,
				'revenue_ytd'   => 0.0,
			),
			'today_rows'         => array(),
			'active_annual_rows' => array(),
		);
	}
}
