<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function oras_training_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$plugin_dir = dirname( __DIR__ ) . '/oras-tickets/';
$schema     = $plugin_dir . 'includes/Registration_Desk/Schema.php';
$base_store = $plugin_dir . 'includes/Registration_Desk/Store.php';
$store      = $plugin_dir . 'includes/Registration_Desk/Training_Store.php';

oras_training_assert( file_exists( $schema ), 'Registration Desk schema exists' );
oras_training_assert( file_exists( $base_store ), 'Registration Desk base store exists' );
oras_training_assert( file_exists( $store ), 'Dedicated Training Store exists' );

require_once $schema;
require_once $base_store;
require_once $store;

$schema_class = '\\ORAS\\Tickets\\Registration_Desk\\Schema';
$store_class  = '\\ORAS\\Tickets\\Registration_Desk\\Training_Store';

oras_training_assert( 3 === $schema_class::VERSION, 'Training table advances the Registration Desk schema version' );
$tables = $schema_class::table_names( 'wp_' );
oras_training_assert( 'wp_oras_registration_desk_training_sessions' === ( $tables['training_sessions'] ?? '' ), 'Training table has a dedicated physical name' );

$sql    = $schema_class::build_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
$joined = implode( "\n", $sql );
oras_training_assert( 6 === count( $sql ), 'Schema defines five live stores and one isolated training store' );
oras_training_assert( false !== strpos( $joined, 'CREATE TABLE wp_oras_registration_desk_training_sessions' ), 'Schema creates the isolated training table' );
foreach (
	array(
		'training_uuid char(36) NOT NULL',
		'station_uuid char(36) NOT NULL',
		'user_id bigint(20) unsigned NOT NULL',
		'wp_session_digest char(64) NOT NULL',
		'event_id bigint(20) unsigned NOT NULL',
		'config_revision bigint(20) unsigned NOT NULL',
		'simulated_local_date date NOT NULL',
		'state_json longtext NOT NULL',
		'record_version bigint(20) unsigned NOT NULL DEFAULT 1',
		'expires_at_utc datetime NOT NULL',
		'UNIQUE KEY station_uuid (station_uuid)',
		'ENGINE=InnoDB',
	) as $fragment
) {
	oras_training_assert( false !== strpos( $joined, $fragment ), "Training schema contains {$fragment}" );
}
oras_training_assert( false === strpos( (string) file_get_contents( $store ), 'oras_event_registrations' ), 'Training Store never references the live registration table' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.

oras_training_assert( class_exists( $store_class ), 'Training Store class loads' );
foreach ( array( 'create', 'find_for_station', 'mutate', 'reset', 'delete_for_station', 'cleanup_expired' ) as $method ) {
	oras_training_assert( method_exists( $store_class, $method ), "Training Store exposes {$method}" );
}

echo "Registration Desk training checks passed.\n";
