<?php

namespace ORAS\Tickets\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Legacy_Membership_Store {
	public const POST_TYPE = 'oras_legacy_member';

	private const SCHEMA_VERSION = 1;
	private const META_PREFIX = '_oras_legacy_membership_';
	private const STATUSES = array( 'active', 'inactive', 'cancelled', 'expired' );

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Legacy PayPal Memberships', 'oras-tickets' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $input Raw record fields.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function sanitize_record( array $input ) {
		$member_name = sanitize_text_field( (string) ( $input['member_name'] ?? '' ) );
		$raw_email   = trim( (string) ( $input['email'] ?? '' ) );
		$email       = sanitize_email( $raw_email );
		$start_raw   = trim( (string) ( $input['start_date'] ?? '' ) );
		$start_date  = '' !== $start_raw ? self::sanitize_date( $start_raw ) : '';
		$end_date    = self::sanitize_date( trim( (string) ( $input['end_date'] ?? '' ) ) );

		if ( '' === $member_name ) {
			return new \WP_Error( 'legacy_member_name_required', __( 'Enter the legacy member name.', 'oras-tickets' ) );
		}
		if ( '' !== $raw_email && '' === $email ) {
			return new \WP_Error( 'legacy_member_email_invalid', __( 'Enter a valid email address or leave it blank.', 'oras-tickets' ) );
		}
		if ( '' !== $start_raw && '' === $start_date ) {
			return new \WP_Error( 'legacy_member_start_invalid', __( 'Enter a valid start date or leave it blank.', 'oras-tickets' ) );
		}
		if ( '' === $end_date ) {
			return new \WP_Error( 'legacy_member_end_required', __( 'Enter a valid expiration or next-renewal date.', 'oras-tickets' ) );
		}

		$status = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'active';
		}

		return array(
			'member_name'      => $member_name,
			'email'            => $email,
			'start_date'       => $start_date,
			'end_date'         => $end_date,
			'status'           => $status,
			'paypal_reference' => sanitize_text_field( (string) ( $input['paypal_reference'] ?? '' ) ),
			'linked_user_id'   => absint( $input['linked_user_id'] ?? 0 ),
			'transitioned'     => ! empty( $input['transitioned'] ),
			'source'           => 'legacy_paypal',
			'notes'            => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
		);
	}

	/** @param array<string,mixed> $input @return int|\WP_Error */
	public static function create( array $input, int $actor_user_id ) {
		$record = self::sanitize_record( $input );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $record['member_name'],
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		self::save_meta( (int) $post_id, $record, $actor_user_id, true );

		return (int) $post_id;
	}

	/** @param array<string,mixed> $input @return true|\WP_Error */
	public static function update( int $post_id, array $input, int $actor_user_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'legacy_member_not_found', __( 'Legacy membership record not found.', 'oras-tickets' ) );
		}
		$record = self::sanitize_record( $input );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$updated = wp_update_post( array( 'ID' => $post_id, 'post_title' => $record['member_name'] ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		self::save_meta( $post_id, $record, $actor_user_id, false );

		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get( int $post_id ): ?array {
		return self::POST_TYPE === get_post_type( $post_id ) ? self::hydrate( $post_id ) : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function query(): array {
		$post_ids = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_map( array( self::class, 'hydrate' ), array_map( 'absint', is_array( $post_ids ) ? $post_ids : array() ) ) );
	}

	/** @param array<string,mixed> $record */
	private static function save_meta( int $post_id, array $record, int $actor_user_id, bool $creating ): void {
		update_post_meta( $post_id, self::META_PREFIX . 'schema_version', self::SCHEMA_VERSION );
		foreach ( array( 'member_name', 'email', 'start_date', 'end_date', 'status', 'paypal_reference', 'linked_user_id', 'transitioned', 'source', 'notes' ) as $field ) {
			update_post_meta( $post_id, self::META_PREFIX . $field, $record[ $field ] );
		}
		if ( $creating ) {
			update_post_meta( $post_id, self::META_PREFIX . 'created_by', max( 0, $actor_user_id ) );
		}
		update_post_meta( $post_id, self::META_PREFIX . 'updated_by', max( 0, $actor_user_id ) );
	}

	/** @return array<string,mixed> */
	private static function hydrate( int $post_id ): array {
		$post = get_post( $post_id );

		return array(
			'id'               => $post_id,
			'member_name'      => (string) get_post_meta( $post_id, self::META_PREFIX . 'member_name', true ),
			'email'            => (string) get_post_meta( $post_id, self::META_PREFIX . 'email', true ),
			'start_date'       => (string) get_post_meta( $post_id, self::META_PREFIX . 'start_date', true ),
			'end_date'         => (string) get_post_meta( $post_id, self::META_PREFIX . 'end_date', true ),
			'status'           => (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true ),
			'paypal_reference' => (string) get_post_meta( $post_id, self::META_PREFIX . 'paypal_reference', true ),
			'linked_user_id'   => absint( get_post_meta( $post_id, self::META_PREFIX . 'linked_user_id', true ) ),
			'transitioned'     => (bool) get_post_meta( $post_id, self::META_PREFIX . 'transitioned', true ),
			'source'           => 'legacy_paypal',
			'notes'            => (string) get_post_meta( $post_id, self::META_PREFIX . 'notes', true ),
			'created_by'       => absint( get_post_meta( $post_id, self::META_PREFIX . 'created_by', true ) ),
			'updated_by'       => absint( get_post_meta( $post_id, self::META_PREFIX . 'updated_by', true ) ),
			'created_at'       => $post instanceof \WP_Post ? (string) $post->post_date_gmt : '',
			'updated_at'       => $post instanceof \WP_Post ? (string) $post->post_modified_gmt : '',
		);
	}

	private static function sanitize_date( string $value ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		$errors = \DateTimeImmutable::getLastErrors();

		return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	private static function timezone(): \DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}
}
