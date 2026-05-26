<?php

namespace ORAS\Tickets\Reporting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Contact_Normalizer {

	/**
	 * @return array<string,string>
	 */
	public static function empty_contact(): array {
		return array(
			'name'            => '',
			'first_name'      => '',
			'last_name'       => '',
			'email'           => '',
			'phone'           => '',
			'address_1'       => '',
			'address_2'       => '',
			'city'            => '',
			'state'           => '',
			'postcode'        => '',
			'country'         => '',
			'address_summary' => '',
			'note'            => '',
		);
	}

	/**
	 * @param \WC_Order $order
	 * @return array<string,string>
	 */
	public static function from_order( \WC_Order $order ): array {
		$contact = self::empty_contact();

		$contact['first_name'] = self::clean_text( $order->get_billing_first_name() );
		$contact['last_name']  = self::clean_text( $order->get_billing_last_name() );
		$contact['name']       = self::build_name( $contact['first_name'], $contact['last_name'] );
		$contact['email']      = sanitize_email( (string) $order->get_billing_email() );
		$contact['phone']      = self::clean_text( $order->get_billing_phone() );
		$contact['address_1']  = self::clean_text( $order->get_billing_address_1() );
		$contact['address_2']  = self::clean_text( $order->get_billing_address_2() );
		$contact['city']       = self::clean_text( $order->get_billing_city() );
		$contact['state']      = self::clean_text( $order->get_billing_state() );
		$contact['postcode']   = self::clean_text( $order->get_billing_postcode() );
		$contact['country']    = self::clean_text( $order->get_billing_country() );
		$contact['address_summary'] = self::build_address_summary( $contact );

		if ( $contact['name'] === '' ) {
			$contact['name'] = $contact['email'];
		}

		return $contact;
	}

	/**
	 * @return array<string,string>
	 */
	public static function from_user( int $user_id ): array {
		$contact = self::empty_contact();
		if ( $user_id <= 0 ) {
			return $contact;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return $contact;
		}

		$contact['first_name'] = self::clean_text( (string) get_user_meta( $user_id, 'first_name', true ) );
		$contact['last_name']  = self::clean_text( (string) get_user_meta( $user_id, 'last_name', true ) );
		$contact['name']       = self::build_name( $contact['first_name'], $contact['last_name'] );
		if ( $contact['name'] === '' ) {
			$contact['name'] = self::clean_text( $user->display_name );
		}
		$contact['email']      = sanitize_email( (string) $user->user_email );
		$contact['phone']      = self::clean_text( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		$contact['address_1']  = self::clean_text( (string) get_user_meta( $user_id, 'billing_address_1', true ) );
		$contact['address_2']  = self::clean_text( (string) get_user_meta( $user_id, 'billing_address_2', true ) );
		$contact['city']       = self::clean_text( (string) get_user_meta( $user_id, 'billing_city', true ) );
		$contact['state']      = self::clean_text( (string) get_user_meta( $user_id, 'billing_state', true ) );
		$contact['postcode']   = self::clean_text( (string) get_user_meta( $user_id, 'billing_postcode', true ) );
		$contact['country']    = self::clean_text( (string) get_user_meta( $user_id, 'billing_country', true ) );
		$contact['address_summary'] = self::build_address_summary( $contact );

		return $contact;
	}

	/**
	 * @param array<string,mixed> $contact
	 * @return array<string,string>
	 */
	public static function from_rsvp_contact( array $contact, int $user_id ): array {
		$fallback = self::from_user( $user_id );
		$normalized = self::empty_contact();

		foreach ( $normalized as $key => $value ) {
			$raw = isset( $contact[ $key ] ) && is_scalar( $contact[ $key ] ) ? (string) $contact[ $key ] : '';
			$normalized[ $key ] = 'email' === $key ? sanitize_email( $raw ) : self::clean_textarea_for_note( $key, $raw );
			if ( $normalized[ $key ] === '' && isset( $fallback[ $key ] ) ) {
				$normalized[ $key ] = $fallback[ $key ];
			}
		}

		$normalized['name'] = self::build_name( $normalized['first_name'], $normalized['last_name'] );
		if ( $normalized['name'] === '' ) {
			$normalized['name'] = $fallback['name'];
		}
		if ( $normalized['email'] === '' ) {
			$normalized['email'] = $fallback['email'];
		}
		$normalized['address_summary'] = self::build_address_summary( $normalized );

		return $normalized;
	}

	/**
	 * @param array<string,string> $contact
	 */
	private static function build_address_summary( array $contact ): string {
		$city_region = trim(
			implode(
				', ',
				array_filter(
					array(
						$contact['city'] ?? '',
						$contact['state'] ?? '',
					)
				)
			)
		);

		return trim(
			implode(
				' ',
				array_filter(
					array(
						$contact['address_1'] ?? '',
						$contact['address_2'] ?? '',
						$city_region,
						$contact['postcode'] ?? '',
						$contact['country'] ?? '',
					)
				)
			)
		);
	}

	private static function build_name( string $first_name, string $last_name ): string {
		return trim( $first_name . ' ' . $last_name );
	}

	private static function clean_text( string $value ): string {
		return sanitize_text_field( wp_unslash( $value ) );
	}

	private static function clean_textarea_for_note( string $key, string $value ): string {
		if ( 'note' === $key ) {
			return sanitize_textarea_field( wp_unslash( $value ) );
		}

		return self::clean_text( $value );
	}
}
