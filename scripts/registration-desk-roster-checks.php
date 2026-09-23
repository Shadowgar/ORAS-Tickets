<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' );
}

function sanitize_text_field( mixed $value ): string {
	return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone WordPress-function test double.
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function oras_roster_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		exit( 1 );
	}
	fwrite( STDOUT, "PASS: {$message}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
}

$root         = dirname( __DIR__ );
$service_file = $root . '/oras-tickets/includes/Registration_Desk/Event_Roster_Service.php';
$training_service_file = $root . '/oras-tickets/includes/Registration_Desk/Training_Service.php';
$rest_file    = $root . '/oras-tickets/includes/Registration_Desk/Rest_Controller.php';
$desk_file    = $root . '/oras-tickets/assets/registration-desk/desk.js';
$css_file     = $root . '/oras-tickets/assets/registration-desk/desk.css';

$desk_source = (string) file_get_contents( $desk_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local UI source fixture.
oras_roster_assert( false !== strpos( $desk_source, '<dialog class="desk-filter-dialog"' ), 'Roster filter choices are contained in a closed-by-default dialog' );
oras_roster_assert( false !== strpos( $desk_source, 'CHANGE FILTERS' ) && false !== strpos( $desk_source, 'CLEAR SEARCH' ), 'Roster has compact filter and search controls' );
oras_roster_assert( false === strpos( $desk_source, 'id="desk-show-everyone"' ), 'Roster does not show a large reset button' );

oras_roster_assert( file_exists( $service_file ), 'Event roster has a dedicated read-only service' );
oras_roster_assert( file_exists( $training_service_file ), 'Training roster has a separate synthetic-only service' );

require_once $service_file;

$filters = \ORAS\Tickets\Registration_Desk\Event_Roster_Service::normalize_filters(
	array(
		'q'           => '  Smith  ',
		'status'      => 'checked_in',
		'option_uuid' => '11111111-1111-4111-8111-111111111111',
		'offset'      => 50000,
		'limit'       => 500,
	)
);
oras_roster_assert( 'Smith' === $filters['q'] && 'checked_in' === $filters['status'], 'Roster filters normalize simple search and one status choice' );
oras_roster_assert( 5000 === $filters['offset'] && 50 === $filters['limit'], 'Roster pagination is bounded' );
oras_roster_assert( 'everyone' === \ORAS\Tickets\Registration_Desk\Event_Roster_Service::normalize_filters( array( 'status' => 'hidden-complex-filter' ) )['status'], 'Unknown roster filters fail back to Everyone' );

$historical = \ORAS\Tickets\Registration_Desk\Event_Roster_Service::historical_label(
	array(
		'source_type'     => 'walk_in',
		'source_evidence' => '{"offering":{"label":"Historic Family Pass"}}',
	)
);
oras_roster_assert( 'Historic Family Pass' === $historical, 'Roster keeps the honest historical offering snapshot' );
$online_historical = \ORAS\Tickets\Registration_Desk\Event_Roster_Service::historical_label(
	array(
		'source_type'     => 'online',
		'source_evidence' => '{"item_label":"Original Website Ticket"}',
	)
);
oras_roster_assert( 'Original Website Ticket' === $online_historical, 'Website roster labels use order-time ticket evidence' );

$service = (string) file_get_contents( $service_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
$training_service = (string) file_get_contents( $training_service_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
$rest    = (string) file_get_contents( $rest_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
$desk    = (string) file_get_contents( $desk_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
$css     = (string) file_get_contents( $css_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.

oras_roster_assert( false !== strpos( $service, 'event_id = %d' ), 'Roster queries are event scoped' );
oras_roster_assert( false !== strpos( $training_service, "'registrations'" ) && false !== strpos( $training_service, "'attendance'" ), 'Training roster reads the isolated training state shape' );
oras_roster_assert( false === strpos( $training_service, '$wpdb' ), 'Training roster never queries live database tables' );
oras_roster_assert( false !== strpos( $service, 'ORDER BY' ) && false !== strpos( $service, 'last_name' ), 'Roster ordering is alphabetical by person name' );
oras_roster_assert( false !== strpos( $service, 'LIMIT %d OFFSET %d' ), 'Roster uses bounded offset pagination' );
oras_roster_assert( false !== strpos( $service, "source_type IN ('walk_in','rsvp_walk_in')" ), 'Roster supports the walk-in status filter' );
oras_roster_assert( false !== strpos( $service, 'option_uuid = %s' ), 'Roster supports one canonical registration-type filter' );
oras_roster_assert( false !== strpos( $service, 'rsvp_waitlist' ), 'Roster supports RSVP waitlist records' );
oras_roster_assert( false !== strpos( $rest, '/registration-desk/roster' ), 'REST controller exposes the station-bound roster route' );
oras_roster_assert( false !== strpos( $rest, 'manager_detail' ) && false !== strpos( $rest, 'is_manager_request' ), 'Manager roster fields require server-side manager validation' );
oras_roster_assert( false !== strpos( $rest, 'Event_Offering_Resolver::desk_offerings' ), 'Roster type filters reuse canonical event offerings' );
oras_roster_assert( false === strpos( $desk, 'id="desk-home-roster"' ), 'Volunteer home removes the duplicate Event Roster button' );
oras_roster_assert( false !== strpos( $desk, "#desk-home-find').addEventListener('click', () => showEventRoster(true)" ), 'Find Registration is the one roster and search entry point' );
oras_roster_assert( false !== strpos( $desk, 'SEARCH THIS EVENT' ), 'Find Registration search is optional and visible above the roster' );
oras_roster_assert( false !== strpos( $desk, 'THEY SAY THEY ALREADY REGISTERED' ), 'Searched roster no-results retain paid-registration recovery' );
oras_roster_assert( false !== strpos( $desk, 'SHOW EVERYONE' ), 'Roster has an explicit Show Everyone reset' );
oras_roster_assert( false !== strpos( $desk, 'SHOW MORE PEOPLE' ), 'Roster uses kiosk-style incremental pagination' );
oras_roster_assert( false !== strpos( $desk, 'CHOOSE REGISTRATION TYPE' ), 'Many registration types use a large picker' );
oras_roster_assert( false !== strpos( $desk, 'ALL REGISTRATION TYPES' ), 'Type filtering always exposes the all-types state' );
oras_roster_assert( false !== strpos( $desk, 'showEventRoster' ) && false !== strpos( $desk, 'showRegistration' ), 'Roster rows reuse Registration Detail' );
oras_roster_assert( false !== strpos( $desk, '/training/roster' ) && false !== strpos( $desk, 'showTrainingRegistration' ), 'Training roster and detail stay on isolated browser paths' );
oras_roster_assert( false !== strpos( $desk, '/training/registrations/' ) && false !== strpos( $desk, 'attendee_uuids' ), 'Training family check-in submits selected synthetic attendee identities' );
oras_roster_assert( false !== strpos( $desk, 'RECORD MEMBERSHIP &amp; SEND EMAIL' ), 'Membership final action clearly states its effects' );
oras_roster_assert( false !== strpos( $desk, 'PAYMENT RECORDED IN ALFAPOS' ), 'Membership flow keeps payment processing in AlfaPOS' );
oras_roster_assert( false !== strpos( $desk, 'desk-membership-payment-choice' ), 'Membership payment methods use kiosk touch choices' );
oras_roster_assert( false !== strpos( $css, '.desk-roster-status' ), 'Roster status choices have dedicated touch-first styling' );
oras_roster_assert( false !== strpos( $css, '.desk-type-picker' ), 'Registration type picker has dedicated large-control styling' );
oras_roster_assert( false !== strpos( $css, '.desk-membership-payment-choice' ), 'Membership payment choices have dedicated large-control styling' );

echo "Registration Desk roster checks passed.\n";
