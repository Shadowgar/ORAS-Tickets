<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['oras_zoom_test_options']    = array();
$GLOBALS['oras_zoom_test_transients'] = array();
$GLOBALS['oras_zoom_test_http_calls'] = array();
$GLOBALS['oras_zoom_test_http_response'] = null;
$GLOBALS['oras_zoom_test_post_meta'] = array();
$GLOBALS['oras_zoom_test_can_edit'] = true;
$GLOBALS['oras_zoom_test_nonce_valid'] = true;
$GLOBALS['oras_zoom_test_scheduled_actions'] = array();
$GLOBALS['oras_zoom_test_uuid_counter'] = 0;

final class WP_Error {
	private string $code;
	private string $message;
	private $data;

	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function add_data( $data ): void {
		$this->data = $data;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function get_option( string $key, $default = false ) {
	return $GLOBALS['oras_zoom_test_options'][ $key ] ?? $default;
}

function update_option( string $key, $value ): bool {
	$GLOBALS['oras_zoom_test_options'][ $key ] = $value;
	return true;
}

function get_transient( string $key ) {
	return $GLOBALS['oras_zoom_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, $value, int $expiration ): bool {
	unset( $expiration );
	$GLOBALS['oras_zoom_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['oras_zoom_test_transients'][ $key ] );
	return true;
}

function wp_salt( string $scheme = 'auth' ): string {
	unset( $scheme );
	return 'oras-zoom-test-salt-with-sufficient-length';
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_email( $value ): string {
	$email = filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL );
	return false === $email ? '' : strtolower( $email );
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?: '';
}

function absint( $value ): int {
	return abs( (int) $value );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function add_query_arg( array $args, string $url ): string {
	return $url . '?' . http_build_query( $args );
}

function wp_json_encode( $value ): string {
	return (string) json_encode( $value );
}

function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_post( string $url, array $args = array() ) {
	$GLOBALS['oras_zoom_test_http_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				'access_token' => 'zoom-access-token',
				'expires_in'   => 3600,
				'token_type'   => 'bearer',
			)
		),
	);
}

function wp_remote_request( string $url, array $args = array() ) {
	$GLOBALS['oras_zoom_test_http_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( is_array( $GLOBALS['oras_zoom_test_http_response'] ) ) {
		return $GLOBALS['oras_zoom_test_http_response'];
	}

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{}',
	);
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	unset( $single );
	if ( '' === $key ) {
		return $GLOBALS['oras_zoom_test_post_meta'][ $post_id ] ?? array();
	}

	return $GLOBALS['oras_zoom_test_post_meta'][ $post_id ][ $key ] ?? '';
}

function update_post_meta( int $post_id, string $key, $value ): bool {
	$GLOBALS['oras_zoom_test_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function current_time( string $type, bool $gmt = false ): string {
	unset( $type, $gmt );
	return '2026-07-23 12:00:00';
}

function current_user_can( string $capability, ...$args ): bool {
	unset( $capability, $args );
	return (bool) $GLOBALS['oras_zoom_test_can_edit'];
}

function wp_verify_nonce( string $nonce, string $action ): bool {
	unset( $nonce, $action );
	return (bool) $GLOBALS['oras_zoom_test_nonce_valid'];
}

function wp_unslash( $value ) {
	return $value;
}

function wp_is_post_revision( int $post_id ): bool {
	unset( $post_id );
	return false;
}

function wp_is_post_autosave( int $post_id ): bool {
	unset( $post_id );
	return false;
}

function wp_generate_uuid4(): string {
	++$GLOBALS['oras_zoom_test_uuid_counter'];
	return '00000000-0000-4000-8000-' . str_pad(
		(string) $GLOBALS['oras_zoom_test_uuid_counter'],
		12,
		'0',
		STR_PAD_LEFT
	);
}

function as_has_scheduled_action( string $hook, array $args, string $group ): bool {
	foreach ( $GLOBALS['oras_zoom_test_scheduled_actions'] as $action ) {
		if ( $hook === $action['hook'] && $args === $action['args'] && $group === $action['group'] ) {
			return true;
		}
	}
	return false;
}

function as_enqueue_async_action( string $hook, array $args, string $group ): int {
	$GLOBALS['oras_zoom_test_scheduled_actions'][] = array(
		'hook'  => $hook,
		'args'  => $args,
		'group' => $group,
	);
	return count( $GLOBALS['oras_zoom_test_scheduled_actions'] );
}

function wp_parse_url( string $url ) {
	return parse_url( $url );
}

function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

/**
 * @return array<int,array{url:string,args:array<string,mixed>}>
 */
function oras_zoom_http_calls(): array {
	return $GLOBALS['oras_zoom_test_http_calls'];
}

/**
 * @throws RuntimeException When an assertion fails.
 */
function oras_zoom_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings_file = dirname( __DIR__ ) . '/src/Integrations/Zoom/Settings.php';
$oauth_file    = dirname( __DIR__ ) . '/src/Integrations/Zoom/OAuth_Client.php';
$api_file      = dirname( __DIR__ ) . '/src/Integrations/Zoom/Api_Client.php';
$meeting_file  = dirname( __DIR__ ) . '/src/Integrations/Zoom/Meeting_Service.php';
$api_interface_file = dirname( __DIR__ ) . '/src/Integrations/Zoom/Api_Interface.php';
$repository_interface_file = dirname( __DIR__ ) . '/src/Integrations/Zoom/Registration_Repository.php';
$registration_store_file = dirname( __DIR__ ) . '/src/Integrations/Zoom/Registration_Store.php';
$registration_service_file = dirname( __DIR__ ) . '/src/Integrations/Zoom/Registration_Service.php';
$rsvp_lifecycle_file = dirname( __DIR__ ) . '/src/Integrations/Zoom/Rsvp_Lifecycle.php';
$event_zoom_metabox_file = dirname( __DIR__ ) . '/includes/Admin/Metaboxes/Event_Zoom_Metabox.php';

require_once $settings_file;
require_once $oauth_file;
require_once $api_interface_file;
require_once $api_file;
require_once $meeting_file;
require_once $repository_interface_file;
require_once $registration_store_file;
require_once $registration_service_file;
require_once $rsvp_lifecycle_file;
require_once $event_zoom_metabox_file;

use ORAS\Tickets\Admin\Metaboxes\Event_Zoom_Metabox;
use ORAS\Tickets\Integrations\Zoom\Api_Client;
use ORAS\Tickets\Integrations\Zoom\Api_Interface;
use ORAS\Tickets\Integrations\Zoom\Meeting_Service;
use ORAS\Tickets\Integrations\Zoom\OAuth_Client;
use ORAS\Tickets\Integrations\Zoom\Registration_Repository;
use ORAS\Tickets\Integrations\Zoom\Registration_Service;
use ORAS\Tickets\Integrations\Zoom\Registration_Store;
use ORAS\Tickets\Integrations\Zoom\Rsvp_Lifecycle;
use ORAS\Tickets\Integrations\Zoom\Settings;

final class Oras_Zoom_Fake_Api implements Api_Interface {
	public int $registrations = 0;
	public int $cancellations = 0;
	/** @var array<string,mixed> */
	public array $meeting_update = array();
	public bool $apply_meeting_update = true;
	/** @var callable|null */
	public $on_update = null;

	public function get_meeting( string $meeting_id ) {
		return array(
			'id'       => $meeting_id,
			'settings' => $this->apply_meeting_update ? $this->meeting_update : array(),
		);
	}

	public function get_meeting_invitation( string $meeting_id ) {
		return array( 'invitation' => 'Meeting ID: ' . $meeting_id );
	}

	public function update_meeting( string $meeting_id, array $settings ) {
		unset( $meeting_id );
		$this->meeting_update = $settings;
		if ( is_callable( $this->on_update ) ) {
			( $this->on_update )();
		}
		return true;
	}

	public function add_meeting_registrant( string $meeting_id, array $registrant ) {
		++$this->registrations;
		return array(
			'id'            => $meeting_id,
			'registrant_id' => 'registrant-' . $this->registrations,
			'join_url'      => 'https://us02web.zoom.us/w/' . $meeting_id . '?tk=private-' . $this->registrations,
		);
	}

	public function update_registrant_status( string $meeting_id, string $registrant_id, string $email, string $action ) {
		unset( $meeting_id, $registrant_id, $email );
		if ( 'cancel' === $action ) {
			++$this->cancellations;
		}
		return true;
	}
}

final class Oras_Zoom_Fake_Repository implements Registration_Repository {
	/** @var array<string,mixed> */
	public array $registration = array();

	/** @var array<string,bool> */
	public array $sources = array();

	public function find_by_event_email( int $event_id, string $meeting_id, string $email ): array {
		if ( ! empty( $this->registration )
			&& $event_id === $this->registration['event_id']
			&& $meeting_id === $this->registration['meeting_id']
			&& $email === $this->registration['email']
		) {
			return $this->registration;
		}
		return array();
	}

	public function save_registration( array $registration ): array {
		$this->registration = array_merge(
			$this->registration,
			$registration,
			array( 'id' => 77 )
		);
		return $this->registration;
	}

	public function activate_source( int $registration_id, string $source_type, string $source_ref ): bool {
		$this->sources[ $registration_id . ':' . $source_type . ':' . $source_ref ] = true;
		return true;
	}

	public function deactivate_source( int $registration_id, string $source_type, string $source_ref ): bool {
		$this->sources[ $registration_id . ':' . $source_type . ':' . $source_ref ] = false;
		return true;
	}

	public function has_active_sources( int $registration_id ): bool {
		foreach ( $this->sources as $key => $active ) {
			if ( 0 === strpos( $key, $registration_id . ':' ) && $active ) {
				return true;
			}
		}
		return false;
	}

	public function update_status( int $registration_id, string $status, string $error = '' ): bool {
		unset( $registration_id );
		$this->registration['status'] = $status;
		$this->registration['last_error'] = $error;
		return true;
	}
}

try {
	$stored = Settings::prepare_for_storage(
		array(
			'enabled'       => true,
			'account_id'    => 'zoom-account',
			'client_id'     => 'zoom-client',
			'client_secret' => 'zoom-secret',
		)
	);

	oras_zoom_assert( 'zoom-secret' !== $stored['client_secret'], 'Zoom client secret is encrypted before storage' );
	oras_zoom_assert( 0 === strpos( (string) $stored['client_secret'], 'oraszoom:v1:' ), 'Encrypted Zoom secret uses a versioned envelope' );

	$hydrated = Settings::hydrate_from_storage( $stored );
	oras_zoom_assert( 'zoom-secret' === $hydrated['client_secret'], 'Encrypted Zoom client secret decrypts correctly' );

	Settings::update(
		array(
			'enabled'       => true,
			'account_id'    => 'zoom-account',
			'client_id'     => 'zoom-client',
			'client_secret' => 'zoom-secret',
		)
	);
	oras_zoom_assert( Settings::is_enabled(), 'Zoom integration reports enabled after configuration' );
	oras_zoom_assert( Settings::has_credentials(), 'Zoom integration recognizes complete credentials' );
	$persisted_settings = get_option( Settings::OPTION_KEY, array() );
	oras_zoom_assert( is_array( $persisted_settings ), 'Zoom settings persist in the shared option' );
	$persisted_zoom = isset( $persisted_settings['zoom'] ) && is_array( $persisted_settings['zoom'] )
		? $persisted_settings['zoom']
		: array();
	oras_zoom_assert(
		'zoom-secret' !== (string) ( $persisted_zoom['client_secret'] ?? '' ),
		'Persisted Zoom client secret is not plaintext'
	);

	$oauth = new OAuth_Client();
	$token = $oauth->get_access_token();
	$http_calls = oras_zoom_http_calls();
	oras_zoom_assert( 'zoom-access-token' === $token, 'OAuth client returns the Zoom access token' );
	oras_zoom_assert( 1 === count( $http_calls ), 'OAuth client performs one token request' );
	oras_zoom_assert(
		false !== strpos( $http_calls[0]['url'], 'grant_type=account_credentials' ),
		'OAuth token request uses the account credentials grant'
	);
	oras_zoom_assert(
		false !== strpos( $http_calls[0]['url'], 'account_id=zoom-account' ),
		'OAuth token request includes the configured account ID'
	);

	$cached_token = $oauth->get_access_token();
	oras_zoom_assert( 'zoom-access-token' === $cached_token, 'OAuth client reuses a cached access token' );
	oras_zoom_assert( 1 === count( $GLOBALS['oras_zoom_test_http_calls'] ), 'Cached token avoids a second HTTP request' );

	Settings::update(
		array(
			'enabled'       => true,
			'account_id'    => '',
			'client_id'     => '',
			'client_secret' => '',
		)
	);
	delete_transient( OAuth_Client::TOKEN_TRANSIENT );
	$missing = ( new OAuth_Client() )->get_access_token();
	oras_zoom_assert( is_wp_error( $missing ), 'OAuth client rejects missing credentials' );
	oras_zoom_assert( 'oras_zoom_missing_credentials' === $missing->get_error_code(), 'Missing credential error is actionable' );

	oras_zoom_assert(
		'89821762143' === Meeting_Service::extract_meeting_id_from_url(
			'https://us02web.zoom.us/j/89821762143?pwd=private&from=addon'
		),
		'Meeting resolver extracts a numeric Zoom meeting ID'
	);
	oras_zoom_assert(
		'89821762143' === Meeting_Service::extract_meeting_id_from_url(
			'https://zoom.us/wc/89821762143/join'
		),
		'Meeting resolver supports Zoom web client URLs'
	);
	oras_zoom_assert(
		'' === Meeting_Service::extract_meeting_id_from_url(
			'https://zoom.us.attacker.example/j/89821762143'
		),
		'Meeting resolver rejects Zoom lookalike hosts'
	);

	$GLOBALS['oras_zoom_test_post_meta'][42] = array(
		'_EventZoomMeetingLink' => 'https://us02web.zoom.us/j/89821762143?pwd=private',
	);
	oras_zoom_assert( '89821762143' === Meeting_Service::resolve_meeting_id( 42 ), 'Meeting resolver uses TEC Zoom URL metadata' );

	$invitation = Meeting_Service::parse_invitation(
		"ORAS is inviting you to a scheduled Zoom meeting.\r\n\r\n"
		. "Join Zoom Meeting\r\nhttps://us02web.zoom.us/j/89821762143?pwd=private\r\n\r\n"
		. "Meeting ID: 898 2176 2143\r\nPasscode: 991108\r\n\r\n"
		. "One tap mobile\r\n+13126266799,,89821762143# US (Chicago)\r\n"
		. "+16465588656,,89821762143# US (New York)\r\n\r\n"
		. "Find your local number: https://us02web.zoom.us/u/example\r\n"
	);
	oras_zoom_assert( '89821762143' === $invitation['meeting_id'], 'Invitation parser normalizes meeting ID digits' );
	oras_zoom_assert( '991108' === $invitation['passcode'], 'Invitation parser extracts passcode' );
	oras_zoom_assert( 2 === count( $invitation['one_tap_mobile'] ), 'Invitation parser extracts one-tap mobile numbers' );
	oras_zoom_assert(
		'https://us02web.zoom.us/u/example' === $invitation['local_number_url'],
		'Invitation parser extracts local dial-in URL'
	);
	$fake_invitation_api = new Oras_Zoom_Fake_Api();
	$resolved_invitation = ( new Meeting_Service( $fake_invitation_api ) )->get_invitation_for_event( 42 );
	oras_zoom_assert( ! is_wp_error( $resolved_invitation ), 'Meeting invitation service supports an injected API client' );
	$unattended_result = ( new Meeting_Service( $fake_invitation_api ) )->configure_unattended_access( 42 );
	oras_zoom_assert( ! is_wp_error( $unattended_result ), 'Meeting service configures unattended access' );
	oras_zoom_assert(
		array(
			'join_before_host' => true,
			'jbh_time'         => 0,
			'waiting_room'     => false,
		) === $fake_invitation_api->meeting_update,
		'Unattended access uses Zoom join-anytime settings and disables the waiting room'
	);
	$GLOBALS['oras_zoom_test_post_meta'][42]['_oras_zoom_integration_v1'] = array(
		'version'           => 1,
		'enabled'           => true,
		'meeting_id'        => '89821762143',
		'unattended_access' => true,
	);
	Settings::update(
		array(
			'enabled'       => true,
			'account_id'    => 'zoom-account',
			'client_id'     => 'zoom-client',
			'client_secret' => 'zoom-secret',
		)
	);
	$event_sync_result = Event_Zoom_Metabox::synchronize_unattended_access(
		42,
		new Meeting_Service( $fake_invitation_api )
	);
	oras_zoom_assert( ! is_wp_error( $event_sync_result ), 'Event Zoom policy synchronizes unattended access' );
	$event_zoom_config = get_post_meta( 42, '_oras_zoom_integration_v1', true );
	oras_zoom_assert(
		is_array( $event_zoom_config ) && 'success' === ( $event_zoom_config['sync_status'] ?? '' ),
		'Successful event synchronization is persisted'
	);
	oras_zoom_assert(
		is_array( $event_zoom_config ) && '2026-07-23 12:00:00' === ( $event_zoom_config['synced_at'] ?? '' ),
		'Event synchronization time is persisted'
	);
	$locked_policy_api = new Oras_Zoom_Fake_Api();
	$locked_policy_api->apply_meeting_update = false;
	$locked_policy_result = Event_Zoom_Metabox::synchronize_unattended_access(
		42,
		new Meeting_Service( $locked_policy_api )
	);
	oras_zoom_assert(
		is_wp_error( $locked_policy_result )
		&& 'oras_zoom_unattended_settings_not_applied' === $locked_policy_result->get_error_code(),
		'Locked Zoom account policy produces an actionable synchronization error'
	);
	$event_zoom_config = get_post_meta( 42, '_oras_zoom_integration_v1', true );
	oras_zoom_assert(
		is_array( $event_zoom_config ) && 'error' === ( $event_zoom_config['sync_status'] ?? '' ),
		'Failed event synchronization is persisted for the event editor'
	);
	$_POST = array(
		'oras_event_zoom_nonce' => 'valid',
		'oras_event_zoom'       => array(
			'enabled'           => '1',
			'meeting_id'        => '89821762143',
			'unattended_access' => '1',
		),
	);
	Event_Zoom_Metabox::save( 43 );
	$saved_event_config = get_post_meta( 43, '_oras_zoom_integration_v1', true );
	oras_zoom_assert(
		is_array( $saved_event_config )
		&& ! empty( $saved_event_config['unattended_access'] )
		&& 'pending' === ( $saved_event_config['sync_status'] ?? '' ),
		'Event save persists unattended access and marks synchronization pending'
	);
	oras_zoom_assert(
		1 === count( $GLOBALS['oras_zoom_test_scheduled_actions'] )
		&& 'oras_tickets_zoom_sync_event_async' === $GLOBALS['oras_zoom_test_scheduled_actions'][0]['hook']
		&& 3 === count( $GLOBALS['oras_zoom_test_scheduled_actions'][0]['args'] ),
		'Event save queues Zoom synchronization instead of blocking on network calls'
	);
	$queued_revision = is_array( $saved_event_config )
		? (string) ( $saved_event_config['sync_revision'] ?? '' )
		: '';
	oras_zoom_assert( '' !== $queued_revision, 'Queued synchronization receives a revision token' );

	$race_api = new Oras_Zoom_Fake_Api();
	$race_api->on_update = static function (): void {
		$current = get_post_meta( 43, '_oras_zoom_integration_v1', true );
		$current = is_array( $current ) ? $current : array();
		$current['unattended_access'] = false;
		$current['sync_status'] = '';
		update_post_meta( 43, '_oras_zoom_integration_v1', $current );
	};
	$race_result = Event_Zoom_Metabox::synchronize_unattended_access(
		43,
		new Meeting_Service( $race_api ),
		$queued_revision
	);
	oras_zoom_assert(
		is_wp_error( $race_result ) && 'oras_zoom_stale_sync' === $race_result->get_error_code(),
		'In-flight synchronization rejects a newer event configuration'
	);
	$race_config = get_post_meta( 43, '_oras_zoom_integration_v1', true );
	oras_zoom_assert(
		is_array( $race_config )
		&& empty( $race_config['unattended_access'] )
		&& '' === ( $race_config['sync_status'] ?? '' ),
		'In-flight synchronization does not overwrite newer event settings'
	);

	unset( $_POST['oras_event_zoom']['unattended_access'] );
	Event_Zoom_Metabox::save( 43 );
	$saved_event_config = get_post_meta( 43, '_oras_zoom_integration_v1', true );
	oras_zoom_assert(
		is_array( $saved_event_config )
		&& empty( $saved_event_config['unattended_access'] )
		&& '' === ( $saved_event_config['sync_status'] ?? '' ),
		'Disabling unattended access clears stale synchronization status'
	);
	oras_zoom_assert(
		1 === count( $GLOBALS['oras_zoom_test_scheduled_actions'] ),
		'Disabling unattended access does not queue another Zoom update'
	);

	$GLOBALS['oras_zoom_test_nonce_valid'] = false;
	$_POST['oras_event_zoom']['unattended_access'] = '1';
	Event_Zoom_Metabox::save( 43 );
	$nonce_rejected_config = get_post_meta( 43, '_oras_zoom_integration_v1', true );
	oras_zoom_assert(
		$saved_event_config === $nonce_rejected_config,
		'Event Zoom policy save rejects an invalid nonce without changing configuration'
	);
	$GLOBALS['oras_zoom_test_nonce_valid'] = true;
	$GLOBALS['oras_zoom_test_can_edit'] = false;
	unset( $_POST['oras_event_zoom']['enabled'] );
	Event_Zoom_Metabox::save( 43 );
	$capability_rejected_config = get_post_meta( 43, '_oras_zoom_integration_v1', true );
	oras_zoom_assert(
		$nonce_rejected_config === $capability_rejected_config,
		'Event Zoom policy save rejects users who cannot edit the event'
	);
	$GLOBALS['oras_zoom_test_can_edit'] = true;
	unset( $_POST );

	Settings::update(
		array(
			'enabled'       => true,
			'account_id'    => 'zoom-account',
			'client_id'     => 'zoom-client',
			'client_secret' => 'zoom-secret',
		)
	);
	set_transient( OAuth_Client::TOKEN_TRANSIENT, 'zoom-access-token', 3000 );
	$GLOBALS['oras_zoom_test_http_response'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'invitation' => 'Meeting ID: 898 2176 2143' ) ),
	);
	$api_invitation = ( new Api_Client() )->get_meeting_invitation( '89821762143' );
	oras_zoom_assert( ! is_wp_error( $api_invitation ), 'Zoom API client retrieves meeting invitation' );
	oras_zoom_assert(
		'Meeting ID: 898 2176 2143' === $api_invitation['invitation'],
		'Zoom API client returns decoded invitation data'
	);
	$http_calls = oras_zoom_http_calls();
	$last_http_call = $http_calls[ count( $http_calls ) - 1 ];
	oras_zoom_assert(
		'https://api.zoom.us/v2/meetings/89821762143/invitation' === $last_http_call['url'],
		'Zoom API client uses the fixed official API host'
	);
	oras_zoom_assert(
		'Bearer zoom-access-token' === $last_http_call['args']['headers']['Authorization'],
		'Zoom API client authenticates with the OAuth bearer token'
	);
	$GLOBALS['oras_zoom_test_http_response'] = array(
		'response' => array( 'code' => 204 ),
		'body'     => '',
	);
	$api_update = ( new Api_Client() )->update_meeting(
		'89821762143',
		array(
			'join_before_host' => true,
			'jbh_time'         => 0,
			'waiting_room'     => false,
		)
	);
	oras_zoom_assert( true === $api_update, 'Zoom API client accepts a successful meeting update' );
	$http_calls = oras_zoom_http_calls();
	$last_http_call = $http_calls[ count( $http_calls ) - 1 ];
	oras_zoom_assert( 'PATCH' === $last_http_call['args']['method'], 'Zoom meeting update uses PATCH' );
	$update_body = json_decode( (string) $last_http_call['args']['body'], true );
	oras_zoom_assert(
		is_array( $update_body )
		&& true === $update_body['settings']['join_before_host']
		&& 0 === $update_body['settings']['jbh_time']
		&& false === $update_body['settings']['waiting_room'],
		'Zoom meeting update sends the unattended-access settings envelope'
	);

	$schema_source = file_get_contents( $registration_store_file );
	oras_zoom_assert(
		is_string( $schema_source ) && false !== strpos( $schema_source, 'oras_zoom_registrations' ),
		'Zoom registration store defines its registration table'
	);
	oras_zoom_assert(
		is_string( $schema_source ) && false !== strpos( $schema_source, 'oras_zoom_registration_sources' ),
		'Zoom registration store defines separate entitlement sources'
	);
	oras_zoom_assert(
		is_string( $schema_source ) && false !== strpos( $schema_source, 'Settings::protect_private_value' ),
		'Zoom registration store protects private join URLs at rest'
	);

	$GLOBALS['oras_zoom_test_post_meta'][42]['_oras_zoom_integration_v1'] = array(
		'enabled'    => true,
		'meeting_id' => '89821762143',
	);
	$fake_api = new Oras_Zoom_Fake_Api();
	$fake_repository = new Oras_Zoom_Fake_Repository();
	$registration_service = new Registration_Service( $fake_api, $fake_repository );

	$first_registration = $registration_service->register_attendee(
		42,
		'ticket',
		'order-100',
		'person@example.org',
		'Paul',
		'Rocco',
		10
	);
	oras_zoom_assert( ! is_wp_error( $first_registration ), 'Managed event attendee registers with Zoom' );
	oras_zoom_assert( 1 === $fake_api->registrations, 'First entitlement creates one Zoom registrant' );
	oras_zoom_assert(
		false !== strpos( (string) $first_registration['join_url'], 'tk=private-1' ),
		'Registration service returns the attendee-specific join URL'
	);

	$second_registration = $registration_service->register_attendee(
		42,
		'ticket',
		'order-101',
		'PERSON@example.org',
		'Paul',
		'Rocco',
		10
	);
	oras_zoom_assert( ! is_wp_error( $second_registration ), 'Second entitlement reuses existing registration' );
	oras_zoom_assert( 1 === $fake_api->registrations, 'Same event and email do not create a duplicate Zoom registrant' );

	$first_cancel = $registration_service->cancel_entitlement( 42, 'ticket', 'order-100', 'person@example.org' );
	oras_zoom_assert( ! is_wp_error( $first_cancel ), 'First entitlement can be deactivated' );
	oras_zoom_assert( 0 === $fake_api->cancellations, 'Zoom registration remains active while another entitlement exists' );

	$second_cancel = $registration_service->cancel_entitlement( 42, 'ticket', 'order-101', 'person@example.org' );
	oras_zoom_assert( ! is_wp_error( $second_cancel ), 'Final entitlement can be deactivated' );
	oras_zoom_assert( 1 === $fake_api->cancellations, 'Final entitlement cancellation cancels Zoom registration' );
	oras_zoom_assert( 'cancelled' === $fake_repository->registration['status'], 'Cancelled registration state is persisted' );

	$rsvp_api = new Oras_Zoom_Fake_Api();
	$rsvp_repository = new Oras_Zoom_Fake_Repository();
	$rsvp_registrations = new Registration_Service( $rsvp_api, $rsvp_repository );
	$rsvp_lifecycle = new Rsvp_Lifecycle(
		$rsvp_registrations,
		new Meeting_Service( $rsvp_api )
	);
	$rsvp_lifecycle->handle_approval_status_changed(
		42,
		22,
		'approved',
		array(
			'email'      => 'rsvp@example.org',
			'first_name' => 'Stella',
			'last_name'  => 'Observer',
		),
		'virtual'
	);
	oras_zoom_assert( 1 === $rsvp_api->registrations, 'Approved virtual RSVP creates a Zoom registrant' );
	$rsvp_access = $rsvp_lifecycle->filter_access_details(
		array( 'join_url' => 'https://us02web.zoom.us/j/89821762143?pwd=shared' ),
		42,
		22,
		'approved',
		'rsvp@example.org'
	);
	oras_zoom_assert(
		false !== strpos( (string) $rsvp_access['join_url'], 'tk=private-1' ),
		'Approved virtual RSVP receives its attendee-specific join URL'
	);
	$rsvp_lifecycle->handle_approval_status_changed(
		42,
		22,
		'rejected',
		array( 'email' => 'rsvp@example.org' ),
		'virtual'
	);
	oras_zoom_assert( 1 === $rsvp_api->cancellations, 'Rejected virtual RSVP cancels its Zoom registrant' );
	$rejected_access = $rsvp_lifecycle->filter_access_details(
		array( 'join_url' => '' ),
		42,
		22,
		'rejected',
		'rsvp@example.org'
	);
	oras_zoom_assert( '' === $rejected_access['join_url'], 'Rejected virtual RSVP never receives a Zoom join URL' );

	$ticket_email_file = dirname( __DIR__ ) . '/includes/Commerce/Woo/Virtual_Ticket_Access_Email.php';
	$ticket_email_source = file_get_contents( $ticket_email_file );
	oras_zoom_assert(
		is_string( $ticket_email_source ) && false !== strpos( $ticket_email_source, 'Registration_Service' ),
		'Paid virtual ticket email uses the Zoom registration service'
	);
	oras_zoom_assert(
		false !== strpos( $ticket_email_source, "woocommerce_order_status_cancelled" )
		&& false !== strpos( $ticket_email_source, "woocommerce_order_status_refunded" ),
		'Paid virtual ticket integration revokes Zoom entitlement on cancellation and refund'
	);
	oras_zoom_assert(
		false !== strpos( $ticket_email_source, "source_reference( \$order )" ),
		'Paid virtual ticket registration uses a stable order entitlement source'
	);
	oras_zoom_assert(
		false !== strpos( $ticket_email_source, "'Meeting ID'" )
		&& false !== strpos( $ticket_email_source, "'Passcode'" )
		&& false !== strpos( $ticket_email_source, "'One tap mobile'" ),
		'Paid virtual ticket email renders complete Zoom invitation details'
	);

	$rsvp_file = dirname( __DIR__ ) . '/includes/Frontend/Event_RSVP.php';
	$rsvp_lifecycle_source = is_file( $rsvp_lifecycle_file ) ? file_get_contents( $rsvp_lifecycle_file ) : '';
	$rsvp_source = file_get_contents( $rsvp_file );
	oras_zoom_assert(
		is_string( $rsvp_lifecycle_source )
		&& false !== strpos( $rsvp_lifecycle_source, 'oras_tickets_rsvp_approval_status_changed' )
		&& false !== strpos( $rsvp_lifecycle_source, 'oras_tickets_rsvp_cancelled' ),
		'Zoom RSVP lifecycle listens for approval and cancellation changes'
	);
	oras_zoom_assert(
		false !== strpos( $rsvp_lifecycle_source, "'rsvp',")
		&& false !== strpos( $rsvp_lifecycle_source, "'user-' . \$user_id" ),
		'Zoom RSVP registration uses a stable RSVP entitlement source'
	);
	oras_zoom_assert(
		is_string( $rsvp_source )
		&& false !== strpos( $rsvp_source, "do_action(\n            'oras_tickets_rsvp_approval_status_changed'" )
		&& false !== strpos( $rsvp_source, "do_action(\n            'oras_tickets_rsvp_cancelled'" ),
		'RSVP workflow publishes lifecycle events after state changes'
	);
	oras_zoom_assert(
		false !== strpos( $rsvp_source, 'oras_tickets_virtual_rsvp_access_details' ),
		'Approved virtual RSVP email can receive attendee-specific Zoom details'
	);

	$module_file = dirname( __DIR__ ) . '/src/Integrations/Zoom/Module.php';
	$metabox_file = dirname( __DIR__ ) . '/includes/Admin/Metaboxes/Event_Zoom_Metabox.php';
	$settings_page_file = dirname( __DIR__ ) . '/includes/Admin/Pages/Settings_Page.php';
	$admin_menu_file = dirname( __DIR__ ) . '/includes/Admin/Admin_Menu.php';
	$event_addon_file = dirname( __DIR__ ) . '/includes/Admin/Event_Addon_Metabox.php';
	$bootstrap_file = dirname( __DIR__ ) . '/includes/Bootstrap.php';
	$plugin_file = dirname( __DIR__ ) . '/oras-tickets.php';

	$module_source = file_get_contents( $module_file );
	oras_zoom_assert(
		is_string( $module_source ) && false !== strpos( $module_source, 'admin_post_oras_tickets_zoom_test_connection' ),
		'Zoom module registers an administrator connection-test action'
	);
	oras_zoom_assert(
		false !== strpos( $module_source, 'admin_post_oras_tickets_zoom_sync_event' ),
		'Zoom module registers an event synchronization action'
	);
	oras_zoom_assert(
		false !== strpos( $module_source, "'oras_tickets_zoom_sync_event_async'" )
		&& false !== strpos( $module_source, "array( \$this, 'handle_async_sync_event' ),\n\t\t\t10,\n\t\t\t3" ),
		'Zoom asynchronous synchronization accepts event, revision, and retry arguments'
	);
	oras_zoom_assert(
		false !== strpos( $module_source, "current_user_can( 'edit_post', \$event_id )" ),
		'Zoom event synchronization requires event edit permission'
	);
	oras_zoom_assert(
		false !== strpos( $module_source, "check_admin_referer( 'oras_tickets_zoom_sync_event_' . \$event_id )" ),
		'Zoom event synchronization verifies an event-specific nonce'
	);
	oras_zoom_assert(
		false !== strpos( $module_source, "current_user_can( 'oras_tickets_manage_settings' )" ),
		'Zoom connection test requires the settings capability'
	);
	oras_zoom_assert(
		false !== strpos( $module_source, "check_admin_referer( 'oras_tickets_zoom_test_connection' )" ),
		'Zoom connection test verifies its nonce'
	);

	$metabox_source = file_get_contents( $metabox_file );
	oras_zoom_assert(
		is_string( $metabox_source ) && false !== strpos( $metabox_source, "current_user_can( 'edit_post', \$post_id )" ),
		'Event Zoom settings require event edit permission'
	);
	oras_zoom_assert(
		false !== strpos( $metabox_source, 'wp_verify_nonce' ),
		'Event Zoom settings verify a save nonce'
	);
	oras_zoom_assert(
		false !== strpos( $metabox_source, Registration_Service::EVENT_CONFIG_META ),
		'Event Zoom settings use the shared integration configuration envelope'
	);

	$settings_page_source = file_get_contents( $settings_page_file );
	oras_zoom_assert(
		is_string( $settings_page_source ) && false !== strpos( $settings_page_source, "'default_managed_registration' => false" ),
		'Managed Zoom registration defaults off'
	);
	oras_zoom_assert(
		false !== strpos( $settings_page_source, 'Zoom Server-to-Server OAuth' ),
		'ORAS settings exposes Zoom Server-to-Server OAuth controls'
	);

	$admin_menu_source = file_get_contents( $admin_menu_file );
	oras_zoom_assert(
		is_string( $admin_menu_source ) && false !== strpos( $admin_menu_source, 'oras-tickets-zoom' ),
		'ORAS Tickets menu includes an administrator Zoom settings page'
	);

	$event_addon_source = file_get_contents( $event_addon_file );
	oras_zoom_assert(
		is_string( $event_addon_source ) && false !== strpos( $event_addon_source, 'oras-events-tab-zoom' ),
		'Unified event editor includes the Zoom Automation tab'
	);

	$bootstrap_source = file_get_contents( $bootstrap_file );
	oras_zoom_assert(
		is_string( $bootstrap_source ) && false !== strpos( $bootstrap_source, 'Integrations/Zoom/Module.php' ),
		'Bootstrap loads the Zoom integration module'
	);

	$plugin_source = file_get_contents( $plugin_file );
	oras_zoom_assert(
		is_string( $plugin_source ) && false !== strpos( $plugin_source, 'Zoom\\Registration_Store::install_schema' ),
		'Plugin activation installs the Zoom registration schema'
	);

	echo "Zoom integration checks passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'Zoom integration checks failed: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}
