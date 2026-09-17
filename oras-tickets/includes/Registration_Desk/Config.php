<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Config {
	public const EVENT_META_KEY = '_oras_registration_desk_v1';
	public const ACTIVE_EVENT_OPTION = 'oras_registration_desk_active_event';

	/** @return array<string,mixed> */
	public static function get_event_config( int $event_id ): array {
		$raw = $event_id > 0 ? get_post_meta( $event_id, self::EVENT_META_KEY, true ) : array();

		return self::normalize_event_config( is_array( $raw ) ? $raw : array() );
	}

	/** @param array<string,mixed> $raw @return array<string,mixed> */
	public static function normalize_event_config( array $raw ): array {
		$options = array();
		foreach ( is_array( $raw['options'] ?? null ) ? $raw['options'] : array() as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$uuid = strtolower( trim( (string) ( $candidate['option_uuid'] ?? '' ) ) );
			if ( ! self::is_uuid( $uuid ) ) {
				continue;
			}
			$classification = sanitize_key( (string) ( $candidate['classification'] ?? 'unclassified' ) );
			if ( ! in_array( $classification, array( 'individual', 'family', 'unclassified' ), true ) ) {
				$classification = 'unclassified';
			}
			$validity = sanitize_key( (string) ( $candidate['validity_type'] ?? 'unclassified' ) );
			if ( ! in_array( $validity, array( 'full_event', 'one_day', 'unclassified' ), true ) ) {
				$validity = 'unclassified';
			}
			$product_ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $candidate['source_product_ids'] ?? null ) ? $candidate['source_product_ids'] : array() ) ) ) );
			$options[]   = array(
				'option_uuid'           => $uuid,
				'label'                 => sanitize_text_field( (string) ( $candidate['label'] ?? '' ) ),
				'available_for_new'     => ! empty( $candidate['available_for_new'] ),
				'existing_access_valid' => ! array_key_exists( 'existing_access_valid', $candidate ) || ! empty( $candidate['existing_access_valid'] ),
				'classification'        => $classification,
				'validity_type'         => $validity,
				'source_product_ids'    => $product_ids,
			);
		}

		return array(
			'schema'   => 1,
			'enabled'  => ! empty( $raw['enabled'] ),
			'revision' => max( 0, (int) ( $raw['revision'] ?? 0 ) ),
			'options'  => $options,
		);
	}

	/** @param array<string,mixed> $raw @return array<string,mixed>|\WP_Error */
	public static function save_event_config( int $event_id, array $raw, int $expected_revision ) {
		if ( ! current_user_can( 'oras_tickets_manage_registration_desk' ) ) {
			return new \WP_Error( 'oras_desk_forbidden', 'You are not allowed to manage Registration Desk settings.', array( 'status' => 403 ) );
		}
		if ( $event_id <= 0 || 'tribe_events' !== get_post_type( $event_id ) ) {
			return new \WP_Error( 'oras_desk_invalid_event', 'Select a valid event.', array( 'status' => 400 ) );
		}
		$current = self::get_event_config( $event_id );
		if ( (int) $current['revision'] !== $expected_revision ) {
			return new \WP_Error( 'oras_desk_stale_config', 'Registration Desk settings changed. Reload and try again.', array( 'status' => 409 ) );
		}
		$next             = self::normalize_event_config( $raw );
		$next['revision'] = $expected_revision + 1;
		update_post_meta( $event_id, self::EVENT_META_KEY, $next );

		return $next;
	}

	public static function get_active_event_id(): int {
		return absint( get_option( self::ACTIVE_EVENT_OPTION, 0 ) );
	}

	/** @return true|\WP_Error */
	public static function set_active_event_id( int $event_id ) {
		if ( ! current_user_can( 'oras_tickets_manage_registration_desk' ) ) {
			return new \WP_Error( 'oras_desk_forbidden', 'You are not allowed to select the active event.', array( 'status' => 403 ) );
		}
		if ( $event_id <= 0 || 'tribe_events' !== get_post_type( $event_id ) ) {
			return new \WP_Error( 'oras_desk_invalid_event', 'Select a valid event.', array( 'status' => 400 ) );
		}
		update_option( self::ACTIVE_EVENT_OPTION, $event_id, false );

		return true;
	}

	/** @param array<string,mixed> $config @return array<string,mixed>|null */
	public static function option( array $config, string $option_uuid ): ?array {
		foreach ( $config['options'] as $option ) {
			if ( hash_equals( (string) $option['option_uuid'], strtolower( trim( $option_uuid ) ) ) ) {
				return $option;
			}
		}

		return null;
	}

	private static function is_uuid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}
}
