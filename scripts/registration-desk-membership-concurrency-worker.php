<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Guarded independent-process fixture.
/** Independent guarded membership writer or database-lock holder. */

use ORAS\Tickets\Registration_Desk\Membership_Credit_Service;
use ORAS\Tickets\Support\DbLock;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$expected = defined( 'ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED' ) ? (string) ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED : '';
$race_mode = defined( 'ORAS_REGISTRATION_DESK_MEMBERSHIP_RACE_MODE' ) ? (string) ORAS_REGISTRATION_DESK_MEMBERSHIP_RACE_MODE : '';
$worker = defined( 'ORAS_REGISTRATION_DESK_WORKER_INDEX' ) ? (int) ORAS_REGISTRATION_DESK_WORKER_INDEX : 0;
if ( '' === $expected || ! hash_equals( $expected, (string) get_option( 'oras_registration_desk_disposable_fixture_id', '' ) ) || 'tests-wordpress' !== DB_NAME || 'tests-mysql' !== DB_HOST || ! defined( 'ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE' ) || ! ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE || ! in_array( $race_mode, array( 'hold', 'create' ), true ) || ( 'create' === $race_mode && ! in_array( $worker, array( 1, 2 ), true ) ) ) {
	WP_CLI::error( 'Membership concurrency worker refused an unverified runtime.' );
}
$context = get_option( 'oras_registration_desk_integration_context', array() );
$fixture = $context['membership_concurrency'] ?? array();
if ( ! is_array( $fixture ) || empty( $fixture['request_uuid'] ) ) {
	WP_CLI::error( 'Membership concurrency fixture is missing.' );
}

/** Poll a cross-process database barrier; do not use process-local option caches. */
function oras_membership_race_wait( string $suffix ): void {
	global $wpdb;
	$name = 'oras_registration_desk_membership_race_' . $suffix;
	$deadline = microtime( true ) + 45;
	while ( microtime( true ) < $deadline ) {
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core options table.
		if ( 'yes' === $value ) {
			return;
		}
		usleep( 50000 );
	}
	WP_CLI::error( 'Membership concurrency barrier timed out.' );
}

if ( 'hold' === $race_mode ) {
	$result = DbLock::withLock(
		'desk-membership:' . $fixture['request_uuid'],
		static function () {
			update_option( 'oras_registration_desk_membership_race_hold_ready', 'yes', false );
			oras_membership_race_wait( 'release_hold' );
			return true;
		}
	);
	if ( true !== $result ) {
		WP_CLI::error( 'Membership concurrency holder failed to obtain the database lock.' );
	}
	WP_CLI::log( 'PASS: membership lock holder released' );
	return;
}

if ( ! function_exists( 'pmpro_getLevel' ) ) {
	function pmpro_getLevel( int $level_id ) {
		$levels = get_option( 'oras_registration_desk_test_pmpro_levels', array() );
		return isset( $levels[ $level_id ] ) ? (object) $levels[ $level_id ] : false;
	}
}
if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
	function pmpro_getAllLevels( bool $include_hidden = false, bool $use_cache = true ): array {
		unset( $include_hidden, $use_cache );
		$levels = get_option( 'oras_registration_desk_test_pmpro_levels', array() );
		return array_map( static fn( array $level ): object => (object) $level, array_values( $levels ) );
	}
}
if ( ! function_exists( 'pmpro_url' ) ) {
	function pmpro_url( string $page, string $query = '' ): string {
		return home_url( '/membership-account/membership-' . sanitize_key( $page ) . '/' . $query );
	}
}
if ( ! class_exists( 'PMPro_Discount_Code' ) ) {
	// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps, Generic.Classes.DuplicateClassName.Found -- Separate guarded WP-CLI processes need the same PMPro fixture.
	class PMPro_Discount_Code {
		public int $id = 0;
		public string $code = '';
		public string $starts = '';
		public string $expires = '';
		public int $uses = 0;
		/** @var array<int,array<string,mixed>> */
		public array $levels = array();
		public function save(): object {
			$this->id = absint( get_option( 'oras_registration_desk_test_pmpro_credit_id', 1000 ) ) + 1;
			update_option( 'oras_registration_desk_test_pmpro_credit_id', $this->id, false );
			return $this;
		}
	}
}

update_option( 'oras_registration_desk_membership_race_worker_ready_' . $worker, 'yes', false );
oras_membership_race_wait( 'release_workers' );
add_filter(
	'pre_wp_mail',
	static function ( $preempted ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", 'oras_registration_desk_membership_race_mail_calls' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Guarded fixture counter in the core options table.
		return $preempted;
	},
	PHP_INT_MAX
);
$result = ( new Membership_Credit_Service() )->create(
	array(
		'first_name'     => 'Concurrent',
		'last_name'      => 'Member',
		'email'          => 'concurrent-' . $context['run'] . '@example.test',
		'level_id'       => (int) $fixture['level_id'],
		'payment_method' => 'card',
	),
	array(
		'request_uuid'   => (string) $fixture['request_uuid'],
		'event_id'       => (int) $context['event_id'],
		'actor_user_id'  => (int) $context['desk_id'],
		'station_uuid'   => wp_generate_uuid4(),
		'operator_label' => 'Concurrent Membership',
	)
);
if ( ! is_array( $result ) || 'sent' !== $result['email_status'] ) {
	$code = is_wp_error( $result ) ? $result->get_error_code() : 'unexpected_result';
	WP_CLI::error( 'Membership concurrency worker failed with safe code: ' . $code );
}
WP_CLI::log(
	wp_json_encode(
		array(
			'worker'          => $worker,
			'activation_uuid' => $result['activation_uuid'],
			'email_sent'      => $result['email_sent'],
		)
	)
);
