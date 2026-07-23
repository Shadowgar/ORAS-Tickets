<?php

namespace ORAS\Tickets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	public const EVENT_COORDINATOR_ROLE = 'event_creator';

	/**
	 * Every ORAS-Tickets capability managed by this plugin.
	 *
	 * @var string[]
	 */
	public const CAPS = [
		'oras_tickets_manage_settings',
		'oras_tickets_manage_events',
		'oras_tickets_manage_event_questions',
		'oras_tickets_manage_rsvps',
		'oras_tickets_view_attendees',
		'oras_tickets_manage_attendees',
		'oras_tickets_checkin',
		'oras_tickets_send_notifications',
		'oras_tickets_view_reports',
		'oras_tickets_export_reports',
		'oras_tickets_view_board_dashboard',
		'oras_tickets_manage_speakers',
	];

	/**
	 * Board members can operate RSVP workflows and communications from Board Reports.
	 *
	 * @var string[]
	 */
	public const BOARD_CAPS = [
		'oras_tickets_view_board_dashboard',
		'oras_tickets_view_reports',
		'oras_tickets_view_attendees',
		'oras_tickets_manage_rsvps',
		'oras_tickets_send_notifications',
	];

	/**
	 * Event Coordinators manage event content and operations, but never plugin settings.
	 *
	 * The role slug remains event_creator for backward compatibility with assigned users.
	 *
	 * @var string[]
	 */
	public const EVENT_COORDINATOR_CAPS = [
		'read',
		'upload_files',
		'edit_posts',
		'edit_published_posts',
		'publish_posts',
		'delete_posts',
		'delete_published_posts',
		'edit_tribe_event',
		'read_tribe_event',
		'delete_tribe_event',
		'edit_tribe_events',
		'edit_others_tribe_events',
		'edit_private_tribe_events',
		'edit_published_tribe_events',
		'publish_tribe_events',
		'read_private_tribe_events',
		'delete_tribe_events',
		'delete_private_tribe_events',
		'delete_published_tribe_events',
		'delete_others_tribe_events',
		'oras_tickets_manage_events',
		'oras_tickets_manage_event_questions',
		'oras_tickets_manage_rsvps',
		'oras_tickets_view_attendees',
		'oras_tickets_manage_attendees',
		'oras_tickets_checkin',
		'oras_tickets_send_notifications',
		'oras_tickets_view_reports',
		'oras_tickets_export_reports',
		'oras_tickets_view_board_dashboard',
		'oras_tickets_manage_speakers',
	];

	/**
	 * @var string[]
	 */
	public const TREASURER_ONLY_CAPS = [
		'oras_tickets_view_treasurer_reconciliation',
	];

	/**
	 * Backward-compatible alias used by older checks and integrations.
	 *
	 * @var string[]
	 */
	public const EVENT_CREATOR_CAPS = self::EVENT_COORDINATOR_CAPS;

	public const EVENT_CREATOR_ROLE = self::EVENT_COORDINATOR_ROLE;

	/**
	 * Backward-compatible alias for the former Board communication subset.
	 *
	 * @var string[]
	 */
	public const BOARD_COMMUNICATION_CAPS = self::BOARD_CAPS;

	public static function add_caps(): void {
		self::reconcile_roles();
	}

	/**
	 * Apply each ORAS role's intended permissions and remove obsolete grants.
	 */
	public static function reconcile_roles(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		self::ensure_event_coordinator_role();

		self::reconcile_role( 'administrator', array_merge( self::CAPS, self::TREASURER_ONLY_CAPS ), array_merge( self::CAPS, self::TREASURER_ONLY_CAPS ) );

		foreach ( array( 'board', 'board_member' ) as $role_slug ) {
			self::reconcile_role( $role_slug, self::BOARD_CAPS, array_merge( self::CAPS, self::TREASURER_ONLY_CAPS ) );
		}

		self::reconcile_role( 'treasurer', self::TREASURER_ONLY_CAPS, array_merge( self::CAPS, self::TREASURER_ONLY_CAPS ) );

		$coordinator_managed_caps = array_merge(
			self::CAPS,
			self::TREASURER_ONLY_CAPS,
			self::EVENT_COORDINATOR_CAPS,
			array( 'manage_options' )
		);
		self::reconcile_role( self::EVENT_COORDINATOR_ROLE, self::EVENT_COORDINATOR_CAPS, $coordinator_managed_caps );
	}

	/**
	 * @param string[] $allowed_caps
	 * @param string[] $managed_caps
	 */
	private static function reconcile_role( string $role_slug, array $allowed_caps, array $managed_caps ): void {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return;
		}

		$allowed_caps = array_values( array_unique( $allowed_caps ) );
		$managed_caps = array_values( array_unique( $managed_caps ) );

		foreach ( $allowed_caps as $capability ) {
			$role->add_cap( $capability );
		}

		foreach ( array_diff( $managed_caps, $allowed_caps ) as $capability ) {
			$role->remove_cap( $capability );
		}
	}

	public static function remove_caps(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		$managed_caps = array_values(
			array_unique(
				array_merge(
					self::CAPS,
					self::TREASURER_ONLY_CAPS,
					self::EVENT_COORDINATOR_CAPS
				)
			)
		);

		foreach ( array( 'administrator', 'board', 'board_member', 'treasurer', self::EVENT_COORDINATOR_ROLE ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $managed_caps as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	public static function ensure_board_communication_caps(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		foreach ( array( 'board', 'board_member' ) as $role_slug ) {
			self::reconcile_role( $role_slug, self::BOARD_CAPS, array_merge( self::CAPS, self::TREASURER_ONLY_CAPS ) );
		}
	}

	public static function ensure_event_coordinator_role(): void {
		if ( ! function_exists( 'get_role' ) || ! function_exists( 'add_role' ) ) {
			return;
		}

		$role = get_role( self::EVENT_COORDINATOR_ROLE );
		if ( ! $role ) {
			add_role( self::EVENT_COORDINATOR_ROLE, 'Event Coordinator', array( 'read' => true ) );
			$role = get_role( self::EVENT_COORDINATOR_ROLE );
		}

		if ( ! $role ) {
			return;
		}

		self::update_event_coordinator_display_name();
	}

	public static function ensure_event_creator_role(): void {
		self::ensure_event_coordinator_role();
	}

	/**
	 * Rename the existing role without changing its slug or user assignments.
	 */
	private static function update_event_coordinator_display_name(): void {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

		$wp_roles = wp_roles();
		if ( ! isset( $wp_roles->roles[ self::EVENT_COORDINATOR_ROLE ] ) ) {
			return;
		}

		if (
			'Event Coordinator' === ( $wp_roles->roles[ self::EVENT_COORDINATOR_ROLE ]['name'] ?? '' )
			&& 'Event Coordinator' === ( $wp_roles->role_names[ self::EVENT_COORDINATOR_ROLE ] ?? '' )
		) {
			return;
		}

		$wp_roles->roles[ self::EVENT_COORDINATOR_ROLE ]['name'] = 'Event Coordinator';
		$wp_roles->role_names[ self::EVENT_COORDINATOR_ROLE ]    = 'Event Coordinator';
		update_option( $wp_roles->role_key, $wp_roles->roles );
	}
}
