<?php

namespace ORAS\Tickets\Reporting;

use ORAS\Tickets\Commerce\Woo\Order_Item_Classifier;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Integrations\QuickBooks\Settings;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Board_Report_Service {

	public const TYPE_TICKETS  = 'tickets';
	public const TYPE_RSVP     = 'rsvp';
	public const TYPE_OBSERVER = 'observer';
	public const TYPE_MERCH    = 'merchandise';

	private const DEFAULT_STATUSES = array( 'completed', 'processing', 'on-hold', 'pending', 'refunded', 'cancelled', 'failed' );

	private Order_Item_Classifier $classifier;

	public function __construct( ?Order_Item_Classifier $classifier = null ) {
		$this->classifier = null !== $classifier ? $classifier : new Order_Item_Classifier();
	}

	/**
	 * @return array<string,string>
	 */
	public function get_report_types(): array {
		return array(
			self::TYPE_TICKETS  => __( 'Ticket Buyers', 'oras-tickets' ),
			self::TYPE_RSVP     => __( 'RSVP List', 'oras-tickets' ),
			self::TYPE_OBSERVER => __( 'Observer Passes', 'oras-tickets' ),
			self::TYPE_MERCH    => __( 'Merchandise', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<int,\WP_Post>
	 */
	public function get_events(): array {
		return get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'future', 'draft', 'private' ),
				'posts_per_page' => 250,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function get_rows( string $type, array $filters ): array {
		if ( self::TYPE_RSVP === $type ) {
			return $this->get_rsvp_attendees( absint( $filters['event_id'] ?? 0 ), $filters );
		}

		if ( self::TYPE_OBSERVER === $type ) {
			return $this->get_observer_pass_buyers( $filters );
		}

		if ( self::TYPE_MERCH === $type ) {
			return $this->get_merchandise_buyers( $filters );
		}

		return $this->get_event_ticket_buyers( absint( $filters['event_id'] ?? 0 ), $filters );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function get_event_ticket_buyers( int $event_id, array $filters ): array {
		if ( $event_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		return $this->iterate_matching_order_items(
			$filters,
			static function ( $item ) use ( $event_id ): bool {
				return (int) $item->get_meta( '_oras_ticket_event_id', true ) === $event_id;
			},
			function ( \WC_Order $order, \WC_Order_Item_Product $item ) use ( $event_id ): array {
				$ticket_name = trim( (string) $item->get_meta( '_oras_ticket_name', true ) );
				if ( '' === $ticket_name ) {
					$ticket_name = (string) $item->get_name();
				}

				return $this->build_order_item_row(
					$order,
					$item,
					array(
						'report_type' => self::TYPE_TICKETS,
						'event_id'    => $event_id,
						'event_title' => get_the_title( $event_id ),
						'item_label'  => $ticket_name,
						'source'      => __( 'Ticket', 'oras-tickets' ),
					)
				);
			}
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function get_rsvp_attendees( int $event_id, array $filters ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		$rows = array();
		$seen = array();
		$status = sanitize_key( (string) ( $filters['status'] ?? 'all' ) );
		$include_yes = ( '' === $status || 'all' === $status || 'yes' === $status );
		$include_waitlist = ( '' === $status || 'all' === $status || 'waitlist' === $status );

		if ( $include_yes ) {
			$yes_users = get_users(
				array(
					'meta_key'     => '_oras_rsvp_event_' . $event_id,
					'meta_value'   => 'yes',
					'meta_compare' => '=',
				)
			);

			foreach ( $yes_users as $user ) {
				if ( ! $user instanceof \WP_User ) {
					continue;
				}

				$rows[] = $this->build_rsvp_row( $event_id, (int) $user->ID, 'yes' );
				$seen[ (int) $user->ID ] = true;
			}
		}

		if ( $include_waitlist ) {
			$waitlist_users = Waitlist_Store::get_waiting_users( $event_id );
			foreach ( $waitlist_users as $user ) {
				if ( ! $user instanceof \WP_User || isset( $seen[ (int) $user->ID ] ) ) {
					continue;
				}

				$rows[] = $this->build_rsvp_row( $event_id, (int) $user->ID, 'waitlist' );
			}
		}

		return $this->filter_rows_by_search( $rows, (string) ( $filters['search'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function get_observer_pass_buyers( array $filters ): array {
		return $this->get_classified_buyers( $filters, array( 'observer_pass' ), self::TYPE_OBSERVER, __( 'Observer Pass', 'oras-tickets' ) );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function get_merchandise_buyers( array $filters ): array {
		return $this->get_classified_buyers( $filters, array( 'merchandise', 'printful' ), self::TYPE_MERCH, __( 'Merchandise', 'oras-tickets' ) );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @param string[]            $types
	 * @return array<int,array<string,mixed>>
	 */
	private function get_classified_buyers( array $filters, array $types, string $report_type, string $source ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$settings = class_exists( Settings::class )
			? Settings::get_quickbooks_settings()
			: array(
				'observer_category_slugs' => 'observer-pass,observer-passes',
				'merch_category_slugs'    => 'merch,merchandise,shirt,shirts,apparel',
				'printful_category_slugs' => 'printful,pod',
				'donation_category_slugs' => 'donation,donations,give,giving',
			);

		return $this->iterate_matching_order_items(
			$filters,
			function ( $item ) use ( $settings, $types ): bool {
				$classification = $this->classifier->classify_product_item( $item, $settings );
				return in_array( (string) ( $classification['type'] ?? '' ), $types, true );
			},
			function ( \WC_Order $order, \WC_Order_Item_Product $item ) use ( $report_type, $source ): array {
				return $this->build_order_item_row(
					$order,
					$item,
					array(
						'report_type' => $report_type,
						'event_id'    => 0,
						'event_title' => '',
						'item_label'  => (string) $item->get_name(),
						'source'      => $source,
					)
				);
			}
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	private function iterate_matching_order_items( array $filters, callable $matcher, callable $mapper ): array {
		$rows = array();
		$page = 1;
		$per_page = 50;
		$search = (string) ( $filters['search'] ?? '' );

		do {
			$args = array(
				'limit'   => $per_page,
				'page'    => $page,
				'status'  => $this->get_order_statuses( $filters ),
				'orderby' => 'date',
				'order'   => 'DESC',
			);

			$date_created = $this->build_date_created_arg( $filters );
			if ( '' !== $date_created ) {
				$args['date_created'] = $date_created;
			}

			$orders = wc_get_orders( $args );
			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$items = $order->get_items( 'line_item' );
				foreach ( $items as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Product || ! $matcher( $item ) ) {
						continue;
					}

					$row = $mapper( $order, $item );
					if ( $this->row_matches_search( $row, $search ) ) {
						$rows[] = $row;
					}
				}
			}

			$count = count( $orders );
			++$page;
		} while ( $count === $per_page );

		return $rows;
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function build_order_item_row( \WC_Order $order, \WC_Order_Item_Product $item, array $extra ): array {
		$contact = Contact_Normalizer::from_order( $order );
		$order_date = $order->get_date_created();

		return array_merge(
			array(
				'name'            => $contact['name'],
				'email'           => $contact['email'],
				'phone'           => $contact['phone'],
				'address_summary' => $contact['address_summary'],
				'item_label'      => '',
				'quantity'        => max( 1, (int) $item->get_quantity() ),
				'order_status'    => (string) $order->get_status(),
				'order_id'        => (int) $order->get_id(),
				'order_date'      => $order_date ? $order_date->date( 'Y-m-d H:i:s' ) : '',
				'source'          => '',
				'note'            => '',
			),
			$extra
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_rsvp_row( int $event_id, int $user_id, string $status ): array {
		$contact_raw = get_user_meta( $user_id, '_oras_rsvp_event_' . $event_id . '_contact', true );
		$contact = Contact_Normalizer::from_rsvp_contact( is_array( $contact_raw ) ? $contact_raw : array(), $user_id );
		$attendance_mode = class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_attendance_mode( $event_id, $user_id ) : '';
		$label = 'waitlist' === $status ? __( 'Waitlist', 'oras-tickets' ) : __( 'RSVP Yes', 'oras-tickets' );

		if ( $attendance_mode !== '' && class_exists( Event_RSVP::class ) ) {
			$label .= ' - ' . Event_RSVP::get_attendance_mode_label( $attendance_mode );
		}

		return array(
			'report_type'     => self::TYPE_RSVP,
			'event_id'        => $event_id,
			'event_title'     => get_the_title( $event_id ),
			'name'            => $contact['name'],
			'email'           => $contact['email'],
			'phone'           => $contact['phone'],
			'address_summary' => $contact['address_summary'],
			'item_label'      => $label,
			'quantity'        => 1,
			'order_status'    => $status,
			'order_id'        => 0,
			'order_date'      => '',
			'source'          => __( 'RSVP', 'oras-tickets' ),
			'note'            => $contact['note'],
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return string[]
	 */
	private function get_order_statuses( array $filters ): array {
		$status = sanitize_key( (string) ( $filters['status'] ?? 'all' ) );
		if ( '' === $status || 'all' === $status ) {
			return self::DEFAULT_STATUSES;
		}

		return in_array( $status, self::DEFAULT_STATUSES, true ) ? array( $status ) : self::DEFAULT_STATUSES;
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function build_date_created_arg( array $filters ): string {
		$after = isset( $filters['after'] ) ? sanitize_text_field( (string) $filters['after'] ) : '';
		$before = isset( $filters['before'] ) ? sanitize_text_field( (string) $filters['before'] ) : '';
		$parts = array();

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ) {
			$parts[] = '>=' . $after;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ) {
			$parts[] = '<=' . $before . ' 23:59:59';
		}

		return implode( '...', $parts );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows_by_search( array $rows, string $search ): array {
		if ( '' === trim( $search ) ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $search ): bool {
					return $this->row_matches_search( $row, $search );
				}
			)
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_matches_search( array $row, string $search ): bool {
		$search = trim( strtolower( $search ) );
		if ( '' === $search ) {
			return true;
		}

		foreach ( array( 'name', 'email', 'phone', 'address_summary', 'item_label', 'order_status', 'note' ) as $key ) {
			$value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? strtolower( (string) $row[ $key ] ) : '';
			if ( '' !== $value && false !== strpos( $value, $search ) ) {
				return true;
			}
		}

		return false;
	}
}
