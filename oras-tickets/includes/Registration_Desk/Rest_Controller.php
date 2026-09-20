<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Controller {
	private Service $service;
	private Projection_Service $projection;
	private Membership_Credit_Service $membership_credit;
	private Member_Lookup_Service $member_lookup;
	private Event_Roster_Service $event_roster;
	private Recovery_Service $recovery;

	public function __construct( ?Service $service = null, ?Projection_Service $projection = null, ?Membership_Credit_Service $membership_credit = null, ?Member_Lookup_Service $member_lookup = null, ?Event_Roster_Service $event_roster = null, ?Recovery_Service $recovery = null ) {
		$this->service    = $service ?? new Service();
		$this->projection = $projection ?? new Projection_Service();
		$this->membership_credit = $membership_credit ?? new Membership_Credit_Service();
		$this->member_lookup      = $member_lookup ?? new Member_Lookup_Service();
		$this->event_roster       = $event_roster ?? new Event_Roster_Service();
		$this->recovery           = $recovery ?? new Recovery_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'events' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
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
			'/registration-desk/offerings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'offerings' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/roster',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'roster' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/roster/rsvp/(?P<user_id>\d+)/check-in',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rsvp_roster_check_in' ),
				'permission_callback' => array( $this, 'permission_admit' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/manager/unlock',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'manager_unlock' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/manager/recovery',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'recovery_search' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/manager/recovery/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recovery_sync' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/membership-offerings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'membership_offerings' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/members',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'member_lookup' ),
				'permission_callback' => array( $this, 'permission_use' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/memberships',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'memberships' ),
					'permission_callback' => array( $this, 'permission_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'record_membership' ),
					'permission_callback' => array( $this, 'permission_admit' ),
				),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/memberships/(?P<activation_uuid>[0-9a-f-]{36})/correct',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'correct_membership' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/memberships/(?P<activation_uuid>[0-9a-f-]{36})/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend_membership' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
		register_rest_route(
			'oras-tickets/v1',
			'/registration-desk/memberships/(?P<activation_uuid>[0-9a-f-]{36})/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_membership' ),
				'permission_callback' => array( $this, 'permission_manage' ),
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
			'/registration-desk/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats' ),
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
			'/registration-desk/registrations/manager-verified',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_manager_verified' ),
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

	/** @return bool|\WP_Error */
	public function permission_manage( ?\WP_REST_Request $request = null ) {
		if ( ! $this->permission_use() || null === $request ) {
			return false;
		}
		$station = $this->station_payload( $request );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}

		return Manager_Access::validate( (string) $request->get_header( 'X-ORAS-Desk-Manager' ), $station ) instanceof \WP_Error
			? new \WP_Error( 'oras_desk_manager_required', 'Enter the manager PIN to use this action.', array( 'status' => 403 ) )
			: true;
	}

	public function events(): \WP_REST_Response {
		return $this->response( array( 'items' => Event_Catalog::current_year() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function station( \WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = Event_Catalog::find( $event_id );
		if ( null === $event ) {
			return new \WP_Error( 'oras_desk_event_unavailable', 'Choose one of the available events.', array( 'status' => 400 ) );
		}
		$config   = Config::get_event_config( $event_id );
		$label = sanitize_text_field( (string) $request->get_param( 'operator_label' ) );
		if ( '' === $label ) {
			return new \WP_Error( 'oras_desk_operator_required', 'Enter the operator name for this station.', array( 'status' => 400 ) );
		}
		$token = Station_Session::issue( get_current_user_id(), $event_id, (int) $config['revision'], $label );

		return $this->response(
			array(
				'station_token'          => $token,
				'event_id'               => $event_id,
				'event_title'            => (string) $event['title'],
				'event_date'             => (string) $event['friendly_date'],
				'config_revision'        => (int) $config['revision'],
				'operator_label'         => $label,
				'options'                => $this->current_offerings( $event_id, $config ),
				'membership_levels'      => Config::get_membership_offerings(),
				'local_date'             => wp_date( 'Y-m-d', null, wp_timezone() ),
				'friendly_date'          => wp_date( 'l, F j, Y', null, wp_timezone() ),
				'manager_pin_configured' => '' !== (string) get_option( Manager_Access::PIN_HASH_OPTION, '' ),
				'logout_url'             => html_entity_decode( wp_logout_url( Landing_Page::url() ), ENT_QUOTES, 'UTF-8' ),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function offerings( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$config = Config::get_event_config( (int) $context['event_id'] );

		return $this->response( array( 'items' => $this->current_offerings( (int) $context['event_id'], $config ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function roster( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$result = $this->event_roster->get(
			(int) $context['event_id'],
			array(
				'q'           => $request->get_param( 'q' ),
				'status'      => $request->get_param( 'status' ),
				'option_uuid' => $request->get_param( 'option_uuid' ),
				'offset'      => $request->get_param( 'offset' ),
				'limit'       => $request->get_param( 'limit' ),
			)
		);
		$config = Config::get_event_config( (int) $context['event_id'] );
		$result['registration_types'] = array_values(
			array_map(
				static fn( array $offering ): array => array(
					'option_uuid' => (string) $offering['option_uuid'],
					'label'       => (string) $offering['label'],
				),
				array_filter(
					Event_Offering_Resolver::desk_offerings( (int) $context['event_id'], $config ),
					static fn( array $offering ): bool => ! empty( $offering['visible'] )
				)
			)
		);

		return $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function rsvp_roster_check_in( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$result = $this->service->check_in_public_rsvp(
			absint( $request['user_id'] ),
			array( 'attendance_local_date' => sanitize_text_field( (string) $request->get_param( 'attendance_local_date' ) ) ),
			$context
		);

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function manager_unlock( \WP_REST_Request $request ) {
		$station = $this->station_payload( $request );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}
		$identity = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$token    = Manager_Access::unlock( preg_replace( '/\D+/', '', (string) $request->get_param( 'pin' ) ) ?? '', $station, $identity );
		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		return $this->response( array( 'manager_token' => $token ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function recovery_search( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$result = $this->recovery->search( (int) $context['event_id'], $query, Config::get_event_config( (int) $context['event_id'] ) );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function recovery_sync( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$result = $this->recovery->sync(
			(int) $context['event_id'],
			absint( $request->get_param( 'order_id' ) ),
			absint( $request->get_param( 'order_item_id' ) ),
			Config::get_event_config( (int) $context['event_id'] )
		);

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function member_lookup( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $query ) < 2 ) {
			return new \WP_Error( 'oras_desk_search_short', 'Enter at least two characters.', array( 'status' => 400 ) );
		}

		return $this->response( array( 'items' => $this->member_lookup->search( $query ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function membership_offerings( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		return $this->response( array( 'items' => Config::get_membership_offerings() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function memberships( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		return $this->response( array( 'items' => ( new Offline_Membership_Store() )->for_event( (int) $context['event_id'] ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function record_membership( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$result = $this->membership_credit->create(
			array(
				'first_name'     => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
				'last_name'      => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
				'email'          => sanitize_email( (string) $request->get_param( 'email' ) ),
				'phone'          => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
				'level_id'       => absint( $request->get_param( 'level_id' ) ),
				'payment_method' => sanitize_key( (string) $request->get_param( 'payment_method' ) ),
			),
			$context
		);

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function correct_membership( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$result = $this->membership_credit->correct_contact(
			sanitize_text_field( (string) $request['activation_uuid'] ),
			array(
				'first_name' => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
				'last_name'  => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
				'email'      => sanitize_email( (string) $request->get_param( 'email' ) ),
				'phone'      => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
			)
		);

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function resend_membership( \WP_REST_Request $request ) {
		$result = $this->membership_credit->resend( sanitize_text_field( (string) $request['activation_uuid'] ) );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function cancel_membership( \WP_REST_Request $request ) {
		$result = $this->membership_credit->cancel( sanitize_text_field( (string) $request['activation_uuid'] ), get_current_user_id(), sanitize_text_field( (string) $request->get_param( 'reason' ) ) );

		return $result instanceof \WP_Error ? $result : $this->response( $result );
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
				'limitations'       => array( 'Search includes discovered and recovered direct individual registrations only.' ),
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
		$is_manager = $this->is_manager_request( $request );
		$admission_diagnostics = is_array( $result['admission']['_diagnostics'] ?? null ) ? $result['admission']['_diagnostics'] : array();
		if ( $is_manager && 'online' !== (string) $raw_registration['source_type'] ) {
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
		if ( $is_manager ) {
			$evidence = json_decode( (string) $raw_registration['source_evidence'], true );
			$evidence = is_array( $evidence ) ? $evidence : array();
			$result['manager_detail'] = array(
				'full_name'            => (string) $raw_registration['source_contact_name'],
				'phone'                => (string) $raw_registration['source_phone'],
				'email'                => (string) $raw_registration['source_email'],
				'mailing_address'      => is_array( $evidence['mailing_address'] ?? null ) ? $evidence['mailing_address'] : array(),
				'source_type'          => (string) $raw_registration['source_type'],
				'source_order_id'      => (int) $raw_registration['source_order_id'],
				'source_order_item_id' => (int) $raw_registration['source_order_item_id'],
				'registration_type'    => Event_Roster_Service::historical_label( $raw_registration ),
				'payment_assertion'    => (string) $raw_registration['payment_assertion'],
				'created_at_utc'       => (string) $raw_registration['created_at_utc'],
				'admission_diagnostics' => $admission_diagnostics,
				'audit_history'        => ( new Audit_Store() )->for_registration( (string) $raw_registration['registration_uuid'] ),
			);
		}
		unset( $result['admission']['_diagnostics'], $result['admission']['_error_code'], $result['admission']['_option'], $result['admission']['_source_label'] );
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
	public function stats( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		return $this->response( ( new Event_Stats_Service() )->for_event( (int) $context['event_id'] ) );
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
	public function create_manager_verified( \WP_REST_Request $request ) {
		$context = $this->context( $request );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$context['request_uuid'] = $this->request_uuid( $request );
		$payload = $this->manual_payload( $request );
		$payload['reason']             = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
		$payload['proof_acknowledged'] = rest_sanitize_boolean( $request->get_param( 'proof_acknowledged' ) );
		$result = $this->service->create_manager_verified( $payload, $context );

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
		$station = $this->station_payload( $request );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}
		$event_id = (int) $station['event_id'];
		$config   = Config::get_event_config( $event_id );

		return array(
			'event_id'        => $event_id,
			'config_revision' => (int) $config['revision'],
			'actor_user_id'   => get_current_user_id(),
			'station_uuid'    => (string) $station['station_uuid'],
			'operator_label'  => (string) $station['operator_label'],
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function station_payload( \WP_REST_Request $request ) {
		$token   = (string) $request->get_header( 'X-ORAS-Desk-Station' );
		$station = Station_Session::validate( $token, get_current_user_id() );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}
		$event_id = (int) ( $station['event_id'] ?? 0 );
		$config   = Config::get_event_config( $event_id );
		if ( (int) ( $station['config_revision'] ?? -1 ) !== (int) $config['revision'] ) {
			return new \WP_Error( 'oras_desk_station_config_changed', 'Registration Desk settings changed. Set up this station again.', array( 'status' => 401 ) );
		}
		if ( null === Event_Catalog::find( $event_id ) ) {
			return new \WP_Error( 'oras_desk_station_event_changed', 'That event is no longer available. Choose an event again.', array( 'status' => 401 ) );
		}

		return $station;
	}

	private function is_manager_request( \WP_REST_Request $request ): bool {
		$station = $this->station_payload( $request );

		return is_array( $station ) && is_array( Manager_Access::validate( (string) $request->get_header( 'X-ORAS-Desk-Manager' ), $station ) );
	}

	private function request_uuid( \WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'X-ORAS-Desk-Request' );

		return '' !== trim( $header ) ? trim( $header ) : trim( (string) $request->get_param( 'request_uuid' ) );
	}

	/** @param array<string,mixed> $config @return array<int,array<string,mixed>> */
	private function current_offerings( int $event_id, array $config ): array {
		if ( Event_Offering_Resolver::has_canonical_tickets( $event_id ) ) {
			return Event_Offering_Resolver::desk_offerings( $event_id, $config );
		}
		$rsvp = RSVP_Capacity::state( $event_id );
		if ( empty( $rsvp['enabled'] ) ) {
			return array();
		}
		$can_submit = 'open' === (string) $rsvp['window_state'] && in_array( (string) $rsvp['decision'], array( 'admit', 'waitlist' ), true );
		$label      = match ( (string) $rsvp['decision'] ) {
			'waitlist' => __( 'RSVP — Waitlist', 'oras-tickets' ),
			'refuse'   => __( 'RSVP — Full', 'oras-tickets' ),
			default    => __( 'RSVP — On-site', 'oras-tickets' ),
		};

		$option = array(
			'kind'                  => 'rsvp',
			'event_id'              => $event_id,
			'option_uuid'           => Event_Offering_Resolver::option_uuid( $event_id, 'rsvp' ),
			'ticket_key'            => '',
			'product_id'            => 0,
			'name'                  => $label,
			'label'                 => $label,
			'description'           => __( 'Accountless event RSVP recorded at the Registration Desk.', 'oras-tickets' ),
			'price'                 => '0.00',
			'phase_key'             => null,
			'phase_label'           => null,
			'attendance_mode'       => 'onsite',
			'sale_state'            => (string) $rsvp['window_state'],
			'availability'          => (string) $rsvp['decision'],
			'availability_label'    => 'waitlist' === (string) $rsvp['decision'] ? __( 'Waitlist available', 'oras-tickets' ) : ( $can_submit ? __( 'Space available', 'oras-tickets' ) : __( 'No RSVP space or waitlist is available', 'oras-tickets' ) ),
			'visible'               => true,
			'selectable'            => $can_submit,
			'available_for_new'     => $can_submit,
			'existing_access_valid' => true,
			'classification'        => 'individual',
			'validity_type'         => 'full_event',
			'valid_local_date'      => '',
			'max_attendees'         => 1,
			'rsvp_state'            => $rsvp,
		);
		$option['offering_fingerprint'] = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'event_id'   => $event_id,
					'rsvp_state' => $rsvp,
				)
			)
		);

		return array(
			$option,
		);
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
			'offering_fingerprint'   => sanitize_text_field( (string) $request->get_param( 'offering_fingerprint' ) ),
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
			'registration_type' => Event_Roster_Service::volunteer_label( $row ),
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
