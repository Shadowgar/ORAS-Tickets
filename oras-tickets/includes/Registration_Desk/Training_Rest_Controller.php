<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** REST boundary for isolated Registration Desk training sessions. */
final class Training_Rest_Controller {
	private Training_Store $store;
	private Training_Context $training_context;
	private Training_Snapshot_Service $snapshot;

	public function __construct( ?Training_Store $store = null, ?Training_Context $training_context = null, ?Training_Snapshot_Service $snapshot = null ) {
		$this->store            = $store ?? new Training_Store();
		$this->training_context = $training_context ?? new Training_Context( $this->store );
		$this->snapshot         = $snapshot ?? new Training_Snapshot_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$namespace = 'oras-tickets/v1';
		$this->route( $namespace, '/registration-desk/training/start', 'POST', 'start', 'permission_start' );
		$this->route( $namespace, '/registration-desk/training/context', 'GET', 'context', 'permission_training' );
		$this->route( $namespace, '/registration-desk/training/offerings', 'GET', 'offerings', 'permission_training' );
		$this->route( $namespace, '/registration-desk/training/roster', 'GET', 'roster', 'permission_training' );
		$this->route( $namespace, '/registration-desk/training/registrations/(?P<registration_uuid>[0-9a-f-]{36})', 'GET', 'detail', 'permission_training' );
		$this->route( $namespace, '/registration-desk/training/registrations/(?P<registration_uuid>[0-9a-f-]{36})/check-in', 'POST', 'check_in', 'permission_training_admit' );
		$this->route( $namespace, '/registration-desk/training/walk-in', 'POST', 'walk_in', 'permission_training_admit' );
		$this->route( $namespace, '/registration-desk/training/members', 'GET', 'members', 'permission_training' );
		$this->route( $namespace, '/registration-desk/training/membership-offerings', 'GET', 'membership_offerings', 'permission_training' );
		$this->route( $namespace, '/registration-desk/training/memberships', 'POST', 'membership', 'permission_training_admit' );
		$this->route( $namespace, '/registration-desk/training/stats', 'GET', 'stats', 'permission_training' );
		$this->route( $namespace, '/registration-desk/training/date', 'POST', 'change_date', 'permission_training_manage' );
		$this->route( $namespace, '/registration-desk/training/reset', 'POST', 'reset', 'permission_training_manage' );
		$this->route( $namespace, '/registration-desk/training/end', 'POST', 'end', 'permission_training_manage' );
	}

	private function route( string $route_namespace, string $route, string $method, string $callback, string $permission ): void {
		register_rest_route(
			$route_namespace,
			$route,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
			)
		);
	}

	/** @return bool|\WP_Error */
	public function permission_start( ?\WP_REST_Request $request = null ) {
		if ( ! $this->can_use() || null === $request ) {
			return false;
		}
		$station = $this->signed_station( $request );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}
		if ( 'training' === (string) ( $station['mode'] ?? 'live' ) ) {
			return new \WP_Error( 'oras_desk_training_already_active', 'Training Mode is already active for this station.', array( 'status' => 409 ) );
		}

		return $this->manager_authorized( $request, $station );
	}

	/** @return bool|\WP_Error */
	public function permission_training( ?\WP_REST_Request $request = null ) {
		if ( ! $this->can_use() || null === $request ) {
			return false;
		}
		$resolved = $this->resolve_request( $request );

		return $resolved instanceof \WP_Error ? $resolved : true;
	}

	/** @return bool|\WP_Error */
	public function permission_training_admit( ?\WP_REST_Request $request = null ) {
		if ( ! current_user_can( 'oras_tickets_admit_registration_desk' ) ) {
			return false;
		}

		return $this->permission_training( $request );
	}

	/** @return bool|\WP_Error */
	public function permission_training_manage( ?\WP_REST_Request $request = null ) {
		if ( ! $this->can_use() || null === $request ) {
			return false;
		}
		$resolved = $this->resolve_scope_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		return $this->manager_authorized( $request, $resolved['station'] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function start( \WP_REST_Request $request ) {
		if ( ! rest_sanitize_boolean( $request->get_param( 'confirmed' ) ) ) {
			return $this->confirmation_required( 'Confirm before starting Training Mode.' );
		}
		$source_station = $this->signed_station( $request );
		if ( $source_station instanceof \WP_Error ) {
			return $source_station;
		}
		$manager = $this->manager_authorized( $request, $source_station );
		if ( true !== $manager ) {
			return $manager;
		}

		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = Event_Catalog::find( $event_id );
		if ( null === $event ) {
			return new \WP_Error( 'oras_desk_training_event_unavailable', 'Choose one of the available events for Training Mode.', array( 'status' => 400 ) );
		}
		$date = trim( (string) $request->get_param( 'simulated_local_date' ) );
		$date = '' !== $date ? $date : (string) $event['start_date'];
		if ( ! Training_Context::is_event_date( $date, (string) $event['start_date'], (string) $event['end_date'] ) ) {
			return new \WP_Error( 'oras_desk_training_date_invalid', 'The training date must be within the selected event.', array( 'status' => 400 ) );
		}
		$config = Config::get_event_config( $event_id );
		$issued = Station_Session::issue_training(
			get_current_user_id(),
			$event_id,
			(int) $config['revision'],
			(string) ( $source_station['operator_label'] ?? '' ),
			$date
		);
		if ( $issued instanceof \WP_Error ) {
			return $issued;
		}
		$training_uuid = wp_generate_uuid4();
		$offerings     = Training_Service::canonical_offerings( $event_id, $config );
		$state         = $this->snapshot->capture( $event_id, $training_uuid, $offerings, $date );
		if ( $state instanceof \WP_Error ) {
			return $state;
		}
		$binding       = $issued['payload'];
		$binding['training_uuid']       = $training_uuid;
		$binding['simulated_local_date'] = $date;
		$this->store->cleanup_expired();
		$row = $this->store->create( $binding, $state );
		if ( $row instanceof \WP_Error ) {
			return $row;
		}

		return $this->response(
			array_merge(
				$this->public_context( $issued['payload'], $row, $event, $offerings ),
				array(
					'station_token'             => $issued['token'],
					'manager_token_invalidated' => true,
				)
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function context( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		return $this->response( $this->public_context( $resolved['station'], $resolved['row'], $resolved['event'], $resolved['offerings'] ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function offerings( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		return $this->response(
			array(
				'items'    => $resolved['offerings'],
				'training' => true,
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function roster( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$result = Training_Service::roster(
			$resolved['row']['state'],
			array(
				'q'           => $request->get_param( 'q' ),
				'status'      => $request->get_param( 'status' ),
				'option_uuid' => $request->get_param( 'option_uuid' ),
				'offset'      => $request->get_param( 'offset' ),
				'limit'       => $request->get_param( 'limit' ),
			),
			(string) $resolved['row']['simulated_local_date']
		);
		$result['registration_types'] = array_map(
			static fn( array $offering ): array => array(
				'option_uuid' => (string) $offering['option_uuid'],
				'label'       => (string) $offering['label'],
			),
			$resolved['offerings']
		);
		foreach ( $result['items'] as &$item ) {
			if ( is_array( $item['roster_item'] ?? null ) ) {
				$checked_in = ! empty( $item['checked_in_today'] );
				$item = array_merge(
					$item['roster_item'],
					array(
						'checked_in_today' => $checked_in,
						'detail_kind'      => 'registration',
					)
				);
				continue;
			}
			$item['name']              = (string) ( $item['contact_name'] ?? '' );
			$item['registration_type'] = (string) ( $item['option_label'] ?? 'Registration' );
			$item['attendees']         = array_values(
				array_map(
					static fn( array $attendee ): string => (string) ( $attendee['name'] ?? '' ),
					array_filter( is_array( $item['attendees'] ?? null ) ? $item['attendees'] : array(), 'is_array' )
				)
			);
			$item['detail_kind'] = 'registration';
		}
		unset( $item );
		$result['next_offset'] = (int) $result['filters']['offset'] + count( $result['items'] );
		$result['has_more']    = $result['next_offset'] < (int) $result['total'];
		$result['record_version'] = (int) $resolved['row']['record_version'];
		$result['training']       = true;

		return $this->response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function detail( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$result = Training_Service::live_detail(
			$resolved['row']['state'],
			sanitize_text_field( (string) $request['registration_uuid'] ),
			(string) $resolved['row']['simulated_local_date']
		);
		if ( null === $result ) {
			return new \WP_Error( 'oras_desk_training_registration_missing', 'That training registration is no longer available.', array( 'status' => 404 ) );
		}

		return $this->response(
			array_merge(
				$result,
				array(
					'record_version' => (int) $resolved['row']['record_version'],
					'training'       => true,
				)
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function check_in( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$arrivals = $request->get_param( 'attendee_uuids' );
		$live_arrivals = $request->get_param( 'arrivals' );
		$payload  = array(
			'request_uuid'      => $this->request_uuid( $request ),
			'registration_uuid' => sanitize_text_field( (string) $request['registration_uuid'] ),
			'attendee_uuids'    => is_array( $arrivals ) ? array_map( 'sanitize_text_field', $arrivals ) : array(),
			'arrivals'          => is_array( $live_arrivals ) ? $live_arrivals : array(),
		);

		return $this->mutate( $resolved, $request, static fn( array $state, array $context ) => Training_Service::check_in_state( $state, $payload, $context ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function walk_in( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$attendees = $request->get_param( 'attendees' );
		$payload   = array(
			'request_uuid'         => $this->request_uuid( $request ),
			'option_uuid'          => sanitize_text_field( (string) $request->get_param( 'option_uuid' ) ),
			'offering_fingerprint' => sanitize_text_field( (string) $request->get_param( 'offering_fingerprint' ) ),
			'contact_name'         => sanitize_text_field( (string) $request->get_param( 'contact_name' ) ),
			'email'                => sanitize_email( (string) $request->get_param( 'email' ) ),
			'phone'                => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
			'attendees'            => is_array( $attendees ) ? $attendees : array(),
			'payment_assertion'    => sanitize_key( (string) $request->get_param( 'payment_assertion' ) ),
		);

		return $this->mutate( $resolved, $request, static fn( array $state, array $context ) => Training_Service::walk_in_state( $state, $payload, $context ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function members( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $query ) < 2 ) {
			return new \WP_Error( 'oras_desk_search_short', 'Enter at least two characters.', array( 'status' => 400 ) );
		}

		return $this->response(
			array(
				'items'    => Training_Service::member_lookup( $resolved['row']['state'], $query ),
				'training' => true,
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function membership_offerings( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		return $this->response(
			array(
				'items'    => Training_Service::canonical_membership_offerings(),
				'training' => true,
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function membership( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$payload = array(
			'request_uuid'   => $this->request_uuid( $request ),
			'level_id'       => absint( $request->get_param( 'level_id' ) ),
			'contact_name'   => sanitize_text_field( (string) $request->get_param( 'contact_name' ) ),
			'email'          => sanitize_email( (string) $request->get_param( 'email' ) ),
			'payment_method' => sanitize_key( (string) $request->get_param( 'payment_method' ) ),
		);

		return $this->mutate( $resolved, $request, static fn( array $state, array $context ) => Training_Service::record_membership_state( $state, $payload, $context ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function stats( \WP_REST_Request $request ) {
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		return $this->response( Training_Service::stats( $resolved['row']['state'], (string) $resolved['row']['simulated_local_date'] ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function change_date( \WP_REST_Request $request ) {
		if ( ! rest_sanitize_boolean( $request->get_param( 'confirmed' ) ) ) {
			return $this->confirmation_required( 'Confirm before changing the training date.' );
		}
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$date = sanitize_text_field( (string) $request->get_param( 'simulated_local_date' ) );
		if ( ! Training_Context::is_event_date( $date, (string) $resolved['event']['start_date'], (string) $resolved['event']['end_date'] ) ) {
			return new \WP_Error( 'oras_desk_training_date_invalid', 'The training date must be within the selected event.', array( 'status' => 400 ) );
		}
		$changed = $this->store->change_date( (string) $resolved['station']['station_uuid'], $this->expected_revision( $request ), $date );
		if ( $changed instanceof \WP_Error ) {
			return $changed;
		}
		$issued = Station_Session::reissue_training( $resolved['station'], $date );
		if ( $issued instanceof \WP_Error ) {
			return $issued;
		}
		$row = $this->store->find_for_station( (string) $resolved['station']['station_uuid'] );
		if ( null === $row ) {
			return new \WP_Error( 'oras_desk_training_required', 'Training Mode is no longer active for this station.', array( 'status' => 409 ) );
		}

		return $this->response(
			array_merge(
				$this->public_context( $issued['payload'], $row, $resolved['event'], $resolved['offerings'] ),
				array(
					'station_token'                => $issued['token'],
					'attendance_history_preserved' => true,
				)
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function reset( \WP_REST_Request $request ) {
		if ( ! rest_sanitize_boolean( $request->get_param( 'confirmed' ) ) ) {
			return $this->confirmation_required( 'Confirm before resetting the training data.' );
		}
		$resolved = $this->resolve_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		$seed = $this->snapshot->capture(
			(int) $resolved['row']['event_id'],
			(string) $resolved['row']['training_uuid'],
			$resolved['offerings'],
			(string) $resolved['row']['simulated_local_date']
		);
		if ( $seed instanceof \WP_Error ) {
			return $seed;
		}
		$result = $this->store->reset( (string) $resolved['station']['station_uuid'], $this->expected_revision( $request ), $seed );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return $this->fresh_context_response( $resolved['station'], $resolved['event'], $resolved['offerings'], array( 'reset' => true ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function end( \WP_REST_Request $request ) {
		if ( ! rest_sanitize_boolean( $request->get_param( 'confirmed' ) ) ) {
			return $this->confirmation_required( 'Confirm before ending Training Mode.' );
		}
		$resolved = $this->resolve_scope_request( $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}
		if ( ! $this->store->delete_for_station( (string) $resolved['station']['station_uuid'] ) ) {
			return new \WP_Error( 'oras_desk_training_end_failed', 'Training Mode could not be ended. No live event data was changed.', array( 'status' => 500 ) );
		}

		return $this->response(
			array(
				'ended'                          => true,
				'return_to_live_event_selection' => true,
			)
		);
	}

	/**
	 * @param array{station:array<string,mixed>,row:array<string,mixed>,event:array<string,mixed>,offerings:array<int,array<string,mixed>>} $resolved
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function mutate( array $resolved, \WP_REST_Request $request, callable $transition ) {
		$result = $this->store->mutate(
			(string) $resolved['station']['station_uuid'],
			$this->expected_revision( $request ),
			function ( array $state, array $locked_row ) use ( $transition, $resolved ) {
				$context = $this->operation_context( $locked_row, $resolved['event'] );
				if ( $context instanceof \WP_Error ) {
					return $context;
				}

				return $transition( $state, $context );
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$row = $this->store->find_for_station( (string) $resolved['station']['station_uuid'] );
		if ( null === $row ) {
			return new \WP_Error( 'oras_desk_training_required', 'Training Mode is no longer active for this station.', array( 'status' => 409 ) );
		}

		return $this->response(
			array(
				'result'         => $result,
				'record_version' => (int) $row['record_version'],
				'training'       => true,
			)
		);
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $event @return array<string,mixed>|\WP_Error */
	private function operation_context( array $row, array $event ) {
		$config = Config::get_event_config( (int) $row['event_id'] );
		if ( (int) $row['config_revision'] !== (int) $config['revision'] ) {
			return new \WP_Error( 'oras_desk_training_config_changed', 'Event configuration changed. End and restart Training Mode before continuing.', array( 'status' => 409 ) );
		}

		return array(
			'training_uuid'                  => (string) $row['training_uuid'],
			'simulated_local_date'           => (string) $row['simulated_local_date'],
			'event_start_date'               => (string) $event['start_date'],
			'event_end_date'                 => (string) $event['end_date'],
			'config_revision'                => (int) $row['config_revision'],
			'current_config_revision'        => (int) $config['revision'],
			'canonical_offerings'            => Training_Service::canonical_offerings( (int) $row['event_id'], $config ),
			'canonical_membership_offerings' => Training_Service::canonical_membership_offerings(),
		);
	}

	/** @return array{station:array<string,mixed>,row:array<string,mixed>,event:array<string,mixed>,offerings:array<int,array<string,mixed>>}|\WP_Error */
	private function resolve_request( \WP_REST_Request $request ) {
		$station = $this->signed_station( $request );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}
		if ( 'training' !== (string) ( $station['mode'] ?? '' ) ) {
			return new \WP_Error( 'oras_desk_training_required', 'Training Mode is not active for this station.', array( 'status' => 409 ) );
		}
		$row = $this->training_context->resolve( $station );
		if ( $row instanceof \WP_Error ) {
			return $row;
		}
		$event = Event_Catalog::find_any( (int) $row['event_id'] );
		if ( null === $event ) {
			return new \WP_Error( 'oras_desk_training_event_changed', 'The training event is no longer available. End and restart Training Mode.', array( 'status' => 409 ) );
		}
		$config = Config::get_event_config( (int) $row['event_id'] );

		return array(
			'station'   => $station,
			'row'       => $row,
			'event'     => $event,
			'offerings' => Training_Service::canonical_offerings( (int) $row['event_id'], $config ),
		);
	}

	/** @return array{station:array<string,mixed>,row:array<string,mixed>}|\WP_Error */
	private function resolve_scope_request( \WP_REST_Request $request ) {
		$station = $this->signed_station( $request );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}
		if ( 'training' !== (string) ( $station['mode'] ?? '' ) ) {
			return new \WP_Error( 'oras_desk_training_required', 'Training Mode is not active for this station.', array( 'status' => 409 ) );
		}
		$row = $this->store->find_for_station( (string) ( $station['station_uuid'] ?? '' ) );
		if ( null === $row ) {
			return new \WP_Error( 'oras_desk_training_required', 'Training Mode is not active for this station.', array( 'status' => 409 ) );
		}
		$scope = Training_Context::validate_scope( $station, $row );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}

		return array(
			'station' => $station,
			'row'     => $row,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function signed_station( \WP_REST_Request $request ) {
		$station = Station_Session::validate( (string) $request->get_header( 'X-ORAS-Desk-Station' ), get_current_user_id() );
		if ( $station instanceof \WP_Error ) {
			return $station;
		}
		if ( 'training' !== (string) ( $station['mode'] ?? 'live' ) ) {
			$config = Config::get_event_config( (int) $station['event_id'] );
			if ( (int) ( $station['config_revision'] ?? -1 ) !== (int) $config['revision'] ) {
				return new \WP_Error( 'oras_desk_station_config_changed', 'Registration Desk settings changed. Set up this station again.', array( 'status' => 401 ) );
			}
		}

		return $station;
	}

	/** @param array<string,mixed> $station @return true|\WP_Error */
	private function manager_authorized( \WP_REST_Request $request, array $station ) {
		$result = Manager_Access::validate( (string) $request->get_header( 'X-ORAS-Desk-Manager' ), $station );

		return $result instanceof \WP_Error
			? new \WP_Error( 'oras_desk_manager_required', 'Enter the manager PIN to use this action.', array( 'status' => 403 ) )
			: true;
	}

	private function can_use(): bool {
		return is_user_logged_in() && current_user_can( 'oras_tickets_use_registration_desk' );
	}

	private function expected_revision( \WP_REST_Request $request ): int {
		return absint( $request->get_param( 'expected_record_version' ) );
	}

	private function request_uuid( \WP_REST_Request $request ): string {
		$header = trim( (string) $request->get_header( 'X-ORAS-Desk-Request' ) );

		return '' !== $header ? $header : trim( (string) $request->get_param( 'request_uuid' ) );
	}

	private function confirmation_required( string $message ): \WP_Error {
		return new \WP_Error( 'oras_desk_training_confirmation_required', $message, array( 'status' => 400 ) );
	}

	/**
	 * @param array<string,mixed> $station
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $event
	 * @param array<int,array<string,mixed>> $offerings
	 * @return array<string,mixed>
	 */
	private function public_context( array $station, array $row, array $event, array $offerings ): array {
		$date = (string) $row['simulated_local_date'];

		return array(
			'training'               => true,
			'mode'                   => 'training',
			'event_id'               => (int) $row['event_id'],
			'event_title'            => (string) $event['title'],
			'event_date'             => (string) $event['friendly_date'],
			'event_start_date'       => (string) $event['start_date'],
			'event_end_date'         => (string) $event['end_date'],
			'simulated_local_date'   => $date,
			'local_date'             => $date,
			'friendly_date'          => $this->friendly_date( $date ),
			'friendly_training_date' => $this->friendly_date( $date ),
			'config_revision'        => (int) $row['config_revision'],
			'record_version'         => (int) $row['record_version'],
			'operator_label'         => (string) ( $station['operator_label'] ?? '' ),
			'options'                => $offerings,
			'membership_levels'      => Training_Service::canonical_membership_offerings(),
			'banner'                 => array(
				'title'   => 'TRAINING MODE',
				'warning' => 'NO LIVE EVENT DATA WILL BE CHANGED',
			),
		);
	}

	/** @param array<string,mixed> $station @param array<string,mixed> $event @param array<int,array<string,mixed>> $offerings @param array<string,mixed> $extra @return \WP_REST_Response|\WP_Error */
	private function fresh_context_response( array $station, array $event, array $offerings, array $extra = array() ) {
		$row = $this->store->find_for_station( (string) $station['station_uuid'] );
		if ( null === $row ) {
			return new \WP_Error( 'oras_desk_training_required', 'Training Mode is no longer active for this station.', array( 'status' => 409 ) );
		}

		return $this->response( array_merge( $this->public_context( $station, $row, $event, $offerings ), $extra ) );
	}

	private function friendly_date( string $date ): string {
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		return $value instanceof \DateTimeImmutable ? wp_date( 'l, F j, Y', $value->getTimestamp(), wp_timezone() ) : $date;
	}

	/** @param array<string,mixed> $data */
	private function response( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
