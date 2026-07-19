<?php

namespace ORAS\Tickets\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_Agenda_Render { // NOSONAR legacy WP class naming

    private const META_KEY = '_oras_agenda_v1';

    public static function register(): void {
        add_filter( 'the_content', array( self::class, 'append_to_content' ), 20 );
    }

    public static function append_to_content( string $content ): string {
        if ( is_admin() ) {
            return $content;
        }

        if ( ! is_singular( 'tribe_events' ) ) {
            return $content;
        }

        if ( ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $event_id = get_the_ID();
        if ( ! $event_id || $event_id <= 0 ) {
            return $content;
        }

        $envelope = get_post_meta( $event_id, self::META_KEY, true );
        if ( ! is_array( $envelope ) ) {
            return $content;
        }

        $settings = isset( $envelope['settings'] ) && is_array( $envelope['settings'] ) ? $envelope['settings'] : array();
        $enabled  = isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : false;
        if ( ! $enabled ) {
            return $content;
        }

        $title              = isset( $settings['title'] ) && $settings['title'] !== '' ? (string) $settings['title'] : 'Agenda';
        $show_timezone_note = ! empty( $settings['show_timezone_note'] );
        $show_end_times     = ! array_key_exists( 'show_end_times', $settings ) || ! empty( $settings['show_end_times'] );
        $show_descriptions  = ! array_key_exists( 'show_descriptions', $settings ) || ! empty( $settings['show_descriptions'] );
        $highlight_current  = ! empty( $settings['highlight_current'] );
        $autoscroll_current = ! empty( $settings['autoscroll_current'] );

        $days = isset( $envelope['days'] ) && is_array( $envelope['days'] ) ? $envelope['days'] : array();
        if ( empty( $days ) ) {
            return $content;
        }

        wp_enqueue_style(
            'oras-agenda-now',
            ORAS_TICKETS_URL . 'assets/css/agenda.css',
            array(),
            ORAS_TICKETS_VERSION
        );

        wp_enqueue_script(
            'oras-agenda-ui',
            ORAS_TICKETS_URL . 'assets/js/agenda-ui.js',
            array(),
            ORAS_TICKETS_VERSION,
            true
        );

        wp_enqueue_style(
            'oras-agenda-colors',
            ORAS_TICKETS_URL . 'assets/css/oras-agenda-colors.css',
            array( 'oras-agenda-now' ),
            ORAS_TICKETS_VERSION
        );

        if ( $highlight_current ) {
            wp_enqueue_script(
                'oras-agenda-now',
                ORAS_TICKETS_URL . 'assets/js/agenda-now.js',
                array(),
                ORAS_TICKETS_VERSION,
                true
            );

            $timezone = wp_timezone_string();
            if ( $timezone === '' ) {
                    $timezone = 'UTC';
            }

            wp_localize_script(
                'oras-agenda-now',
                'ORAS_AGENDA_NOW',
                array(
                    'tz'         => $timezone,
                    'autoscroll' => $autoscroll_current,
                    'label'      => __( 'Currently happening', 'oras-tickets' ),
                )
            );
        }

        $html  = '<section class="oras-agenda" aria-label="' . esc_attr( $title ) . '">';
        $html .= '<h2 class="oras-agenda__title">' . esc_html( $title ) . '</h2>';

        if ( $show_timezone_note ) {
            $tz = wp_timezone_string();
            if ( $tz === '' ) {
                $tz = 'UTC';
            }
            /* translators: %s is the site timezone abbreviation or identifier. */
            $html .= '<p class="oras-agenda__timezone">' . esc_html( sprintf( __( 'Times shown in %s', 'oras-tickets' ), $tz ) ) . '</p>';
        }

        $tab_buttons       = array();
        $panels_html       = '';
        $first_panel       = true;
        $event_speaker_ids = array();
        $type_options      = array();
        $location_options  = array();

        foreach ( $days as $day_index => $day ) {
            if ( ! is_array( $day ) ) {
                continue;
            }

            $day_label = isset( $day['day_label'] ) ? (string) $day['day_label'] : '';
            $date      = isset( $day['date'] ) ? (string) $day['date'] : '';
            $slots     = isset( $day['slots'] ) && is_array( $day['slots'] ) ? $day['slots'] : array();
            $program   = self::partition_day_program( $slots );
            $day_namespace = 'day-' . (int) $day_index;

            $rendered_slots = count( $program['ongoing'] ) + count( $program['unscheduled'] );
            foreach ( $program['time_groups'] as $time_group ) {
                $rendered_slots += count( $time_group );
            }

            if ( $rendered_slots === 0 ) {
                continue;
            }

            self::collect_filter_options( $program, $type_options, $location_options );

            /* translators: %d is the day number in the agenda. */
            $day_title = $day_label !== '' ? $day_label : sprintf( __( 'Day %d', 'oras-tickets' ), ( (int) $day_index + 1 ) );
            $panel_id  = 'oras-agenda-day-' . (int) $day_index;
            $tab_id    = 'oras-agenda-tab-' . (int) $day_index;
            $hidden    = $first_panel ? '' : ' hidden';
            $selected  = $first_panel ? 'true' : 'false';

            $tab_buttons[] = '<button id="' . esc_attr( $tab_id ) . '" class="oras-agenda__tab" type="button" role="tab" aria-selected="' . esc_attr( $selected ) . '" aria-controls="' . esc_attr( $panel_id ) . '" data-day-tab="' . esc_attr( (string) $day_index ) . '">' . esc_html( $day_title ) . '</button>';

            $panels_html .= '<section class="oras-agenda__panel" id="' . esc_attr( $panel_id ) . '" role="tabpanel" aria-labelledby="' . esc_attr( $tab_id ) . '" data-day-panel="' . esc_attr( (string) $day_index ) . '"' . $hidden . '>';
            $panels_html .= '<header class="oras-agenda__day-header">';
            $panels_html .= '<div class="oras-agenda__day-label">' . esc_html( $day_title ) . '</div>';
            if ( $date !== '' ) {
                $panels_html .= '<div class="oras-agenda__day-date">' . esc_html( self::format_display_date( $date ) ) . '</div>';
            }
            $panels_html .= '</header>';
            $panels_html .= self::render_ongoing_region( $program['ongoing'], $event_speaker_ids, $show_descriptions, $show_end_times, $date, $highlight_current, $day_namespace );
            $panels_html .= '<ol class="oras-agenda__program">';
            foreach ( $program['time_groups'] as $start => $time_group ) {
                $panels_html .= self::render_time_group( $start, $time_group, $event_speaker_ids, $show_descriptions, $show_end_times, $date, $highlight_current, $day_namespace );
            }
            $panels_html .= '</ol>';
            $panels_html .= self::render_unscheduled_region( $program['unscheduled'], $event_speaker_ids, $show_descriptions, $show_end_times, $date, $highlight_current, $day_namespace );
            $panels_html .= '</section>';

            $first_panel = false;
        }

        if ( $panels_html === '' ) {
            return $content;
        }

        $html .= self::render_filter_controls( $type_options, $location_options );

        if ( ! empty( $tab_buttons ) ) {
            $html .= '<nav class="oras-agenda__nav" role="tablist">' . implode( '', $tab_buttons ) . '</nav>';
        }

        $html .= $panels_html;

        $speaker_payload = self::build_speaker_payload( array_keys( $event_speaker_ids ) );
        if ( ! empty( $speaker_payload ) ) {
            wp_enqueue_script(
                'oras-speaker-modal',
                ORAS_TICKETS_URL . 'assets/js/speaker-modal.js',
                array(),
                ORAS_TICKETS_VERSION,
                true
            );
        }

        $html .= '</section>';

        if ( ! empty( $speaker_payload ) ) {
            $html .= self::render_speaker_payload_script( $speaker_payload );
            $html .= self::render_speaker_drawer_markup();
        }

        return $content . $html;
    }

    private static function collect_filter_options( array $program, array &$type_options, array &$location_options ): void {
        $slots = array_merge( $program['ongoing'], $program['unscheduled'] );
        foreach ( $program['time_groups'] as $time_group ) {
            $slots = array_merge( $slots, $time_group );
        }

        usort(
            $slots,
            static function ( array $first, array $second ): int {
                return (int) $first['source_index'] <=> (int) $second['source_index'];
            }
        );

        foreach ( $slots as $slot ) {
            $type       = isset( $slot['type'] ) ? (string) $slot['type'] : 'other';
            $type_value = self::normalize_filter_value( $type !== '' ? $type : 'other' );
            if ( $type_value === '' ) {
                $type_value = 'other';
            }
            if ( ! isset( $type_options[ $type_value ] ) ) {
                $type_options[ $type_value ] = self::format_type_label( $type );
            }

            $location       = isset( $slot['location'] ) ? sanitize_text_field( (string) $slot['location'] ) : '';
            $location_value = self::normalize_filter_value( $location );
            if ( $location_value !== '' && ! isset( $location_options[ $location_value ] ) ) {
                $location_options[ $location_value ] = $location;
            }
        }
    }

    private static function render_filter_controls( array $type_options, array $location_options ): string {
        $show_type_filter     = count( $type_options ) > 1;
        $show_location_filter = count( $location_options ) > 1;
        if ( ! $show_type_filter && ! $show_location_filter ) {
            return '';
        }

        $html = '<div class="oras-agenda__filters" aria-label="' . esc_attr__( 'Filter agenda sessions', 'oras-tickets' ) . '">';
        if ( $show_type_filter ) {
            $html .= '<label class="oras-agenda__filter">' . esc_html__( 'Session type', 'oras-tickets' );
            $html .= '<select data-agenda-filter="type"><option value="">' . esc_html__( 'All session types', 'oras-tickets' ) . '</option>';
            foreach ( $type_options as $value => $label ) {
                $html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
            }
            $html .= '</select></label>';
        }

        if ( $show_location_filter ) {
            $html .= '<label class="oras-agenda__filter">' . esc_html__( 'Location', 'oras-tickets' );
            $html .= '<select data-agenda-filter="location"><option value="">' . esc_html__( 'All locations', 'oras-tickets' ) . '</option>';
            foreach ( $location_options as $value => $label ) {
                $html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
            }
            $html .= '</select></label>';
        }

        $html .= '<button type="button" class="oras-agenda__filter-reset" data-agenda-filter-reset>' . esc_html__( 'Clear filters', 'oras-tickets' ) . '</button>';
        $html .= '<span class="screen-reader-text" aria-live="polite" data-agenda-filter-status></span>';
        $html .= '</div>';

        return $html;
    }

    private static function render_ongoing_region( array $slots, array &$speaker_ids, bool $show_descriptions, bool $show_end_times, string $date, bool $highlight_current, string $day_namespace ): string {
        if ( empty( $slots ) ) {
            return '';
        }

        $heading_id = 'oras-agenda-ongoing-' . sanitize_key( $day_namespace );
        $html       = '<section class="oras-agenda__ongoing" aria-labelledby="' . esc_attr( $heading_id ) . '">';
        $html      .= '<h3 id="' . esc_attr( $heading_id ) . '" class="oras-agenda__region-title">' . esc_html__( 'Happening throughout the day', 'oras-tickets' ) . '</h3>';
        $html      .= '<div class="oras-agenda__session-grid oras-agenda__session-grid--ongoing">';
        foreach ( $slots as $slot ) {
            $html .= self::render_session_card( $slot, $speaker_ids, $show_descriptions, $show_end_times, $date, $highlight_current, $day_namespace );
        }
        $html .= '</div></section>';

        return $html;
    }

    private static function render_time_group( string $start, array $slots, array &$speaker_ids, bool $show_descriptions, bool $show_end_times, string $date, bool $highlight_current, string $day_namespace ): string {
        if ( empty( $slots ) ) {
            return '';
        }

        $grid_class = 'oras-agenda__session-grid';
        if ( count( $slots ) > 1 ) {
            $grid_class .= ' oras-agenda__session-grid--concurrent';
        }

        $html  = '<li class="oras-agenda__time-band" data-start-group="' . esc_attr( $start ) . '">';
        $html .= '<div class="oras-agenda__band-time">' . esc_html( self::format_display_time( $start ) ) . '</div>';
        $html .= '<div class="' . esc_attr( $grid_class ) . '">';
        foreach ( $slots as $slot ) {
            $html .= self::render_session_card( $slot, $speaker_ids, $show_descriptions, $show_end_times, $date, $highlight_current, $day_namespace );
        }
        $html .= '</div></li>';

        return $html;
    }

    private static function render_unscheduled_region( array $slots, array &$speaker_ids, bool $show_descriptions, bool $show_end_times, string $date, bool $highlight_current, string $day_namespace ): string {
        if ( empty( $slots ) ) {
            return '';
        }

        $heading_id = 'oras-agenda-unscheduled-' . sanitize_key( $day_namespace );
        $html       = '<section class="oras-agenda__unscheduled" aria-labelledby="' . esc_attr( $heading_id ) . '">';
        $html      .= '<h3 id="' . esc_attr( $heading_id ) . '" class="oras-agenda__region-title">' . esc_html__( 'Additional activities', 'oras-tickets' ) . '</h3>';
        $html      .= '<div class="oras-agenda__session-grid oras-agenda__session-grid--unscheduled">';
        foreach ( $slots as $slot ) {
            $html .= self::render_session_card( $slot, $speaker_ids, $show_descriptions, $show_end_times, $date, $highlight_current, $day_namespace );
        }
        $html .= '</div></section>';

        return $html;
    }

    private static function render_session_card( array $slot, array &$speaker_ids, bool $show_descriptions, bool $show_end_times, string $date, bool $highlight_current, string $day_namespace ): string {
        $start_24      = isset( $slot['start_24'] ) ? (string) $slot['start_24'] : '';
        $end_24        = isset( $slot['end_24'] ) ? (string) $slot['end_24'] : '';
        $title         = isset( $slot['title'] ) ? sanitize_text_field( (string) $slot['title'] ) : '';
        $description   = isset( $slot['desc'] ) ? sanitize_textarea_field( (string) $slot['desc'] ) : '';
        $type          = isset( $slot['type'] ) ? (string) $slot['type'] : 'other';
        $location      = isset( $slot['location'] ) ? sanitize_text_field( (string) $slot['location'] ) : '';
        $type_value    = self::normalize_filter_value( $type !== '' ? $type : 'other' );
        $location_value = self::normalize_filter_value( $location );
        $type_label    = self::format_type_label( $type );
        $source_index  = isset( $slot['source_index'] ) ? (int) $slot['source_index'] : 0;
        $slot_id       = 'oras-agenda-slot-' . substr( md5( $day_namespace . '|' . $date . '|' . $source_index . '|' . $title ), 0, 12 );

        if ( $type_value === '' ) {
            $type_value = 'other';
        }

        $data_attrs = ' data-agenda-type="' . esc_attr( $type_value ) . '" data-agenda-location="' . esc_attr( $location_value ) . '"';
        if ( $start_24 !== '' ) {
            $data_attrs .= ' data-agenda-date="' . esc_attr( $date ) . '" data-start="' . esc_attr( $start_24 ) . '" data-end="' . esc_attr( $end_24 ) . '"';
        }
        if ( $highlight_current ) {
            $data_attrs .= ' data-agenda-current-target="true"';
        }

        $time_display = self::render_time_range( $start_24, $end_24, $show_end_times );
        $html         = '<article id="' . esc_attr( $slot_id ) . '" class="oras-agenda__session-card oras-agenda__item"' . $data_attrs . '>';
        $html        .= '<div class="oras-agenda__session-eyebrow">';
        $html        .= '<span class="oras-agenda__pill oras-agenda__pill--' . esc_attr( $type_value ) . '">' . esc_html( $type_label ) . '</span>';
        if ( $location !== '' ) {
            $html .= '<span class="oras-agenda__location">' . esc_html( $location ) . '</span>';
        }
        $html .= '</div>';
        $html .= '<h4 class="oras-agenda__session-title">' . esc_html( $title !== '' ? $title : __( 'Agenda Item', 'oras-tickets' ) ) . '</h4>';
        $html .= '<div class="oras-agenda__session-time">' . esc_html( $time_display ) . '</div>';

        if ( $show_descriptions && $description !== '' ) {
            $html .= '<div class="oras-agenda__session-description">' . nl2br( esc_html( $description ) ) . '</div>';
        }

        $speakers = isset( $slot['speakers'] ) && is_array( $slot['speakers'] ) ? $slot['speakers'] : array();
        $html    .= self::render_speakers( $speakers, $speaker_ids );

        $resources = isset( $slot['resources'] ) && is_array( $slot['resources'] ) ? $slot['resources'] : array();
        $html     .= self::render_resource_actions( $resources );
        $html     .= '</article>';

        return $html;
    }

    private static function render_speakers( array $speakers, array &$speaker_ids ): string {
        $speaker_items = '';
        foreach ( $speakers as $speaker_row ) {
            if ( ! is_array( $speaker_row ) ) {
                continue;
            }

            $speaker_id = isset( $speaker_row['speaker_id'] ) ? absint( $speaker_row['speaker_id'] ) : 0;
            if ( $speaker_id <= 0 ) {
                continue;
            }

            $speaker_post = get_post( $speaker_id );
            if ( ! ( $speaker_post instanceof \WP_Post ) || $speaker_post->post_type !== 'oras_speaker' || $speaker_post->post_status !== 'publish' ) {
                continue;
            }

            $label_override = isset( $speaker_row['label'] ) ? sanitize_text_field( (string) $speaker_row['label'] ) : '';
            $display_name   = $label_override !== '' ? $label_override : get_the_title( $speaker_id );
            if ( ! is_string( $display_name ) || $display_name === '' ) {
                continue;
            }

            $speaker_ids[ $speaker_id ] = true;
            $role                       = isset( $speaker_row['role'] ) ? sanitize_text_field( (string) $speaker_row['role'] ) : '';

            $speaker_items .= '<li class="oras-agenda__speaker">';
            $speaker_items .= '<button type="button" class="oras-agenda__speaker-link" data-speaker-id="' . esc_attr( (string) $speaker_id ) . '">' . esc_html( $display_name ) . '</button>';
            if ( $role !== '' ) {
                $speaker_items .= ' <span class="oras-agenda__speaker-role">(' . esc_html( $role ) . ')</span>';
            }
            $speaker_items .= '</li>';
        }

        if ( $speaker_items === '' ) {
            return '';
        }

        $html  = '<div class="oras-agenda__speakers">';
        $html .= '<span class="oras-agenda__speakers-label">' . esc_html__( 'Speakers:', 'oras-tickets' ) . '</span>';
        $html .= '<ul class="oras-agenda__speaker-list" role="list">' . $speaker_items . '</ul>';
        $html .= '</div>';

        return $html;
    }

    private static function render_resource_actions( array $resources ): string {
        $resource_items = '';
        foreach ( $resources as $resource ) {
            if ( ! is_array( $resource ) ) {
                continue;
            }

            $visibility = isset( $resource['visibility'] ) ? (string) $resource['visibility'] : 'public';
            if ( $visibility === 'internal' && ! is_user_logged_in() ) {
                continue;
            }

            $attachment_id = isset( $resource['attachment_id'] ) ? absint( $resource['attachment_id'] ) : 0;
            $raw_url       = isset( $resource['url'] ) ? (string) $resource['url'] : '';
            $link_url      = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : esc_url_raw( $raw_url );
            if ( ! is_string( $link_url ) || $link_url === '' ) {
                continue;
            }

            $label = isset( $resource['label'] ) ? sanitize_text_field( (string) $resource['label'] ) : '';
            if ( $label === '' ) {
                $label = basename( $link_url );
            }

            $type       = isset( $resource['type'] ) ? sanitize_text_field( (string) $resource['type'] ) : '';
            $type_label = $type !== '' ? self::format_type_label( $type ) : '';

            $resource_items .= '<li class="oras-agenda__resource">';
            $resource_items .= '<a class="oras-agenda__resource-action" href="' . esc_url( $link_url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
            if ( $type_label !== '' ) {
                $resource_items .= '<span class="oras-agenda__resource-type">' . esc_html( $type_label ) . '</span>';
            }
            $resource_items .= '</li>';
        }

        if ( $resource_items === '' ) {
            return '';
        }

        $html  = '<div class="oras-agenda__resources">';
        $html .= '<span class="oras-agenda__resources-label">' . esc_html__( 'Resources:', 'oras-tickets' ) . '</span>';
        $html .= '<ul class="oras-agenda__resource-list" role="list">' . $resource_items . '</ul>';
        $html .= '</div>';

        return $html;
    }

    private static function render_time_range( string $start, string $end, bool $show_end_times ): string {
        if ( $start === '' ) {
            return __( 'Time to be announced', 'oras-tickets' );
        }

        $time_display = self::format_display_time( $start );
        if ( $show_end_times && $end !== '' ) {
            $time_display .= ' – ' . self::format_display_time( $end );
        }

        return $time_display;
    }

    private static function normalize_filter_value( string $value ): string {
        return sanitize_title( sanitize_text_field( $value ) );
    }

    private static function build_speaker_payload( array $speaker_ids ): array {
        if ( empty( $speaker_ids ) ) {
            return array();
        }

        $payload = array();

        foreach ( $speaker_ids as $speaker_id ) {
            $speaker_id = absint( $speaker_id );
            if ( $speaker_id <= 0 ) {
                continue;
            }

            $speaker_post = get_post( $speaker_id );
            if ( ! ( $speaker_post instanceof \WP_Post ) || $speaker_post->post_type !== 'oras_speaker' || $speaker_post->post_status !== 'publish' ) {
                continue;
            }

            $name = get_the_title( $speaker_id );
            if ( ! is_string( $name ) || $name === '' ) {
                continue;
            }

            $permalink = get_permalink( $speaker_id );
            if ( ! is_string( $permalink ) ) {
                $permalink = '';
            }

            $affiliation = (string) get_post_meta( $speaker_id, '_oras_speaker_affiliation', true );
            $website_url = esc_url_raw( (string) get_post_meta( $speaker_id, '_oras_speaker_website_url', true ) );
            $headshot_id = absint( get_post_meta( $speaker_id, '_oras_speaker_headshot_id', true ) );
            if ( ! $headshot_id ) {
                $headshot_id = get_post_thumbnail_id( $speaker_id );
            }
            $headshot_url = $headshot_id ? wp_get_attachment_image_url( $headshot_id, 'medium' ) : '';
            if ( ! is_string( $headshot_url ) || $headshot_url === '' ) {
                $headshot_url = '';
            }
            $headshot_alt = $name;

            $bio_raw   = (string) get_post_field( 'post_content', $speaker_id );
            $bio_plain = trim( wp_strip_all_tags( $bio_raw ) );
            $bio_short = wp_trim_words( $bio_plain, 40, '…' );

            $payload[] = array(
                'id'           => $speaker_id,
                'name'         => $name,
                'permalink'    => $permalink,
                'affiliation'  => $affiliation,
                'website_url'  => $website_url,
                'headshot_url' => $headshot_url,
                'headshot_alt' => $headshot_alt,
                'bio_short'    => $bio_short,
            );
        }

        return $payload;
    }

    private static function render_speaker_payload_script( array $speaker_payload ): string {
        $json = wp_json_encode( $speaker_payload );
        if ( ! is_string( $json ) ) {
            return '';
        }

        return '<script type="application/json" id="oras-speaker-data">' . $json . '</script>';
    }

    private static function render_speaker_drawer_markup(): string {
        return '<div class="oras-speaker-drawer" id="oras-speaker-drawer" hidden>'
        . '<div class="oras-speaker-drawer__backdrop" data-speaker-close aria-hidden="true"></div>'
        . '<aside class="oras-speaker-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="oras-speaker-drawer-title">'
        . '<header class="oras-speaker-drawer__header">'
        . '<span class="oras-speaker-drawer__label">' . esc_html__( 'Speaker profile', 'oras-tickets' ) . '</span>'
        . '<button type="button" class="oras-speaker-drawer__close" data-speaker-close aria-label="' . esc_attr__( 'Close speaker profile', 'oras-tickets' ) . '">' . esc_html__( 'Close', 'oras-tickets' ) . '</button>'
        . '</header>'
        . '<div class="oras-speaker-drawer__content">'
        . '<img class="oras-speaker-drawer__headshot" alt="" hidden>'
        . '<div class="oras-speaker-drawer__details">'
        . '<h3 class="oras-speaker-drawer__name" id="oras-speaker-drawer-title"></h3>'
        . '<div class="oras-speaker-drawer__affiliation"></div>'
        . '<div class="oras-speaker-drawer__bio"></div>'
        . '<div class="oras-speaker-drawer__links">'
        . '<a class="oras-speaker-drawer__website" target="_blank" rel="noopener" hidden>' . esc_html__( 'Website', 'oras-tickets' ) . '</a>'
        . '<a class="oras-speaker-drawer__profile" hidden>' . esc_html__( 'View full profile', 'oras-tickets' ) . '</a>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</aside>'
        . '</div>';
    }

    private static function normalize_public_slots( array $slots ): array {
        $normalized_slots = array();

        foreach ( $slots as $source_index => $slot ) {
            if ( ! is_array( $slot ) ) {
                continue;
            }

            $visibility = isset( $slot['visibility'] ) ? (string) $slot['visibility'] : 'public';
            if ( $visibility === 'hidden' ) {
                continue;
            }

            $start = isset( $slot['start'] ) ? (string) $slot['start'] : '';
            $end   = isset( $slot['end'] ) ? (string) $slot['end'] : '';
            $title = isset( $slot['title'] ) ? (string) $slot['title'] : '';
            if ( $start === '' && $title === '' ) {
                continue;
            }

            $start_24     = self::normalize_time_24h( $start );
            $end_24       = self::normalize_time_24h( $end );
            $start_parts  = $start_24 !== '' ? explode( ':', $start_24 ) : array();
            $end_parts    = $end_24 !== '' ? explode( ':', $end_24 ) : array();
            $start_minutes = count( $start_parts ) === 2
                ? ( (int) $start_parts[0] * 60 ) + (int) $start_parts[1]
                : -1;
            $end_minutes = count( $end_parts ) === 2
                ? ( (int) $end_parts[0] * 60 ) + (int) $end_parts[1]
                : -1;
            if ( $start_minutes >= 0 && $end_minutes >= 0 && $end_minutes < $start_minutes ) {
                $end_minutes += 1440;
            }

            $normalized                 = $slot;
            $normalized['source_index'] = (int) $source_index;
            $normalized['start_24']      = $start_24;
            $normalized['end_24']        = $end_24;
            $normalized['start_minutes'] = $start_minutes;
            $normalized['end_minutes']   = $end_minutes;
            $normalized_slots[]          = $normalized;
        }

        return $normalized_slots;
    }

    private static function partition_day_program( array $slots ): array {
        $timed       = array();
        $unscheduled = array();

        foreach ( self::normalize_public_slots( $slots ) as $slot ) {
            if ( (int) $slot['start_minutes'] < 0 ) {
                $unscheduled[] = $slot;
                continue;
            }

            $timed[] = $slot;
        }

        usort(
            $timed,
            static function ( array $first, array $second ): int {
                $start_comparison = (int) $first['start_minutes'] <=> (int) $second['start_minutes'];
                if ( $start_comparison !== 0 ) {
                    return $start_comparison;
                }

                return (int) $first['source_index'] <=> (int) $second['source_index'];
            }
        );

        $ongoing_indices = array();
        $timed_count      = count( $timed );
        for ( $index = 0; $index < $timed_count; ++$index ) {
            if ( self::slot_duration_minutes( $timed[ $index ] ) < 120 ) {
                continue;
            }

            for ( $later_index = $index + 1; $later_index < $timed_count; ++$later_index ) {
                if ( self::slots_overlap( $timed[ $index ], $timed[ $later_index ] ) ) {
                    $ongoing_indices[ $index ] = true;
                    break;
                }
            }
        }

        $ongoing     = array();
        $time_groups = array();
        foreach ( $timed as $index => $slot ) {
            if ( isset( $ongoing_indices[ $index ] ) ) {
                $ongoing[] = $slot;
                continue;
            }

            $time_groups[ $slot['start_24'] ][] = $slot;
        }

        return array(
            'ongoing'     => $ongoing,
            'time_groups' => $time_groups,
            'unscheduled' => $unscheduled,
        );
    }

    private static function slot_duration_minutes( array $slot ): int {
        $start_minutes = isset( $slot['start_minutes'] ) ? (int) $slot['start_minutes'] : -1;
        $end_minutes   = isset( $slot['end_minutes'] ) ? (int) $slot['end_minutes'] : -1;
        if ( $start_minutes < 0 || $end_minutes <= $start_minutes ) {
            return 0;
        }

        return $end_minutes - $start_minutes;
    }

    private static function slots_overlap( array $first, array $second ): bool {
        $first_start  = isset( $first['start_minutes'] ) ? (int) $first['start_minutes'] : -1;
        $first_end    = isset( $first['end_minutes'] ) ? (int) $first['end_minutes'] : -1;
        $second_start = isset( $second['start_minutes'] ) ? (int) $second['start_minutes'] : -1;
        $second_end   = isset( $second['end_minutes'] ) ? (int) $second['end_minutes'] : -1;
        if ( $first_start < 0 || $second_start < 0 || $first_end <= $first_start || $second_end <= $second_start ) {
            return false;
        }

        return $first_start < $second_end && $second_start < $first_end;
    }

    private static function is_valid_day_date( string $date ): bool {
        return $date !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) === 1;
    }

    private static function normalize_time_24h( string $value ): string {
        $raw = strtolower( trim( $value ) );
        if ( $raw === '' ) {
            return '';
        }

        $raw = preg_replace( '/\s+/', '', $raw );
        if ( ! is_string( $raw ) || $raw === '' ) {
            return '';
        }

        if ( preg_match( '/^(\d{1,2}):(\d{2})(am|pm)?$/', $raw, $matches ) === 1 ) {
            $hour   = (int) $matches[1];
            $minute = (int) $matches[2];
            $suffix = isset( $matches[3] ) ? $matches[3] : '';

            if ( $minute < 0 || $minute > 59 ) {
                return '';
            }

            if ( $suffix === '' ) {
                if ( $hour < 0 || $hour > 23 ) {
                    return '';
                }

                return sprintf( '%02d:%02d', $hour, $minute );
            }

            if ( $hour < 1 || $hour > 12 ) {
                return '';
            }

            if ( $suffix === 'am' ) {
                $hour = ( $hour === 12 ) ? 0 : $hour;
            } else {
                $hour = ( $hour === 12 ) ? 12 : $hour + 12;
            }

            return sprintf( '%02d:%02d', $hour, $minute );
        }

        if ( preg_match( '/^(\d{1,2})(am|pm)$/', $raw, $matches ) === 1 ) {
            $hour   = (int) $matches[1];
            $suffix = $matches[2];

            if ( $hour < 1 || $hour > 12 ) {
                return '';
            }

            if ( $suffix === 'am' ) {
                $hour = ( $hour === 12 ) ? 0 : $hour;
            } else {
                $hour = ( $hour === 12 ) ? 12 : $hour + 12;
            }

            return sprintf( '%02d:00', $hour );
        }

        return '';
    }

    private static function format_display_time( string $value ): string {
        $hm = self::normalize_time_24h( $value );
        if ( $hm === '' ) {
            return $value;
        }

        $parts = explode( ':', $hm );
        if ( count( $parts ) !== 2 ) {
            return $hm;
        }

        $hour         = (int) $parts[0];
        $minute       = (int) $parts[1];
        $suffix       = $hour >= 12 ? 'PM' : 'AM';
        $display_hour = $hour % 12;
        if ( $display_hour === 0 ) {
            $display_hour = 12;
        }

        return sprintf( '%d:%02d %s', $display_hour, $minute, $suffix );
    }

    private static function format_display_date( string $date ): string {
        if ( ! self::is_valid_day_date( $date ) ) {
            return $date;
        }

        $dt = \DateTime::createFromFormat( 'Y-m-d', $date, wp_timezone() );
        if ( ! ( $dt instanceof \DateTimeInterface ) ) {
            return $date;
        }

        return wp_date( 'M j, Y', $dt->getTimestamp(), wp_timezone() );
    }

    private static function format_type_label( string $type ): string {
        $normalized = sanitize_key( $type );
        if ( $normalized === '' ) {
            return __( 'Other', 'oras-tickets' );
        }

        return ucwords( str_replace( '-', ' ', $normalized ) );
    }
}
