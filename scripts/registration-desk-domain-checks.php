<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function oras_desk_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	echo "PASS: {$message}\n";
}

$base = dirname( __DIR__ ) . '/oras-tickets/includes/Registration_Desk/';

foreach ( array( 'Schema.php', 'Store.php', 'Registration_Store.php', 'Attendee_Store.php', 'Attendance_Store.php', 'Audit_Store.php' ) as $file ) {
	oras_desk_assert( file_exists( $base . $file ), "{$file} exists" );
	require_once $base . $file;
}

$schema_class = '\\ORAS\\Tickets\\Registration_Desk\\Schema';
oras_desk_assert( class_exists( $schema_class ), 'Schema class loads' );

$sql = $schema_class::build_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
oras_desk_assert( 4 === count( $sql ), 'Schema defines exactly four desk-owned tables' );

$joined = implode( "\n", $sql );
foreach ( array( 'wp_oras_event_registrations', 'wp_oras_event_attendees', 'wp_oras_event_attendance', 'wp_oras_event_audit' ) as $table ) {
	oras_desk_assert( false !== strpos( $joined, "CREATE TABLE {$table}" ), "Schema defines {$table}" );
}

oras_desk_assert( false !== strpos( $joined, 'source_order_id bigint(20) unsigned NULL' ), 'Online order source is nullable' );
oras_desk_assert( false !== strpos( $joined, 'source_order_item_id bigint(20) unsigned NULL' ), 'Online order-item source is nullable' );
oras_desk_assert( false !== strpos( $joined, 'source_key varchar(191) NULL' ), 'Online source identity is nullable' );
oras_desk_assert( false !== strpos( $joined, 'UNIQUE KEY registration_uuid (registration_uuid)' ), 'Registration UUID is unique' );
oras_desk_assert( false !== strpos( $joined, 'UNIQUE KEY source_identity (event_id,source_key)' ), 'Online source identity is unique inside target event' );
oras_desk_assert( false !== strpos( $joined, 'UNIQUE KEY attendee_uuid (attendee_uuid)' ), 'Attendee UUID is unique' );
oras_desk_assert( false !== strpos( $joined, 'UNIQUE KEY registration_slot (registration_id,slot_key)' ), 'Attendee slot identity is stable' );
oras_desk_assert( false !== strpos( $joined, 'UNIQUE KEY daily_attendance (event_id,attendee_id,attendance_local_date)' ), 'Daily attendance has a database unique key' );
oras_desk_assert( substr_count( $joined, 'record_version bigint(20) unsigned NOT NULL DEFAULT 1' ) >= 3, 'Mutable records carry guarded versions' );
oras_desk_assert( false !== strpos( $joined, 'UNIQUE KEY request_uuid (request_uuid)' ), 'Audit request identifier is unique' );
oras_desk_assert( false !== strpos( $joined, 'payload_hash char(64) NOT NULL' ), 'Audit binds the normalized payload hash' );
oras_desk_assert( false !== strpos( $joined, 'config_revision bigint(20) unsigned NOT NULL' ), 'Audit binds the configuration revision' );
oras_desk_assert( false !== strpos( $joined, 'created_at_utc datetime NOT NULL' ), 'Audit and operational records use explicit UTC timestamps' );
oras_desk_assert( 4 === substr_count( $joined, 'ENGINE=InnoDB' ), 'All four stores require transactional InnoDB tables' );
oras_desk_assert( false === stripos( $joined, 'FOREIGN KEY' ), 'Schema does not make online sources mandatory through foreign keys' );
oras_desk_assert( false === stripos( $joined, 'UNIQUE KEY email' ), 'Email is not globally unique' );
oras_desk_assert( false === stripos( $joined, 'UNIQUE KEY phone' ), 'Phone is not globally unique' );

foreach ( array( 'Registration_Store', 'Attendee_Store', 'Attendance_Store', 'Audit_Store' ) as $store ) {
	$class = '\\ORAS\\Tickets\\Registration_Desk\\' . $store;
	oras_desk_assert( class_exists( $class ), "{$store} class loads" );
}

oras_desk_assert( method_exists( $schema_class, 'install' ), 'Schema exposes repeat-safe installation' );
oras_desk_assert( method_exists( $schema_class, 'verify_transactional_tables' ), 'Schema can verify transactional table engines' );

echo "Registration Desk domain checks passed.\n";
