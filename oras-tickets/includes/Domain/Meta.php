<?php

/**
 * Domain meta constants.
 *
 * @package ORAS\Tickets
 */

namespace ORAS\Tickets\Domain;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Domain meta key definitions.
 */
final class Meta {


    public const EVENT_POST_TYPE = 'tribe_events';

    /**
     * Single meta key on the event that stores all ticket definitions.
     * Versioned envelope for migrations.
     */
    public const META_KEY_TICKETS = '_oras_tickets_v1';

    /**
     * Single meta key on the event that stores door prize definitions.
     * Versioned envelope for migrations.
     */
    public const META_KEY_DOOR_PRIZES = '_oras_door_prizes_v1';
}
