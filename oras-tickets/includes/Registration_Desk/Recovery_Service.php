<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manager-qualified lookup and synchronization of canonical website registrations. */
final class Recovery_Service {
	public function __construct(
		private ?Source_Adapter $adapter = null,
		private ?Projection_Service $projection = null,
		private ?Registration_Store $registrations = null
	) {
		$this->adapter       = $adapter ?? new Source_Adapter();
		$this->projection    = $projection ?? new Projection_Service( $this->adapter );
		$this->registrations = $registrations ?? new Registration_Store();
	}

	/** @param array<string,mixed> $config @return array<string,mixed>|\WP_Error */
	public function search( int $event_id, string $query, array $config, int $limit = 25 ) {
		$result = $this->adapter->search( $query, $limit );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$items = array_map(
			fn( array $evidence ): array => $this->manager_result( $event_id, $evidence, $config ),
			$result['items']
		);

		return array(
			'items'          => $items,
			'scanned_orders' => (int) $result['scanned_orders'],
			'truncated'      => (bool) $result['truncated'],
		);
	}

	/** @param array<string,mixed> $config @return array<string,mixed>|\WP_Error */
	public function sync( int $event_id, int $order_id, int $order_item_id, array $config ) {
		$evidence = $this->adapter->load( $order_id, $order_item_id );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}
		$resolution = Source_Resolver::resolve( $evidence, $event_id, $config );
		if ( ! self::grants_access( $resolution ) ) {
			return new \WP_Error( 'oras_desk_recovery_not_valid', 'This website registration does not currently grant access to the selected event.', array( 'status' => 409 ) );
		}
		$result = $this->projection->reconcile_source( $event_id, $order_id, $order_item_id, $config );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$uuids = array_values(
			array_filter(
				array_map(
					static fn( array $registration ): string => (string) ( $registration['registration_uuid'] ?? '' ),
					$result['registrations']
				)
			)
		);

		return array(
			'result'             => 'registration_synchronized',
			'message'            => 'Registration found and added to the roster.',
			'registration_uuids' => $uuids,
			'registration_uuid'  => (string) ( $uuids[0] ?? '' ),
			'source'             => $this->manager_result( $event_id, $evidence, $config ),
		);
	}

	/** @param array<string,mixed> $config @return array<int,array<string,mixed>>|\WP_Error */
	public function contact_matches( int $event_id, string $email, string $phone, array $config ) {
		$queries = array_values( array_unique( array_filter( array( strtolower( sanitize_email( $email ) ), sanitize_text_field( $phone ) ) ) ) );
		$matches = array();
		foreach ( $queries as $query ) {
			$result = $this->adapter->search( $query, 50 );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			foreach ( $result['items'] as $evidence ) {
				$email_match = '' !== $email && strtolower( sanitize_email( (string) $evidence['email'] ) ) === strtolower( sanitize_email( $email ) );
				$phone_match = '' !== $phone && self::phone( (string) $evidence['phone'] ) === self::phone( $phone );
				$resolution  = Source_Resolver::resolve( $evidence, $event_id, $config );
				if ( ( $email_match || $phone_match ) && self::grants_access( $resolution ) ) {
					$key             = (int) $evidence['order_id'] . ':' . (int) $evidence['order_item_id'];
					$matches[ $key ] = $this->manager_result( $event_id, $evidence, $config );
				}
			}
		}

		return array_values( $matches );
	}

	/** @param array<string,mixed> $evidence @param array<string,mixed> $config @return array<string,mixed> */
	private function manager_result( int $event_id, array $evidence, array $config ): array {
		$resolution = Source_Resolver::resolve( $evidence, $event_id, $config );
		$valid      = self::grants_access( $resolution );
		$review     = 'review_required' === (string) $resolution['resolution'] && 'Source product or event is not explicitly mapped.' !== (string) $resolution['reason'];
		$source_key = implode( ':', array( 'woo', (int) $evidence['order_id'], (int) $evidence['order_item_id'], 1 ) );
		$projected  = $this->registrations->find_by_source_key( $event_id, $source_key );
		$status     = sanitize_key( (string) $evidence['order_status'] );
		$status_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : ucwords( str_replace( '-', ' ', $status ) );

		return array(
			'order_id'           => (int) $evidence['order_id'],
			'order_number'       => (string) ( $evidence['order_number'] ?? $evidence['order_id'] ),
			'order_item_id'      => (int) $evidence['order_item_id'],
			'contact_name'       => sanitize_text_field( (string) $evidence['contact_name'] ),
			'email'              => sanitize_email( (string) $evidence['email'] ),
			'phone'              => sanitize_text_field( (string) $evidence['phone'] ),
			'order_status'       => $status,
			'order_status_label' => sanitize_text_field( (string) $status_label ),
			'registration_type'  => sanitize_text_field( (string) $evidence['item_label'] ),
			'event_access'       => $valid ? 'valid' : ( $review ? 'review' : 'not_valid' ),
			'access_label'       => $valid ? 'Valid for this event' : ( $review ? 'Manager review needed' : 'Not valid for this event' ),
			'cross_event'        => $valid && (int) $evidence['source_event_id'] !== $event_id,
			'projected'          => is_array( $projected ),
			'registration_uuid'  => is_array( $projected ) ? (string) $projected['registration_uuid'] : '',
		);
	}

	/** @param array<string,mixed> $resolution */
	private static function grants_access( array $resolution ): bool {
		return 'supported' === (string) ( $resolution['resolution'] ?? '' )
			&& in_array( (string) ( $resolution['eligibility'] ?? '' ), array( 'eligible', 'explicit_unpaid_required' ), true );
	}

	private static function phone( string $phone ): string {
		return preg_replace( '/\D+/', '', $phone ) ?? '';
	}
}
