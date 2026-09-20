<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function oras_stats_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 ); }
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$file = dirname( __DIR__ ) . '/oras-tickets/includes/Registration_Desk/Event_Stats_Service.php';
oras_stats_assert( file_exists( $file ), 'Event_Stats_Service.php exists' );
require_once $file;
$class = '\\ORAS\\Tickets\\Registration_Desk\\Event_Stats_Service';

$registrations = array(
	array(
		'id'                 => 1,
		'source_type'        => 'online',
		'classification'     => 'family',
		'validity_type'      => 'full_event',
		'option_label'       => 'Family',
		'payment_assertion'  => '',
		'created_local_date' => '2026-09-18',
	),
	array(
		'id'                 => 2,
		'source_type'        => 'online_included',
		'classification'     => 'individual',
		'validity_type'      => 'full_event',
		'option_label'       => 'Bundle',
		'payment_assertion'  => '',
		'created_local_date' => '2026-09-18',
	),
	array(
		'id'                 => 3,
		'source_type'        => 'walk_in',
		'classification'     => 'individual',
		'validity_type'      => 'one_day',
		'option_label'       => 'One Day',
		'payment_assertion'  => 'cash',
		'created_local_date' => '2026-09-19',
	),
	array(
		'id'                 => 4,
		'source_type'        => 'complimentary',
		'classification'     => 'individual',
		'validity_type'      => 'full_event',
		'option_label'       => 'Speaker',
		'payment_assertion'  => 'unpaid',
		'created_local_date' => '2026-09-19',
	),
	array(
		'id'                 => 5,
		'source_type'        => 'walk_in',
		'classification'     => 'individual',
		'validity_type'      => 'full_event',
		'option_label'       => 'Individual',
		'payment_assertion'  => 'card',
		'created_local_date' => '2026-09-18',
	),
	array(
		'id'                 => 6,
		'source_type'        => 'manager_verified_manual',
		'classification'     => 'individual',
		'validity_type'      => 'full_event',
		'option_label'       => 'Manager Verified',
		'payment_assertion'  => 'manager_verified',
		'created_local_date' => '2026-09-19',
	),
	array(
		'id'                 => 7,
		'source_type'        => 'rsvp_website',
		'classification'     => 'individual',
		'validity_type'      => 'full_event',
		'option_label'       => 'RSVP',
		'payment_assertion'  => 'rsvp',
		'created_local_date' => '2026-09-19',
	),
);
$attendees = array(
	array(
		'id'              => 11,
		'registration_id' => 1,
	),
	array(
		'id'              => 12,
		'registration_id' => 1,
	),
	array(
		'id'              => 13,
		'registration_id' => 1,
	),
	array(
		'id'              => 21,
		'registration_id' => 2,
	),
	array(
		'id'              => 22,
		'registration_id' => 3,
	),
	array(
		'id'              => 31,
		'registration_id' => 4,
	),
	array(
		'id'              => 41,
		'registration_id' => 5,
	),
	array(
		'id'              => 51,
		'registration_id' => 6,
	),
	array(
		'id'              => 61,
		'registration_id' => 7,
	),
);
$attendance = array(
	array(
		'attendee_id'           => 11,
		'attendance_local_date' => '2026-09-18',
	),
	array(
		'attendee_id'           => 11,
		'attendance_local_date' => '2026-09-19',
	),
	array(
		'attendee_id'           => 12,
		'attendance_local_date' => '2026-09-19',
	),
	array(
		'attendee_id'           => 21,
		'attendance_local_date' => '2026-09-19',
	),
	array(
		'attendee_id'           => 22,
		'attendance_local_date' => '2026-09-19',
	),
	array(
		'attendee_id'           => 31,
		'attendance_local_date' => '2026-09-19',
	),
	array(
		'attendee_id'           => 51,
		'attendance_local_date' => '2026-09-19',
	),
	array(
		'attendee_id'           => 61,
		'attendance_local_date' => '2026-09-19',
	),
);
$memberships = array(
	array(
		'level_name'     => 'Individual',
		'payment_method' => 'cash',
		'status'         => 'pending',
		'expires_at_utc' => '2026-12-18 12:00:00',
	),
	array(
		'level_name'     => 'Family',
		'payment_method' => 'check',
		'status'         => 'redeemed',
		'expires_at_utc' => '2026-12-18 12:00:00',
	),
);

$stats = $class::summarize_rows( $registrations, $attendees, $attendance, $memberships, '2026-09-19', '2026-09-19 12:00:00' );
oras_stats_assert( 7 === $stats['today']['actual_people'], 'Today counts actual checked-in people, not registrations' );
oras_stats_assert( 2 === $stats['today']['website_people'] && 1 === $stats['today']['included_event_people'] && 1 === $stats['today']['walk_in_people'] && 1 === $stats['today']['complimentary_people'] && 1 === $stats['today']['rsvp_people'] && 1 === $stats['today']['manager_verified_people'], 'Today source split follows each attendee registration honestly' );
oras_stats_assert( 1 === $stats['today']['new_walk_in_registrations'], 'Today counts newly created walk-in registrations without mixing complimentary records' );
oras_stats_assert( 7 === $stats['event_total']['active_registrations'], 'Event total counts registrations separately' );
oras_stats_assert( 7 === $stats['event_total']['unique_attendees'], 'Unique event attendance de-duplicates multi-day people' );
oras_stats_assert( 8 === $stats['event_total']['attendance_instances'], 'Attendance instances include every checked-in day' );
oras_stats_assert( 1 === $stats['event_total']['no_show_registrations'], 'Registration with no attendance is a no-show' );
oras_stats_assert( 1 === $stats['event_total']['family_registrations'] && 2 === $stats['event_total']['family_attendees_attended'], 'Family passes and actual family attendees remain distinct' );
oras_stats_assert(
	array(
		'2026-09-18' => 1,
		'2026-09-19' => 7,
	) === $stats['event_total']['attendance_by_day'],
	'Attendance is grouped by event-local day'
);
oras_stats_assert( 1 === $stats['event_total']['payment_assertions']['cash'] && 1 === $stats['event_total']['payment_assertions']['card'], 'Walk-in payment assertions are counts, not revenue' );
oras_stats_assert( 1 === $stats['event_total']['rsvp_registrations'] && 1 === $stats['event_total']['manager_verified_registrations'], 'RSVP and Manager Verified registrations remain distinct from website registrations' );
oras_stats_assert( 1 === $stats['event_total']['direct_website_registrations'] && 1 === $stats['event_total']['included_event_registrations'], 'Direct website and included-event registrations have honest separate source totals' );
oras_stats_assert( 2 === $stats['memberships']['total'] && 1 === $stats['memberships']['pending'] && 1 === $stats['memberships']['redeemed'], 'Event-originated membership lifecycle is summarized' );

echo "Registration Desk stats checks passed.\n";
