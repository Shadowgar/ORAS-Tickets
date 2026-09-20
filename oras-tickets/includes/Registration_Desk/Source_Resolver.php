<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;
use ORAS\Tickets\Domain\Included_Event_Access;

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
		$source_kind     = '';
		if ( $source_event_id === $target_event_id ) {
			$canonical = Event_Offering_Resolver::access_option_for_product( $target_event_id, $config, $product_id );
			if ( null !== $canonical ) {
				$matches[] = $canonical;
				$source_kind = 'direct';
			}
		} else {
			$snapshot = Included_Event_Access::normalize_snapshot( $evidence['event_access'] ?? array() );
			if ( Included_Event_Access::includes( $snapshot, $target_event_id ) ) {
				$matches[]   = self::included_option( $evidence, $snapshot, $target_event_id );
				$source_kind = 'included_event';
			} else {
				foreach ( is_array( $config['entitlements'] ?? null ) ? $config['entitlements'] : array() as $entitlement ) {
					if ( ! is_array( $entitlement ) || $product_id !== (int) ( $entitlement['source_product_id'] ?? 0 ) || $source_event_id !== (int) ( $entitlement['source_event_id'] ?? 0 ) ) {
						continue;
					}
					$entitlement['option_uuid']           = Event_Offering_Resolver::option_uuid( $target_event_id, 'entitlement:' . (string) $entitlement['entitlement_uuid'] );
					$entitlement['available_for_new']     = false;
					$entitlement['existing_access_valid'] = true;
					$entitlement['label']                 = sanitize_text_field( (string) ( $evidence['item_label'] ?? 'Legacy cross-event access' ) );
					$matches[] = $entitlement;
					$source_kind = 'legacy_cross_event';
				}
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
					$source_kind = $source_event_id === $target_event_id ? 'direct' : 'legacy_cross_event';
				}
			}
		}
		if ( 1 !== count( $matches ) ) {
			return self::review( 0 === count( $matches ) ? 'Source product or event is not explicitly mapped.' : 'Source product has conflicting mappings.' );
		}
		$option         = $matches[0];
		$snapshot       = Included_Event_Access::normalize_snapshot( $evidence['event_access'] ?? array() );
		$primary        = is_array( $snapshot['primary_event'] ?? null ) ? $snapshot['primary_event'] : array();
		$snapshot_ticket = is_array( $snapshot['ticket'] ?? null ) ? $snapshot['ticket'] : array();
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
			'resolution'         => $resolution,
			'classification'     => $classification,
			'validity_type'      => $validity,
			'eligibility'        => $eligibility['state'],
			'payment_label'      => $eligibility['label'],
			'available_for_new'  => ! empty( $option['available_for_new'] ),
			'option_uuid'        => (string) $option['option_uuid'],
			'valid_local_date'   => $valid_local_date,
			'max_attendees'      => max( 1, min( 20, (int) ( $option['max_attendees'] ?? 1 ) ) ),
			'option'             => $option,
			'source_kind'        => $source_kind,
			'source_event_title' => sanitize_text_field( (string) ( $primary['title'] ?? '' ) ),
			'source_event_date'  => sanitize_text_field( (string) ( $primary['date'] ?? '' ) ),
			'source_ticket_name' => sanitize_text_field( (string) ( $snapshot_ticket['name'] ?? ( $evidence['item_label'] ?? '' ) ) ),
			'target_event'       => self::snapshot_event( $snapshot, $target_event_id ),
			'reason'             => '',
		);
	}

	/** @param array<string,mixed> $evidence @param array<string,mixed> $snapshot @return array<string,mixed> */
	private static function included_option( array $evidence, array $snapshot, int $target_event_id ): array {
		$source_event_id = (int) ( $evidence['source_event_id'] ?? 0 );
		$product_id      = (int) ( $evidence['product_id'] ?? 0 );
		$ticket          = is_array( $snapshot['ticket'] ?? null ) ? $snapshot['ticket'] : array();
		$ticket_key      = sanitize_text_field( (string) ( $ticket['ticket_key'] ?? '' ) );
		$option          = null;
		if ( class_exists( Config::class ) && $source_event_id > 0 ) {
			$source_config = Config::get_event_config( $source_event_id );
			$option        = Event_Offering_Resolver::access_option_for_product( $source_event_id, $source_config, $product_id );
		}
		if ( ! is_array( $option ) ) {
			$option = array(
				'classification'   => 'individual',
				'validity_type'    => 'full_event',
				'valid_local_date' => '',
				'max_attendees'    => 1,
			);
		}
		$identity = '' !== $ticket_key ? $ticket_key : 'product:' . $product_id;
		$option['option_uuid']           = Event_Offering_Resolver::option_uuid( $target_event_id, 'included:' . $source_event_id . ':' . $identity );
		$option['available_for_new']     = false;
		$option['existing_access_valid'] = true;
		$option['label']                 = sanitize_text_field( (string) ( $ticket['name'] ?? ( $evidence['item_label'] ?? 'Included with another event' ) ) );
		$option['source_kind']           = 'included_event';

		return $option;
	}

	/** @param array<string,mixed> $snapshot @return array<string,mixed> */
	private static function snapshot_event( array $snapshot, int $target_event_id ): array {
		foreach ( is_array( $snapshot['included_events'] ?? null ) ? $snapshot['included_events'] : array() as $event ) {
			if ( is_array( $event ) && $target_event_id === (int) ( $event['event_id'] ?? 0 ) ) {
				return $event;
			}
		}

		return array();
	}

	/** @return array<string,mixed> */
	private static function review( string $reason ): array {
		return array(
			'resolution'         => 'review_required',
			'classification'     => 'unclassified',
			'validity_type'      => 'unclassified',
			'eligibility'        => 'review_required',
			'payment_label'      => 'Website registration requires review',
			'available_for_new'  => false,
			'option_uuid'        => '',
			'valid_local_date'   => '',
			'max_attendees'      => 1,
			'option'             => array(),
			'source_kind'        => '',
			'source_event_title' => '',
			'source_event_date'  => '',
			'source_ticket_name' => '',
			'target_event'       => array(),
			'reason'             => $reason,
		);
	}
}
