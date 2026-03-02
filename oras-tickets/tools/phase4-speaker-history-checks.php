<?php
/**
 * Phase 4 speaker history/index regression checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /tmp/oras-phase4-speaker-history-checks.php
 */

use ORAS\Tickets\Admin\Metaboxes\Event_Agenda_Metabox;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class OrasPhase4CheckException extends RuntimeException {}

/**
 * @throws OrasPhase4CheckException
 */
function oras_phase4_fail( string $message ): void {
	throw new OrasPhase4CheckException( $message );
}

/**
 * @throws OrasPhase4CheckException
 */
function oras_phase4_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		oras_phase4_fail( $message );
	}

	echo 'PASS: ' . $message . "\n";
}

/**
 * @return array<string, mixed>|null
 */
function oras_phase4_find_history_event( array $history, int $event_id ): ?array {
	$events = $history['events'] ?? null;
	if ( ! is_array( $events ) ) {
		return null;
	}

	foreach ( $events as $event ) {
		if ( ! is_array( $event ) ) {
			continue;
		}

		if ( (int) ( $event['event_id'] ?? 0 ) === $event_id ) {
			return $event;
		}
	}

	return null;
}

function oras_phase4_save_agenda( int $event_id, int $admin_id, array $agenda_payload ): void {
	wp_set_current_user( $admin_id );
	$_POST = array(
		'oras_agenda_metabox_nonce' => wp_create_nonce( 'oras_agenda_metabox' ),
		'oras_agenda'               => $agenda_payload,
	);

	Event_Agenda_Metabox::save( $event_id );
}

/**
 * @throws OrasPhase4CheckException
 */
function oras_phase4_run_checks(): void {
	require_once ABSPATH . 'wp-admin/includes/post.php';
	require_once ABSPATH . 'wp-admin/includes/user.php';

	if ( ! post_type_exists( 'tribe_events' ) ) {
		register_post_type(
			'tribe_events',
			array(
				'label'  => 'Events',
				'public' => true,
			)
		);
	}

	if ( ! post_type_exists( 'oras_speaker' ) ) {
		register_post_type(
			'oras_speaker',
			array(
				'label'  => 'Speakers',
				'public' => true,
			)
		);
	}

	$admin_ids = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ids',
			'number' => 1,
		)
	);
	oras_phase4_assert( ! empty( $admin_ids ), 'Administrator user exists' );
	$admin_id = (int) $admin_ids[0];

	$suffix = gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );

	$created_posts = array();

	try {
		$speaker_one_id = wp_insert_post(
			array(
				'post_title'  => 'ORAS Phase4 Speaker One ' . $suffix,
				'post_status' => 'publish',
				'post_type'   => 'oras_speaker',
			)
		);
		oras_phase4_assert( is_int( $speaker_one_id ) && $speaker_one_id > 0, 'Speaker one fixture created' );
		$created_posts[] = $speaker_one_id;

		$speaker_two_id = wp_insert_post(
			array(
				'post_title'  => 'ORAS Phase4 Speaker Two ' . $suffix,
				'post_status' => 'publish',
				'post_type'   => 'oras_speaker',
			)
		);
		oras_phase4_assert( is_int( $speaker_two_id ) && $speaker_two_id > 0, 'Speaker two fixture created' );
		$created_posts[] = $speaker_two_id;

		$event_id = wp_insert_post(
			array(
				'post_title'  => 'ORAS Phase4 Event ' . $suffix,
				'post_status' => 'publish',
				'post_type'   => 'tribe_events',
			)
		);
		oras_phase4_assert( is_int( $event_id ) && $event_id > 0, 'Fixture event created' );
		$created_posts[] = $event_id;

		update_post_meta( $event_id, '_EventStartDate', '2026-03-01 09:00:00' );

		$initial_agenda = array(
			'settings' => array(
				'enabled' => 1,
				'title'   => 'Agenda',
			),
			'days'     => array(
				array(
					'day_label' => 'Day 1',
					'date'      => '2026-03-01',
					'slots'     => array(
						array(
							'start'     => '09:00',
							'end'       => '10:00',
							'title'     => 'Opening Session',
							'desc'      => 'Session description',
							'type'      => 'talk',
							'location'  => 'Main Hall',
							'visibility'=> 'public',
							'speakers'  => array(
								array(
									'speaker_id' => $speaker_one_id,
									'role'       => 'Presenter',
								),
							),
							'resources' => array(
								array(
									'attachment_id' => 0,
									'url'           => 'https://example.org/phase4-resource-one',
									'label'         => 'Speaker Two Resource',
									'type'          => 'link',
									'visibility'    => 'public',
									'speaker_ids'   => array( $speaker_two_id ),
								),
							),
						),
					),
				),
			),
		);

		oras_phase4_save_agenda( $event_id, $admin_id, $initial_agenda );

		$agenda_meta = get_post_meta( $event_id, '_oras_agenda_v1', true );
		oras_phase4_assert( is_array( $agenda_meta ) && (int) ( $agenda_meta['version'] ?? 0 ) === 1, 'Agenda envelope is saved with version 1' );

		$speaker_one_history = get_post_meta( $speaker_one_id, '_oras_speaker_history_v1', true );
		oras_phase4_assert( is_array( $speaker_one_history ) && is_array( $speaker_one_history['events'] ?? null ), 'Speaker one history envelope is created' );
		$speaker_one_event = oras_phase4_find_history_event( $speaker_one_history, $event_id );
		oras_phase4_assert( is_array( $speaker_one_event ), 'Speaker one history includes event entry' );
		$one_slots = $speaker_one_event['slots'] ?? array();
		oras_phase4_assert( is_array( $one_slots ) && count( $one_slots ) === 1, 'Speaker one event history includes one slot' );
		$one_slot_resources = $one_slots[0]['resources'] ?? array();
		oras_phase4_assert( empty( $one_slot_resources ), 'Speaker one slot excludes resources not assigned to speaker one' );

		$speaker_two_history = get_post_meta( $speaker_two_id, '_oras_speaker_history_v1', true );
		oras_phase4_assert( is_array( $speaker_two_history ) && is_array( $speaker_two_history['events'] ?? null ), 'Speaker two history envelope is created from resource assignment' );
		$speaker_two_event = oras_phase4_find_history_event( $speaker_two_history, $event_id );
		oras_phase4_assert( is_array( $speaker_two_event ), 'Speaker two history includes event entry from resource speaker_ids' );
		$two_slots = $speaker_two_event['slots'] ?? array();
		oras_phase4_assert( is_array( $two_slots ) && count( $two_slots ) === 1, 'Speaker two event history includes one slot' );
		$two_slot_resources = $two_slots[0]['resources'] ?? array();
		oras_phase4_assert( is_array( $two_slot_resources ) && count( $two_slot_resources ) === 1, 'Speaker two slot includes assigned resource' );
		oras_phase4_assert( (string) ( $two_slot_resources[0]['label'] ?? '' ) === 'Speaker Two Resource', 'Speaker two resource label is preserved in history' );

		$updated_agenda = $initial_agenda;
		$updated_agenda['days'][0]['slots'][0]['resources'][0]['label'] = 'Speaker One Resource';
		$updated_agenda['days'][0]['slots'][0]['resources'][0]['speaker_ids'] = array( $speaker_one_id );

		oras_phase4_save_agenda( $event_id, $admin_id, $updated_agenda );

		$speaker_one_history_updated = get_post_meta( $speaker_one_id, '_oras_speaker_history_v1', true );
		$speaker_one_event_updated   = is_array( $speaker_one_history_updated ) ? oras_phase4_find_history_event( $speaker_one_history_updated, $event_id ) : null;
		oras_phase4_assert( is_array( $speaker_one_event_updated ), 'Speaker one history still includes event after update' );
		$one_updated_slots = $speaker_one_event_updated['slots'] ?? array();
		$one_updated_resources = is_array( $one_updated_slots ) && isset( $one_updated_slots[0]['resources'] ) && is_array( $one_updated_slots[0]['resources'] )
			? $one_updated_slots[0]['resources']
			: array();
		oras_phase4_assert( count( $one_updated_resources ) === 1, 'Speaker one history now includes reassigned resource' );
		oras_phase4_assert( (string) ( $one_updated_resources[0]['label'] ?? '' ) === 'Speaker One Resource', 'Speaker one reassigned resource label is reflected in history' );

		$speaker_two_history_updated = get_post_meta( $speaker_two_id, '_oras_speaker_history_v1', true );
		$speaker_two_event_updated   = is_array( $speaker_two_history_updated ) ? oras_phase4_find_history_event( $speaker_two_history_updated, $event_id ) : null;
		oras_phase4_assert( ! is_array( $speaker_two_event_updated ), 'Speaker two history entry is removed when no longer assigned' );

		$cleared_agenda = array(
			'settings' => array(
				'enabled' => 0,
				'title'   => 'Agenda',
			),
			'days'     => array(),
		);

		oras_phase4_save_agenda( $event_id, $admin_id, $cleared_agenda );

		$cleared_agenda_meta = get_post_meta( $event_id, '_oras_agenda_v1', true );
		oras_phase4_assert( ! is_array( $cleared_agenda_meta ), 'Agenda envelope is removed when agenda is disabled with no days' );

		$speaker_one_history_cleared = get_post_meta( $speaker_one_id, '_oras_speaker_history_v1', true );
		$speaker_one_event_cleared   = is_array( $speaker_one_history_cleared ) ? oras_phase4_find_history_event( $speaker_one_history_cleared, $event_id ) : null;
		oras_phase4_assert( ! is_array( $speaker_one_event_cleared ), 'Speaker one history event entry is removed when agenda is cleared' );

		echo "PASS: Phase 4 speaker history/index checks completed\n";
	} finally {
		wp_set_current_user( $admin_id );
		$_POST = array();
		$_GET  = array();

		foreach ( $created_posts as $post_id ) {
			if ( $post_id > 0 ) {
				wp_delete_post( $post_id, true );
			}
		}
	}
}

try {
	oras_phase4_run_checks();
} catch ( OrasPhase4CheckException $e ) {
	fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" );
	exit( 1 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'FAIL: Unexpected exception: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

