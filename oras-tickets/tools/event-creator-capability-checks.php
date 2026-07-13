<?php
/**
 * Targeted checks for the Event Creator role capability model.
 *
 * Run:
 *   php oras-tickets/tools/event-creator-capability-checks.php
 */

$capabilities_file = dirname( __DIR__ ) . '/includes/Capabilities.php';
$code = file_exists( $capabilities_file ) ? (string) file_get_contents( $capabilities_file ) : '';

function oras_event_creator_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	echo "PASS: {$message}\n";
}

oras_event_creator_assert( false !== strpos( $code, 'EVENT_CREATOR_ROLE' ), 'Capabilities define Event Creator role slug' );
oras_event_creator_assert( false !== strpos( $code, 'ensure_event_creator_role' ), 'Capabilities can ensure Event Creator role exists' );
oras_event_creator_assert( false !== strpos( $code, 'EVENT_CREATOR_CAPS' ), 'Capabilities define Event Creator capability set' );
oras_event_creator_assert( false !== strpos( $code, "'oras_tickets_view_board_dashboard'" ), 'Event Creator can view Board Reports' );
oras_event_creator_assert( false !== strpos( $code, "'oras_tickets_manage_rsvps'" ), 'Event Creator can manage RSVP workflows' );
oras_event_creator_assert( false !== strpos( $code, "'oras_tickets_send_notifications'" ), 'Event Creator can send event communications' );
oras_event_creator_assert( false !== strpos( $code, "'edit_tribe_events'" ), 'Event Creator receives The Events Calendar edit capability' );
oras_event_creator_assert( false !== strpos( $code, "'publish_tribe_events'" ), 'Event Creator receives The Events Calendar publish capability' );
oras_event_creator_assert( false !== strpos( $code, "'delete_tribe_events'" ), 'Event Creator receives The Events Calendar delete capability' );
oras_event_creator_assert( false === strpos( $code, "'manage_options'" ), 'Event Creator is not granted manage_options' );

echo "Event Creator capability checks passed.\n";
