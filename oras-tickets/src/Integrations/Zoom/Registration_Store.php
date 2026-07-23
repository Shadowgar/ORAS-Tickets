<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registration_Store implements Registration_Repository {

	private const SCHEMA_VERSION_OPTION = 'oras_zoom_registration_schema_version';
	private const SCHEMA_VERSION        = '1';

	public static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$registrations = self::registration_table();
		$sources       = self::source_table();
		$charset       = $wpdb->get_charset_collate();

		$sql_registrations = "CREATE TABLE {$registrations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meeting_id varchar(20) NOT NULL,
			email varchar(190) NOT NULL,
			email_hash char(64) NOT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			registrant_id varchar(120) NOT NULL DEFAULT '',
			join_url longtext NULL,
			status varchar(24) NOT NULL DEFAULT 'active',
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			cancelled_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_meeting_email (event_id, meeting_id, email_hash),
			KEY event_status (event_id, status),
			KEY registrant_id (registrant_id)
		) {$charset};";

		$sql_sources = "CREATE TABLE {$sources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL,
			source_type varchar(20) NOT NULL,
			source_ref varchar(191) NOT NULL,
			active tinyint(1) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY registration_source (registration_id, source_type, source_ref),
			KEY active_registration (registration_id, active)
		) {$charset};";

		dbDelta( $sql_registrations );
		dbDelta( $sql_sources );
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION );
	}

	public static function maybe_install_schema(): void {
		if ( self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) ) {
			self::install_schema();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function find_by_event_email( int $event_id, string $meeting_id, string $email ): array {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( $event_id <= 0 || '' === $meeting_id || '' === $email ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is generated internally from $wpdb->prefix.
				'SELECT * FROM ' . self::registration_table() . ' WHERE event_id = %d AND meeting_id = %s AND email_hash = %s LIMIT 1',
				$event_id,
				$meeting_id,
				hash( 'sha256', $email )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array();
		}

		$row['join_url'] = Settings::reveal_private_value( (string) ( $row['join_url'] ?? '' ) );
		return $row;
	}

	/**
	 * @param array<string,mixed> $registration
	 * @return array<string,mixed>
	 */
	public function save_registration( array $registration ): array {
		global $wpdb;

		$event_id   = absint( $registration['event_id'] ?? 0 );
		$meeting_id = preg_replace( '/\D+/', '', (string) ( $registration['meeting_id'] ?? '' ) );
		$meeting_id = is_string( $meeting_id ) ? $meeting_id : '';
		$email      = sanitize_email( (string) ( $registration['email'] ?? '' ) );
		if ( $event_id <= 0 || '' === $meeting_id || '' === $email ) {
			return array();
		}

		$existing = $this->find_by_event_email( $event_id, $meeting_id, $email );
		$now      = current_time( 'mysql', true );
		$data     = array(
			'event_id'      => $event_id,
			'user_id'       => absint( $registration['user_id'] ?? 0 ),
			'meeting_id'    => $meeting_id,
			'email'         => $email,
			'email_hash'    => hash( 'sha256', $email ),
			'first_name'    => sanitize_text_field( (string) ( $registration['first_name'] ?? '' ) ),
			'last_name'     => sanitize_text_field( (string) ( $registration['last_name'] ?? '' ) ),
			'registrant_id' => sanitize_text_field( (string) ( $registration['registrant_id'] ?? '' ) ),
			'join_url'      => Settings::protect_private_value( (string) ( $registration['join_url'] ?? '' ) ),
			'status'        => sanitize_key( (string) ( $registration['status'] ?? 'active' ) ),
			'last_error'    => sanitize_textarea_field( (string) ( $registration['last_error'] ?? '' ) ),
			'updated_at'    => $now,
			'cancelled_at'  => 'cancelled' === (string) ( $registration['status'] ?? '' ) ? $now : null,
		);

		if ( ! empty( $existing['id'] ) ) {
			$wpdb->update( self::registration_table(), $data, array( 'id' => absint( $existing['id'] ) ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( self::registration_table(), $data );
		}

		return $this->find_by_event_email( $event_id, $meeting_id, $email );
	}

	public function activate_source( int $registration_id, string $source_type, string $source_ref ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );
		return false !== $wpdb->replace(
			self::source_table(),
			array(
				'registration_id' => $registration_id,
				'source_type'     => sanitize_key( $source_type ),
				'source_ref'      => sanitize_text_field( $source_ref ),
				'active'          => 1,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
	}

	public function deactivate_source( int $registration_id, string $source_type, string $source_ref ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			self::source_table(),
			array(
				'active'     => 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'registration_id' => $registration_id,
				'source_type'     => sanitize_key( $source_type ),
				'source_ref'      => sanitize_text_field( $source_ref ),
			)
		);
	}

	public function has_active_sources( int $registration_id ): bool {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is generated internally from $wpdb->prefix.
				'SELECT COUNT(*) FROM ' . self::source_table() . ' WHERE registration_id = %d AND active = 1',
				$registration_id
			)
		);
		return (int) $count > 0;
	}

	public function update_status( int $registration_id, string $status, string $error = '' ): bool {
		global $wpdb;

		$status = sanitize_key( $status );
		$data = array(
			'status'       => $status,
			'last_error'   => sanitize_textarea_field( $error ),
			'updated_at'   => current_time( 'mysql', true ),
			'cancelled_at' => 'cancelled' === $status ? current_time( 'mysql', true ) : null,
		);
		if ( 'cancelled' === $status ) {
			$data['join_url'] = '';
		}

		return false !== $wpdb->update(
			self::registration_table(),
			$data,
			array( 'id' => $registration_id )
		);
	}

	private static function registration_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oras_zoom_registrations';
	}

	private static function source_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oras_zoom_registration_sources';
	}
}
