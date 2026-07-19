<?php
/**
 * Phase 4 frontend/admin surface checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase4-surface-checks.php
 */

use ORAS\Tickets\Admin\Event_Speakers_Metabox;
use ORAS\Tickets\Frontend\Event_Agenda_Render;

if ( ! defined( 'ABSPATH' ) ) {
    $bootstrap_path = getenv( 'ORAS_WP_LOAD_PATH' );
    if ( is_string( $bootstrap_path ) && $bootstrap_path !== '' && file_exists( $bootstrap_path ) ) {
        require_once $bootstrap_path;
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

final class OrasPhase4SurfaceCheckException extends RuntimeException {}

function phase4SurfaceFail( string $message ): void {
    throw new OrasPhase4SurfaceCheckException( $message );
}

function phase4SurfaceAssert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        phase4SurfaceFail( $message );
    }

    echo 'PASS: ' . $message . "\n";
}

function phase4RenderAgendaForEvent( int $event_id, int $user_id ): string {
    global $wp_query;
    global $wp_the_query;

    $previous_query = $wp_query;
    $previous_the_query = $wp_the_query;

    wp_set_current_user( $user_id );

    $query = new WP_Query(
        array(
            'post_type'      => 'tribe_events',
            'p'              => $event_id,
            'posts_per_page' => 1,
        )
    );

    $output = '<p>base content</p>';
    if ( $query->have_posts() ) {
        $query->the_post();
        $query->is_singular  = true;
        $query->is_single    = true;
        $query->in_the_loop  = true;
        $wp_query = $query;
        $wp_the_query = $query;
        $output   = Event_Agenda_Render::append_to_content( $output );
    }

    wp_reset_postdata();
    $wp_query = $previous_query;
    $wp_the_query = $previous_the_query;

    return $output;
}

function phase4RunSurfaceChecks(): void {
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
    phase4SurfaceAssert( ! empty( $admin_ids ), 'Administrator user exists' );
    $admin_id = (int) $admin_ids[0];

    $suffix        = gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
    $created_posts = array();

    try {
        $speaker_id = wp_insert_post(
            array(
                'post_title'  => 'ORAS Phase4 Surface Speaker ' . $suffix,
                'post_status' => 'publish',
                'post_type'   => 'oras_speaker',
            )
        );
        phase4SurfaceAssert( is_int( $speaker_id ) && $speaker_id > 0, 'Surface speaker fixture created' );
        $created_posts[] = $speaker_id;

        $event_id = wp_insert_post(
            array(
                'post_title'   => 'ORAS Phase4 Surface Event ' . $suffix,
                'post_content' => 'Event body',
                'post_status'  => 'publish',
                'post_type'    => 'tribe_events',
            )
        );
        phase4SurfaceAssert( is_int( $event_id ) && $event_id > 0, 'Surface event fixture created' );
        $created_posts[] = $event_id;

        $event_post = get_post( $event_id );
        phase4SurfaceAssert( $event_post instanceof WP_Post, 'Surface event post is available' );

        $metabox = new Event_Speakers_Metabox();

        wp_set_current_user( $admin_id );
        $_POST = array(
            'oras_speakers_metabox_nonce' => wp_create_nonce( 'oras_speakers_metabox' ),
            'oras_speakers_assignments'   => array(
                array(
                    'speaker_id'        => (string) $speaker_id,
                    'role'              => '<b>Lead</b>',
                    'is_primary'        => '1',
                    'compensation_type' => 'invalid_type',
                    'fee_amount'        => '-5.75',
                    'pmpro_level_id'    => '-10',
                    'fulfilled'         => '1',
                    'fulfilled_date'    => '2026-03-02',
                    'internal_notes'    => "Notes <script>alert('x')</script>",
                ),
            ),
        );

        $metabox->save_post( $event_id, $event_post );

        $assignments = get_post_meta( $event_id, '_oras_speakers_v1', true );
        phase4SurfaceAssert( is_array( $assignments ) && isset( $assignments[0] ) && is_array( $assignments[0] ), 'Speaker assignments are persisted' );

        $saved = $assignments[0];
        phase4SurfaceAssert( (string) ( $saved['compensation_type'] ?? '' ) === 'none', 'Invalid compensation type is normalized to none' );
        phase4SurfaceAssert( (float) ( $saved['fee_amount'] ?? -1 ) === 0.0, 'Fee is reset for normalized none compensation' );
        phase4SurfaceAssert( (int) ( $saved['pmpro_level_id'] ?? -1 ) === 0, 'PMPro level is reset for normalized none compensation' );
        phase4SurfaceAssert( strpos( (string) ( $saved['role'] ?? '' ), '<' ) === false, 'Speaker role is sanitized' );
        phase4SurfaceAssert( strpos( (string) ( $saved['internal_notes'] ?? '' ), '<script' ) === false, 'Speaker internal notes are sanitized' );

        update_post_meta( $speaker_id, '_oras_speaker_affiliation', 'ORAS' );
        update_post_meta( $speaker_id, '_oras_speaker_website_url', 'https://example.org/speaker' );

        $build_payload_method = new ReflectionMethod( Event_Agenda_Render::class, 'build_speaker_payload' );
        $build_payload_method->setAccessible( true );
        $payload = $build_payload_method->invoke( null, array( $speaker_id ) );
        phase4SurfaceAssert( is_array( $payload ) && count( $payload ) === 1, 'Frontend speaker payload is generated for published speaker' );
        phase4SurfaceAssert( (string) ( $payload[0]['name'] ?? '' ) !== '', 'Frontend speaker payload includes speaker name' );
        phase4SurfaceAssert( (string) ( $payload[0]['affiliation'] ?? '' ) === 'ORAS', 'Frontend speaker payload includes affiliation' );

        $render_payload_method = new ReflectionMethod( Event_Agenda_Render::class, 'render_speaker_payload_script' );
        $render_payload_method->setAccessible( true );
        $payload_script = $render_payload_method->invoke( null, $payload );
        phase4SurfaceAssert( is_string( $payload_script ) && strpos( $payload_script, 'id="oras-speaker-data"' ) !== false, 'Frontend speaker payload JSON script markup is generated' );

        $render_modal_method = new ReflectionMethod( Event_Agenda_Render::class, 'render_speaker_modal_markup' );
        $render_modal_method->setAccessible( true );
        $modal_markup = $render_modal_method->invoke( null );
        phase4SurfaceAssert( is_string( $modal_markup ) && strpos( $modal_markup, 'oras-modal__dialog' ) !== false, 'Frontend speaker modal markup is generated' );

		update_post_meta(
			$event_id,
			'_oras_agenda_v1',
			array(
				'version'  => 1,
				'settings' => array(
					'enabled'            => true,
					'title'              => 'Conference Program',
					'show_timezone_note' => true,
					'show_end_times'     => true,
					'show_descriptions'  => true,
				),
				'days'     => array(
					array(
						'day_label' => 'Friday',
						'date'      => '2026-07-17',
						'slots'     => array(
							array(
								'start'      => '10:00',
								'end'        => '18:00',
								'title'      => 'Registration',
								'desc'       => 'Registration remains open throughout the day.',
								'type'       => 'other',
								'location'   => 'Welcome Tent',
								'visibility' => 'public',
							),
							array(
								'start'      => '10:00',
								'end'        => '14:00',
								'title'      => 'Astronomy Flea Market',
								'desc'       => 'Browse astronomy equipment and accessories.',
								'type'       => 'social',
								'location'   => 'Vendor Field',
								'visibility' => 'public',
							),
							array(
								'start'      => '11:00',
								'end'        => '12:00',
								'title'      => 'Observatory Tour',
								'desc'       => 'Tour the ORAS observatory and main telescope.',
								'type'       => 'observation',
								'location'   => 'Observatory',
								'visibility' => 'public',
								'speakers'   => array(
									array(
										'speaker_id' => $speaker_id,
										'role'       => 'Guide',
									),
								),
								'resources'  => array(
									array(
										'attachment_id' => 0,
										'url'           => 'https://example.org/observatory-guide',
										'label'         => 'Observatory Guide',
										'type'          => 'handout',
										'visibility'    => 'public',
										'speaker_ids'   => array( $speaker_id ),
									),
								),
							),
							array(
								'start'      => '11:00',
								'end'        => '12:00',
								'title'      => 'Beginning Astrophotography',
								'desc'       => 'Learn the basics of capturing the night sky.',
								'type'       => 'workshop',
								'location'   => 'Education Center',
								'visibility' => 'public',
							),
							array(
								'start'      => '12:30',
								'end'        => '13:30',
								'title'      => 'Solar System Science',
								'desc'       => 'A guided presentation about our solar system.',
								'type'       => 'talk',
								'location'   => 'Main Hall',
								'visibility' => 'public',
							),
							array(
								'start'      => 'not-a-time',
								'end'        => '',
								'title'      => 'Weather-dependent Observing',
								'desc'       => 'Timing will be announced when conditions are known.',
								'type'       => 'observation',
								'location'   => 'Observing Field',
								'visibility' => 'public',
							),
						),
					),
					array(
						'day_label' => 'Saturday',
						'date'      => '2026-07-18',
						'slots'     => array(
							array(
								'start'      => '09:00',
								'end'        => '10:00',
								'title'      => 'Saturday Welcome',
								'desc'       => 'Preview the second day of conference programming.',
								'type'       => 'talk',
								'location'   => 'Main Hall',
								'visibility' => 'public',
							),
						),
					),
				),
			)
		);

		$agenda_fixture = get_post_meta( $event_id, '_oras_agenda_v1', true );
		phase4SurfaceAssert( is_array( $agenda_fixture ), 'Conference agenda fixture is available for program partition checks' );
		$partition_input = $agenda_fixture['days'][0]['slots'] ?? array();
		$partition_input[] = array(
			'start'      => '13:30',
			'end'        => '14:30',
			'title'      => 'Hidden Staff Briefing',
			'visibility' => 'hidden',
		);
		$partition_input[] = array(
			'start'      => '',
			'end'        => '',
			'title'      => '',
			'visibility' => 'public',
		);

		$agenda_reflection = new ReflectionClass( Event_Agenda_Render::class );
		$program_helper_names = array( 'normalize_public_slots', 'partition_day_program', 'slot_duration_minutes', 'slots_overlap' );
		foreach ( $program_helper_names as $program_helper_name ) {
			phase4SurfaceAssert( $agenda_reflection->hasMethod( $program_helper_name ), 'Agenda renderer exposes the ' . $program_helper_name . ' helper' );
			phase4SurfaceAssert( $agenda_reflection->getMethod( $program_helper_name )->isPrivate(), 'Agenda helper ' . $program_helper_name . ' remains private' );
		}
		$partition_method = $agenda_reflection->getMethod( 'partition_day_program' );
		$partition_method->setAccessible( true );
		$normalize_method = $agenda_reflection->getMethod( 'normalize_public_slots' );
		$normalize_method->setAccessible( true );
		$duration_method = $agenda_reflection->getMethod( 'slot_duration_minutes' );
		$duration_method->setAccessible( true );
		$overlap_method = $agenda_reflection->getMethod( 'slots_overlap' );
		$overlap_method->setAccessible( true );
		$day_program = $partition_method->invoke( null, $partition_input );
		phase4SurfaceAssert( is_array( $day_program ), 'Agenda program partition helper returns an array' );

		$ongoing_titles = array_column( $day_program['ongoing'] ?? array(), 'title' );
		phase4SurfaceAssert( $ongoing_titles === array( 'Registration', 'Astronomy Flea Market' ), 'Registration and Astronomy Flea Market are the ordered ongoing activities' );
		phase4SurfaceAssert( (int) ( $day_program['ongoing'][0]['source_index'] ?? -1 ) === 0, 'Normalized agenda slots preserve their original source index' );
		phase4SurfaceAssert( (string) ( $day_program['ongoing'][0]['start_24'] ?? '' ) === '10:00', 'Normalized agenda slots include their 24-hour start time' );
		phase4SurfaceAssert( (int) ( $day_program['ongoing'][0]['start_minutes'] ?? -1 ) === 600, 'Normalized agenda slots include start minutes' );
		phase4SurfaceAssert( (int) ( $day_program['ongoing'][0]['end_minutes'] ?? -1 ) === 1080, 'Normalized agenda slots include end minutes' );

		$eleven_group = $day_program['time_groups']['11:00'] ?? array();
		phase4SurfaceAssert( array_column( $eleven_group, 'title' ) === array( 'Observatory Tour', 'Beginning Astrophotography' ), 'Both 11:00 sessions share one stable time group' );
		phase4SurfaceAssert( isset( $day_program['time_groups']['12:30'] ), 'The 12:30 session occupies a separate time group' );
		phase4SurfaceAssert( array_column( $day_program['time_groups']['12:30'], 'title' ) === array( 'Solar System Science' ), 'The 12:30 time group contains Solar System Science' );

		$unscheduled_titles = array_column( $day_program['unscheduled'] ?? array(), 'title' );
		phase4SurfaceAssert( $unscheduled_titles === array( 'Weather-dependent Observing' ), 'Weather-dependent Observing survives in the unscheduled program' );
		$partitioned_titles = array_merge(
			$ongoing_titles,
			array_column( $eleven_group, 'title' ),
			array_column( $day_program['time_groups']['12:30'] ?? array(), 'title' ),
			$unscheduled_titles
		);
		phase4SurfaceAssert( ! in_array( 'Hidden Staff Briefing', $partitioned_titles, true ), 'Hidden agenda slots are excluded from the public program' );

		$normalized_fixture = $normalize_method->invoke( null, $partition_input );
		$observatory_rows   = array_values(
			array_filter(
				$normalized_fixture,
				static function ( array $slot ): bool {
					return (string) ( $slot['title'] ?? '' ) === 'Observatory Tour';
				}
			)
		);
		phase4SurfaceAssert( count( $observatory_rows ) === 1, 'Observatory Tour has one normalized row' );
		$observatory_source = $partition_input[2];
		$observatory_row    = $observatory_rows[0];
		foreach ( array( 'desc', 'type', 'location', 'speakers', 'resources', 'visibility' ) as $preserved_field ) {
			phase4SurfaceAssert( $observatory_row[ $preserved_field ] === $observatory_source[ $preserved_field ], 'Normalized Observatory Tour preserves ' . $preserved_field );
		}

		$threshold_program = $partition_method->invoke(
			null,
			array(
				array(
					'start'      => '10:00',
					'end'        => '11:59',
					'title'      => '119 Minute Session',
					'visibility' => 'public',
				),
				array(
					'start'      => '11:00',
					'end'        => '11:30',
					'title'      => 'Overlapping Session',
					'visibility' => 'public',
				),
			)
		);
		phase4SurfaceAssert( ! in_array( '119 Minute Session', array_column( $threshold_program['ongoing'], 'title' ), true ), 'A 119-minute overlapping slot is not ongoing' );
		phase4SurfaceAssert( isset( $threshold_program['time_groups']['10:00'] ), 'A 119-minute overlapping slot remains in its time group' );

		$threshold_program = $partition_method->invoke(
			null,
			array(
				array(
					'start'      => '10:00',
					'end'        => '12:00',
					'title'      => '120 Minute Session',
					'visibility' => 'public',
				),
				array(
					'start'      => '11:00',
					'end'        => '11:30',
					'title'      => 'Overlapping Session',
					'visibility' => 'public',
				),
			)
		);
		phase4SurfaceAssert( in_array( '120 Minute Session', array_column( $threshold_program['ongoing'], 'title' ), true ), 'A 120-minute overlapping slot is ongoing' );

		$touching_first = array(
			'start_minutes' => 600,
			'end_minutes'   => 660,
		);
		$touching_second = array(
			'start_minutes' => 660,
			'end_minutes'   => 720,
		);
		phase4SurfaceAssert( ! $overlap_method->invoke( null, $touching_first, $touching_second ), 'Sessions whose boundaries only touch do not overlap' );

		$overnight_rows = $normalize_method->invoke(
			null,
			array(
				array(
					'start'      => '23:00',
					'end'        => '01:00',
					'title'      => 'Overnight Observing',
					'visibility' => 'public',
				),
				array(
					'start'      => '23:30',
					'end'        => '00:30',
					'title'      => 'Late Night Workshop',
					'visibility' => 'public',
				),
			)
		);
		phase4SurfaceAssert( (int) $overnight_rows[0]['end_minutes'] === 1500, 'A 23:00-01:00 slot normalizes its end into the next day' );
		phase4SurfaceAssert( (int) $duration_method->invoke( null, $overnight_rows[0] ) === 120, 'A 23:00-01:00 slot has a 120-minute duration' );
		phase4SurfaceAssert( (int) $overnight_rows[1]['end_minutes'] === 1470, 'A 23:30-00:30 slot normalizes its end into the next day' );
		phase4SurfaceAssert( $overlap_method->invoke( null, $overnight_rows[0], $overnight_rows[1] ), 'Overnight slots overlap across midnight' );

		$invalid_end_rows = $normalize_method->invoke(
			null,
			array(
				array(
					'start'      => '21:00',
					'end'        => 'invalid',
					'title'      => 'Open-ended Observing',
					'visibility' => 'public',
				),
			)
		);
		phase4SurfaceAssert( (int) $duration_method->invoke( null, $invalid_end_rows[0] ) === 0, 'A slot with an invalid end has zero duration' );

		$equal_time_rows = $normalize_method->invoke(
			null,
			array(
				array(
					'start'      => '09:00',
					'end'        => '09:00',
					'title'      => 'Zero Duration Marker',
					'visibility' => 'public',
				),
			)
		);
		phase4SurfaceAssert( (int) $duration_method->invoke( null, $equal_time_rows[0] ) === 0, 'Equal start and end times remain zero duration' );

		$agenda_html = phase4RenderAgendaForEvent( $event_id, $admin_id );
		phase4SurfaceAssert( strpos( $agenda_html, 'Conference Program' ) !== false, 'Conference agenda fixture renders through the frontend renderer' );
		phase4SurfaceAssert( strpos( $agenda_html, 'Observatory Tour' ) !== false, 'Conference agenda fixture renders its session content' );
		phase4SurfaceAssert( class_exists( DOMDocument::class ), 'DOM extension is available for agenda markup assertions' );

		$agenda_document = new DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$agenda_loaded   = $agenda_document->loadHTML( '<?xml encoding="utf-8" ?>' . $agenda_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		phase4SurfaceAssert( $agenda_loaded, 'Rendered conference agenda is parseable HTML' );

		$agenda_xpath = new DOMXPath( $agenda_document );
		$eleven_nodes = $agenda_xpath->query( '//*[@data-start-group="11:00"]' );
		phase4SurfaceAssert( $eleven_nodes instanceof DOMNodeList && $eleven_nodes->length === 1, 'Concurrent sessions share exactly one 11:00 time band' );
		$eleven_band = $eleven_nodes->item( 0 );
		$eleven_text = (string) $eleven_band->textContent;
		phase4SurfaceAssert( strpos( $eleven_text, 'Observatory Tour' ) !== false, 'The 11:00 time band contains Observatory Tour' );
		phase4SurfaceAssert( strpos( $eleven_text, 'Beginning Astrophotography' ) !== false, 'The 11:00 time band contains Beginning Astrophotography' );
		$concurrent_grids = $agenda_xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__session-grid--concurrent ")]', $eleven_band );
		phase4SurfaceAssert( $concurrent_grids instanceof DOMNodeList && $concurrent_grids->length === 1, 'The concurrent session grid is contained by the 11:00 time band' );

		$session_cards = $agenda_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__session-card ")]' );
		phase4SurfaceAssert( $session_cards instanceof DOMNodeList && $session_cards->length > 0, 'Conference sessions render as session cards' );
		$cards_missing_filter_data = $agenda_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__session-card ") and (not(@data-agenda-type) or not(@data-agenda-location))]' );
		phase4SurfaceAssert( $cards_missing_filter_data instanceof DOMNodeList && $cards_missing_filter_data->length === 0, 'Every session card exposes type and location filter data' );

		$observatory_cards = $agenda_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__session-card ") and .//*[normalize-space(.)="Observatory Tour"]]' );
		phase4SurfaceAssert( $observatory_cards instanceof DOMNodeList && $observatory_cards->length === 1, 'Observatory Tour renders in one session card' );
		$observatory_resources = $agenda_xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__resource-action ") and normalize-space(.)="Observatory Guide"]', $observatory_cards->item( 0 ) );
		phase4SurfaceAssert( $observatory_resources instanceof DOMNodeList && $observatory_resources->length === 1, 'Observatory Tour includes its labeled public resource action' );

		$ongoing_nodes = $agenda_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__ongoing ")]' );
		phase4SurfaceAssert( $ongoing_nodes instanceof DOMNodeList && $ongoing_nodes->length > 0, 'Long overlapping activities render in the ongoing region' );
		$ongoing_text = (string) $ongoing_nodes->item( 0 )->textContent;
		phase4SurfaceAssert( strpos( $ongoing_text, 'Registration' ) !== false, 'The ongoing region contains Registration' );
		phase4SurfaceAssert( strpos( $ongoing_text, 'Astronomy Flea Market' ) !== false, 'The ongoing region contains Astronomy Flea Market' );

		$unscheduled_nodes = $agenda_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__unscheduled ")]' );
		phase4SurfaceAssert( $unscheduled_nodes instanceof DOMNodeList && $unscheduled_nodes->length > 0, 'Untimed sessions render in the unscheduled region' );
		$unscheduled_text = (string) $unscheduled_nodes->item( 0 )->textContent;
		phase4SurfaceAssert( strpos( $unscheduled_text, 'Weather-dependent Observing' ) !== false, 'The unscheduled region contains Weather-dependent Observing' );

        echo "PASS: Phase 4 frontend/admin surface checks completed\n";
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
    phase4RunSurfaceChecks();
} catch ( OrasPhase4SurfaceCheckException $e ) {
    fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" );
    exit( 1 );
} catch ( Throwable $e ) {
    fwrite( STDERR, 'FAIL: Unexpected exception: ' . $e->getMessage() . "\n" );
    exit( 1 );
}
