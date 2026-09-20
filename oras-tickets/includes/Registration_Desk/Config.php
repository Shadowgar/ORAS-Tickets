<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Support\DbLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Config {
	public const EVENT_META_KEY = '_oras_registration_desk_v1';
	public const ACTIVE_EVENT_OPTION = 'oras_registration_desk_active_event';
	public const MEMBERSHIP_MAPPINGS_OPTION = 'oras_registration_desk_membership_mappings';

	/** @return array<string,mixed> */
	public static function get_event_config( int $event_id ): array {
		$raw = $event_id > 0 ? get_post_meta( $event_id, self::EVENT_META_KEY, true ) : array();

		return self::normalize_event_config( is_array( $raw ) ? $raw : array() );
	}

	/** @param array<string,mixed> $raw @return array<string,mixed> */
	public static function normalize_event_config( array $raw ): array {
		$ticket_rules = array();
		foreach ( is_array( $raw['ticket_rules'] ?? null ) ? $raw['ticket_rules'] : array() as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$ticket_key = sanitize_text_field( (string) ( $candidate['ticket_key'] ?? '' ) );
			if ( '' === $ticket_key ) {
				continue;
			}
			$ticket_rules[] = array_merge( array( 'ticket_key' => $ticket_key ), self::normalize_coverage( $candidate ) );
		}

		$entitlements = array();
		foreach ( is_array( $raw['entitlements'] ?? null ) ? $raw['entitlements'] : array() as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$source_event_id   = absint( $candidate['source_event_id'] ?? 0 );
			$source_product_id = absint( $candidate['source_product_id'] ?? 0 );
			if ( $source_event_id <= 0 || $source_product_id <= 0 ) {
				continue;
			}
			$uuid = strtolower( sanitize_text_field( (string) ( $candidate['entitlement_uuid'] ?? '' ) ) );
			if ( ! self::is_uuid( $uuid ) ) {
				$uuid = wp_generate_uuid4();
			}
			$entitlements[] = array_merge(
				array(
					'entitlement_uuid'  => $uuid,
					'source_event_id'   => $source_event_id,
					'source_product_id' => $source_product_id,
				),
				self::normalize_coverage( $candidate )
			);
		}

		// Legacy options remain readable only for already-created records. They are never
		// returned as current walk-in offerings.
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
			$source_event_ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $candidate['source_event_ids'] ?? null ) ? $candidate['source_event_ids'] : array() ) ) ) );
			$valid_local_date = sanitize_text_field( (string) ( $candidate['valid_local_date'] ?? '' ) );
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valid_local_date ) ) {
				$valid_local_date = '';
			}
			$options[] = array(
				'option_uuid'           => $uuid,
				'label'                 => sanitize_text_field( (string) ( $candidate['label'] ?? '' ) ),
				'available_for_new'     => ! empty( $candidate['available_for_new'] ),
				'existing_access_valid' => ! array_key_exists( 'existing_access_valid', $candidate ) || ! empty( $candidate['existing_access_valid'] ),
				'classification'        => $classification,
				'validity_type'         => $validity,
				'source_product_ids'    => $product_ids,
				'source_event_ids'      => $source_event_ids,
				'valid_local_date'      => $valid_local_date,
				'max_attendees'         => max( 1, min( 20, absint( $candidate['max_attendees'] ?? 1 ) ) ),
			);
		}

		return array(
			'schema'       => 2,
			'enabled'      => ! empty( $raw['enabled'] ),
			'revision'     => max( 0, (int) ( $raw['revision'] ?? 0 ) ),
			'ticket_rules' => $ticket_rules,
			'entitlements' => $entitlements,
			'options'      => $options,
		);
	}

	/** @param array<string,mixed> $candidate @return array<string,mixed> */
	private static function normalize_coverage( array $candidate ): array {
		$classification = sanitize_key( (string) ( $candidate['classification'] ?? 'individual' ) );
		if ( ! in_array( $classification, array( 'individual', 'family' ), true ) ) {
			$classification = 'individual';
		}
		$validity = sanitize_key( (string) ( $candidate['validity_type'] ?? 'full_event' ) );
		if ( ! in_array( $validity, array( 'full_event', 'one_day' ), true ) ) {
			$validity = 'full_event';
		}
		$date = sanitize_text_field( (string) ( $candidate['valid_local_date'] ?? '' ) );
		if ( 'one_day' !== $validity || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}

		return array(
			'classification'   => $classification,
			'validity_type'    => $validity,
			'valid_local_date' => $date,
			'max_attendees'    => 'family' === $classification ? max( 1, min( 20, absint( $candidate['max_attendees'] ?? 1 ) ) ) : 1,
		);
	}

	/** @param array<string,mixed> $raw @return array<string,mixed>|\WP_Error */
	public static function save_event_config( int $event_id, array $raw, int $expected_revision ) {
		return self::persist( $event_id, $raw, $expected_revision, false );
	}

	/** @param array<string,mixed> $raw @return array<string,mixed>|\WP_Error */
	public static function save_and_activate( int $event_id, array $raw, int $expected_revision, ?int $expected_active_event_id = null ) {
		return self::persist( $event_id, $raw, $expected_revision, true, $expected_active_event_id );
	}

	public static function get_active_event_id(): int {
		return absint( get_option( self::ACTIVE_EVENT_OPTION, 0 ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function get_membership_mappings(): array {
		$raw = get_option( self::MEMBERSHIP_MAPPINGS_OPTION, array() );

		return self::normalize_membership_mappings( is_array( $raw ) ? $raw : array() );
	}

	/** @param array<int,mixed> $raw @return array<int,array<string,mixed>> */
	public static function normalize_membership_mappings( array $raw ): array {
		$mappings = array();
		$seen     = array();
		foreach ( $raw as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$level_id = absint( $candidate['level_id'] ?? 0 );
			// Rows saved before canonical resolution did not include this flag. Treat
			// those complete legacy mappings as enabled while discarding their copied facts.
			$enabled = array_key_exists( 'event_sale_enabled', $candidate ) ? ! empty( $candidate['event_sale_enabled'] ) : isset( $candidate['display_name'], $candidate['price'], $candidate['checkout_url'] );
			if ( $level_id <= 0 || ! $enabled || isset( $seen[ $level_id ] ) ) {
				continue;
			}
			$seen[ $level_id ] = true;
			$mappings[] = array(
				'level_id'           => $level_id,
				'event_sale_enabled' => true,
			);
		}

		return $mappings;
	}

	/** @return array<string,mixed>|null */
	public static function membership_mapping( int $level_id ): ?array {
		foreach ( self::get_membership_mappings() as $mapping ) {
			if ( $level_id === (int) $mapping['level_id'] ) {
				return Membership_Offering_Resolver::resolve( $level_id );
			}
		}

		return null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function get_membership_offerings(): array {
		$offerings = array();
		foreach ( self::get_membership_mappings() as $mapping ) {
			$offering = Membership_Offering_Resolver::resolve( (int) $mapping['level_id'] );
			if ( null !== $offering ) {
				$offerings[] = $offering;
			}
		}

		return $offerings;
	}

	/** @return true|\WP_Error */
	public static function set_active_event_id( int $event_id ) {
		$valid = self::validate_management( $event_id );
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		$result = DbLock::withLock(
			'registration-desk-config',
			static function () use ( $event_id ) {
				return Store::transaction(
					static function () use ( $event_id ) {
						return self::write_active_event( $event_id );
					}
				);
			}
		);
		self::clear_caches( $event_id );

		return $result instanceof \WP_Error ? $result : true;
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

	/** @param array<string,mixed> $raw @return array<string,mixed>|\WP_Error */
	private static function persist( int $event_id, array $raw, int $expected_revision, bool $activate, ?int $expected_active_event_id = null ) {
		$valid = self::validate_management( $event_id );
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		$result = DbLock::withLock(
			'registration-desk-config',
			static function () use ( $event_id, $raw, $expected_revision, $activate, $expected_active_event_id ) {
				return Store::transaction(
					static function () use ( $event_id, $raw, $expected_revision, $activate, $expected_active_event_id ) {
						if ( $activate && null !== $expected_active_event_id ) {
							$active_event_id = self::durable_active_event_for_update();
							if ( $active_event_id instanceof \WP_Error ) {
								return $active_event_id;
							}
							if ( $active_event_id !== $expected_active_event_id ) {
								return new \WP_Error( 'oras_desk_active_event_changed', 'The active Registration Desk event changed. Reload and try again.', array( 'status' => 409 ) );
							}
						}
						$current = self::durable_event_config_for_update( $event_id );
						if ( $current instanceof \WP_Error ) {
							return $current;
						}
						if ( (int) $current['revision'] !== $expected_revision ) {
							return new \WP_Error( 'oras_desk_stale_config', 'Registration Desk settings changed. Reload and try again.', array( 'status' => 409 ) );
						}
						$next             = self::normalize_event_config( $raw );
						$next['revision'] = $expected_revision + 1;
						$written = self::write_event_config( $event_id, $next );
						if ( $written instanceof \WP_Error ) {
							return $written;
						}
						if ( $activate ) {
							$active = self::write_active_event( $event_id );
							if ( $active instanceof \WP_Error ) {
								return $active;
							}
						}

						return $next;
					}
				);
			}
		);
		self::clear_caches( $event_id );

		return $result;
	}

	/** @return true|\WP_Error */
	private static function validate_management( int $event_id ) {
		if ( ! current_user_can( 'oras_tickets_manage_registration_desk' ) ) {
			return new \WP_Error( 'oras_desk_forbidden', 'You are not allowed to manage Registration Desk settings.', array( 'status' => 403 ) );
		}
		if ( $event_id <= 0 || 'tribe_events' !== get_post_type( $event_id ) ) {
			return new \WP_Error( 'oras_desk_invalid_event', 'Select a valid event.', array( 'status' => 400 ) );
		}

		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function durable_event_config_for_update( int $event_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1 FOR UPDATE",
				$event_id,
				self::EVENT_META_KEY
			),
			ARRAY_A
		);
		if ( null === $row && '' !== $wpdb->last_error ) {
			return new \WP_Error( 'oras_desk_config_read_failed', 'Registration Desk settings could not be read.' );
		}
		$value = is_array( $row ) ? maybe_unserialize( $row['meta_value'] ) : array();

		return self::normalize_event_config( is_array( $value ) ? $value : array() );
	}

	/** @param array<string,mixed> $next @return true|\WP_Error */
	private static function write_event_config( int $event_id, array $next ) {
		global $wpdb;
		$forced = apply_filters( 'oras_registration_desk_config_meta_write_error', null, $event_id, $next );
		if ( $forced instanceof \WP_Error ) {
			return $forced;
		}
		$meta_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1 FOR UPDATE", $event_id, self::EVENT_META_KEY ) );
		$serialized = maybe_serialize( $next );
		$written = $meta_id
			? $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $serialized ), array( 'meta_id' => (int) $meta_id ), array( '%s' ), array( '%d' ) )
			: $wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $event_id,
					'meta_key'   => self::EVENT_META_KEY,
					'meta_value' => $serialized,
				),
				array( '%d', '%s', '%s' )
			);

		return false === $written ? new \WP_Error( 'oras_desk_config_write_failed', 'Registration Desk settings could not be saved.' ) : true;
	}

	/** @return true|\WP_Error */
	private static function write_active_event( int $event_id ) {
		global $wpdb;
		$forced = apply_filters( 'oras_registration_desk_config_active_write_error', null, $event_id );
		if ( $forced instanceof \WP_Error ) {
			return $forced;
		}
		$option_id = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 FOR UPDATE", self::ACTIVE_EVENT_OPTION ) );
		$written = $option_id
			? $wpdb->update( $wpdb->options, array( 'option_value' => (string) $event_id ), array( 'option_id' => (int) $option_id ), array( '%s' ), array( '%d' ) )
			: $wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => self::ACTIVE_EVENT_OPTION,
					'option_value' => (string) $event_id,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			);

		return false === $written ? new \WP_Error( 'oras_desk_active_event_write_failed', 'The active Registration Desk event could not be saved.' ) : true;
	}

	/** @return int|\WP_Error */
	private static function durable_active_event_for_update() {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 FOR UPDATE", self::ACTIVE_EVENT_OPTION ) );
		if ( null === $value && '' !== $wpdb->last_error ) {
			return new \WP_Error( 'oras_desk_active_event_read_failed', 'The active Registration Desk event could not be read.' );
		}

		return absint( $value );
	}

	private static function clear_caches( int $event_id ): void {
		clean_post_cache( $event_id );
		wp_cache_delete( self::ACTIVE_EVENT_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
