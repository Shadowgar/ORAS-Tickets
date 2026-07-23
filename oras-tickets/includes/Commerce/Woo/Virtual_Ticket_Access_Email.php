<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Integrations\Zoom\Meeting_Service;
use ORAS\Tickets\Integrations\Zoom\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Virtual_Ticket_Access_Email {

	private const SENT_META_PREFIX = '_oras_virtual_access_email_sent_';
	private const EMAIL_HEADERS = array( 'Content-Type: text/html; charset=UTF-8' );

	private Registration_Service $registrations;
	private Meeting_Service $meetings;

	public function __construct(
		?Registration_Service $registrations = null,
		?Meeting_Service $meetings = null
	) {
		$this->registrations = $registrations ?? new Registration_Service();
		$this->meetings      = $meetings ?? new Meeting_Service();
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_paid_order' ), 25, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_paid_order' ), 25, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_cancelled_order' ), 25, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_cancelled_order' ), 25, 1 );
	}

	/**
	 * @param int|string $order_id
	 */
	public function handle_paid_order( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send_for_order( $order );
	}

	/**
	 * @param int|string $order_id
	 */
	public function handle_cancelled_order( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$email = sanitize_email( (string) $order->get_billing_email() );
		if ( '' === $email ) {
			return;
		}

		foreach ( $this->get_virtual_ticket_event_ids( $order ) as $event_id ) {
			$this->registrations->cancel_entitlement(
				$event_id,
				'ticket',
				$this->source_reference( $order ),
				$email
			);
		}
	}

	/**
	 * @return array<int,bool>
	 */
	public function send_for_order( \WC_Order $order ): array {
		$recipient_email = sanitize_email( (string) $order->get_billing_email() );
		if ( '' === $recipient_email || ! is_email( $recipient_email ) ) {
			return array();
		}

		$results = array();
		foreach ( $this->get_virtual_ticket_event_ids( $order ) as $event_id ) {
			if ( $this->was_sent_for_event( $order, $event_id ) ) {
				continue;
			}

			$access = $this->resolve_access_details( $order, $event_id, $recipient_email );
			$virtual_link = (string) ( $access['join_url'] ?? '' );
			if ( '' === $virtual_link ) {
				$this->log_attempt( $event_id, $recipient_email, $this->build_subject( $event_id ), '', false );
				$results[ $event_id ] = false;
				continue;
			}

			$subject = $this->build_subject( $event_id );
			$body = $this->build_email_body( $order, $event_id, $access );
			$sent = (bool) wp_mail( $recipient_email, $subject, $body, self::EMAIL_HEADERS );
			$this->log_attempt( $event_id, $recipient_email, $subject, $body, $sent );

			if ( $sent ) {
				$order->update_meta_data( self::SENT_META_PREFIX . $event_id, current_time( 'mysql', true ) );
				$order->add_order_note(
					sprintf(
						/* translators: %d: event ID */
						__( 'ORAS virtual access email sent for event #%d.', 'oras-tickets' ),
						$event_id
					)
				);
				$order->save();
			}

			$results[ $event_id ] = $sent;
		}

		return $results;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolve_access_details( \WC_Order $order, int $event_id, string $email ): array {
		$fallback = array(
			'join_url'         => Event_RSVP::get_virtual_join_link( $event_id ),
			'meeting_id'       => '',
			'passcode'         => '',
			'one_tap_mobile'   => array(),
			'local_number_url' => '',
			'managed'          => false,
		);
		if ( ! Registration_Service::is_managed_event( $event_id ) ) {
			return $fallback;
		}

		$registration = $this->registrations->register_attendee(
			$event_id,
			'ticket',
			$this->source_reference( $order ),
			$email,
			(string) $order->get_billing_first_name(),
			(string) $order->get_billing_last_name(),
			absint( $order->get_customer_id() )
		);
		if ( is_wp_error( $registration ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Zoom API error */
					__( 'ORAS Zoom registration fallback used: %s', 'oras-tickets' ),
					$registration->get_error_message()
				)
			);
			return $fallback;
		}

		$invitation = $this->meetings->get_invitation_for_event( $event_id );
		$details = is_wp_error( $invitation ) ? $fallback : array_merge( $fallback, $invitation );
		$details['join_url'] = esc_url_raw( (string) ( $registration['join_url'] ?? '' ) );
		$details['managed']  = true;

		return $details;
	}

	private function source_reference( \WC_Order $order ): string {
		return 'order-' . absint( $order->get_id() );
	}

	/**
	 * @return int[]
	 */
	private function get_virtual_ticket_event_ids( \WC_Order $order ): array {
		$event_ids = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$event_id = absint( $item->get_meta( '_oras_ticket_event_id', true ) );
			if ( $event_id <= 0 ) {
				continue;
			}

			$attendance_mode = Ticket::normalizeAttendanceMode(
				(string) $item->get_meta( '_oras_ticket_attendance_mode', true ),
				Ticket::ATTENDANCE_MODE_ONSITE
			);
			if ( Ticket::ATTENDANCE_MODE_VIRTUAL !== $attendance_mode ) {
				continue;
			}

			$event_ids[ $event_id ] = $event_id;
		}

		return array_values( $event_ids );
	}

	private function was_sent_for_event( \WC_Order $order, int $event_id ): bool {
		return '' !== (string) $order->get_meta( self::SENT_META_PREFIX . $event_id, true );
	}

	private function build_subject( int $event_id ): string {
		return sprintf(
			/* translators: %s: event title */
			__( 'Your virtual event access for %s', 'oras-tickets' ),
			$this->get_event_title( $event_id )
		);
	}

	/**
	 * @param array<string,mixed> $access
	 */
	private function build_email_body( \WC_Order $order, int $event_id, array $access ): string {
		$virtual_link = esc_url_raw( (string) ( $access['join_url'] ?? '' ) );
		$event_url = get_permalink( $event_id );
		if ( ! is_string( $event_url ) || '' === $event_url ) {
			$event_url = home_url();
		}

		$title = $this->get_event_title( $event_id );
		$order_number = (string) $order->get_order_number();

		$html = '<!doctype html><html><body style="margin:0;padding:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif;line-height:1.5;">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7;margin:0;padding:28px 12px;"><tr><td align="center">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d8dee8;box-shadow:0 12px 36px rgba(15,23,42,0.12);">';
		$html .= '<tr><td style="background:#0b1220;padding:28px 32px;color:#ffffff;">';
		$html .= '<div style="font-size:28px;font-weight:800;letter-spacing:0.18em;line-height:1;">ORAS</div>';
		$html .= '<div style="font-size:14px;color:#cbd5e1;margin-top:8px;">' . esc_html__( 'Oil Region Astronomical Society', 'oras-tickets' ) . '</div>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:32px;">';
		$html .= '<h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;color:#0f172a;">' . esc_html__( 'Your virtual event access', 'oras-tickets' ) . '</h1>';
		$html .= '<p style="margin:0 0 24px;font-size:16px;color:#334155;">' . esc_html__( 'Thank you for purchasing a virtual ticket. Your access details are below.', 'oras-tickets' ) . '</p>';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">';
		$html .= $this->detail_row( __( 'Event', 'oras-tickets' ), $title );
		$html .= $this->detail_row( __( 'Date & Time', 'oras-tickets' ), $this->get_event_datetime_text( $event_id ) );
		$html .= $this->detail_row( __( 'Order', 'oras-tickets' ), '#' . $order_number );
		$html .= $this->detail_row( __( 'Virtual access', 'oras-tickets' ), $virtual_link, $virtual_link );
		if ( '' !== (string) ( $access['meeting_id'] ?? '' ) ) {
			$html .= $this->detail_row( __( 'Meeting ID', 'oras-tickets' ), (string) $access['meeting_id'] );
		}
		if ( '' !== (string) ( $access['passcode'] ?? '' ) ) {
			$html .= $this->detail_row( __( 'Passcode', 'oras-tickets' ), (string) $access['passcode'] );
		}
		$one_tap = isset( $access['one_tap_mobile'] ) && is_array( $access['one_tap_mobile'] )
			? array_filter( array_map( 'sanitize_text_field', $access['one_tap_mobile'] ) )
			: array();
		if ( ! empty( $one_tap ) ) {
			$html .= $this->detail_row( __( 'One tap mobile', 'oras-tickets' ), implode( "\n", $one_tap ), '', true );
		}
		$local_number_url = esc_url_raw( (string) ( $access['local_number_url'] ?? '' ) );
		if ( '' !== $local_number_url ) {
			$html .= $this->detail_row( __( 'Local dial-in numbers', 'oras-tickets' ), $local_number_url, $local_number_url );
		}
		$html .= '</table>';
		$html .= '<div style="margin:28px 0 8px;">';
		$html .= '<a href="' . esc_url( $virtual_link ) . '" style="display:inline-block;margin:0 10px 10px 0;padding:13px 18px;border-radius:10px;background:#1e3a8a;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">' . esc_html__( 'Join Virtual Event', 'oras-tickets' ) . '</a>';
		$html .= '<a href="' . esc_url( $event_url ) . '" style="display:inline-block;margin:0 10px 10px 0;padding:13px 18px;border-radius:10px;background:#e2e8f0;color:#0f172a;font-size:15px;font-weight:700;text-decoration:none;">' . esc_html__( 'View Event', 'oras-tickets' ) . '</a>';
		$html .= '</div>';
		$html .= '<p style="margin:22px 0 0;font-size:13px;color:#64748b;">' . esc_html__( 'This access email is sent automatically for paid virtual ticket purchases. Virtual RSVP requests still require board approval.', 'oras-tickets' ) . '</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;">';
		$html .= esc_html__( 'This message was sent by Oil Region Astronomical Society regarding an ORAS event.', 'oras-tickets' );
		$html .= '</td></tr></table></td></tr></table></body></html>';

		return $html;
	}

	private function detail_row( string $label, string $value, string $url = '', bool $multiline = false ): string {
		$html = '<tr>';
		$html .= '<td style="width:34%;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">' . esc_html( $label ) . '</td>';
		$html .= '<td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:15px;">';
		if ( '' !== $url ) {
			$html .= '<a href="' . esc_url( $url ) . '" style="color:#1d4ed8;text-decoration:underline;word-break:break-word;">' . esc_html( $value ) . '</a>';
		} elseif ( $multiline ) {
			$html .= nl2br( esc_html( $value ) );
		} else {
			$html .= esc_html( $value );
		}
		$html .= '</td></tr>';

		return $html;
	}

	private function get_event_title( int $event_id ): string {
		$title = get_the_title( $event_id );
		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			return __( 'ORAS Event', 'oras-tickets' );
		}

		$decoded = wp_specialchars_decode( trim( $title ), ENT_QUOTES );
		$decoded = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$decoded = preg_replace( '/[\r\n\t ]+/', ' ', $decoded );

		return is_string( $decoded ) && '' !== trim( $decoded ) ? trim( $decoded ) : __( 'ORAS Event', 'oras-tickets' );
	}

	private function get_event_datetime_text( int $event_id ): string {
		if ( function_exists( 'tribe_get_start_date' ) && function_exists( 'tribe_get_end_date' ) ) {
			$start = tribe_get_start_date( $event_id, true, 'F j, Y g:i A' );
			$end = tribe_get_end_date( $event_id, true, 'F j, Y g:i A T' );
			if ( is_string( $start ) && '' !== $start ) {
				return is_string( $end ) && '' !== $end ? $start . ' - ' . $end : $start;
			}
		}

		$start_raw = get_post_meta( $event_id, '_EventStartDate', true );
		if ( is_string( $start_raw ) && '' !== $start_raw ) {
			return $start_raw;
		}

		return __( 'See event page for schedule details.', 'oras-tickets' );
	}

	private function log_attempt( int $event_id, string $recipient_email, string $subject, string $body, bool $sent ): void {
		if ( ! class_exists( Communication_Log_Store::class ) ) {
			return;
		}

		Communication_Log_Store::insert(
			array(
				'event_id'               => $event_id,
				'sender_user_id'         => 0,
				'sender_display_name'    => __( 'System', 'oras-tickets' ),
				'sender_email'           => '',
				'recipient_segment'      => 'virtual_ticket_purchaser',
				'recipient_count'        => 1,
				'email_subject'          => $subject,
				'email_body_snapshot'    => $body,
				'sent_at'                => current_time( 'mysql', true ),
				'send_status'            => $sent ? 'sent' : 'failed',
				'failed_recipient_count' => $sent ? 0 : 1,
				'related_action_type'    => 'virtual_ticket_access',
			)
		);
	}
}
