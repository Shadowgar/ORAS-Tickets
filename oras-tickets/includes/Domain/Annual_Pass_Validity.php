<?php

namespace ORAS\Tickets\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Annual_Pass_Validity {
	public const STATUS_ACTIVE        = 'active';
	public const STATUS_EXPIRING_SOON = 'expiring_soon';
	public const STATUS_EXPIRED       = 'expired';
	public const STATUS_DATE_MISSING  = 'date_missing';

	public static function expiration_for( \DateTimeImmutable $start_date ): \DateTimeImmutable {
		$local_date = $start_date->setTime( 0, 0, 0 );
		$next_year  = (int) $local_date->format( 'Y' ) + 1;
		$month      = (int) $local_date->format( 'n' );
		$day        = (int) $local_date->format( 'j' );

		if ( 2 === $month && 29 === $day && ! checkdate( 2, 29, $next_year ) ) {
			return $local_date->setDate( $next_year, 3, 1 );
		}

		return $local_date->setDate( $next_year, $month, $day );
	}

	/**
	 * @return array{status:string,is_valid:bool}
	 */
	public static function state_for( ?\DateTimeImmutable $expiration_date, \DateTimeImmutable $today ): array {
		if ( null === $expiration_date ) {
			return array(
				'status'   => self::STATUS_DATE_MISSING,
				'is_valid' => false,
			);
		}

		$today      = $today->setTimezone( $expiration_date->getTimezone() )->setTime( 0, 0, 0 );
		$expiration = $expiration_date->setTime( 0, 0, 0 );
		if ( $today >= $expiration ) {
			return array(
				'status'   => self::STATUS_EXPIRED,
				'is_valid' => false,
			);
		}

		return array(
			'status'   => $today >= $expiration->modify( '-30 days' ) ? self::STATUS_EXPIRING_SOON : self::STATUS_ACTIVE,
			'is_valid' => true,
		);
	}
}
