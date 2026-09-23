<?php

namespace ORAS\Tickets\Reporting;

use ORAS\Tickets\Commerce\Woo\Order_Item_Classifier;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Event_Questions;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Integrations\QuickBooks\Settings;
use ORAS\Tickets\Registration_Desk\Event_Roster_Service;
use ORAS\Tickets\Registration_Desk\Event_Stats_Service;
use ORAS\Tickets\Registration_Desk\Schema;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Board_Report_Service {

	public const TYPE_TICKETS  = 'tickets';
	public const TYPE_ROSTER   = 'roster';
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
			self::TYPE_ROSTER   => __( 'Event Roster', 'oras-tickets' ),
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
				'posts_per_page' => -1,
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
		if ( self::TYPE_ROSTER === $type ) {
			return $this->get_unified_attendees( absint( $filters['event_id'] ?? 0 ), $filters );
		}

		if ( self::TYPE_RSVP === $type ) {
			return $this->get_rsvp_attendees( absint( $filters['event_id'] ?? 0 ), $filters );
		}

		if ( self::TYPE_OBSERVER === $type ) {
			return $this->get_observer_pass_buyers( $filters );
		}

		if ( self::TYPE_MERCH === $type ) {
			return $this->get_merchandise_buyers( $filters );
		}

		$event_id = absint( $filters['event_id'] ?? 0 );

		return $this->get_event_report( $event_id, $filters )['tickets'];
	}

	/**
	 * Canonical event reporting boundary shared by Overview, Tickets, Roster,
	 * exports, and event-originated membership summaries.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{overview:array<string,mixed>,tickets:array<int,array<string,mixed>>,roster:array<int,array<string,mixed>>,memberships:array<string,mixed>}
	 */
	public function get_event_report( int $event_id, array $filters = array() ): array {
		if ( $event_id <= 0 ) {
			return array(
				'overview'    => $this->empty_event_overview(),
				'tickets'     => array(),
				'roster'      => array(),
				'memberships' => array( 'total' => 0 ),
			);
		}

		$desk = $this->get_registration_desk_snapshot( $event_id );
		$website_tickets = $this->get_event_ticket_buyers(
			$event_id,
			array_merge( $filters, array( 'status' => (string) ( $filters['ticket_status'] ?? $filters['status'] ?? 'all' ) ) )
		);
		$rsvp_rows = $this->get_rsvp_attendees(
			$event_id,
			array(
				'status'          => (string) ( $filters['rsvp_status'] ?? 'all' ),
				'attendance_type' => (string) ( $filters['attendance_type'] ?? 'all' ),
				'approval_status' => (string) ( $filters['approval_status'] ?? 'all' ),
				'search'          => '',
			)
		);
		$tickets = $this->build_unified_ticket_rows( $event_id, $website_tickets, $desk, $filters );
		$roster = $this->build_unified_roster_rows( $event_id, $website_tickets, $rsvp_rows, $desk );
		$roster = $this->filter_unified_roster_rows( $roster, $filters );
		$desk_stats = ( new Event_Stats_Service() )->for_event( $event_id );
		$overview = $this->build_unified_overview( $event_id, $website_tickets, $rsvp_rows, $desk, $desk_stats );

		return array(
			'overview'    => $overview,
			'tickets'     => $tickets,
			'roster'      => $roster,
			'memberships' => is_array( $desk_stats['memberships'] ?? null ) ? $desk_stats['memberships'] : array( 'total' => 0 ),
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function get_unified_attendees( int $event_id, array $filters ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		return $this->get_event_report( $event_id, $filters )['roster'];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_event_statistics( int $event_id ): array {
		return $this->get_event_report( $event_id )['overview'];
	}

	/** @return array<string,mixed> */
	private function empty_event_overview(): array {
		return array(
			'total_registrations'            => 0,
			'people_registered'              => 0,
			'website_registrations'          => 0,
			'walk_in_registrations'          => 0,
			'included_event_registrations'   => 0,
			'complimentary_registrations'    => 0,
			'manager_verified_registrations' => 0,
			'rsvp_yes_count'                 => 0,
			'rsvp_waitlist_count'            => 0,
			'checked_in_today'               => 0,
			'expected_attendance'            => 0,
			'no_show_registrations'          => 0,
			'event_memberships'              => 0,
			'total_attendee_rows'            => 0,
			'ticket_quantity'                => 0,
			'ticket_order_count'             => 0,
			'ticket_status_counts'           => array(),
			'ticket_onsite_count'            => 0,
			'ticket_virtual_count'           => 0,
			'rsvp_onsite_count'              => 0,
			'rsvp_virtual_count'             => 0,
			'rsvp_virtual_approved_count'    => 0,
			'rsvp_approval_counts'           => array_fill_keys( Event_RSVP::get_approval_statuses(), 0 ),
			'virtual_attendance_count'       => 0,
			'onsite_attendance_count'        => 0,
		);
	}

	/** @return array{registrations:array<int,array<string,mixed>>,attendees:array<int,array<string,mixed>>,attendance:array<int,array<string,mixed>>,attendees_by_registration:array<int,array<int,array<string,mixed>>>,attendance_by_attendee:array<int,array<int,array<string,mixed>>>} */
	private function get_registration_desk_snapshot( int $event_id ): array {
		global $wpdb;
		$empty = array(
			'registrations'             => array(),
			'attendees'                 => array(),
			'attendance'                => array(),
			'attendees_by_registration' => array(),
			'attendance_by_attendee'    => array(),
		);
		if ( ! $wpdb instanceof \wpdb ) {
			return $empty;
		}

		$tables = Schema::table_names();
		if ( $tables['registrations'] !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tables['registrations'] ) ) ) {
			return $empty;
		}

		$registrations = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['registrations']} WHERE event_id = %d AND status = 'active' ORDER BY id", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table name.
			ARRAY_A
		);
		$attendees = $wpdb->get_results(
			$wpdb->prepare( "SELECT a.* FROM {$tables['attendees']} a INNER JOIN {$tables['registrations']} r ON r.id = a.registration_id WHERE r.event_id = %d AND r.status = 'active' AND a.status = 'active' ORDER BY a.id", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table names.
			ARRAY_A
		);
		$attendance = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['attendance']} WHERE event_id = %d AND state = 'checked_in' ORDER BY attendance_local_date,id", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table name.
			ARRAY_A
		);
		$registrations = is_array( $registrations ) ? $registrations : array();
		$attendees = is_array( $attendees ) ? $attendees : array();
		$attendance = is_array( $attendance ) ? $attendance : array();
		$attendees_by_registration = array();
		foreach ( $attendees as $attendee ) {
			$attendees_by_registration[ (int) $attendee['registration_id'] ][] = $attendee;
		}
		$attendance_by_attendee = array();
		foreach ( $attendance as $instance ) {
			$attendance_by_attendee[ (int) $instance['attendee_id'] ][] = $instance;
		}

		return array(
			'registrations'             => $registrations,
			'attendees'                 => $attendees,
			'attendance'                => $attendance,
			'attendees_by_registration' => $attendees_by_registration,
			'attendance_by_attendee'    => $attendance_by_attendee,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $website_rows
	 * @param array<string,mixed>            $desk
	 * @param array<string,mixed>            $filters
	 * @return array<int,array<string,mixed>>
	 */
	private function build_unified_ticket_rows( int $event_id, array $website_rows, array $desk, array $filters ): array {
		$rows = array();
		$website_items = array();
		foreach ( $website_rows as $row ) {
			$row['source'] = __( 'Website', 'oras-tickets' );
			$row['source_group'] = 'website';
			$row['identity'] = 'woo:' . absint( $row['order_id'] ?? 0 ) . ':' . absint( $row['order_item_id'] ?? 0 );
			$row['payment_assertion_label'] = '';
			$rows[] = $row;
			$website_items[ absint( $row['order_id'] ?? 0 ) . ':' . absint( $row['order_item_id'] ?? 0 ) ] = true;
		}

		$grouped_fallback = array();
		foreach ( $desk['registrations'] as $registration ) {
			$source_type = (string) ( $registration['source_type'] ?? '' );
			if ( in_array( $source_type, array( 'online', 'online_included' ), true ) ) {
				$key = absint( $registration['source_order_id'] ?? 0 ) . ':' . absint( $registration['source_order_item_id'] ?? 0 );
				if ( isset( $website_items[ $key ] ) ) {
					continue;
				}
				if ( ! isset( $grouped_fallback[ $key ] ) ) {
					$grouped_fallback[ $key ] = $this->build_desk_ticket_row( $event_id, $registration );
					$grouped_fallback[ $key ]['source'] = 'online_included' === $source_type ? __( 'Included with another event', 'oras-tickets' ) : __( 'Website', 'oras-tickets' );
					$grouped_fallback[ $key ]['source_group'] = 'website';
					$grouped_fallback[ $key ]['quantity'] = 0;
				}
				++$grouped_fallback[ $key ]['quantity'];
				continue;
			}

			if ( ! in_array( $source_type, array( 'walk_in', 'rsvp_walk_in' ), true ) ) {
				continue;
			}
			$rows[] = $this->build_desk_ticket_row( $event_id, $registration );
		}
		$rows = array_merge( $rows, array_values( $grouped_fallback ) );

		$source_filter = sanitize_key( (string) ( $filters['ticket_source'] ?? 'all' ) );
		$type_filter = sanitize_text_field( (string) ( $filters['ticket_type'] ?? '' ) );
		$status_filter = sanitize_key( (string) ( $filters['status'] ?? 'all' ) );
		$after = sanitize_text_field( (string) ( $filters['after'] ?? '' ) );
		$before = sanitize_text_field( (string) ( $filters['before'] ?? '' ) );
		$search = (string) ( $filters['search'] ?? '' );
		$rows = array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $source_filter, $type_filter, $status_filter, $after, $before, $search ): bool {
					if ( in_array( $source_filter, array( 'website', 'onsite' ), true ) && $source_filter !== (string) ( $row['source_group'] ?? '' ) ) {
						return false;
					}
					if ( '' !== $type_filter && $type_filter !== (string) ( $row['item_label'] ?? '' ) ) {
						return false;
					}
					if ( 'all' !== $status_filter && 'onsite' === (string) ( $row['source_group'] ?? '' ) ) {
						return false;
					}
					$date = substr( (string) ( $row['order_date'] ?? '' ), 0, 10 );
					if ( '' !== $after && '' !== $date && $date < $after ) {
						return false;
					}
					if ( '' !== $before && '' !== $date && $date > $before ) {
						return false;
					}

					return $this->row_matches_search( $row, $search );
				}
			)
		);
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$date_order = strcmp( (string) ( $right['order_date'] ?? '' ), (string) ( $left['order_date'] ?? '' ) );

				return 0 !== $date_order ? $date_order : strcmp( (string) ( $left['identity'] ?? '' ), (string) ( $right['identity'] ?? '' ) );
			}
		);

		return $rows;
	}

	/** @param array<string,mixed> $registration @return array<string,mixed> */
	private function build_desk_ticket_row( int $event_id, array $registration ): array {
		$registration_id = absint( $registration['id'] ?? 0 );

		return array(
			'report_type'             => self::TYPE_TICKETS,
			'event_id'                => $event_id,
			'event_title'             => get_the_title( $event_id ),
			'identity'                => 'desk-registration:' . (string) ( $registration['registration_uuid'] ?? $registration_id ),
			'name'                    => sanitize_text_field( (string) ( $registration['source_contact_name'] ?? '' ) ),
			'email'                   => sanitize_email( (string) ( $registration['source_email'] ?? '' ) ),
			'phone'                   => sanitize_text_field( (string) ( $registration['source_phone'] ?? '' ) ),
			'address_summary'         => '',
			'item_label'              => Event_Roster_Service::historical_label( $registration ),
			'quantity'                => 1,
			'order_status'            => __( 'Active registration', 'oras-tickets' ),
			'order_id'                => 0,
			'order_item_id'           => 0,
			'order_date'              => get_date_from_gmt( (string) ( $registration['created_at_utc'] ?? '' ), 'Y-m-d H:i:s' ),
			'source'                  => __( 'On-site / Registration Desk', 'oras-tickets' ),
			'source_group'            => 'onsite',
			'note'                    => __( 'Operational registration record; no WooCommerce or Stripe revenue is asserted.', 'oras-tickets' ),
			'user_id'                 => 0,
			'attendance_type'         => Ticket::ATTENDANCE_MODE_ONSITE,
			'attendance_label'        => __( 'On-site', 'oras-tickets' ),
			'approval_status'         => '',
			'approval_label'          => '',
			'question_answers'        => array(),
			'payment_assertion_label' => $this->payment_assertion_label( (string) ( $registration['payment_assertion'] ?? '' ) ),
			'registration_status'     => __( 'Active', 'oras-tickets' ),
		);
	}

	private function payment_assertion_label( string $assertion ): string {
		return array(
			'paid_card'  => __( 'Card', 'oras-tickets' ),
			'card'       => __( 'Card', 'oras-tickets' ),
			'paid_cash'  => __( 'Cash', 'oras-tickets' ),
			'cash'       => __( 'Cash', 'oras-tickets' ),
			'paid_check' => __( 'Check', 'oras-tickets' ),
			'check'      => __( 'Check', 'oras-tickets' ),
			'unpaid'     => __( 'Unpaid', 'oras-tickets' ),
		)[ sanitize_key( $assertion ) ] ?? '';
	}

	/**
	 * @param array<int,array<string,mixed>> $website_rows
	 * @param array<int,array<string,mixed>> $rsvp_rows
	 * @param array<string,mixed>            $desk
	 * @return array<int,array<string,mixed>>
	 */
	private function build_unified_roster_rows( int $event_id, array $website_rows, array $rsvp_rows, array $desk ): array {
		$rows = array();
		$projected_website_units = array();
		$projected_rsvp_users = array();
		$today = wp_date( 'Y-m-d', null, wp_timezone() );
		foreach ( $desk['registrations'] as $registration ) {
			$registration_id = absint( $registration['id'] ?? 0 );
			$source_type = (string) ( $registration['source_type'] ?? '' );
			if ( in_array( $source_type, array( 'online', 'online_included' ), true ) ) {
				$projected_website_units[ absint( $registration['source_order_id'] ?? 0 ) . ':' . absint( $registration['source_order_item_id'] ?? 0 ) . ':' . absint( $registration['source_unit_number'] ?? 0 ) ] = true;
			}
			$evidence = json_decode( (string) ( $registration['source_evidence'] ?? '' ), true );
			$evidence = is_array( $evidence ) ? $evidence : array();
			if ( 'rsvp_website' === $source_type && absint( $evidence['rsvp_user_id'] ?? 0 ) > 0 ) {
				$projected_rsvp_users[ absint( $evidence['rsvp_user_id'] ) ] = true;
			}
			$registration_attendees = $desk['attendees_by_registration'][ $registration_id ] ?? array();
			if ( empty( $registration_attendees ) ) {
				$registration_attendees = array(
					array(
						'id'             => 0,
						'attendee_uuid'  => '',
						'slot_key'       => 'registration-coverage',
						'display_name'   => (string) ( $registration['source_contact_name'] ?? '' ),
						'identity_state' => 'registration',
					),
				);
			}

			foreach ( $registration_attendees as $attendee ) {
				$attendee_id = absint( $attendee['id'] ?? 0 );
				$instances = $desk['attendance_by_attendee'][ $attendee_id ] ?? array();
				$checked_today = false;
				foreach ( $instances as $instance ) {
					if ( $today === (string) ( $instance['attendance_local_date'] ?? '' ) ) {
						$checked_today = true;
						break;
					}
				}
				$name = trim( (string) ( $attendee['display_name'] ?? '' ) );
				if ( '' === $name ) {
					$name = 'family' === (string) ( $registration['classification'] ?? '' ) ? __( 'Unnamed family attendee', 'oras-tickets' ) : sanitize_text_field( (string) ( $registration['source_contact_name'] ?? '' ) );
				}
				$rows[] = array(
					'report_type'           => self::TYPE_TICKETS,
					'event_id'              => $event_id,
					'event_title'           => get_the_title( $event_id ),
					'identity'              => $attendee_id > 0 ? 'desk-attendee:' . (string) ( $attendee['attendee_uuid'] ?? $attendee_id ) : 'desk-registration:' . (string) ( $registration['registration_uuid'] ?? $registration_id ),
					'registration_identity' => 'desk-registration:' . (string) ( $registration['registration_uuid'] ?? $registration_id ),
					'name'                  => $name,
					'email'                 => sanitize_email( (string) ( $registration['source_email'] ?? '' ) ),
					'phone'                 => sanitize_text_field( (string) ( $registration['source_phone'] ?? '' ) ),
					'address_summary'       => '',
					'item_label'            => Event_Roster_Service::historical_label( $registration ),
					'quantity'              => 1,
					'order_status'          => __( 'Active', 'oras-tickets' ),
					'order_id'              => absint( $registration['source_order_id'] ?? 0 ),
					'order_item_id'         => absint( $registration['source_order_item_id'] ?? 0 ),
					'order_date'            => get_date_from_gmt( (string) ( $registration['created_at_utc'] ?? '' ), 'Y-m-d H:i:s' ),
					'source'                => $this->registration_source_label( $source_type ),
					'source_group'          => str_starts_with( $source_type, 'rsvp_' ) ? 'rsvps' : 'tickets',
					'note'                  => '',
					'user_id'               => absint( $evidence['rsvp_user_id'] ?? 0 ),
					'attendance_type'       => Ticket::ATTENDANCE_MODE_ONSITE,
					'attendance_label'      => $checked_today ? __( 'Checked in today', 'oras-tickets' ) : ( ! empty( $instances ) ? __( 'Checked in previously', 'oras-tickets' ) : __( 'Not checked in', 'oras-tickets' ) ),
					'attendance_status'     => $checked_today ? __( 'Checked in today', 'oras-tickets' ) : ( ! empty( $instances ) ? __( 'Checked in previously', 'oras-tickets' ) : __( 'Not checked in', 'oras-tickets' ) ),
					'registration_status'   => __( 'Active', 'oras-tickets' ),
					'approval_status'       => '',
					'approval_label'        => '',
					'question_answers'      => array(),
					'classification'        => sanitize_key( (string) ( $registration['classification'] ?? '' ) ),
					'source_type'           => $source_type,
				);
			}
		}

		foreach ( $website_rows as $website ) {
			$quantity = max( 1, absint( $website['quantity'] ?? 1 ) );
			for ( $unit = 1; $unit <= $quantity; ++$unit ) {
				$key = absint( $website['order_id'] ?? 0 ) . ':' . absint( $website['order_item_id'] ?? 0 ) . ':' . $unit;
				if ( isset( $projected_website_units[ $key ] ) ) {
					continue;
				}
				$row = $website;
				$row['identity'] = 'woo:' . $key;
				$row['registration_identity'] = 'woo:' . absint( $website['order_id'] ?? 0 ) . ':' . absint( $website['order_item_id'] ?? 0 );
				$row['quantity'] = 1;
				$row['source'] = __( 'Website', 'oras-tickets' );
				$row['source_group'] = 'tickets';
				$row['registration_status'] = ucfirst( (string) ( $website['order_status'] ?? '' ) );
				$row['attendance_status'] = __( 'Not checked in', 'oras-tickets' );
				$row['attendance_label'] = __( 'Not checked in', 'oras-tickets' );
				$row['classification'] = 'individual';
				$rows[] = $row;
			}
		}

		foreach ( $rsvp_rows as $rsvp ) {
			$user_id = absint( $rsvp['user_id'] ?? 0 );
			if ( $user_id > 0 && isset( $projected_rsvp_users[ $user_id ] ) ) {
				continue;
			}
			$rsvp['identity'] = 'rsvp-user:' . $user_id;
			$rsvp['registration_identity'] = 'rsvp-user:' . $user_id;
			$rsvp['quantity'] = 1;
			$rsvp['source'] = __( 'RSVP', 'oras-tickets' );
			$rsvp['source_group'] = 'rsvps';
			$rsvp['registration_status'] = 'waitlist' === (string) ( $rsvp['order_status'] ?? '' ) ? __( 'Waitlist', 'oras-tickets' ) : __( 'RSVP Yes', 'oras-tickets' );
			$rsvp['attendance_status'] = __( 'Not checked in', 'oras-tickets' );
			$rsvp['attendance_label'] = __( 'Not checked in', 'oras-tickets' );
			$rsvp['classification'] = 'individual';
			$rows[] = $rsvp;
		}
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$name_order = strcasecmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );

				return 0 !== $name_order ? $name_order : strcmp( (string) ( $left['identity'] ?? '' ), (string) ( $right['identity'] ?? '' ) );
			}
		);

		return $rows;
	}

	private function registration_source_label( string $source_type ): string {
		return match ( $source_type ) {
			'online'                  => __( 'Website', 'oras-tickets' ),
			'online_included'         => __( 'Included with another event', 'oras-tickets' ),
			'walk_in'                 => __( 'On-site / Registration Desk', 'oras-tickets' ),
			'complimentary', 'speaker' => __( 'Complimentary', 'oras-tickets' ),
			'rsvp_walk_in', 'rsvp_website', 'rsvp_waitlist' => __( 'RSVP', 'oras-tickets' ),
			'manager_verified_manual' => __( 'Manager Verified', 'oras-tickets' ),
			default                   => __( 'Registration', 'oras-tickets' ),
		};
	}

	/** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
	private function filter_unified_roster_rows( array $rows, array $filters ): array {
		$source = sanitize_key( (string) ( $filters['attendee_source'] ?? 'all' ) );
		$attendance_type = sanitize_key( (string) ( $filters['attendance_type'] ?? 'all' ) );
		$approval = sanitize_key( (string) ( $filters['approval_status'] ?? 'all' ) );
		$ticket_status = sanitize_key( (string) ( $filters['ticket_status'] ?? 'all' ) );
		$rsvp_status = sanitize_key( (string) ( $filters['rsvp_status'] ?? 'all' ) );
		$search = (string) ( $filters['search'] ?? '' );

		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $source, $attendance_type, $approval, $ticket_status, $rsvp_status, $search ): bool {
					if ( in_array( $source, array( 'tickets', 'rsvps' ), true ) && $source !== (string) ( $row['source_group'] ?? '' ) ) {
						return false;
					}
					if ( 'all' !== $attendance_type && $attendance_type !== (string) ( $row['attendance_type'] ?? Ticket::ATTENDANCE_MODE_ONSITE ) ) {
						return false;
					}
					if ( 'all' !== $approval && 'rsvps' === (string) ( $row['source_group'] ?? '' ) && $approval !== (string) ( $row['approval_status'] ?? '' ) ) {
						return false;
					}
					if ( 'all' !== $ticket_status && 'tickets' === (string) ( $row['source_group'] ?? '' ) && absint( $row['order_id'] ?? 0 ) > 0 && $ticket_status !== (string) ( $row['order_status'] ?? '' ) ) {
						return false;
					}
					if ( 'all' !== $rsvp_status && 'rsvps' === (string) ( $row['source_group'] ?? '' ) && $rsvp_status !== (string) ( $row['order_status'] ?? '' ) ) {
						return false;
					}

					return $this->row_matches_search( $row, $search );
				}
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $website_rows
	 * @param array<int,array<string,mixed>> $rsvp_rows
	 * @param array<string,mixed>            $desk
	 * @param array<string,mixed>            $desk_stats
	 * @return array<string,mixed>
	 */
	private function build_unified_overview( int $event_id, array $website_rows, array $rsvp_rows, array $desk, array $desk_stats ): array {
		unset( $event_id );
		$total = is_array( $desk_stats['event_total'] ?? null ) ? $desk_stats['event_total'] : array();
		$today = is_array( $desk_stats['today'] ?? null ) ? $desk_stats['today'] : array();
		$memberships = is_array( $desk_stats['memberships'] ?? null ) ? $desk_stats['memberships'] : array();
		$projected_units = array();
		$projected_rsvp_users = array();
		foreach ( $desk['registrations'] as $registration ) {
			$source_type = (string) ( $registration['source_type'] ?? '' );
			if ( in_array( $source_type, array( 'online', 'online_included' ), true ) ) {
				$projected_units[ absint( $registration['source_order_id'] ?? 0 ) . ':' . absint( $registration['source_order_item_id'] ?? 0 ) . ':' . absint( $registration['source_unit_number'] ?? 0 ) ] = true;
			}
			if ( 'rsvp_website' === $source_type ) {
				$evidence = json_decode( (string) ( $registration['source_evidence'] ?? '' ), true );
				$user_id = is_array( $evidence ) ? absint( $evidence['rsvp_user_id'] ?? 0 ) : 0;
				if ( $user_id > 0 ) {
					$projected_rsvp_users[ $user_id ] = true;
				}
			}
		}

		$fallback_website = 0;
		$ticket_quantity = 0;
		$ticket_orders = array();
		$ticket_status_counts = array();
		$ticket_virtual = 0;
		$ticket_onsite = 0;
		foreach ( $website_rows as $row ) {
			$quantity = max( 1, absint( $row['quantity'] ?? 1 ) );
			$ticket_quantity += $quantity;
			$order_id = absint( $row['order_id'] ?? 0 );
			$item_id = absint( $row['order_item_id'] ?? 0 );
			if ( $order_id > 0 ) {
				$ticket_orders[ $order_id ] = true;
			}
			for ( $unit = 1; $unit <= $quantity; ++$unit ) {
				if ( ! isset( $projected_units[ $order_id . ':' . $item_id . ':' . $unit ] ) ) {
					++$fallback_website;
				}
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
		$fallback_rsvp = 0;
		$rsvp_virtual = 0;
		$rsvp_onsite = 0;
		$rsvp_virtual_approved = 0;
		$rsvp_approval_counts = array_fill_keys( Event_RSVP::get_approval_statuses(), 0 );
		foreach ( $rsvp_rows as $row ) {
			$is_waitlist = 'waitlist' === (string) ( $row['order_status'] ?? '' );
			if ( $is_waitlist ) {
				++$rsvp_waitlist;
			} else {
				++$rsvp_yes;
			}
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( ! $is_waitlist && ! isset( $projected_rsvp_users[ $user_id ] ) ) {
				++$fallback_rsvp;
			}
			if ( Ticket::ATTENDANCE_MODE_VIRTUAL === (string) ( $row['attendance_type'] ?? '' ) ) {
				++$rsvp_virtual;
			} else {
				++$rsvp_onsite;
			}
			$approval = Event_RSVP::normalize_approval_status( (string) ( $row['approval_status'] ?? '' ), Event_RSVP::APPROVAL_STATUS_APPROVED );
			$rsvp_approval_counts[ $approval ] = ( $rsvp_approval_counts[ $approval ] ?? 0 ) + 1;
			if ( Ticket::ATTENDANCE_MODE_VIRTUAL === (string) ( $row['attendance_type'] ?? '' ) && Event_RSVP::APPROVAL_STATUS_APPROVED === $approval ) {
				++$rsvp_virtual_approved;
			}
		}

		$desk_registrations = absint( $total['active_registrations'] ?? 0 );
		$people_registered = absint( $total['people_registered'] ?? 0 ) + $fallback_website + $fallback_rsvp;
		$total_registrations = $desk_registrations + $fallback_website + $fallback_rsvp;
		$no_shows = absint( $total['no_show_registrations'] ?? 0 ) + $fallback_website + $fallback_rsvp;
		$overview = $this->empty_event_overview();
		$overview = array_merge(
			$overview,
			array(
				'total_registrations'            => $total_registrations,
				'people_registered'              => $people_registered,
				'website_registrations'          => absint( $total['website_registrations'] ?? 0 ) + $fallback_website,
				'walk_in_registrations'          => absint( $total['walk_in_registrations'] ?? 0 ),
				'included_event_registrations'   => absint( $total['included_event_registrations'] ?? 0 ),
				'complimentary_registrations'    => absint( $total['complimentary_registrations'] ?? 0 ),
				'manager_verified_registrations' => absint( $total['manager_verified_registrations'] ?? 0 ),
				'rsvp_yes_count'                 => $rsvp_yes,
				'rsvp_waitlist_count'            => $rsvp_waitlist,
				'checked_in_today'               => absint( $today['actual_people'] ?? 0 ),
				'expected_attendance'            => $people_registered,
				'no_show_registrations'          => max( 0, $no_shows ),
				'event_memberships'              => absint( $memberships['total'] ?? 0 ),
				'total_attendee_rows'            => $people_registered,
				'ticket_quantity'                => $ticket_quantity + absint( $total['walk_in_registrations'] ?? 0 ),
				'ticket_order_count'             => count( $ticket_orders ),
				'ticket_status_counts'           => $ticket_status_counts,
				'ticket_onsite_count'            => $ticket_onsite,
				'ticket_virtual_count'           => $ticket_virtual,
				'rsvp_onsite_count'              => $rsvp_onsite,
				'rsvp_virtual_count'             => $rsvp_virtual,
				'rsvp_virtual_approved_count'    => $rsvp_virtual_approved,
				'rsvp_approval_counts'           => $rsvp_approval_counts,
				'virtual_attendance_count'       => $ticket_virtual + $rsvp_virtual,
				'onsite_attendance_count'        => max( 0, $people_registered - $ticket_virtual - $rsvp_virtual ),
			)
		);

		return $overview;
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
						'report_type'      => self::TYPE_TICKETS,
						'event_id'         => $event_id,
						'event_title'      => get_the_title( $event_id ),
						'item_label'       => $ticket_name,
						'source'           => __( 'Website', 'oras-tickets' ),
						'attendance_type'  => Ticket::normalizeAttendanceMode( (string) $item->get_meta( '_oras_ticket_attendance_mode', true ), Ticket::ATTENDANCE_MODE_ONSITE ),
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
				'name'             => $contact['name'],
				'email'            => $contact['email'],
				'phone'            => $contact['phone'],
				'address_summary'  => $contact['address_summary'],
				'item_label'       => '',
				'quantity'         => max( 1, (int) $item->get_quantity() ),
				'order_status'     => (string) $order->get_status(),
				'order_id'         => (int) $order->get_id(),
				'order_item_id'    => (int) $item->get_id(),
				'order_date'       => $order_date ? $order_date->date( 'Y-m-d H:i:s' ) : '',
				'source'           => '',
				'note'             => '',
				'user_id'          => (int) $order->get_user_id(),
				'attendance_type'  => Ticket::ATTENDANCE_MODE_ONSITE,
				'attendance_label' => __( 'On-site', 'oras-tickets' ),
				'approval_status'  => '',
				'approval_label'   => '',
				'question_answers' => $this->normalize_question_answer_snapshots( $item->get_meta( Event_Questions::ORDER_ITEM_KEY, true ) ),
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
		$question_answers = is_array( $contact_raw ) && isset( $contact_raw[ Event_Questions::RSVP_CONTACT_KEY ] ) && is_array( $contact_raw[ Event_Questions::RSVP_CONTACT_KEY ] )
			? $this->normalize_question_answer_snapshots( $contact_raw[ Event_Questions::RSVP_CONTACT_KEY ] )
			: array();
		$attendance_mode = class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_attendance_type_for_report( $event_id, $user_id ) : Ticket::ATTENDANCE_MODE_ONSITE;
		$approval_status = class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_approval_status( $event_id, $user_id ) : Event_RSVP::APPROVAL_STATUS_APPROVED;
		$label = 'waitlist' === $status ? __( 'Waitlist', 'oras-tickets' ) : __( 'RSVP Yes', 'oras-tickets' );

		if ( $attendance_mode !== '' && class_exists( Event_RSVP::class ) ) {
			$label .= ' - ' . Event_RSVP::get_attendance_mode_label( $attendance_mode );
		}
		$source = 'waitlist' === $status ? __( 'RSVP Waitlist', 'oras-tickets' ) : __( 'RSVP', 'oras-tickets' );

		return array(
			'report_type'      => self::TYPE_RSVP,
			'event_id'         => $event_id,
			'event_title'      => get_the_title( $event_id ),
			'name'             => $contact['name'],
			'email'            => $contact['email'],
			'phone'            => $contact['phone'],
			'address_summary'  => $contact['address_summary'],
			'item_label'       => $label,
			'quantity'         => 1,
			'order_status'     => $status,
			'order_id'         => 0,
			'order_date'       => '',
			'source'           => $source,
			'note'             => $contact['note'],
			'user_id'          => $user_id,
			'attendance_type'  => $attendance_mode,
			'attendance_label' => class_exists( Event_RSVP::class ) ? Event_RSVP::get_attendance_mode_label( $attendance_mode ) : __( 'On-site', 'oras-tickets' ),
			'approval_status'  => $approval_status,
			'approval_label'   => class_exists( Event_RSVP::class ) ? Event_RSVP::get_approval_status_label( $approval_status ) : __( 'Approved', 'oras-tickets' ),
			'approved_by'      => class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_approved_by_display( $event_id, $user_id ) : '',
			'approved_at'      => class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_approved_at( $event_id, $user_id ) : '',
			'rejection_reason' => class_exists( Event_RSVP::class ) ? Event_RSVP::get_user_rejection_reason( $event_id, $user_id ) : '',
			'question_answers' => $question_answers,
		);
	}

	/**
	 * @param mixed $value
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_question_answer_snapshots( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$answers = array();
		foreach ( $value as $snapshot ) {
			if ( ! is_array( $snapshot ) || empty( $snapshot['label'] ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) $snapshot['label'] );
			$answer_map = Event_Questions::snapshots_to_label_map( array( $snapshot ) );
			$answers[] = array(
				'id'            => isset( $snapshot['id'] ) && is_scalar( $snapshot['id'] ) ? sanitize_key( (string) $snapshot['id'] ) : '',
				'label'         => $label,
				'type'          => isset( $snapshot['type'] ) && is_scalar( $snapshot['type'] ) ? sanitize_key( (string) $snapshot['type'] ) : 'text',
				'value'         => $snapshot['value'] ?? '',
				'display_value' => isset( $snapshot['display_value'] ) && is_scalar( $snapshot['display_value'] ) ? sanitize_text_field( (string) $snapshot['display_value'] ) : ( $answer_map[ $label ] ?? '' ),
			);
		}

		return $answers;
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

		$question_text = strtolower( $this->format_question_answers_for_report( $row['question_answers'] ?? array() ) );
		if ( '' !== $question_text && false !== strpos( $question_text, $search ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param mixed $answers
	 */
	public function format_question_answers_for_report( $answers ): string {
		if ( ! is_array( $answers ) ) {
			return '';
		}

		$parts = array();
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}

			$label = isset( $answer['label'] ) && is_scalar( $answer['label'] ) ? sanitize_text_field( (string) $answer['label'] ) : '';
			$answer_map = Event_Questions::snapshots_to_label_map( array( $answer ) );
			$value = isset( $answer['display_value'] ) && is_scalar( $answer['display_value'] )
				? sanitize_text_field( (string) $answer['display_value'] )
				: ( $answer_map[ $label ] ?? '' );
			if ( '' !== $label && '' !== $value ) {
				$parts[] = $label . ': ' . $value;
			}
		}

		return implode( '; ', $parts );
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
