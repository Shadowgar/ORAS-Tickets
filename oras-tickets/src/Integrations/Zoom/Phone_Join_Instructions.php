<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Phone_Join_Instructions {

	/**
	 * @return array{phone_number:string,display_number:string,location:string,tel_uri:string}|array{}
	 */
	public static function parse_one_tap_line( string $line ): array {
		$line = sanitize_text_field( $line );
		if ( ! preg_match( '/^(\+\d{10,15})((?:[,#*]|\d)+)(?:\s+(.+))?$/', $line, $matches ) ) {
			return array();
		}

		$phone_number = (string) $matches[1];
		$dial_suffix  = (string) $matches[2];
		$location     = isset( $matches[3] ) ? trim( sanitize_text_field( (string) $matches[3] ) ) : '';

		if ( ! preg_match( '/^,,\d{9,12}#(?:,,,,\*\d{4,10}#)?$/', $dial_suffix ) ) {
			return array();
		}

		return array(
			'phone_number'   => $phone_number,
			'display_number' => self::format_phone_number( $phone_number ),
			'location'       => $location,
			'tel_uri'        => 'tel:' . $phone_number . $dial_suffix,
		);
	}

	/**
	 * @param array<string,mixed> $access
	 */
	public static function render_email_html( array $access ): string {
		$one_tap = isset( $access['one_tap_mobile'] ) && is_array( $access['one_tap_mobile'] )
			? $access['one_tap_mobile']
			: array();
		$entries = array();
		foreach ( $one_tap as $line ) {
			$entry = self::parse_one_tap_line( (string) $line );
			if ( ! empty( $entry ) ) {
				$entries[] = $entry;
			}
		}

		if ( empty( $entries ) ) {
			$fallback_lines = array_filter( array_map( 'sanitize_text_field', $one_tap ) );
			if ( empty( $fallback_lines ) ) {
				return '';
			}

			$html = '<div style="margin:0 0 24px;padding:20px;border-radius:14px;background:#f8fafc;border:1px solid #dbe3ee;">';
			$html .= '<h2 style="margin:0 0 8px;font-size:17px;line-height:1.3;color:#0f172a;">' . esc_html__( 'Phone dial-in information', 'oras-tickets' ) . '</h2>';
			$html .= '<p style="margin:0 0 10px;font-size:15px;color:#334155;">' . esc_html__( 'Zoom provided the following telephone details. If you need assistance joining, contact the event organizer.', 'oras-tickets' ) . '</p>';
			$html .= '<div style="font-size:15px;color:#0f172a;">' . nl2br( esc_html( implode( "\n", $fallback_lines ) ) ) . '</div>';
			$html .= '</div>';

			return $html;
		}

		$meeting_id     = preg_replace( '/\D+/', '', (string) ( $access['meeting_id'] ?? '' ) );
		$phone_passcode = preg_replace( '/\D+/', '', (string) ( $access['phone_passcode'] ?? '' ) );
		$meeting_id     = is_string( $meeting_id ) ? $meeting_id : '';
		$phone_passcode = is_string( $phone_passcode ) ? $phone_passcode : '';

		$html = '<div style="margin:0 0 24px;padding:20px;border-radius:14px;background:#f8fafc;border:1px solid #dbe3ee;">';
		$html .= '<h2 style="margin:0 0 8px;font-size:19px;line-height:1.3;color:#0f172a;">' . esc_html__( 'Join by mobile phone', 'oras-tickets' ) . '</h2>';
		$html .= '<p style="margin:0 0 16px;font-size:15px;color:#334155;">' . esc_html__( 'Tap one of the buttons below. Your phone will dial Zoom and enter the meeting information automatically.', 'oras-tickets' ) . '</p>';

		foreach ( $entries as $entry ) {
			$label = sprintf(
				/* translators: 1: telephone number, 2: dial-in location */
				__( 'Call %1$s%2$s', 'oras-tickets' ),
				$entry['display_number'],
				'' !== $entry['location'] ? ' - ' . $entry['location'] : ''
			);
			$html .= '<a href="' . esc_attr( $entry['tel_uri'] ) . '" style="display:inline-block;margin:0 10px 10px 0;padding:12px 16px;border-radius:10px;background:#1e3a8a;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">' . esc_html( $label ) . '</a>';
		}

		$html .= '<div style="margin:14px 0 0;padding-top:18px;border-top:1px solid #dbe3ee;">';
		$html .= '<h2 style="margin:0 0 8px;font-size:17px;line-height:1.3;color:#0f172a;">' . esc_html__( 'Calling from a landline or dialing manually', 'oras-tickets' ) . '</h2>';
		$html .= '<ol style="margin:0;padding-left:22px;color:#334155;font-size:15px;">';
		$html .= '<li style="margin:0 0 8px;">' . esc_html__( 'Dial one of these Zoom telephone numbers:', 'oras-tickets' );
		$html .= '<ul style="margin:6px 0 0;padding-left:20px;">';
		foreach ( $entries as $entry ) {
			$number_text = $entry['display_number'];
			if ( '' !== $entry['location'] ) {
				$number_text .= ' - ' . $entry['location'];
			}
			$html .= '<li style="margin:0 0 4px;">' . esc_html( $number_text ) . '</li>';
		}
		$html .= '</ul></li>';
		$html .= '<li style="margin:0 0 8px;">' . sprintf(
			/* translators: %s: Zoom meeting ID */
			esc_html__( 'When prompted, enter the Meeting ID: %s, then press #.', 'oras-tickets' ),
			'<strong>' . esc_html( $meeting_id ) . '</strong>'
		) . '</li>';
		$html .= '<li style="margin:0 0 8px;">' . __( 'When asked for a participant ID, press <strong>#</strong> to skip it.', 'oras-tickets' ) . '</li>';
		$html .= '<li style="margin:0;">' . sprintf(
			/* translators: %s: numeric Zoom telephone passcode */
			esc_html__( 'When prompted, enter the Phone Passcode: %s, then press #.', 'oras-tickets' ),
			'<strong>' . esc_html( $phone_passcode ) . '</strong>'
		) . '</li>';
		$html .= '</ol></div></div>';

		$local_number_url = esc_url_raw( (string) ( $access['local_number_url'] ?? '' ) );
		if ( '' !== $local_number_url ) {
			$html .= '<p style="margin:-10px 0 24px;font-size:14px;color:#475569;">';
			$html .= '<a href="' . esc_attr( $local_number_url ) . '" style="color:#1d4ed8;text-decoration:underline;">' . esc_html__( 'Find another local Zoom telephone number', 'oras-tickets' ) . '</a>';
			$html .= '</p>';
		}

		return $html;
	}

	private static function format_phone_number( string $phone_number ): string {
		if ( preg_match( '/^\+1(\d{3})(\d{3})(\d{4})$/', $phone_number, $matches ) ) {
			return sprintf( '+1 (%s) %s-%s', $matches[1], $matches[2], $matches[3] );
		}

		return $phone_number;
	}
}
