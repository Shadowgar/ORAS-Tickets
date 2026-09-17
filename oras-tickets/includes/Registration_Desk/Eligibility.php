<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Eligibility {
	/** @param array<string,mixed> $evidence @param array<string,mixed> $option @return array{state:string,label:string} */
	public static function evaluate( array $evidence, array $option ): array {
		if ( empty( $option['existing_access_valid'] ) ) {
			return array( 'state' => 'revoked', 'label' => 'Access revoked by Registration Desk configuration' );
		}
		$quantity = max( 1, (int) ( $evidence['quantity'] ?? 1 ) );
		$refunded = max( 0, (int) ( $evidence['refunded_quantity'] ?? 0 ) );
		if ( $refunded >= $quantity ) {
			return array( 'state' => 'revoked', 'label' => 'Website records this registration as fully refunded' );
		}
		if ( $refunded > 0 ) {
			return array( 'state' => 'review_required', 'label' => 'Website records a partial refund; covered attendee is ambiguous' );
		}

		$status = sanitize_key( (string) ( $evidence['order_status'] ?? '' ) );
		return match ( $status ) {
			'processing' => array( 'state' => 'eligible', 'label' => 'Website order: Processing' ),
			'completed'  => array( 'state' => 'eligible', 'label' => 'Website order: Completed' ),
			'on-hold'    => array( 'state' => 'explicit_unpaid_required', 'label' => 'Website order: On hold — payment not confirmed' ),
			'pending'    => array( 'state' => 'not_active', 'label' => 'Website order: Pending payment' ),
			'failed'     => array( 'state' => 'not_active', 'label' => 'Website order: Failed' ),
			'cancelled'  => array( 'state' => 'revoked', 'label' => 'Website order: Cancelled' ),
			'refunded'   => array( 'state' => 'revoked', 'label' => 'Website order: Refunded' ),
			default      => array( 'state' => 'review_required', 'label' => 'Website order status requires review' ),
		};
	}
}
