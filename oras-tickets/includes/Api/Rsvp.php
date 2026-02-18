<?php

namespace ORAS\Tickets\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rsvp {

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'oras/v1',
			'/rsvp/my',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_my_rsvps' ),
				'permission_callback' => array( self::class, 'permission_logged_in' ),
			)
		);

		register_rest_route(
			'oras/v1',
			'/rsvp/event/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_event_rsvp' ),
				'permission_callback' => array( self::class, 'permission_logged_in' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
				),
			)
		);
	}

	public static function permission_logged_in(): bool {
		return is_user_logged_in();
	}

	public static function get_my_rsvps( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_REST_Response( array(), 401 );
		}

		global $wpdb;
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				'_oras_rsvp_event_%'
			)
		);

		$rsvps = array();
		foreach ( $meta_keys as $meta_key ) {
			if ( ! preg_match( '/^_oras_rsvp_event_(\d+)$/', $meta_key, $matches ) ) {
				continue;
			}
			$event_id = (int) $matches[1];
			$post = get_post( $event_id );
			if ( ! $post || $post->post_type !== 'tribe_events' ) {
				continue;
			}

			$status = get_user_meta( $user_id, $meta_key, true );
			if ( ! in_array( $status, array( 'yes', 'no', 'waitlist' ), true ) ) {
				continue;
			}

			$event_start = get_post_meta( $event_id, '_EventStartDate', true );
			$rsvps[] = array(
				'event_id'    => $event_id,
				'status'      => sanitize_text_field( $status ),
				'event_title' => sanitize_text_field( get_the_title( $event_id ) ),
				'event_start' => sanitize_text_field( $event_start ),
				'event_url'   => esc_url_raw( get_permalink( $event_id ) ),
			);
		}

		return new \WP_REST_Response( $rsvps, 200 );
	}

	public static function get_event_rsvp( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_REST_Response( array(), 401 );
		}

		$event_id = (int) $request->get_param( 'id' );
		$post = get_post( $event_id );
		if ( ! $post || $post->post_type !== 'tribe_events' ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid event' ), 404 );
		}

		$meta = get_post_meta( $event_id, '_oras_rsvp_v1', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$enabled          = ! empty( $meta['enabled'] );
		$capacity         = absint( $meta['capacity'] ?? 0 );
		$waitlist_enabled = ! empty( $meta['waitlist_enabled'] );

		$yes_count = self::yes_count( $event_id );
		$is_full   = ( $capacity > 0 && $yes_count >= $capacity );

		$current_user_status = get_user_meta( $user_id, '_oras_rsvp_event_' . $event_id, true );
		if ( ! in_array( $current_user_status, array( 'yes', 'no', 'waitlist' ), true ) ) {
			$current_user_status = null;
		}

		$data = array(
			'event_id'           => $event_id,
			'enabled'            => $enabled,
			'capacity'           => $capacity,
			'waitlist_enabled'   => $waitlist_enabled,
			'yes_count'          => $yes_count,
			'is_full'            => $is_full,
			'current_user_status' => $current_user_status,
		);

		return new \WP_REST_Response( $data, 200 );
	}

	private static function yes_count( int $event_id ): int {
		$users = get_users(
			array(
				'meta_key'   => '_oras_rsvp_event_' . $event_id,
				'meta_value' => 'yes',
				'fields'     => 'ID',
			)
		);

		if ( ! is_array( $users ) ) {
			return 0;
		}

		return count( $users );
	}
}