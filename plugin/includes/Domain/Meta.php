<?php
namespace ORAS\Tickets\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Meta {
	public const EVENT_POST_TYPE = 'tribe_events';

	/**
	 * Single meta key on the event that stores all ticket definitions.
	 * Versioned envelope for migrations.
	 */
	public const META_KEY_TICKETS = '_oras_tickets_v1';
}
