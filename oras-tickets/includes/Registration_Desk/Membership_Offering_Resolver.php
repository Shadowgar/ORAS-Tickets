<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves event-sale membership choices from the current PMPro level source. */
final class Membership_Offering_Resolver {
	/** @return array<int,array<string,mixed>> */
	public static function all(): array {
		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			return array();
		}
		$offerings = array();
		$levels    = pmpro_getAllLevels( true, true );
		foreach ( is_array( $levels ) ? $levels : array() as $level ) {
			if ( ! is_object( $level ) ) {
				continue;
			}
			$offering = self::from_level( $level );
			if ( null !== $offering ) {
				$offerings[] = $offering;
			}
		}

		return $offerings;
	}

	/** @return array<string,mixed>|null */
	public static function resolve( int $level_id ): ?array {
		if ( $level_id <= 0 || ! function_exists( 'pmpro_getLevel' ) ) {
			return null;
		}
		$level = pmpro_getLevel( $level_id );

		return is_object( $level ) ? self::from_level( $level ) : null;
	}

	/** @return array<string,mixed>|null */
	private static function from_level( object $level ): ?array {
		$level_id = absint( $level->id ?? 0 );
		$name     = sanitize_text_field( (string) ( $level->name ?? '' ) );
		if ( $level_id <= 0 || '' === $name ) {
			return null;
		}
		$initial   = (float) ( $level->initial_payment ?? 0 );
		$recurring = (float) ( $level->billing_amount ?? 0 );
		$price     = $initial > 0 ? $initial : $recurring;
		$url       = function_exists( 'pmpro_url' ) ? pmpro_url( 'checkout', '?level=' . $level_id ) : '';

		return array(
			'level_id'          => $level_id,
			'display_name'      => $name,
			'price'             => number_format( $price, 2, '.', '' ),
			'checkout_url'      => esc_url_raw( (string) $url ),
			'initial_payment'   => number_format( $initial, 2, '.', '' ),
			'billing_amount'    => number_format( $recurring, 2, '.', '' ),
			'cycle_number'      => absint( $level->cycle_number ?? 0 ),
			'cycle_period'      => sanitize_text_field( (string) ( $level->cycle_period ?? '' ) ),
			'billing_limit'     => absint( $level->billing_limit ?? 0 ),
			'expiration_number' => absint( $level->expiration_number ?? 0 ),
			'expiration_period' => sanitize_text_field( (string) ( $level->expiration_period ?? '' ) ),
			'period_label'      => self::period_label( $level ),
		);
	}

	private static function period_label( object $level ): string {
		$expiration_number = absint( $level->expiration_number ?? 0 );
		$expiration_period = sanitize_text_field( (string) ( $level->expiration_period ?? '' ) );
		if ( $expiration_number > 0 && '' !== $expiration_period ) {
			return sprintf( 'Expires after %d %s', $expiration_number, self::pluralize( $expiration_period, $expiration_number ) );
		}
		$cycle_number = absint( $level->cycle_number ?? 0 );
		$cycle_period = sanitize_text_field( (string) ( $level->cycle_period ?? '' ) );
		if ( (float) ( $level->billing_amount ?? 0 ) > 0 && $cycle_number > 0 && '' !== $cycle_period ) {
			return sprintf( 'Every %d %s', $cycle_number, self::pluralize( $cycle_period, $cycle_number ) );
		}

		return 'One-time membership';
	}

	private static function pluralize( string $period, int $number ): string {
		$period = ucfirst( strtolower( $period ) );

		return 1 === $number ? $period : $period . 's';
	}
}
