<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Source_Resolver {
	/** @param array<string,mixed> $evidence @param array<string,mixed> $config @return array<string,mixed> */
	public static function resolve( array $evidence, int $target_event_id, array $config ): array {
		if ( $target_event_id <= 0 || (int) ( $evidence['source_event_id'] ?? 0 ) !== $target_event_id ) {
			return self::review( 'Source does not directly identify the active event.' );
		}
		$product_id = (int) ( $evidence['product_id'] ?? 0 );
		$matches    = array();
		foreach ( is_array( $config['options'] ?? null ) ? $config['options'] : array() as $option ) {
			if ( is_array( $option ) && in_array( $product_id, array_map( 'intval', (array) ( $option['source_product_ids'] ?? array() ) ), true ) ) {
				$matches[] = $option;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return self::review( 0 === count( $matches ) ? 'Source product is not explicitly mapped.' : 'Source product has conflicting mappings.' );
		}
		$option         = $matches[0];
		$classification = (string) ( $option['classification'] ?? 'unclassified' );
		$validity       = (string) ( $option['validity_type'] ?? 'unclassified' );
		$eligibility    = Eligibility::evaluate( $evidence, $option );
		$resolution     = 'supported';
		if ( 'family' === $classification ) {
			$resolution = 'unsupported_family';
		} elseif ( 'one_day' === $validity ) {
			$resolution = 'unsupported_one_day';
		} elseif ( 'individual' !== $classification || 'full_event' !== $validity ) {
			$resolution = 'review_required';
		}

		return array(
			'resolution'        => $resolution,
			'classification'    => $classification,
			'validity_type'      => $validity,
			'eligibility'        => $eligibility['state'],
			'payment_label'      => $eligibility['label'],
			'available_for_new'  => ! empty( $option['available_for_new'] ),
			'option_uuid'        => (string) $option['option_uuid'],
			'option'             => $option,
			'reason'             => '',
		);
	}

	/** @return array<string,mixed> */
	private static function review( string $reason ): array {
		return array(
			'resolution'       => 'review_required',
			'classification'   => 'unclassified',
			'validity_type'     => 'unclassified',
			'eligibility'       => 'review_required',
			'payment_label'     => 'Website registration requires review',
			'available_for_new' => false,
			'option_uuid'       => '',
			'option'            => array(),
			'reason'            => $reason,
		);
	}
}
