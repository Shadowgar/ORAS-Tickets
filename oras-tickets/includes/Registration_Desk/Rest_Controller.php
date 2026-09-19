<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Controller {
	private Service $service;
	private Projection_Service $projection;

	public function __construct( ?Service $service = null, ?Projection_Service $projection = null ) {
		$this->service    = $service ?? new Service();
		$this->projection = $projection ?? new Projection_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/station',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'station' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/project',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'project' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations/(?P<registration_uuid>[0-9a-f-]{36})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'detail' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations/(?P<registration_uuid>[0-9a-f-]{36})/confirm-and-check-in',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm_and_check_in' ),
				'permission_callback' => array( $this, 'permission_admit' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations/(?P<registration_uuid>[0-9a-f-]{36})/attendees/(?P<attendee_uuid>[0-9a-f-]{36})/reverse',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reverse' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/attendance/recent',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'recent' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'dashboard' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations/walk-in',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_walk_in' ),
				'permission_callback' => array( $this, 'permission_admit' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations/(?P<registration_uuid>[0-9a-f-]{36})/check-in',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'check_in' ),
				'permission_callback' => array( $this, 'permission_admit' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations/complimentary',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_complimentary' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/registrations/(?P<registration_uuid>[0-9a-f-]{36})/correct',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'correct_registration' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
	}

	public function permission_use(): bool {
		return is_user_logged_in() && current_user_can( 'oras_tickets_use_registration_desk' );
	}

	public function permission_admit(): bool {
		return is_user_logged_in() && current_user_can( 'oras_tickets_admit_registration_desk' );
	}

	public function permission_manage(): bool {
		return is_user_logged_in() && current_user_can( 'oras_tickets_manage_registration_desk' );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function station( \WP_REST_Request $request ) {
		$event_id = Config::get_active_event_id();
		$config   = Config::get_event_config( $event_id );
		if ( $event_id <= 0 || empty( $config['enabled'] ) ) {
			return new \WP_Error( 'oras_desk_inactive', 'Registration Desk is not enabled for an active event.', array( 'status' => 409 ) );
		}
		$label = sanitize_text_field( (string) $request->get_param( 'operator_label' ) );
		if ( '' === $label ) {
			return new \WP_Error( 'oras_desk_operator_required', 'Enter the operator name for this station.', array( 'status' => 400 ) );
		}
		$token = Station_Session::issue( get_current_user_id(), $event_id, (int) $config['revision'], $label );

		return $this->response(
			array(
				'station_token'   => $token,
				'event_id'        => $event_id,
				'event_title'     => get_the_title( $event_id ),
				'config_revision' => (int) $config['revision'],
				'operator_label'  => $label,
				'options'         => $config['options'],
				'local_date'      => wp_date( 'Y-m-d', null, wp_timezone() ),
				'friendly_date'   => wp_date( 'l, F j, Y', null, wp_timezone() ),
				'can_manage'      => current_user_can( 'oras_tickets_manage_registration_desk' ),
				'logout_url'      => html_entity_decode( wp_logout_url( Landing_Page::url() ), ENT_QUOTES, 'UTF-8' ),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function project( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$config = Config::get_event_config( $context['event_id'] );
		$result = $this->projection->reconcile_page( $context['event_id'], $config, trim( (string) $request->get_param( 'continuation' ) ), min( 100, max( 1, (int) $request->get_param( 'limit' ) ) ) );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function search( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $query ) < 2 ) {
			return new \WP_Error( 'oras_desk_search_short', 'Enter at least two characters.', array( 'status' => 400 ) );
		}
		$items = array_map( array( $this, 'public_registration' ), $this->service->search( $context['event_id'], $query ) );

		$coverage = ( new Coverage_Store() )->get( $context['event_id'], (int) $context['config_revision'] );
		return $this->response(
			array(
				'items'             => $items,
				'coverage'          => $coverage,
				'coverage_complete' => 'complete' === $coverage['status'],
				'limitations'       => array( 'M1A search includes listener-discovered and recovered direct individual registrations only.' ),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function detail( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$result = $this->service->detail( $context['event_id'], sanitize_text_field( (string) $request['registration_uuid'] ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$raw_registration = $result['registration'];
		if ( current_user_can( 'oras_tickets_manage_registration_desk' ) && 'online' !== (string) $raw_registration['source_type'] ) {
			$evidence = json_decode( (string) $raw_registration['source_evidence'], true );
			$address  = is_array( $evidence['mailing_address'] ?? null ) ? $evidence['mailing_address'] : array();
			$name     = preg_split( '/\s+/', trim( (string) $raw_registration['source_contact_name'] ), 2 );
			$result['editable_registration'] = array(
				'first_name'              => (string) ( $name[0] ?? '' ),
				'last_name'               => (string) ( $name[1] ?? '' ),
				'email'                   => (string) $raw_registration['source_email'],
				'phone'                   => (string) $raw_registration['source_phone'],
				'option_uuid'             => (string) $raw_registration['option_uuid'],
				'valid_local_date'        => (string) $raw_registration['valid_local_date'],
				'payment_assertion'       => (string) $raw_registration['payment_assertion'],
				'expected_record_version' => (int) $raw_registration['record_version'],
				'address_1'               => (string) ( $address['address_1'] ?? '' ),
				'address_2'               => (string) ( $address['address_2'] ?? '' ),
				'city'                    => (string) ( $address['city'] ?? '' ),
				'state'                   => (string) ( $address['state'] ?? '' ),
				'postcode'                => (string) ( $address['postcode'] ?? '' ),
			);
		}
		$result['registration'] = $this->public_registration( $result['registration'] );

		return $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function confirm_and_check_in( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$payload = array(
			'first_name'            => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
			'last_name'             => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
			'attendance_local_date' => sanitize_text_field( (string) $request->get_param( 'attendance_local_date' ) ),
			'explicit_unpaid'       => rest_sanitize_boolean( $request->get_param( 'explicit_unpaid' ) ),
		);
		$result = $this->service->confirm_and_check_in( sanitize_text_field( (string) $request['registration_uuid'] ), $payload, $context );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function reverse( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$payload = array(
			'attendance_local_date'   => sanitize_text_field( (string) $request->get_param( 'attendance_local_date' ) ),
			'expected_record_version' => absint( $request->get_param( 'expected_record_version' ) ),
			'reason'                  => sanitize_text_field( (string) $request->get_param( 'reason' ) ),
		);
		$result = $this->service->reverse( sanitize_text_field( (string) $request['registration_uuid'] ), sanitize_text_field( (string) $request['attendee_uuid'] ), $payload, $context );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function recent( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		$limit = absint( $request->get_param( 'limit' ) );

		return $this->response(
			array(
				'items'    => $this->service->recent( $context['event_id'], $limit > 0 ? $limit : 25 ),
				'event_id' => $context['event_id'],
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function dashboard( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		return $this->response( $this->service->dashboard( (int) $context['event_id'] ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_walk_in( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$result = $this->service->create_walk_in( $this->manual_payload( $request ), $context );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function check_in( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$arrivals = $request->get_param( 'arrivals' );
		$payload  = array(
			'attendance_local_date' => sanitize_text_field( (string) $request->get_param( 'attendance_local_date' ) ),
			'explicit_unpaid'       => rest_sanitize_boolean( $request->get_param( 'explicit_unpaid' ) ),
			'arrivals'              => is_array( $arrivals ) ? $arrivals : array(),
		);
		$result = $this->service->check_in( sanitize_text_field( (string) $request['registration_uuid'] ), $payload, $context );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_complimentary( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$result = $this->service->create_complimentary( $this->manual_payload( $request ), $context );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function correct_registration( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$payload = $this->manual_payload( $request );
		$payload['expected_record_version'] = absint( $request->get_param( 'expected_record_version' ) );
		$result = $this->service->correct_registration( sanitize_text_field( (string) $request['registration_uuid'] ), $payload, $context );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function context( \WP_REST_Request $request ) {
		$event_id = Config::get_active_event_id();
		$config   = Config::get_event_config( $event_id );
		if ( $event_id <= 0 || empty( $config['enabled'] ) ) {
			return new \WP_Error( 'oras_desk_inactive', 'Registration Desk is not enabled for an active event.', array( 'status' => 409 ) );
		}
		$token   = (string) $request->get_header( 'X-ORAS-Desk-Station' );
		$station = Station_Session::validate( $token, get_current_user_id(), $event_id, (int) $config['revision'] );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}

		return array(
			'event_id'        => $event_id,
			'config_revision' => (int) $config['revision'],
			'actor_user_id'   => get_current_user_id(),
			'station_uuid'    => (string) $station['station_uuid'],
			'operator_label'  => (string) $station['operator_label'],
		);
	}

	private function request_uuid( \WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'X-ORAS-Desk-Request' );

		return '' !== trim( $header ) ? trim( $header ) : trim( (string) $request->get_param( 'request_uuid' ) );
	}

	/** @return array<string,mixed> */
	private function manual_payload( \WP_REST_Request $request ): array {
		$additional = $request->get_param( 'additional_attendees' );

		return array(
			'first_name'             => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
			'last_name'              => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
			'email'                  => sanitize_email( (string) $request->get_param( 'email' ) ),
			'phone'                  => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
			'address_1'              => sanitize_text_field( (string) $request->get_param( 'address_1' ) ),
			'address_2'              => sanitize_text_field( (string) $request->get_param( 'address_2' ) ),
			'city'                   => sanitize_text_field( (string) $request->get_param( 'city' ) ),
			'state'                  => sanitize_text_field( (string) $request->get_param( 'state' ) ),
			'postcode'               => sanitize_text_field( (string) $request->get_param( 'postcode' ) ),
			'option_uuid'            => sanitize_text_field( (string) $request->get_param( 'option_uuid' ) ),
			'valid_local_date'       => sanitize_text_field( (string) $request->get_param( 'valid_local_date' ) ),
			'payment_assertion'      => sanitize_key( (string) $request->get_param( 'payment_assertion' ) ),
			'source_type'            => sanitize_key( (string) $request->get_param( 'source_type' ) ),
			'duplicate_acknowledged' => rest_sanitize_boolean( $request->get_param( 'duplicate_acknowledged' ) ),
			'additional_attendees'   => is_array( $additional ) ? $additional : array(),
		);
	}

	/** @param array<string,mixed> $data */
	private function response( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function public_registration( array $row ): array {
		return array(
			'registration_uuid' => (string) $row['registration_uuid'],
			'event_id'          => (int) $row['event_id'],
			'option_uuid'       => (string) $row['option_uuid'],
			'source_type'       => (string) $row['source_type'],
			'classification'    => (string) $row['classification'],
			'status'            => (string) $row['status'],
			'website_status'    => (string) $row['source_status'],
			'contact_name'      => (string) $row['source_contact_name'],
			'contact_email'     => $this->mask_email( (string) $row['source_email'] ),
			'contact_phone'     => $this->mask_phone( (string) $row['source_phone'] ),
			'validity_type'     => (string) $row['validity_type'],
			'valid_local_date'  => (string) $row['valid_local_date'],
			'payment_assertion' => (string) $row['payment_assertion'],
			'record_version'    => (int) $row['record_version'],
		);
	}

	private function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );
		return 2 === count( $parts ) ? substr( $parts[0], 0, 1 ) . '***@' . $parts[1] : '';
	}

	private function mask_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';

		return strlen( $digits ) >= 4 ? '***-***-' . substr( $digits, -4 ) : '';
	}
}
