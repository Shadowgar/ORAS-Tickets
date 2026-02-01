<?php
namespace ORAS\Tickets\Domain;

use ORAS\Tickets\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ticket_Collection {

	/**
	 * Envelope stored in postmeta:
	 * [
	 *   'schema'  => 1,
	 *   'tickets' => [ ticket_key => [ ...Ticket fields... ], ... ]
	 * ]
	 */
	public static function load_for_event( int $event_id ): array {
		$raw = get_post_meta( $event_id, Meta::META_KEY_TICKETS, true );

		if ( ! is_array( $raw ) ) {
			return [
				'schema'  => 1,
				'tickets' => [],
			];
		}

		$schema  = isset( $raw['schema'] ) ? (int) $raw['schema'] : 1;
		$tickets = isset( $raw['tickets'] ) && is_array( $raw['tickets'] ) ? $raw['tickets'] : [];

		return [
			'schema'  => $schema,
			'tickets' => $tickets,
		];
	}

	public static function save_for_event( int $event_id, array $envelope ): void {
		$schema  = isset( $envelope['schema'] ) ? (int) $envelope['schema'] : 1;
		$tickets = isset( $envelope['tickets'] ) && is_array( $envelope['tickets'] ) ? $envelope['tickets'] : [];

		$clean = [
			'schema'  => $schema,
			'tickets' => $tickets,
		];

		update_post_meta( $event_id, Meta::META_KEY_TICKETS, $clean );
		Logger::instance()->log( "Saved tickets meta for event {$event_id} (count=" . count( $tickets ) . ')' );
	}

	/**
	 * Create a new ticket_key.
	 * Must be stable, unique per event, and safe for array keys.
	 */
	public static function generate_ticket_key(): string {
		// 12 chars is enough entropy; keep it short for admin UX.
		return substr( wp_generate_uuid4(), 0, 12 );
	}
}
