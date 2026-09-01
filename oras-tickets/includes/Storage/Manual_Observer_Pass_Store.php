<?php

namespace ORAS\Tickets\Storage;

use ORAS\Tickets\Domain\Annual_Pass_Validity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Manual_Observer_Pass_Store {
	public const POST_TYPE = 'oras_manual_pass';

	private const SCHEMA_VERSION = 1;
	private const META_PREFIX = '_oras_manual_pass_';
	private const SOURCES = array( 'legacy_import', 'cash', 'check', 'complimentary', 'other' );
	private const STATES = array( 'active', 'invalid' );

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Manual Observer Passes', 'oras-tickets' ),
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
		$holder_names = self::sanitize_holder_names( $input['holder_names'] ?? array() );
		$start_date   = self::sanitize_date( (string) ( $input['start_date'] ?? '' ) );
		$raw_email    = trim( (string) ( $input['email'] ?? '' ) );
		$email        = sanitize_email( $raw_email );
		$quantity     = absint( $input['quantity'] ?? 0 );

		if ( empty( $holder_names ) ) {
			return new \WP_Error( 'manual_pass_holders_required', __( 'Enter at least one passholder name.', 'oras-tickets' ) );
		}
		if ( '' === $start_date ) {
			return new \WP_Error( 'manual_pass_start_required', __( 'Enter a valid Annual pass start date.', 'oras-tickets' ) );
		}
		if ( $quantity < 1 || $quantity > 100 ) {
			return new \WP_Error( 'manual_pass_quantity_invalid', __( 'Pass quantity must be between 1 and 100.', 'oras-tickets' ) );
		}
		if ( '' !== $raw_email && '' === $email ) {
			return new \WP_Error( 'manual_pass_email_invalid', __( 'Enter a valid email address or leave it blank.', 'oras-tickets' ) );
		}

		$source = sanitize_key( (string) ( $input['source'] ?? 'other' ) );
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			$source = 'other';
		}
		$record_state = sanitize_key( (string) ( $input['record_state'] ?? 'active' ) );
		if ( ! in_array( $record_state, self::STATES, true ) ) {
			$record_state = 'active';
		}

		$start      = new \DateTimeImmutable( $start_date . ' 00:00:00', self::timezone() );
		$expiration = Annual_Pass_Validity::expiration_for( $start );

		return array(
			'holder_names'   => $holder_names,
			'quantity'       => $quantity,
			'email'          => $email,
			'start_date'     => $start_date,
			'expiration_date'=> $expiration->format( 'Y-m-d' ),
			'source'         => $source,
			'linked_user_id' => absint( $input['linked_user_id'] ?? 0 ),
			'notes'          => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
			'record_state'   => $record_state,
		);
	}

	/**
	 * @param array<string,mixed> $input Record fields.
	 * @return int|\WP_Error
	 */
	public static function create( array $input, int $actor_user_id ) {
		$record = self::sanitize_record( $input );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => implode( ' / ', $record['holder_names'] ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::save_meta( (int) $post_id, $record, $actor_user_id, true );

		return (int) $post_id;
	}

	/**
	 * @param array<string,mixed> $input Record fields.
	 * @return true|\WP_Error
	 */
	public static function update( int $post_id, array $input, int $actor_user_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'manual_pass_not_found', __( 'Manual Observer Pass record not found.', 'oras-tickets' ) );
		}
		$record = self::sanitize_record( $input );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => implode( ' / ', $record['holder_names'] ),
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		self::save_meta( $post_id, $record, $actor_user_id, false );

		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get( int $post_id ): ?array {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return null;
		}

		return self::hydrate( $post_id );
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
		foreach ( array( 'holder_names', 'quantity', 'email', 'start_date', 'expiration_date', 'source', 'linked_user_id', 'notes', 'record_state' ) as $field ) {
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
			'id'              => $post_id,
			'holder_names'    => array_values( array_filter( array_map( 'strval', (array) get_post_meta( $post_id, self::META_PREFIX . 'holder_names', true ) ) ) ),
			'quantity'        => absint( get_post_meta( $post_id, self::META_PREFIX . 'quantity', true ) ),
			'email'           => (string) get_post_meta( $post_id, self::META_PREFIX . 'email', true ),
			'start_date'      => (string) get_post_meta( $post_id, self::META_PREFIX . 'start_date', true ),
			'expiration_date' => (string) get_post_meta( $post_id, self::META_PREFIX . 'expiration_date', true ),
			'source'          => (string) get_post_meta( $post_id, self::META_PREFIX . 'source', true ),
			'linked_user_id'  => absint( get_post_meta( $post_id, self::META_PREFIX . 'linked_user_id', true ) ),
			'notes'           => (string) get_post_meta( $post_id, self::META_PREFIX . 'notes', true ),
			'record_state'    => (string) get_post_meta( $post_id, self::META_PREFIX . 'record_state', true ),
			'created_by'      => absint( get_post_meta( $post_id, self::META_PREFIX . 'created_by', true ) ),
			'updated_by'      => absint( get_post_meta( $post_id, self::META_PREFIX . 'updated_by', true ) ),
			'created_at'      => $post instanceof \WP_Post ? (string) $post->post_date_gmt : '',
			'updated_at'      => $post instanceof \WP_Post ? (string) $post->post_modified_gmt : '',
		);
	}

	/** @return string[] */
	private static function sanitize_holder_names( $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
		$names  = array();
		foreach ( is_array( $values ) ? $values : array() as $name ) {
			$clean = sanitize_text_field( (string) $name );
			if ( '' !== $clean ) {
				$names[] = $clean;
			}
		}

		return array_values( array_unique( $names ) );
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
