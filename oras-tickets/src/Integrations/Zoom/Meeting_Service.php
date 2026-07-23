<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Meeting_Service {

	private const EVENT_CONFIG_META = '_oras_zoom_integration_v1';

	private Api_Interface $api;

	public function __construct( ?Api_Interface $api = null ) {
		$this->api = $api ?? new Api_Client();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_invitation_for_event( int $event_id ) {
		$meeting_id = self::resolve_meeting_id( $event_id );
		if ( '' === $meeting_id ) {
			return new \WP_Error(
				'oras_zoom_missing_meeting_id',
				__( 'This event is not mapped to a Zoom meeting.', 'oras-tickets' )
			);
		}

		$response = $this->api->get_meeting_invitation( $meeting_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed               = self::parse_invitation( (string) ( $response['invitation'] ?? '' ) );
		$parsed['meeting_id'] = '' !== $parsed['meeting_id'] ? $parsed['meeting_id'] : $meeting_id;
		$parsed['raw']        = (string) ( $response['invitation'] ?? '' );

		return $parsed;
	}

	/**
	 * Configure and verify that attendees can enter without a host at any time.
	 *
	 * @return array{meeting_id:string,join_before_host:bool,jbh_time:int,waiting_room:bool}|\WP_Error
	 */
	public function configure_unattended_access( int $event_id ) {
		$meeting_id = self::resolve_meeting_id( $event_id );
		if ( '' === $meeting_id ) {
			return new \WP_Error(
				'oras_zoom_missing_meeting_id',
				__( 'This event is not mapped to a Zoom meeting.', 'oras-tickets' )
			);
		}

		return $this->configure_unattended_access_for_meeting( $meeting_id );
	}

	/**
	 * @return array{meeting_id:string,join_before_host:bool,jbh_time:int,waiting_room:bool}|\WP_Error
	 */
	public function configure_unattended_access_for_meeting( string $meeting_id ) {
		$meeting_id = self::normalize_meeting_id( $meeting_id );
		if ( '' === $meeting_id ) {
			return new \WP_Error(
				'oras_zoom_invalid_meeting_id',
				__( 'The Zoom meeting ID is invalid.', 'oras-tickets' )
			);
		}

		$required_settings = array(
			'join_before_host' => true,
			'jbh_time'         => 0,
			'waiting_room'     => false,
		);
		$updated = $this->api->update_meeting( $meeting_id, $required_settings );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$meeting = $this->api->get_meeting( $meeting_id );
		if ( is_wp_error( $meeting ) ) {
			return $meeting;
		}

		$settings = isset( $meeting['settings'] ) && is_array( $meeting['settings'] )
			? $meeting['settings']
			: array();
		$verified = array_key_exists( 'join_before_host', $settings )
			&& true === (bool) $settings['join_before_host']
			&& array_key_exists( 'jbh_time', $settings )
			&& 0 === (int) $settings['jbh_time']
			&& array_key_exists( 'waiting_room', $settings )
			&& false === (bool) $settings['waiting_room'];
		if ( ! $verified ) {
			return new \WP_Error(
				'oras_zoom_unattended_settings_not_applied',
				__(
					'Zoom did not apply unattended access. Check whether account-level Waiting Room or join-before-host settings are locked.',
					'oras-tickets'
				)
			);
		}

		return array(
			'meeting_id'       => $meeting_id,
			'join_before_host' => true,
			'jbh_time'         => 0,
			'waiting_room'     => false,
		);
	}

	public static function resolve_meeting_id( int $event_id ): string {
		if ( $event_id <= 0 ) {
			return '';
		}

		$config = get_post_meta( $event_id, self::EVENT_CONFIG_META, true );
		if ( is_array( $config ) && ! empty( $config['meeting_id'] ) ) {
			$meeting_id = self::normalize_meeting_id( (string) $config['meeting_id'] );
			if ( '' !== $meeting_id ) {
				return $meeting_id;
			}
		}

		foreach (
			array(
				'_tribe_events_zoom_meeting_id',
				'_EventZoomMeetingID',
				'_EventZoomMeetingId',
			) as $key
		) {
			$meeting_id = self::normalize_meeting_id( (string) get_post_meta( $event_id, $key, true ) );
			if ( '' !== $meeting_id ) {
				return $meeting_id;
			}
		}

		foreach (
			array(
				'_tribe_events_zoom_join_url',
				'_EventZoomJoinURL',
				'_EventZoomMeetingLink',
				'_EventVirtualURL',
			) as $key
		) {
			$meeting_id = self::extract_meeting_id_from_url( (string) get_post_meta( $event_id, $key, true ) );
			if ( '' !== $meeting_id ) {
				return $meeting_id;
			}
		}

		return '';
	}

	public static function extract_meeting_id_from_url( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );
		if ( 'https' !== $scheme || ( 'zoom.us' !== $host && ! str_ends_with( $host, '.zoom.us' ) ) ) {
			return '';
		}

		if ( ! preg_match( '#/(?:j|wc)/(\d{9,12})(?:/|$)#', $path, $matches ) ) {
			return '';
		}

		return self::normalize_meeting_id( (string) $matches[1] );
	}

	/**
	 * @return array{
	 *   join_url:string,
	 *   meeting_id:string,
	 *   passcode:string,
	 *   one_tap_mobile:string[],
	 *   local_number_url:string
	 * }
	 */
	public static function parse_invitation( string $invitation ): array {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $invitation );
		$lines      = array_map( 'trim', explode( "\n", $normalized ) );
		$result     = array(
			'join_url'         => '',
			'meeting_id'       => '',
			'passcode'         => '',
			'one_tap_mobile'   => array(),
			'local_number_url' => '',
		);
		$in_one_tap = false;

		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				if ( $in_one_tap && ! empty( $result['one_tap_mobile'] ) ) {
					$in_one_tap = false;
				}
				continue;
			}

			if ( 0 === stripos( $line, 'Meeting ID:' ) ) {
				$result['meeting_id'] = self::normalize_meeting_id( substr( $line, strlen( 'Meeting ID:' ) ) );
				continue;
			}

			if ( 0 === stripos( $line, 'Passcode:' ) ) {
				$result['passcode'] = sanitize_text_field( substr( $line, strlen( 'Passcode:' ) ) );
				continue;
			}

			if ( 0 === strcasecmp( $line, 'One tap mobile' ) ) {
				$in_one_tap = true;
				continue;
			}

			if ( $in_one_tap && preg_match( '/^\+\d[\d,#+* ]+/', $line ) ) {
				$result['one_tap_mobile'][] = sanitize_text_field( $line );
				continue;
			}

			if ( preg_match( '#Find your local number:\s*(https://\S+)#i', $line, $matches ) ) {
				$result['local_number_url'] = self::normalize_zoom_url( (string) $matches[1] );
				continue;
			}

			if ( '' === $result['join_url'] && 0 === strpos( $line, 'https://' ) ) {
				$meeting_id = self::extract_meeting_id_from_url( $line );
				if ( '' !== $meeting_id ) {
					$result['join_url'] = self::normalize_zoom_url( $line );
				}
			}
		}

		return $result;
	}

	private static function normalize_meeting_id( string $meeting_id ): string {
		$digits = preg_replace( '/\D+/', '', $meeting_id );
		if ( ! is_string( $digits ) || ! preg_match( '/^\d{9,12}$/', $digits ) ) {
			return '';
		}

		return $digits;
	}

	private static function normalize_zoom_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| ( 'zoom.us' !== $host && ! str_ends_with( $host, '.zoom.us' ) )
		) {
			return '';
		}

		return esc_url_raw( trim( $url ) );
	}
}
