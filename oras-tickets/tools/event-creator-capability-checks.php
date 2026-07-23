<?php
/**
 * Targeted checks for the Event Creator role capability model.
 *
 * Run:
 *   php oras-tickets/tools/event-creator-capability-checks.php
 */

$capabilities_file = dirname( __DIR__ ) . '/includes/Capabilities.php';
$bootstrap_file    = dirname( __DIR__ ) . '/includes/Bootstrap.php';
$code              = file_exists( $capabilities_file ) ? (string) file_get_contents( $capabilities_file ) : '';
$bootstrap_code    = file_exists( $bootstrap_file ) ? (string) file_get_contents( $bootstrap_file ) : '';

function oras_event_creator_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	echo "PASS: {$message}\n";
}

preg_match( '/public const BOARD_CAPS\s*=\s*\[(.*?)\];/s', $code, $board_match );
preg_match( '/public const EVENT_COORDINATOR_CAPS\s*=\s*\[(.*?)\];/s', $code, $coordinator_match );
$board_caps       = $board_match[1] ?? '';
$coordinator_caps = $coordinator_match[1] ?? '';

oras_event_creator_assert( false !== strpos( $code, 'EVENT_COORDINATOR_ROLE' ), 'Capabilities preserve a dedicated Event Coordinator role slug' );
oras_event_creator_assert( false !== strpos( $code, "'Event Coordinator'" ), 'Existing role is presented as Event Coordinator' );
oras_event_creator_assert( false !== strpos( $code, 'reconcile_roles' ), 'Capabilities can reconcile persisted role permissions' );
oras_event_creator_assert( false !== strpos( $bootstrap_code, 'reconcile_roles' ), 'Bootstrap reconciles role permissions during upgrades' );

oras_event_creator_assert( false !== strpos( $board_caps, "'oras_tickets_view_board_dashboard'" ), 'Board can view Board Reports' );
oras_event_creator_assert( false !== strpos( $board_caps, "'oras_tickets_view_reports'" ), 'Board can view reports' );
oras_event_creator_assert( false !== strpos( $board_caps, "'oras_tickets_view_attendees'" ), 'Board can view attendee information' );
oras_event_creator_assert( false !== strpos( $board_caps, "'oras_tickets_manage_rsvps'" ), 'Board can approve RSVPs and manage waitlists' );
oras_event_creator_assert( false !== strpos( $board_caps, "'oras_tickets_send_notifications'" ), 'Board can send event communications' );
oras_event_creator_assert( false === strpos( $board_caps, "'oras_tickets_manage_settings'" ), 'Board cannot manage ORAS settings' );
oras_event_creator_assert( false === strpos( $board_caps, "'oras_tickets_manage_events'" ), 'Board cannot manage events' );
oras_event_creator_assert( false === strpos( $board_caps, "'oras_tickets_manage_attendees'" ), 'Board cannot modify attendee records outside RSVP operations' );

oras_event_creator_assert( false !== strpos( $coordinator_caps, "'oras_tickets_view_board_dashboard'" ), 'Event Coordinator can view Board Reports' );
oras_event_creator_assert( false !== strpos( $coordinator_caps, "'oras_tickets_manage_rsvps'" ), 'Event Coordinator can manage RSVP workflows' );
oras_event_creator_assert( false !== strpos( $coordinator_caps, "'oras_tickets_send_notifications'" ), 'Event Coordinator can send event communications' );
oras_event_creator_assert( false !== strpos( $coordinator_caps, "'edit_tribe_events'" ), 'Event Coordinator receives The Events Calendar edit capability' );
oras_event_creator_assert( false !== strpos( $coordinator_caps, "'publish_tribe_events'" ), 'Event Coordinator receives The Events Calendar publish capability' );
oras_event_creator_assert( false !== strpos( $coordinator_caps, "'delete_tribe_events'" ), 'Event Coordinator receives The Events Calendar delete capability' );
oras_event_creator_assert( false === strpos( $coordinator_caps, "'oras_tickets_manage_settings'" ), 'Event Coordinator cannot manage ORAS settings' );
oras_event_creator_assert( false === strpos( $coordinator_caps, "'manage_options'" ), 'Event Coordinator is not granted manage_options' );

echo "Event Coordinator and Board capability checks passed.\n";
