<?php
/**
 * Phase 4 frontend/admin surface checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase4-surface-checks.php
 */

use ORAS\Tickets\Admin\Event_Speakers_Metabox;
use ORAS\Tickets\Admin\Metaboxes\Event_Agenda_Metabox;
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

    $event_post = get_post( $event_id );
    $query      = new WP_Query();

    if ( $event_post instanceof WP_Post && $event_post->post_type === 'tribe_events' ) {
        $query->posts             = array( $event_post );
        $query->post_count        = 1;
        $query->found_posts       = 1;
        $query->max_num_pages     = 1;
        $query->queried_object    = $event_post;
        $query->queried_object_id = $event_id;
        $query->is_singular       = true;
        $query->is_single         = true;
    }

    $output = '<p>base content</p>';
    if ( $query->have_posts() ) {
        $query->the_post();
        $query->in_the_loop = true;
        $wp_query           = $query;
        $wp_the_query       = $query;
        $output             = Event_Agenda_Render::append_to_content( $output );
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

		$draft_speaker_id = wp_insert_post(
			array(
				'post_title'  => 'ORAS Draft Agenda Speaker ' . $suffix,
				'post_status' => 'draft',
				'post_type'   => 'oras_speaker',
			)
		);
		phase4SurfaceAssert( is_int( $draft_speaker_id ) && $draft_speaker_id > 0, 'Draft speaker security fixture created' );
		$created_posts[] = $draft_speaker_id;

		$wrong_type_speaker_id = wp_insert_post(
			array(
				'post_title'  => 'ORAS Wrong Type Agenda Speaker ' . $suffix,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		phase4SurfaceAssert( is_int( $wrong_type_speaker_id ) && $wrong_type_speaker_id > 0, 'Wrong-post-type speaker security fixture created' );
		$created_posts[] = $wrong_type_speaker_id;

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

        $render_drawer_method = new ReflectionMethod( Event_Agenda_Render::class, 'render_speaker_drawer_markup' );
        $render_drawer_method->setAccessible( true );
        $drawer_markup = $render_drawer_method->invoke( null );
        phase4SurfaceAssert( is_string( $drawer_markup ) && strpos( $drawer_markup, 'id="oras-speaker-drawer"' ) !== false, 'Frontend speaker drawer markup is generated' );
        phase4SurfaceAssert( strpos( $drawer_markup, 'role="dialog"' ) !== false && strpos( $drawer_markup, 'aria-modal="true"' ) !== false, 'Speaker drawer exposes accessible dialog semantics' );
        phase4SurfaceAssert( strpos( $drawer_markup, 'Close speaker profile' ) !== false, 'Speaker drawer has a clearly labeled close control' );

        $render_payload_method = new ReflectionMethod( Event_Agenda_Render::class, 'render_speaker_payload_script' );
        $render_payload_method->setAccessible( true );
        $payload_script = $render_payload_method->invoke(
            null,
            array(
                array(
                    'id'   => 1,
                    'name' => '</script><script>alert(1)</script>',
                ),
            )
        );
        phase4SurfaceAssert( is_string( $payload_script ) && strpos( $payload_script, '</script><script>' ) === false, 'Speaker payload cannot terminate its JSON script element' );

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
									array(
										'speaker_id' => $draft_speaker_id,
										'role'       => 'Draft presenter',
									),
									array(
										'speaker_id' => $wrong_type_speaker_id,
										'role'       => 'Invalid presenter',
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
									array(
										'attachment_id' => 0,
										'url'           => 'https://example.org/internal-speaker-notes',
										'label'         => 'Internal Speaker Notes',
										'type'          => 'notes',
										'visibility'    => 'internal',
										'speaker_ids'   => array( $speaker_id ),
									),
									array(
										'attachment_id' => 0,
										'url'           => 'javascript:alert(1)',
										'label'         => 'Unsafe Agenda Resource',
										'type'          => 'link',
										'visibility'    => 'public',
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
					'date'      => '2026-07-17',
					'slots'     => array(
						array(
							'start'      => '09:00',
							'end'        => '13:00',
							'title'      => 'Registration',
							'desc'       => 'A second-day registration window with a repeated title and date.',
							'type'       => 'other',
							'location'   => 'Welcome Tent',
							'visibility' => 'public',
						),
						array(
							'start'      => '09:00',
							'end'        => '12:00',
							'title'      => 'Astronomy Flea Market',
							'desc'       => 'A repeated activity that would collide without a day namespace.',
							'type'       => 'social',
							'location'   => 'Vendor Field',
							'visibility' => 'public',
						),
						array(
							'start'      => '10:00',
							'end'        => '11:00',
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

		ob_start();
		Event_Agenda_Metabox::render( $event_post );
		$agenda_editor_html = (string) ob_get_clean();
		$editor_document    = new DOMDocument();
		$previous_errors    = libxml_use_internal_errors( true );
		$editor_loaded      = $editor_document->loadHTML( '<?xml encoding="utf-8" ?>' . $agenda_editor_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		phase4SurfaceAssert( $editor_loaded, 'Agenda editor fixture is parseable HTML' );

		$editor_xpath = new DOMXPath( $editor_document );
			$slot_type_options = $editor_xpath->query( '//select[@name="oras_agenda[days][0][slots][2][type]"]/option[@selected and @value="observation"]' );
			phase4SurfaceAssert( $slot_type_options instanceof DOMNodeList && $slot_type_options->length === 1, 'Agenda resources do not overwrite their session type in the editor' );

			$_POST = array(
				'oras_agenda_metabox_nonce' => wp_create_nonce( 'oras_agenda_metabox' ),
				'oras_agenda'               => array(
					'settings' => array(
						'enabled' => '1',
						'title'   => 'Save-cycle Agenda',
					),
					'days'     => array(
						array(
							'day_label' => 'Save-cycle Day',
							'date'      => '2026-07-18',
							'slots'     => array(
								array(
									'schedule_mode' => 'ongoing',
									'start'         => '09:00',
									'end'           => '17:00',
									'title'         => 'Save-cycle Session',
									'type'          => 'talk',
									'location'      => 'Main Hall',
									'visibility'    => 'public',
									'resources'     => array(
										array(
											'url'        => 'https://example.org/save-cycle-slides',
											'label'      => 'Slides',
											'type'       => 'slides',
											'visibility' => 'internal',
										),
									),
								),
							),
						),
					),
				),
			);
			Event_Agenda_Metabox::save( $event_id );
			$saved_agenda = get_post_meta( $event_id, '_oras_agenda_v1', true );
			$saved_slot   = $saved_agenda['days'][0]['slots'][0] ?? array();
			phase4SurfaceAssert( ( $saved_slot['type'] ?? '' ) === 'talk', 'Agenda save keeps the parent session type when resources are present' );
			phase4SurfaceAssert( ( $saved_slot['visibility'] ?? '' ) === 'public', 'Agenda save keeps the parent session visibility when internal resources are present' );
			phase4SurfaceAssert( ( $saved_slot['schedule_mode'] ?? '' ) === 'ongoing', 'Agenda save persists an explicit schedule mode' );
			phase4SurfaceAssert( ( $saved_slot['resources'][0]['type'] ?? '' ) === 'slides', 'Agenda save independently persists the resource type' );
			update_post_meta( $event_id, '_oras_agenda_v1', $agenda_fixture );
			$_POST = array();

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

		$explicit_mode_program = $partition_method->invoke(
			null,
			array(
				array(
					'start'         => '08:00',
					'end'           => '08:30',
					'title'         => 'Explicit Welcome Desk',
					'schedule_mode' => 'ongoing',
					'visibility'    => 'public',
				),
				array(
					'start'         => '09:00',
					'end'           => '13:00',
					'title'         => 'Explicit Scheduled Workshop',
					'schedule_mode' => 'scheduled',
					'visibility'    => 'public',
				),
				array(
					'start'         => '10:00',
					'end'           => '11:00',
					'title'         => 'Overlapping Talk',
					'schedule_mode' => 'scheduled',
					'visibility'    => 'public',
				),
				array(
					'start'         => '12:00',
					'end'           => '13:00',
					'title'         => 'Weather Call',
					'schedule_mode' => 'tbd',
					'visibility'    => 'public',
				),
			)
		);
		phase4SurfaceAssert( (string) ( $explicit_mode_program['ongoing'][0]['title'] ?? '' ) === 'Explicit Welcome Desk', 'Explicit ongoing mode overrides short duration' );
		phase4SurfaceAssert( isset( $explicit_mode_program['time_groups']['09:00'][0] ) && (string) $explicit_mode_program['time_groups']['09:00'][0]['title'] === 'Explicit Scheduled Workshop', 'Explicit scheduled mode prevents long overlapping sessions from becoming ongoing' );
		phase4SurfaceAssert( (string) ( $explicit_mode_program['unscheduled'][0]['title'] ?? '' ) === 'Weather Call', 'Explicit time-TBD mode renders in the unscheduled region' );

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
		$id_nodes     = $agenda_xpath->query( '//*[@id != ""]' );
		$id_counts    = array();
		foreach ( $id_nodes as $id_node ) {
			if ( ! ( $id_node instanceof DOMElement ) ) {
				continue;
			}

			$node_id = $id_node->getAttribute( 'id' );
			$id_counts[ $node_id ] = ( $id_counts[ $node_id ] ?? 0 ) + 1;
		}
		$duplicate_ids = array_filter(
			$id_counts,
			static function ( int $count ): bool {
				return $count > 1;
			}
		);
		phase4SurfaceAssert( empty( $duplicate_ids ), 'Every non-empty agenda ID is unique across repeated day data' );

		$labelled_regions = $agenda_xpath->query( '//*[@aria-labelledby != ""]' );
		foreach ( $labelled_regions as $labelled_region ) {
			if ( ! ( $labelled_region instanceof DOMElement ) ) {
				continue;
			}

			$label_id       = $labelled_region->getAttribute( 'aria-labelledby' );
			$label_matches  = $agenda_xpath->query( '//*[@id="' . $label_id . '"]' );
			$region_classes = $labelled_region->getAttribute( 'class' );
			phase4SurfaceAssert( $label_matches instanceof DOMNodeList && $label_matches->length === 1, 'Agenda region aria-labelledby resolves exactly once for ' . $region_classes );
		}

		$panel_headers = $agenda_xpath->query( '//section[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__panel ")]/header[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__day-header ")]' );
		phase4SurfaceAssert( $panel_headers instanceof DOMNodeList && $panel_headers->length === 2, 'Each rendered agenda day has a semantic panel header' );
		$program_lists = $agenda_xpath->query( '//ol[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__program ")]' );
		phase4SurfaceAssert( $program_lists instanceof DOMNodeList && $program_lists->length === 2, 'Each rendered agenda day has an ordered conference program' );
		$time_bands = $agenda_xpath->query( '//ol[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__program ")]/li[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__time-band ")]' );
		phase4SurfaceAssert( $time_bands instanceof DOMNodeList && $time_bands->length === 3, 'Timed sessions render inside semantic time bands' );
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
		$observatory_card = $observatory_cards->item( 0 );
		phase4SurfaceAssert( $observatory_card instanceof DOMElement && $observatory_card->tagName === 'article', 'Observatory Tour uses semantic article markup' );
		phase4SurfaceAssert( $observatory_card instanceof DOMElement && strpos( ' ' . $observatory_card->getAttribute( 'class' ) . ' ', ' oras-agenda__item ' ) !== false, 'Timed cards retain the agenda-now compatibility class' );
		phase4SurfaceAssert( $observatory_card instanceof DOMElement && $observatory_card->getAttribute( 'data-agenda-date' ) === '2026-07-17', 'Timed cards retain their agenda date' );
		phase4SurfaceAssert( $observatory_card instanceof DOMElement && $observatory_card->getAttribute( 'data-start' ) === '11:00', 'Timed cards retain their normalized start time' );
		phase4SurfaceAssert( $observatory_card instanceof DOMElement && $observatory_card->getAttribute( 'data-end' ) === '12:00', 'Timed cards retain their normalized end time' );
		$observatory_hierarchy = array();
		if ( $observatory_card instanceof DOMElement ) {
			foreach ( $observatory_card->childNodes as $child_node ) {
				if ( ! ( $child_node instanceof DOMElement ) ) {
					continue;
				}

				$observatory_hierarchy[] = $child_node->getAttribute( 'class' );
			}
		}
		phase4SurfaceAssert(
			$observatory_hierarchy === array(
				'oras-agenda__session-eyebrow',
				'oras-agenda__session-title',
				'oras-agenda__session-time',
				'oras-agenda__session-description',
				'oras-agenda__speakers',
				'oras-agenda__resources',
			),
			'Session cards render the approved information hierarchy'
		);
		$observatory_speaker = $agenda_xpath->query( './/button[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__speaker-link ") and @data-speaker-id="' . (int) $speaker_id . '"]', $observatory_card );
		phase4SurfaceAssert( $observatory_speaker instanceof DOMNodeList && $observatory_speaker->length === 1, 'Session speaker buttons preserve their validated payload ID' );
		$observatory_role = $agenda_xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__speaker-role ") and normalize-space(.)="(Guide)"]', $observatory_card );
		phase4SurfaceAssert( $observatory_role instanceof DOMNodeList && $observatory_role->length === 1, 'Session speaker role labels remain visible' );
		$observatory_resources = $agenda_xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__resource-action ") and normalize-space(.)="Observatory Guide"]', $observatory_cards->item( 0 ) );
		phase4SurfaceAssert( $observatory_resources instanceof DOMNodeList && $observatory_resources->length === 1, 'Observatory Tour includes its labeled public resource action' );
		$observatory_resource = $observatory_resources->item( 0 );
		phase4SurfaceAssert( $observatory_resource instanceof DOMElement && $observatory_resource->getAttribute( 'href' ) === 'https://example.org/observatory-guide', 'Public resource actions retain their safe URL' );
		phase4SurfaceAssert( $observatory_resource instanceof DOMElement && $observatory_resource->getAttribute( 'target' ) === '_blank', 'Public resource actions open in a new tab' );
		phase4SurfaceAssert( $observatory_resource instanceof DOMElement && $observatory_resource->getAttribute( 'rel' ) === 'noopener', 'Public resource actions prevent opener access' );
		$resource_types = $agenda_xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__resource-type ") and normalize-space(.)="Handout"]', $observatory_card );
		phase4SurfaceAssert( $resource_types instanceof DOMNodeList && $resource_types->length === 1, 'Resource actions retain their configured type label' );
		$internal_resources = $agenda_xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__resource-action ") and normalize-space(.)="Internal Speaker Notes"]', $observatory_card );
		phase4SurfaceAssert( $internal_resources instanceof DOMNodeList && $internal_resources->length === 1, 'Logged-in users can access internal agenda resources' );
		phase4SurfaceAssert( strpos( $agenda_html, 'Unsafe Agenda Resource' ) === false, 'Unsafe-scheme agenda resource labels are not rendered' );
		phase4SurfaceAssert( strpos( $agenda_html, 'javascript:alert(1)' ) === false, 'Unsafe-scheme agenda resource URLs are not rendered' );
		$draft_speaker_buttons = $agenda_xpath->query( '//button[@data-speaker-id="' . (int) $draft_speaker_id . '"]' );
		phase4SurfaceAssert( $draft_speaker_buttons instanceof DOMNodeList && $draft_speaker_buttons->length === 0, 'Draft speakers are excluded from agenda speaker buttons' );
		$wrong_type_speaker_buttons = $agenda_xpath->query( '//button[@data-speaker-id="' . (int) $wrong_type_speaker_id . '"]' );
		phase4SurfaceAssert( $wrong_type_speaker_buttons instanceof DOMNodeList && $wrong_type_speaker_buttons->length === 0, 'Wrong-post-type IDs are excluded from agenda speaker buttons' );
		phase4SurfaceAssert( strpos( $agenda_html, 'ORAS Draft Agenda Speaker ' . $suffix ) === false, 'Draft speakers are excluded from the speaker payload' );
		phase4SurfaceAssert( strpos( $agenda_html, 'ORAS Wrong Type Agenda Speaker ' . $suffix ) === false, 'Wrong-post-type IDs are excluded from the speaker payload' );

		$ongoing_nodes = $agenda_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__ongoing ")]' );
		phase4SurfaceAssert( $ongoing_nodes instanceof DOMNodeList && $ongoing_nodes->length > 0, 'Long overlapping activities render in the ongoing region' );
		$ongoing_text = (string) $ongoing_nodes->item( 0 )->textContent;
		phase4SurfaceAssert( strpos( $ongoing_text, 'Registration' ) !== false, 'The ongoing region contains Registration' );
		phase4SurfaceAssert( strpos( $ongoing_text, 'Astronomy Flea Market' ) !== false, 'The ongoing region contains Astronomy Flea Market' );

		$unscheduled_nodes = $agenda_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " oras-agenda__unscheduled ")]' );
		phase4SurfaceAssert( $unscheduled_nodes instanceof DOMNodeList && $unscheduled_nodes->length > 0, 'Untimed sessions render in the unscheduled region' );
		$unscheduled_text = (string) $unscheduled_nodes->item( 0 )->textContent;
		phase4SurfaceAssert( strpos( $unscheduled_text, 'Weather-dependent Observing' ) !== false, 'The unscheduled region contains Weather-dependent Observing' );

		$type_filters = $agenda_xpath->query( '//*[@data-agenda-filter="type"]' );
		phase4SurfaceAssert( $type_filters instanceof DOMNodeList && $type_filters->length === 1, 'Agenda renders one useful session-type filter' );
		$type_options = $agenda_xpath->query( './option', $type_filters->item( 0 ) );
		$type_labels  = array();
		foreach ( $type_options as $type_option ) {
			$type_labels[] = trim( (string) $type_option->textContent );
		}
		phase4SurfaceAssert( $type_labels === array( 'All session types', 'Other', 'Social', 'Observation', 'Workshop', 'Talk' ), 'Session-type filter preserves exact public option labels' );

		$location_filters = $agenda_xpath->query( '//*[@data-agenda-filter="location"]' );
		phase4SurfaceAssert( $location_filters instanceof DOMNodeList && $location_filters->length === 1, 'Agenda renders one useful location filter' );
		$location_options = $agenda_xpath->query( './option', $location_filters->item( 0 ) );
		$location_labels  = array();
		foreach ( $location_options as $location_option ) {
			$location_labels[] = trim( (string) $location_option->textContent );
		}
		phase4SurfaceAssert(
			$location_labels === array( 'All locations', 'Welcome Tent', 'Vendor Field', 'Observatory', 'Education Center', 'Main Hall', 'Observing Field' ),
			'Location filter preserves exact public option labels'
		);
		$filter_resets = $agenda_xpath->query( '//*[@data-agenda-filter-reset]' );
		phase4SurfaceAssert( $filter_resets instanceof DOMNodeList && $filter_resets->length === 1, 'Agenda filters provide a reset control' );
		$filter_statuses = $agenda_xpath->query( '//*[@data-agenda-filter-status and @aria-live="polite"]' );
		phase4SurfaceAssert( $filter_statuses instanceof DOMNodeList && $filter_statuses->length === 1, 'Agenda filters provide an accessible live status' );

		$logged_out_agenda = phase4RenderAgendaForEvent( $event_id, 0 );
		phase4SurfaceAssert( strpos( $logged_out_agenda, 'Observatory Guide' ) !== false, 'Logged-out visitors can access public agenda resources' );
		phase4SurfaceAssert( strpos( $logged_out_agenda, 'Internal Speaker Notes' ) === false, 'Logged-out visitors cannot access internal agenda resources' );
		phase4SurfaceAssert( strpos( $logged_out_agenda, 'https://example.org/internal-speaker-notes' ) === false, 'Logged-out HTML excludes the exact internal agenda resource URL' );

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
