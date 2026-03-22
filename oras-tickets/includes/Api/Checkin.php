<?php

namespace ORAS\Tickets\Api;

use ORAS\Tickets\Security\TicketCheckinToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Checkin {

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
    }

    public function registerRoutes(): void {
        register_rest_route(
            'oras-tickets/v1',
            '/checkin/verify',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'verifyToken' ),
                'permission_callback' => array( $this, 'permissionCheckin' ),
                'args'                => array(
                    'token' => array(
                        'required'          => true,
                        'sanitize_callback' => array( $this, 'sanitizeToken' ),
                    ),
                ),
            )
        );

        register_rest_route(
            'oras-tickets/v1',
            '/checkin/mark',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'markCheckedIn' ),
                'permission_callback' => array( $this, 'permissionCheckin' ),
                'args'                => array(
                    'token' => array(
                        'required'          => true,
                        'sanitize_callback' => array( $this, 'sanitizeToken' ),
                    ),
                ),
            )
        );

        register_rest_route(
            'oras-tickets/v1',
            '/checkin/unmark',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'unmarkCheckedIn' ),
                'permission_callback' => array( $this, 'permissionCheckin' ),
                'args'                => array(
                    'token' => array(
                        'required'          => true,
                        'sanitize_callback' => array( $this, 'sanitizeToken' ),
                    ),
                ),
            )
        );
    }

    public function permissionCheckin(): bool {
        return current_user_can( 'oras_tickets_checkin' );
    }

    /**
     * @param mixed $value
     */
    public function sanitizeToken( $value ): string {
        return is_string( $value ) ? trim( $value ) : '';
    }

    public function verifyToken( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = $this->sanitizeToken( $request->get_param( 'token' ) );
        $result = TicketCheckinToken::verify( $token );

        return rest_ensure_response( $result );
    }

    public function markCheckedIn( \WP_REST_Request $request ): \WP_REST_Response {
        $token   = $this->sanitizeToken( $request->get_param( 'token' ) );
        $user_id = (int) get_current_user_id();
        $result  = TicketCheckinToken::markCheckedIn( $token, $user_id );

        return rest_ensure_response( $result );
    }

    public function unmarkCheckedIn( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = $this->sanitizeToken( $request->get_param( 'token' ) );
        $result = TicketCheckinToken::unmarkCheckedIn( $token );

        return rest_ensure_response( $result );
    }
}
