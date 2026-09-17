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
		$resolution = Source_Resolver::resolve( $evidence, $event_id, $config );
		$quantity   = max( 1, (int) ( $evidence['quantity'] ?? 1 ) );
		$rows       = array();
		for ( $unit = 1; $unit <= $quantity; ++$unit ) {
			$source_key = implode( ':', array( 'woo', (int) $evidence['order_id'], (int) $evidence['order_item_id'], $unit ) );
			$rows[]     = $this->registrations->upsert_source_projection( $event_id, $source_key, $unit, $evidence, $resolution, (int) $config['revision'] );
		}

		return array(
			'resolution'    => $resolution,
			'registrations' => $rows,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function reconcile_page( int $event_id, array $config, int $page = 1, int $limit = 50 ) {
		$items = $this->adapter->page_for_event( $event_id, $page, $limit );
		if ( $items instanceof \WP_Error ) {
			return $items;
		}
		$results = array();
		foreach ( $items as $evidence ) {
			$results[] = $this->reconcile_evidence( $event_id, $evidence, $config );
		}

		return array(
			'page'    => max( 1, $page ),
			'count'   => count( $items ),
			'results' => $results,
		);
	}
}
