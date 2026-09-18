<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Projection_Service {
	public function __construct(
		private ?Source_Adapter $adapter = null,
		private ?Registration_Store $registrations = null
	) {
		$this->adapter       = $adapter ?? new Source_Adapter();
		$this->registrations = $registrations ?? new Registration_Store();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function reconcile_source( int $event_id, int $order_id, int $order_item_id, array $config ) {
		$evidence = $this->adapter->load( $order_id, $order_item_id );
		if ( $evidence instanceof \WP_Error ) {
			return $evidence;
		}

		return $this->reconcile_evidence( $event_id, $evidence, $config );
	}

	/** @param array<string,mixed> $evidence @param array<string,mixed> $config @return array<string,mixed>|\WP_Error */
	public function reconcile_evidence( int $event_id, array $evidence, array $config ) {
		$forced_failure = apply_filters( 'oras_registration_desk_projection_failure', null, $event_id, $evidence );
		if ( $forced_failure instanceof \WP_Error ) {
			return $forced_failure;
		}
		$resolution = Source_Resolver::resolve( $evidence, $event_id, $config );
		$quantity   = max( 1, (int) ( $evidence['quantity'] ?? 1 ) );
		$rows       = array();
		for ( $unit = 1; $unit <= $quantity; ++$unit ) {
			$source_key = implode( ':', array( 'woo', (int) $evidence['order_id'], (int) $evidence['order_item_id'], $unit ) );
			$row        = $this->registrations->upsert_source_projection( $event_id, $source_key, $unit, $evidence, $resolution, (int) $config['revision'] );
			if ( $row instanceof \WP_Error ) {
				return $row;
			}
			$rows[] = $row;
		}
		$revoked = $this->registrations->revoke_source_units_above( $event_id, (int) $evidence['order_id'], (int) $evidence['order_item_id'], $quantity );
		if ( $revoked instanceof \WP_Error ) {
			return $revoked;
		}

		return array(
			'resolution'    => $resolution,
			'registrations' => $rows,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function reconcile_page( int $event_id, array $config, string $continuation = '', int $limit = 50 ) {
		$limit    = max( 1, min( 100, $limit ) );
		$revision = (int) ( $config['revision'] ?? 0 );
		$coverage = new Coverage_Store();
		$snapshot = $this->adapter->snapshot();
		if ( $snapshot instanceof \WP_Error ) {
			return $snapshot;
		}
		if ( '' === $continuation ) {
			$page = 1;
			$coverage->begin( $event_id, $revision, $snapshot );
		} else {
			$cursor = Recovery_Cursor::validate( $continuation, $event_id, $revision, $limit, $snapshot );
			if ( $cursor instanceof \WP_Error ) {
				if ( 'oras_desk_recovery_snapshot_changed' === $cursor->get_error_code() ) {
					$coverage->fail( $event_id, $revision, 'recovery_snapshot', $cursor->get_error_code(), $continuation );
				}
				return $cursor;
			}
			$page = (int) $cursor['page'];
		}
		$source_page = $this->adapter->page_for_event( $event_id, $page, $limit, $config );
		if ( $source_page instanceof \WP_Error ) {
			$coverage->fail( $event_id, $revision, 'recovery_page:' . $page, $source_page->get_error_code(), $continuation );
			return $source_page;
		}
		$results = array();
		foreach ( $source_page['items'] as $evidence ) {
			$identity = Source_Change_Listener::identity( (int) $evidence['order_id'], (int) $evidence['order_item_id'] );
			$result   = $this->reconcile_evidence( $event_id, $evidence, $config );
			if ( $result instanceof \WP_Error ) {
				$coverage->fail( $event_id, $revision, $identity, $result->get_error_code(), $continuation );
				return $result;
			}
			$coverage->clear_failure( $event_id, $revision, $identity );
			$results[] = $result;
		}
		$next = ! empty( $source_page['has_more'] ) ? Recovery_Cursor::issue( $event_id, $revision, $page + 1, $limit, $snapshot ) : '';
		$state = ! empty( $source_page['has_more'] )
			? $coverage->advance( $event_id, $revision, $snapshot, $page + 1, $next )
			: $coverage->complete( $event_id, $revision, $snapshot );

		return array(
			'page'           => $page,
			'scanned_orders' => (int) $source_page['scanned_orders'],
			'matching_items' => (int) $source_page['matching_items'],
			'source_orders'  => (int) $source_page['source_orders'],
			'total_pages'    => (int) $source_page['total_pages'],
			'has_more'       => (bool) $source_page['has_more'],
			'continuation'   => $next,
			'coverage'       => $state,
			'results'        => $results,
		);
	}
}
