<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public const VERSION = 2;
	public const OPTION_VERSION = 'oras_registration_desk_schema_version';

	/** @return array<string,string> */
	public static function table_names( ?string $prefix = null ): array {
		if ( null === $prefix ) {
			global $wpdb;
			$prefix = $wpdb->prefix;
		}

		return array(
			'registrations'       => $prefix . 'oras_event_registrations',
			'attendees'           => $prefix . 'oras_event_attendees',
			'attendance'          => $prefix . 'oras_event_attendance',
			'audit'               => $prefix . 'oras_event_audit',
			'offline_memberships' => $prefix . 'oras_offline_memberships',
		);
	}

	/** @return string[] */
	public static function build_schema_sql( string $prefix, string $charset_collate ): array {
		$tables = self::table_names( $prefix );
		$suffix = trim( $charset_collate ) . ' ENGINE=InnoDB';

		return array(
			"CREATE TABLE {$tables['registrations']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_uuid char(36) NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				option_uuid char(36) NOT NULL,
				source_type varchar(24) NOT NULL,
				source_key varchar(191) NULL,
				source_order_id bigint(20) unsigned NULL,
				source_order_item_id bigint(20) unsigned NULL,
				source_unit_number int(10) unsigned NULL,
				classification varchar(24) NOT NULL DEFAULT 'unclassified',
				status varchar(24) NOT NULL DEFAULT 'active',
				source_status varchar(32) NOT NULL DEFAULT '',
				source_contact_name varchar(191) NOT NULL DEFAULT '',
				source_email varchar(191) NOT NULL DEFAULT '',
				source_phone varchar(64) NOT NULL DEFAULT '',
				search_name varchar(191) NOT NULL DEFAULT '',
				search_email varchar(191) NOT NULL DEFAULT '',
				search_phone varchar(64) NOT NULL DEFAULT '',
				coverage_type varchar(24) NOT NULL DEFAULT 'unclassified',
				validity_type varchar(24) NOT NULL DEFAULT 'unclassified',
				valid_local_date date NULL,
				payment_assertion varchar(24) NULL,
				source_evidence longtext NOT NULL,
				source_checked_at_utc datetime NULL,
				config_revision bigint(20) unsigned NOT NULL,
				record_version bigint(20) unsigned NOT NULL DEFAULT 1,
				created_at_utc datetime NOT NULL,
				updated_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY registration_uuid (registration_uuid),
				UNIQUE KEY source_identity (event_id,source_key),
				KEY event_status (event_id,status,id),
				KEY event_email (event_id,search_email),
				KEY event_phone (event_id,search_phone),
				KEY event_name (event_id,search_name)
			) {$suffix};",
			"CREATE TABLE {$tables['attendees']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attendee_uuid char(36) NOT NULL,
				registration_id bigint(20) unsigned NOT NULL,
				slot_key varchar(64) NOT NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				display_name varchar(191) NOT NULL DEFAULT '',
				identity_state varchar(24) NOT NULL DEFAULT 'unconfirmed',
				status varchar(24) NOT NULL DEFAULT 'active',
				record_version bigint(20) unsigned NOT NULL DEFAULT 1,
				confirmed_at_utc datetime NULL,
				created_at_utc datetime NOT NULL,
				updated_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY attendee_uuid (attendee_uuid),
				UNIQUE KEY registration_slot (registration_id,slot_key),
				KEY registration_status (registration_id,status,id)
			) {$suffix};",
			"CREATE TABLE {$tables['attendance']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				attendee_id bigint(20) unsigned NOT NULL,
				attendance_local_date date NOT NULL,
				state varchar(24) NOT NULL DEFAULT 'checked_in',
				record_version bigint(20) unsigned NOT NULL DEFAULT 1,
				checked_in_at_utc datetime NOT NULL,
				checked_in_by bigint(20) unsigned NOT NULL,
				checked_in_station_uuid char(36) NOT NULL,
				checked_in_operator_label varchar(100) NOT NULL,
				reversed_at_utc datetime NULL,
				reversed_by bigint(20) unsigned NULL,
				reversal_reason text NULL,
				created_at_utc datetime NOT NULL,
				updated_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY daily_attendance (event_id,attendee_id,attendance_local_date),
				KEY event_date_state (event_id,attendance_local_date,state,id)
			) {$suffix};",
			"CREATE TABLE {$tables['audit']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_uuid char(36) NOT NULL,
				operation varchar(48) NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				station_uuid char(36) NOT NULL,
				operator_label varchar(100) NOT NULL,
				payload_hash char(64) NOT NULL,
				config_revision bigint(20) unsigned NOT NULL,
				registration_uuid char(36) NULL,
				attendee_uuid char(36) NULL,
				attendance_id bigint(20) unsigned NULL,
				result_status varchar(24) NOT NULL,
				result_code varchar(64) NOT NULL,
				result_json longtext NOT NULL,
				changes_json longtext NOT NULL,
				created_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_uuid (request_uuid),
				KEY event_created (event_id,created_at_utc,id),
				KEY registration_created (registration_uuid,created_at_utc,id),
				KEY attendee_created (attendee_uuid,created_at_utc,id)
			) {$suffix};",
			"CREATE TABLE {$tables['offline_memberships']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				activation_uuid char(36) NOT NULL,
				request_uuid char(36) NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				first_name varchar(100) NOT NULL,
				last_name varchar(100) NOT NULL,
				email varchar(191) NOT NULL,
				normalized_email varchar(191) NOT NULL,
				phone varchar(64) NOT NULL DEFAULT '',
				level_id bigint(20) unsigned NOT NULL,
				level_name varchar(191) NOT NULL,
				reference_price varchar(32) NOT NULL DEFAULT '',
				checkout_url text NOT NULL,
				payment_method varchar(16) NOT NULL,
				credit_code varchar(64) NOT NULL,
				discount_code_id bigint(20) unsigned NULL,
				status varchar(24) NOT NULL DEFAULT 'pending',
				email_status varchar(24) NOT NULL DEFAULT 'not_sent',
				email_attempts int(10) unsigned NOT NULL DEFAULT 0,
				last_email_at_utc datetime NULL,
				expires_at_utc datetime NOT NULL,
				linked_user_id bigint(20) unsigned NULL,
				redeemed_at_utc datetime NULL,
				cancelled_at_utc datetime NULL,
				cancelled_by bigint(20) unsigned NULL,
				cancel_reason text NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				station_uuid char(36) NOT NULL,
				operator_label varchar(100) NOT NULL,
				created_at_utc datetime NOT NULL,
				updated_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY activation_uuid (activation_uuid),
				UNIQUE KEY request_uuid (request_uuid),
				UNIQUE KEY credit_code (credit_code),
				KEY event_status (event_id,status,id),
				KEY email_status (normalized_email,status,id),
				KEY discount_code_id (discount_code_id)
			) {$suffix};",
		);
	}

	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::OPTION_VERSION, 0 );
		if ( $installed >= self::VERSION && self::tables_exist() ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::build_schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		if ( self::tables_exist() && self::verify_transactional_tables() ) {
			update_option( self::OPTION_VERSION, self::VERSION, false );
		}
	}

	public static function tables_exist(): bool {
		global $wpdb;
		foreach ( self::table_names() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $found ) {
				return false;
			}
		}

		return true;
	}

	public static function verify_transactional_tables(): bool {
		global $wpdb;
		foreach ( self::table_names() as $table ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			if ( 'InnoDB' !== $engine ) {
				return false;
			}
		}

		return true;
	}
}
