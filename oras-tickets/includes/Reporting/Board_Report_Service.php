<?php

namespace ORAS\Tickets\Reporting;

use ORAS\Tickets\Commerce\Woo\Order_Item_Classifier;
use ORAS\Tickets\Domain\Ticket;
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
		$events = array();

		foreach ( $this->get_ticket_event_ids_from_orders() as $event_id ) {
			$post = get_post( $event_id );
			if ( ! $post instanceof \WP_Post || 'tribe_events' !== $post->post_type ) {
				continue;
			}
			if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				continue;
			}
			$events[ (int) $post->ID ] = $post;
		}

		foreach ( $this->get_rsvp_enabled_event_ids() as $event_id ) {
			$post = get_post( $event_id );
			if ( ! $post instanceof \WP_Post || 'tribe_events' !== $post->post_type ) {
				continue;
			}
			if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				continue;
			}
			$events[ (int) $post->ID ] = $post;
		}

		$all_events = get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'future', 'draft', 'private' ),
				'posts_per_page' => 250,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $all_events as $event ) {
			if ( ! $event instanceof \WP_Post ) {
				continue;
			}
			$events[ (int) $event->ID ] = $event;
		}

		$events = array_values( $events );
		usort(
			$events,
			static function ( \WP_Post $left, \WP_Post $right ): int {
				return strcmp( (string) $right->post_date, (string) $left->post_date );
			}
		);

		return $events;
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
	public function get_unified_attendees( int $event_id, array $filters ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		$source = sanitize_key( (string) ( $filters['attendee_source'] ?? 'all' ) );
		if ( ! in_array( $source, array( 'all', 'tickets', 'rsvps' ), true ) ) {
			$source = 'all';
		}

		$rows = array();
		if ( 'all' === $source || 'tickets' === $source ) {
			$ticket_filters = $filters;
			$ticket_filters['status'] = isset( $filters['ticket_status'] ) ? sanitize_key( (string) $filters['ticket_status'] ) : 'all';
			$rows = array_merge( $rows, $this->get_event_ticket_buyers( $event_id, $ticket_filters ) );
		}

		if ( 'all' === $source || 'rsvps' === $source ) {
			$rsvp_filters = $filters;
			$rsvp_filters['status'] = isset( $filters['rsvp_status'] ) ? sanitize_key( (string) $filters['rsvp_status'] ) : 'all';
			$rows = array_merge( $rows, $this->get_rsvp_attendees( $event_id, $rsvp_filters ) );
		}

		return $this->filter_rows_by_search(
			$this->filter_attendee_rows( $this->merge_attendee_rows( $rows ), $filters ),
			(string) ( $filters['search'] ?? '' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_event_statistics( int $event_id ): array {
		$ticket_rows = $this->get_event_ticket_buyers(
			$event_id,
			array(
				'status' => 'all',
				'search' => '',
			)
		);
		$rsvp_rows = $this->get_rsvp_attendees(
			$event_id,
			array(
				'status'          => 'all',
				'attendance_type' => 'all',
				'approval_status' => 'all',
				'search'          => '',
			)
		);
		$attendees = $this->merge_attendee_rows( array_merge( $ticket_rows, $rsvp_rows ) );

		$ticket_quantity = 0;
		$ticket_orders = array();
		$ticket_status_counts = array();
		$ticket_virtual = 0;
		$ticket_onsite = 0;
		foreach ( $ticket_rows as $row ) {
			$quantity = max( 1, (int) ( $row['quantity'] ?? 1 ) );
			$ticket_quantity += $quantity;
			$order_id = absint( $row['order_id'] ?? 0 );
			if ( $order_id > 0 ) {
				$ticket_orders[ $order_id ] = true;
			}
			$status = sanitize_key( (string) ( $row['order_status'] ?? '' ) );
			if ( '' !== $status ) {
				$ticket_status_counts[ $status ] = ( $ticket_status_counts[ $status ] ?? 0 ) + $quantity;
			}
			if ( Ticket::ATTENDANCE_MODE_VIRTUAL === (string) ( $row['attendance_type'] ?? '' ) ) {
				$ticket_virtual += $quantity;
			} else {
				$ticket_onsite += $quantity;
			}
		}

		$rsvp_yes = 0;
		$rsvp_waitlist = 0;
		$rsvp_virtual = 0;
		$rsvp_onsite = 0;
		$rsvp_approval_counts = array_fill_keys( Event_RSVP::get_approval_statuses(), 0 );
		foreach ( $rsvp_rows as $row ) {
			if ( 'waitlist' === (string) ( $row['order_status'] ?? '' ) ) {
				++$rsvp_waitlist;
			} else {
				++$rsvp_yes;
			}

			if ( Ticket::ATTENDANCE_MODE_VIRTUAL === (string) ( $row['attendance_type'] ?? '' ) ) {
				++$rsvp_virtual;
			} else {
				++$rsvp_onsite;
			}

			$approval = Event_RSVP::normalize_approval_status( (string) ( $row['approval_status'] ?? '' ), Event_RSVP::APPROVAL_STATUS_APPROVED );
			$rsvp_approval_counts[ $approval ] = ( $rsvp_approval_counts[ $approval ] ?? 0 ) + 1;
		}

		return array(
			'total_attendee_rows'      => count( $attendees ),
			'ticket_quantity'          => $ticket_quantity,
			'ticket_order_count'       => count( $ticket_orders ),
			'ticket_status_counts'     => $ticket_status_counts,
			'ticket_onsite_count'      => $ticket_onsite,
			'ticket_virtual_count'     => $ticket_virtual,
			'rsvp_yes_count'           => $rsvp_yes,
			'rsvp_waitlist_count'      => $rsvp_waitlist,
			'rsvp_onsite_count'        => $rsvp_onsite,
			'rsvp_virtual_count'       => $rsvp_virtual,
			'rsvp_approval_counts'     => $rsvp_approval_counts,
			'virtual_attendance_count' => $ticket_virtual + $rsvp_virtual,
			'onsite_attendance_count'  => $ticket_onsite + $rsvp_onsite,
		);
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
						'attendance_type' => Ticket::normalizeAttendanceMode( (string) $item->get_meta( '_oras_ticket_attendance_mode', true ), Ticket::ATTENDANCE_MODE_ONSITE ),
						'attendance_label' => Event_RSVP::get_attendance_mode_label( Ticket::normalizeAttendanceMode( (string) $item->get_meta( '_oras_ticket_attendance_mode', true ), Ticket::ATTENDANCE_MODE_ONSITE ) ),
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
		$attendance_type = sanitize_key( (string) ( $filters['attendance_type'] ?? 'all' ) );
		if ( ! in_array( $attendance_type, array( 'all', Ticket::ATTENDANCE_MODE_ONSITE, Ticket::ATTENDANCE_MODE_VIRTUAL ), true ) ) {
			$attendance_type = 'all';
		}
		$approval_status = sanitize_key( (string) ( $filters['approval_status'] ?? 'all' ) );
		if ( class_exists( Event_RSVP::class ) && ! in_array( $approval_status, array_merge( array( 'all' ), Event_RSVP::get_approval_statuses() ), true ) ) {
			$approval_status = 'all';
		}

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

		$rows = array_filter(
			$rows,
			static function ( array $row ) use ( $attendance_type, $approval_status ): bool {
				if ( 'all' !== $attendance_type && (string) ( $row['attendance_type'] ?? '' ) !== $attendance_type ) {
					return false;
				}

				if ( 'all' !== $approval_status && (string) ( $row['approval_status'] ?? '' ) !== $approval_status ) {
					return false;
				}

				return true;
			}
		);

		return $this->filter_rows_by_search( array_values( $rows ), (string) ( $filters['search'] ?? '' ) );
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
				'user_id'         => (int) $order->get_user_id(),
				'attendance_type' => Ticket::ATTENDANCE_MODE_ONSITE,
				'attendance_label' => __( 'On-site', 'oras-tickets' ),
				'approval_status' => '',
				'approval_label'  => '',
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
		$attendance_mode = class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_attendance_type_for_report( $event_id, $user_id ) : Ticket::ATTENDANCE_MODE_ONSITE;
		$approval_status = class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_approval_status( $event_id, $user_id ) : Event_RSVP::APPROVAL_STATUS_APPROVED;
		$label = 'waitlist' === $status ? __( 'Waitlist', 'oras-tickets' ) : __( 'RSVP Yes', 'oras-tickets' );

		if ( $attendance_mode !== '' && class_exists( Event_RSVP::class ) ) {
			$label .= ' - ' . Event_RSVP::get_attendance_mode_label( $attendance_mode );
		}
		$source = 'waitlist' === $status ? __( 'RSVP Waitlist', 'oras-tickets' ) : __( 'RSVP', 'oras-tickets' );

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
			'source'          => $source,
			'note'            => $contact['note'],
			'user_id'         => $user_id,
			'attendance_type' => $attendance_mode,
			'attendance_label' => class_exists( Event_RSVP::class ) ? Event_RSVP::get_attendance_mode_label( $attendance_mode ) : __( 'On-site', 'oras-tickets' ),
			'approval_status' => $approval_status,
			'approval_label'  => class_exists( Event_RSVP::class ) ? Event_RSVP::get_approval_status_label( $approval_status ) : __( 'Approved', 'oras-tickets' ),
			'approved_by'     => class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_approved_by_display( $event_id, $user_id ) : '',
			'approved_at'     => class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_approved_at( $event_id, $user_id ) : '',
			'rejection_reason' => class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_rejection_reason( $event_id, $user_id ) : '',
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

		foreach ( array( 'name', 'email', 'phone', 'address_summary', 'item_label', 'order_status', 'source', 'attendance_label', 'approval_label', 'note' ) as $key ) {
			$value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? strtolower( (string) $row[ $key ] ) : '';
			if ( '' !== $value && false !== strpos( $value, $search ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_attendee_rows( array $rows ): array {
		$merged = array();
		$order = array();

		foreach ( $rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
			$key = $user_id > 0
				? 'u:' . $user_id
				: 'e:' . $email . '|o:' . absint( $row['order_id'] ?? 0 ) . '|i:' . sanitize_title( (string) ( $row['item_label'] ?? '' ) );

			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $row;
				$order[] = $key;
				continue;
			}

			$existing_source = (string) ( $merged[ $key ]['source'] ?? '' );
			$new_source = (string) ( $row['source'] ?? '' );
			if ( '' !== $new_source && false === stripos( $existing_source, $new_source ) ) {
				$merged[ $key ]['source'] = '' === $existing_source ? $new_source : $existing_source . ' + ' . $new_source;
			}

			$merged[ $key ]['quantity'] = max( 1, (int) ( $merged[ $key ]['quantity'] ?? 1 ) ) + max( 1, (int) ( $row['quantity'] ?? 1 ) );
			foreach ( array( 'phone', 'address_summary', 'note', 'attendance_type', 'attendance_label', 'approval_status', 'approval_label' ) as $field ) {
				if ( empty( $merged[ $key ][ $field ] ) && ! empty( $row[ $field ] ) ) {
					$merged[ $key ][ $field ] = $row[ $field ];
				}
			}

			if ( 'waitlist' === (string) ( $row['order_status'] ?? '' ) ) {
				$merged[ $key ]['order_status'] = 'waitlist';
			}
		}

		$result = array();
		foreach ( $order as $key ) {
			$result[] = $merged[ $key ];
		}

		return $result;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_attendee_rows( array $rows, array $filters ): array {
		$attendance_type = sanitize_key( (string) ( $filters['attendance_type'] ?? 'all' ) );
		if ( ! in_array( $attendance_type, array( 'all', Ticket::ATTENDANCE_MODE_ONSITE, Ticket::ATTENDANCE_MODE_VIRTUAL ), true ) ) {
			$attendance_type = 'all';
		}

		$approval_status = sanitize_key( (string) ( $filters['approval_status'] ?? 'all' ) );
		if ( ! in_array( $approval_status, array_merge( array( 'all' ), Event_RSVP::get_approval_statuses() ), true ) ) {
			$approval_status = 'all';
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $attendance_type, $approval_status ): bool {
					if ( 'all' !== $attendance_type && (string) ( $row['attendance_type'] ?? Ticket::ATTENDANCE_MODE_ONSITE ) !== $attendance_type ) {
						return false;
					}

					if ( 'all' !== $approval_status && (string) ( $row['approval_status'] ?? '' ) !== $approval_status ) {
						return false;
					}

					return true;
				}
			)
			);
		}

	/**
	 * @return int[]
	 */
	private function get_rsvp_enabled_event_ids(): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status IN ('publish', 'future', 'draft', 'pending', 'private')
				AND pm.meta_key = %s
				ORDER BY p.post_date DESC, p.ID DESC",
				'tribe_events',
				'_oras_rsvp_v1'
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$enabled_ids = array();
		foreach ( $ids as $id ) {
			$event_id = absint( $id );
			if ( $event_id <= 0 ) {
				continue;
			}

			$rsvp = get_post_meta( $event_id, '_oras_rsvp_v1', true );
			if ( is_array( $rsvp ) && ! empty( $rsvp['enabled'] ) ) {
				$enabled_ids[] = $event_id;
			}
		}

		return array_values( array_unique( $enabled_ids ) );
	}

	/**
	 * @return int[]
	 */
	private function get_ticket_event_ids_from_orders(): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}

		// Pull distinct linked event IDs from Woo ticket line item meta.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT CAST(meta_value AS UNSIGNED) AS event_id
				FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE meta_key = %s
				AND meta_value <> ''
				AND meta_value IS NOT NULL",
				'_oras_ticket_event_id'
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $rows ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);

		return $ids;
	}
}
