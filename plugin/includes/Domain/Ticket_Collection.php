<?php
namespace ORAS\Tickets\Domain;

use ORAS\Tickets\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ticket_Collection {

	/** @var Ticket[] */
	private array $tickets = [];

	public function __construct( array $tickets = [] ) {
		$this->tickets = $tickets;
	}

	/**
	 * Envelope stored in postmeta:
	 * [
	 *   'schema'  => 1,
	 *   'tickets' => [ ticket_key => [ ...Ticket fields... ], ... ]
	 * ]
	 *
	 * Returns a Ticket_Collection instance. If the stored envelope is missing
	 * or the schema is not 1, an empty collection is returned.
	 */
	public static function load_for_event( int $event_id ): self {
		$raw = get_post_meta( $event_id, Meta::META_KEY_TICKETS, true );

		if ( ! is_array( $raw ) ) {
			return new self();
		}

		$schema  = isset( $raw['schema'] ) ? (int) $raw['schema'] : 1;
		if ( 1 !== $schema ) {
			return new self();
		}

		$tickets_raw = isset( $raw['tickets'] ) && is_array( $raw['tickets'] ) ? $raw['tickets'] : [];

		$tickets = [];
		foreach ( $tickets_raw as $maybe_key => $ticket_arr ) {
			if ( ! is_array( $ticket_arr ) ) {
				continue;
			}

			// Ensure ticket_key present in the data; fall back to the array key.
			if ( empty( $ticket_arr['ticket_key'] ) ) {
				$ticket_arr['ticket_key'] = (string) $maybe_key;
			}

			$tickets[] = new Ticket( $ticket_arr );
		}

		return new self( $tickets );
	}

	/**
	 * Return the raw envelope array from postmeta. Useful for callers that
	 * still need the original shape.
	 *
	 * Always returns an envelope with 'schema' and 'tickets' keys.
	 *
	 * @return array{schema:int,tickets:array}
	 */
	public static function load_envelope_for_event( int $event_id ): array {
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
	 * Return tickets as an ordered array of Ticket objects.
	 * @return Ticket[]
	 */
	public function all(): array {
		return $this->tickets;
	}

	public function count(): int {
		return count( $this->tickets );
	}

	public function is_empty(): bool {
		return empty( $this->tickets );
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
