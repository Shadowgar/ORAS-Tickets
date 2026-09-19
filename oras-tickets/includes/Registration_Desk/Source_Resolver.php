<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Source_Resolver {
	/** @param array<string,mixed> $evidence @param array<string,mixed> $config */
	public static function matches_configured_source( array $evidence, int $target_event_id, array $config ): bool {
		$resolution = self::resolve( $evidence, $target_event_id, $config );

		return 'Source product or event is not explicitly mapped.' !== (string) $resolution['reason'];
	}

	/** @param array<string,mixed> $evidence @param array<string,mixed> $config @return array<string,mixed> */
	public static function resolve( array $evidence, int $target_event_id, array $config ): array {
		if ( $target_event_id <= 0 ) {
			return self::review( 'The target event is invalid.' );
		}
		$product_id      = (int) ( $evidence['product_id'] ?? 0 );
		$source_event_id = (int) ( $evidence['source_event_id'] ?? 0 );
		$matches         = array();
		if ( $source_event_id === $target_event_id ) {
			$canonical = Event_Offering_Resolver::access_option_for_product( $target_event_id, $config, $product_id );
			if ( null !== $canonical ) {
				$matches[] = $canonical;
			}
		} else {
			foreach ( is_array( $config['entitlements'] ?? null ) ? $config['entitlements'] : array() as $entitlement ) {
				if ( ! is_array( $entitlement ) || $product_id !== (int) ( $entitlement['source_product_id'] ?? 0 ) || $source_event_id !== (int) ( $entitlement['source_event_id'] ?? 0 ) ) {
					continue;
				}
				$entitlement['option_uuid']           = Event_Offering_Resolver::option_uuid( $target_event_id, 'entitlement:' . (string) $entitlement['entitlement_uuid'] );
				$entitlement['available_for_new']     = false;
				$entitlement['existing_access_valid'] = true;
				$entitlement['label']                 = sanitize_text_field( (string) ( $evidence['item_label'] ?? 'Cross-event entitlement' ) );
				$matches[] = $entitlement;
			}
		}
		if ( empty( $matches ) ) {
			foreach ( is_array( $config['options'] ?? null ) ? $config['options'] : array() as $legacy ) {
				if ( ! is_array( $legacy ) || ! in_array( $product_id, array_map( 'intval', (array) ( $legacy['source_product_ids'] ?? array() ) ), true ) ) {
					continue;
				}
				$events = array_values( array_filter( array_map( 'intval', (array) ( $legacy['source_event_ids'] ?? array() ) ) ) );
				if ( ( empty( $events ) && $source_event_id === $target_event_id ) || in_array( $source_event_id, $events, true ) ) {
					$matches[] = $legacy;
				}
			}
		}
		if ( 1 !== count( $matches ) ) {
			return self::review( 0 === count( $matches ) ? 'Source product or event is not explicitly mapped.' : 'Source product has conflicting mappings.' );
		}
		$option         = $matches[0];
		$classification = (string) ( $option['classification'] ?? 'individual' );
		$validity       = (string) ( $option['validity_type'] ?? 'full_event' );
		$eligibility    = Eligibility::evaluate( $evidence, $option );
		$valid_local_date = sanitize_text_field( (string) ( $option['valid_local_date'] ?? '' ) );
		$resolution       = 'supported';
		if ( ! in_array( $classification, array( 'individual', 'family' ), true ) || ! in_array( $validity, array( 'full_event', 'one_day' ), true ) ) {
			$resolution = 'review_required';
		} elseif ( 'one_day' === $validity && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valid_local_date ) ) {
			$resolution = 'review_required';
		}

		return array(
			'resolution'        => $resolution,
			'classification'    => $classification,
			'validity_type'     => $validity,
			'eligibility'       => $eligibility['state'],
			'payment_label'     => $eligibility['label'],
			'available_for_new' => ! empty( $option['available_for_new'] ),
			'option_uuid'       => (string) $option['option_uuid'],
			'valid_local_date'  => $valid_local_date,
			'max_attendees'     => max( 1, min( 20, (int) ( $option['max_attendees'] ?? 1 ) ) ),
			'option'            => $option,
			'reason'            => '',
		);
	}

	/** @return array<string,mixed> */
	private static function review( string $reason ): array {
		return array(
			'resolution'        => 'review_required',
			'classification'    => 'unclassified',
			'validity_type'     => 'unclassified',
			'eligibility'       => 'review_required',
			'payment_label'     => 'Website registration requires review',
			'available_for_new' => false,
			'option_uuid'       => '',
			'valid_local_date'  => '',
			'max_attendees'     => 1,
			'option'            => array(),
			'reason'            => $reason,
		);
	}
}
