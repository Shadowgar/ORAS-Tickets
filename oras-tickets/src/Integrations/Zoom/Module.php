<?php

namespace ORAS\Tickets\Integrations\Zoom;

require_once __DIR__ . '/Settings.php'; // NOSONAR include: Zoom settings
require_once __DIR__ . '/OAuth_Client.php'; // NOSONAR include: Zoom OAuth
require_once __DIR__ . '/Api_Interface.php'; // NOSONAR include: Zoom API contract
require_once __DIR__ . '/Api_Client.php'; // NOSONAR include: Zoom API client
require_once __DIR__ . '/Meeting_Service.php'; // NOSONAR include: Zoom meeting mapping
require_once __DIR__ . '/Phone_Join_Instructions.php'; // NOSONAR include: shared Zoom phone email guidance
require_once __DIR__ . '/Registration_Repository.php'; // NOSONAR include: repository contract
require_once __DIR__ . '/Registration_Store.php'; // NOSONAR include: registration persistence
require_once __DIR__ . '/Registration_Service.php'; // NOSONAR include: registration lifecycle
require_once __DIR__ . '/Rsvp_Lifecycle.php'; // NOSONAR include: RSVP registration lifecycle

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {

	private OAuth_Client $oauth;

	public function __construct( ?OAuth_Client $oauth = null ) {
		$this->oauth = $oauth ?? new OAuth_Client();
	}

	public function register(): void {
		Registration_Store::maybe_install_schema();
		( new Rsvp_Lifecycle() )->register();
		add_action(
			'oras_tickets_zoom_sync_event_async',
			array( $this, 'handle_async_sync_event' ),
			10,
			3
		);

		if ( is_admin() ) {
			add_action(
				'admin_post_oras_tickets_zoom_test_connection',
				array( $this, 'handle_test_connection' )
			);
			add_action(
				'admin_post_oras_tickets_zoom_sync_event',
				array( $this, 'handle_sync_event' )
			);
		}
	}

	public function handle_test_connection(): void {
		if ( ! current_user_can( 'oras_tickets_manage_settings' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to test the Zoom connection.', 'oras-tickets' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'oras_tickets_zoom_test_connection' );
		$this->oauth->clear_cached_token();
		$token = $this->oauth->get_access_token();
		$args  = array();

		if ( is_wp_error( $token ) ) {
			Settings::update(
				array(
					'last_connection_test_at' => current_time( 'mysql', true ),
					'last_error'              => $token->get_error_message(),
				)
			);
			$args['oras_zoom_error'] = rawurlencode( $token->get_error_message() );
		} else {
			Settings::update(
				array(
					'last_connection_test_at' => current_time( 'mysql', true ),
					'last_error'              => '',
				)
			);
			$args['oras_zoom_notice'] = rawurlencode(
				__( 'Zoom Server-to-Server OAuth connection succeeded.', 'oras-tickets' )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array( 'page' => 'oras-tickets-zoom' ),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_sync_event(): void {
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		if ( $event_id <= 0 || 'tribe_events' !== get_post_type( $event_id ) ) {
			wp_die(
				esc_html__( 'The selected event is invalid.', 'oras-tickets' ),
				'',
				array( 'response' => 400 )
			);
		}

		if ( ! current_user_can( 'edit_post', $event_id ) ) {
			wp_die(
				esc_html__( 'You do not have permission to synchronize this event.', 'oras-tickets' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'oras_tickets_zoom_sync_event_' . $event_id );
		$result = \ORAS\Tickets\Admin\Metaboxes\Event_Zoom_Metabox::synchronize_unattended_access( $event_id );
		$status = is_wp_error( $result ) ? 'error' : 'success';
		$target = get_edit_post_link( $event_id, 'raw' );
		if ( ! is_string( $target ) || '' === $target ) {
			$target = admin_url( 'post.php?post=' . $event_id . '&action=edit' );
		}

		wp_safe_redirect( add_query_arg( 'oras_zoom_sync', $status, $target ) );
		exit;
	}

	public function handle_async_sync_event( int $event_id, string $sync_revision = '', int $attempt = 0 ): void {
		if ( $event_id <= 0 || 'tribe_events' !== get_post_type( $event_id ) ) {
			return;
		}

		$result = \ORAS\Tickets\Admin\Metaboxes\Event_Zoom_Metabox::synchronize_unattended_access(
			$event_id,
			null,
			$sync_revision
		);
		if ( ! is_wp_error( $result ) || $attempt >= 3 ) {
			return;
		}

		$error_data = $result->get_error_data();
		$retriable  = is_array( $error_data ) && ! empty( $error_data['retriable'] );
		if ( ! $retriable ) {
			return;
		}

		$retry_delays = array( 60, 300, 900 );
		\ORAS\Tickets\Admin\Metaboxes\Event_Zoom_Metabox::mark_retry_scheduled(
			$event_id,
			$sync_revision,
			$attempt + 1,
			$retry_delays[ $attempt ]
		);
		\ORAS\Tickets\Admin\Metaboxes\Event_Zoom_Metabox::queue_unattended_access_sync(
			$event_id,
			$sync_revision,
			$attempt + 1,
			$retry_delays[ $attempt ]
		);
	}
}
