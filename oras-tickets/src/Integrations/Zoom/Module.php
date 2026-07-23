<?php

namespace ORAS\Tickets\Integrations\Zoom;

require_once __DIR__ . '/Settings.php'; // NOSONAR include: Zoom settings
require_once __DIR__ . '/OAuth_Client.php'; // NOSONAR include: Zoom OAuth
require_once __DIR__ . '/Api_Interface.php'; // NOSONAR include: Zoom API contract
require_once __DIR__ . '/Api_Client.php'; // NOSONAR include: Zoom API client
require_once __DIR__ . '/Meeting_Service.php'; // NOSONAR include: Zoom meeting mapping
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

		if ( is_admin() ) {
			add_action(
				'admin_post_oras_tickets_zoom_test_connection',
				array( $this, 'handle_test_connection' )
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
}
