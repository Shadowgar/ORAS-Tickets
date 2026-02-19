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

        wp_enqueue_script(
            'oras-darkmode-hook',
            ORAS_TICKETS_URL . 'assets/js/oras-darkmode-hook.js',
            array(),
            ORAS_TICKETS_VERSION,
            true
        );

        $inline_css = '
            .oras-agenda__timeline::before {
                background: rgba(15,23,42,0.18) !important;
            }
            html.oras-dark-on .oras-agenda__timeline::before {
                background: rgba(255,255,255,0.18) !important;
            }
            .oras-agenda__item::before {
                background: rgba(15,23,42,0.18) !important;
            }
            html.oras-dark-on .oras-agenda__item::before {
                background: rgba(255,255,255,0.18) !important;
            }
            .oras-agenda__time {
                color: rgba(15,23,42,0.65) !important;
            }
            html.oras-dark-on .oras-agenda__time {
                color: rgba(255,255,255,0.65) !important;
            }
            .oras-agenda__title {
                color: rgba(15,23,42,0.95) !important;
            }
            html.oras-dark-on .oras-agenda__title {
                color: rgba(255,255,255,0.92) !important;
            }
            .oras-agenda__desc {
                color: rgba(15,23,42,0.78) !important;
            }
            html.oras-dark-on .oras-agenda__desc {
                color: rgba(255,255,255,0.78) !important;
            }
            .oras-agenda__pill {
                background: rgba(15,23,42,0.06) !important;
                border-color: rgba(15,23,42,0.14) !important;
            }
            html.oras-dark-on .oras-agenda__pill {
                background: rgba(255,255,255,0.08) !important;
                border-color: rgba(255,255,255,0.14) !important;
            }
            .oras-agenda__speakers-label {
                color: rgba(15,23,42,0.7) !important;
            }
            html.oras-dark-on .oras-agenda__speakers-label {
                color: rgba(255,255,255,0.7) !important;
            }
            .oras-agenda-resources strong {
                color: rgba(15,23,42,0.7) !important;
                font-weight: normal !important;
            }
            html.oras-dark-on .oras-agenda-resources strong {
                color: rgba(255,255,255,0.7) !important;
            }
            .oras-agenda__body {
                display: flex !important;
                flex-direction: column !important;
                row-gap: 6px !important;
                padding-top: 7px !important;
                margin-left: -27px !important;
                position: relative !important;
            }
            .oras-agenda__time,
            .oras-agenda__title,
            .oras-agenda__sub,
            .oras-agenda__desc,
            .oras-agenda__speakers,
            .oras-agenda-resources {
                margin: 0 !important;
            }
            .oras-agenda__pill {
                position: absolute !important;
                left: 120px !important;
                top: 6px !important;
            }
        ';
        wp_add_inline_style( 'oras-agenda-now', $inline_css );

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

        foreach ( $days as $day_index => $day ) {
            if ( ! is_array( $day ) ) {
                continue;
            }

            $day_label      = isset( $day['day_label'] ) ? (string) $day['day_label'] : '';
            $date           = isset( $day['date'] ) ? (string) $day['date'] : '';
            $has_valid_date = self::is_valid_day_date( $date );
            $slots          = isset( $day['slots'] ) && is_array( $day['slots'] ) ? $day['slots'] : array();

            $items_html     = '';
            $rendered_slots = 0;

            foreach ( $slots as $slot_index => $slot ) {
                if ( ! is_array( $slot ) ) {
                    continue;
                }

                $visibility = isset( $slot['visibility'] ) ? (string) $slot['visibility'] : 'public';
                if ( $visibility === 'hidden' ) {
                    continue;
                }

                $start      = isset( $slot['start'] ) ? (string) $slot['start'] : '';
                $end        = isset( $slot['end'] ) ? (string) $slot['end'] : '';
                $slot_title = isset( $slot['title'] ) ? (string) $slot['title'] : '';
                $desc       = isset( $slot['desc'] ) ? (string) $slot['desc'] : '';
                $type       = isset( $slot['type'] ) ? (string) $slot['type'] : 'other';
                $location   = isset( $slot['location'] ) ? (string) $slot['location'] : '';
                $speakers   = isset( $slot['speakers'] ) && is_array( $slot['speakers'] ) ? $slot['speakers'] : array();

                if ( $start === '' && $slot_title === '' ) {
                    continue;
                }

                $start_24       = self::normalize_time_24h( $start );
                $end_24         = self::normalize_time_24h( $end );
                $has_time_range = ( $start_24 !== '' && $end_24 !== '' );
                $slot_id        = 'oras-agenda-slot-' . (int) $day_index . '-' . (int) $slot_index;
                $data_attrs     = '';
                if ( $highlight_current && $has_valid_date && $has_time_range ) {
                    $data_attrs = ' data-agenda-date="' . esc_attr( $date ) . '" data-start="' . esc_attr( $start_24 ) . '" data-end="' . esc_attr( $end_24 ) . '"';
                }

                ++$rendered_slots;

                $display_start = self::format_display_time( $start_24 !== '' ? $start_24 : $start );
                $display_end   = self::format_display_time( $end_24 !== '' ? $end_24 : $end );
                $type_key      = sanitize_key( $type );
                $type_label    = self::format_type_label( $type );
                $time_display  = $display_start;
                if ( $show_end_times && $display_end !== '' ) {
                    $time_display .= ' – ' . $display_end;
                }

                $items_html .= '<li id="' . esc_attr( $slot_id ) . '" class="oras-agenda__item"' . $data_attrs . '>';
                $items_html .= '<div class="oras-agenda__node" aria-hidden="true"></div>';
                $items_html .= '<div class="oras-agenda__body">';
                $items_html .= '<div class="oras-agenda__time">' . esc_html( $time_display ) . '</div>';
                $items_html .= '<div class="oras-agenda__title">' . esc_html( $slot_title !== '' ? $slot_title : __( 'Agenda Item', 'oras-tickets' ) ) . '</div>';

                if ( ! empty( $speakers ) ) {
                    $speakers_items_html = '';

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
                        if ( $display_name === '' ) {
                            continue;
                        }

                        $event_speaker_ids[ $speaker_id ] = true;

                        $role = isset( $speaker_row['role'] ) ? sanitize_text_field( (string) $speaker_row['role'] ) : '';

                        $speakers_items_html .= '<li class="oras-agenda__speaker">';
                        $speakers_items_html .= '<button type="button" class="oras-agenda__speaker-link" data-speaker-id="' . esc_attr( (string) $speaker_id ) . '">' . esc_html( $display_name ) . '</button>';
                        if ( $role !== '' ) {
                            $speakers_items_html .= ' <span class="oras-agenda__speaker-role">(' . esc_html( $role ) . ')</span>';
                        }
                        $speakers_items_html .= '</li>';
                    }

                    if ( $speakers_items_html !== '' ) {
                        $items_html .= '<div class="oras-agenda__speakers">';
                        $items_html .= '<span class="oras-agenda__speakers-label">' . esc_html__( 'Speakers:', 'oras-tickets' ) . '</span>';
                        $items_html .= '<ul class="oras-agenda__speaker-list" role="list">' . $speakers_items_html . '</ul>';
                        $items_html .= '</div>';
                    }
                }

                $items_html .= '<div class="oras-agenda__sub">';
                if ( $location !== '' ) {
                    $items_html .= '<span class="oras-agenda__meta">' . esc_html( $location ) . '</span>';
                }
                $items_html .= '<span class="oras-agenda__pill oras-agenda__pill--' . esc_attr( $type_key !== '' ? $type_key : 'other' ) . '">' . esc_html( $type_label ) . '</span>';
                $items_html .= '</div>';

                if ( $show_descriptions && $desc !== '' ) {
                    $items_html .= '<div class="oras-agenda__desc">' . esc_html( $desc ) . '</div>';
                }

                $resources = isset( $slot['resources'] ) && is_array( $slot['resources'] ) ? $slot['resources'] : array();
                if ( ! empty( $resources ) ) {
                    $resources_html = '';
                    foreach ( $resources as $resource ) {
                        if ( ! is_array( $resource ) ) {
                            continue;
                        }

                        $attachment_id = isset( $resource['attachment_id'] ) ? absint( $resource['attachment_id'] ) : 0;
                        $url = isset( $resource['url'] ) ? esc_url_raw( $resource['url'] ) : '';
                        $link_url = '';
                        if ( $attachment_id > 0 ) {
                            $link_url = wp_get_attachment_url( $attachment_id );
                        } elseif ( $url !== '' ) {
                            $link_url = $url;
                        }
                        if ( $link_url === '' ) {
                            continue;
                        }

                        $label = isset( $resource['label'] ) ? sanitize_text_field( $resource['label'] ) : '';
                        if ( $label === '' ) {
                            $label = basename( $link_url );
                        }

                        $visibility = isset( $resource['visibility'] ) ? $resource['visibility'] : 'public';
                        if ( $visibility === 'internal' && ! is_user_logged_in() ) {
                            continue;
                        }

                        $type = isset( $resource['type'] ) ? sanitize_text_field( $resource['type'] ) : '';
                        $resources_html .= '<li><a href="' . esc_url( $link_url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
                        if ( $type !== '' ) {
                            $resources_html .= ' <span class="oras-resource-type">(' . esc_html( $type ) . ')</span>';
                        }
                        $resources_html .= '</li>';
                    }
                    if ( $resources_html !== '' ) {
                        $items_html .= '<div class="oras-agenda-resources">';
                        $items_html .= '<strong>' . esc_html__( 'Resources:', 'oras-tickets' ) . '</strong>';
                        $items_html .= '<ul>' . $resources_html . '</ul>';
                        $items_html .= '</div>';
                    }
                }

                $items_html .= '</div>';
                $items_html .= '</li>';
            }

            if ( $rendered_slots === 0 ) {
                continue;
            }

            /* translators: %d is the day number in the agenda. */
            $day_title = $day_label !== '' ? $day_label : sprintf( __( 'Day %d', 'oras-tickets' ), ( (int) $day_index + 1 ) );
            $panel_id  = 'oras-agenda-day-' . (int) $day_index;
            $tab_id    = 'oras-agenda-tab-' . (int) $day_index;
            $expanded  = $first_panel ? 'true' : 'false';
$hidden    = $first_panel ? '' : ' hidden';
            $selected  = $first_panel ? 'true' : 'false';

            $tab_buttons[] = '<button id="' . esc_attr( $tab_id ) . '" class="oras-agenda__tab" type="button" role="tab" aria-selected="' . esc_attr( $selected ) . '" aria-controls="' . esc_attr( $panel_id ) . '" data-day-tab="' . esc_attr( (string) $day_index ) . '">' . esc_html( $day_title ) . '</button>';

            $panels_html .= '<section class="oras-agenda__panel" id="' . esc_attr( $panel_id ) . '" role="tabpanel" aria-labelledby="' . esc_attr( $tab_id ) . '" data-day-panel="' . esc_attr( (string) $day_index ) . '"' . $hidden . '>';
            $panels_html .= '<div class="oras-agenda__day-header">';
            $panels_html .= '<div class="oras-agenda__day-label">' . esc_html( $day_title ) . '</div>';
            if ( $date !== '' ) {
                $panels_html .= '<div class="oras-agenda__day-date">' . esc_html( self::format_display_date( $date ) ) . '</div>';
            }
            $panels_html .= '</div>';
            $panels_html .= '<ul class="oras-agenda__timeline" role="list">' . $items_html . '</ul>';
            $panels_html .= '</section>';

            $first_panel = false;
        }

        if ( $panels_html === '' ) {
            return $content;
        }

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
            $html .= self::render_speaker_modal_markup();
        }

        return $content . $html;
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
            $website_url = (string) get_post_meta( $speaker_id, '_oras_speaker_website_url', true );
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

    private static function render_speaker_modal_markup(): string {
        return '<div class="oras-modal" id="oras-speaker-modal" hidden>'
        . '<div class="oras-modal__backdrop" data-close></div>'
        . '<div class="oras-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="oras-modal-title">'
        . '<button type="button" class="oras-modal__close" data-close aria-label="Close">×</button>'
        . '<div class="oras-modal__content">'
        . '<img class="oras-modal__headshot" alt="" hidden>'
        . '<div class="oras-modal__right">'
        . '<h3 class="oras-modal__name" id="oras-modal-title"></h3>'
        . '<div class="oras-modal__affiliation"></div>'
        . '<div class="oras-modal__bio"></div>'
        . '<div class="oras-modal__links">'
        . '<a class="oras-modal__website" target="_blank" rel="noopener" hidden>Website</a>'
        . '<a class="oras-modal__profile" hidden>View full profile</a>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>';
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
