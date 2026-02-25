<?php

namespace ORAS\Tickets;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RSVP {
    /**
     * Determine whether a user has RSVP'd "yes" for an event.
     */
    public static function user_has_rsvped_yes( int $event_id, int $user_id ): bool {
        if ( $user_id <= 0 || $event_id <= 0 ) {
            return false;
        }

        $val = get_user_meta( $user_id, '_oras_rsvp_event_' . $event_id, true );
        return 'yes' === $val;
    }
}
