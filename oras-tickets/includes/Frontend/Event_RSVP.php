<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Support\DbLock;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_RSVP { // NOSONAR legacy WP class naming

    private const META_KEY = '_oras_rsvp_v1';
    private const USERMETA_PREFIX = '_oras_rsvp_event_';
    private const USERMETA_ATTENDANCE_SUFFIX = '_attendance_mode';
    private const ACTION = 'oras_rsvp_update';
    private const VIRTUAL_EMAIL_HEADERS = array( 'Content-Type: text/plain; charset=UTF-8' );

    public static function register(): void {
        // Render after tickets display (tickets appended at priority 20).
        add_filter( 'the_content', array( self::class, 'render_rsvp_block' ), 21 );
        add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_post' ) );
    }

    public static function render_rsvp_block( string $content ): string {
        if ( ! function_exists( 'is_singular' ) || ! is_singular( 'tribe_events' ) ) {
            return $content;
        }

        if ( ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        global $post;
        if ( empty( $post ) || ! isset( $post->ID ) ) {
            return $content;
        }

        $event_id = (int) $post->ID;
        $meta = get_post_meta( $event_id, self::META_KEY, true );
        if ( ! is_array( $meta ) || empty( $meta['enabled'] ) ) {
            return $content;
        }

        $capacity = absint( $meta['capacity'] ?? 0 );
        $waitlist_enabled = ! empty( $meta['waitlist_enabled'] );

        $user_id = get_current_user_id();

        // Enqueue frontend RSVP script only on pages where RSVP block is rendered.
        if ( function_exists( 'wp_enqueue_style' ) ) {
            wp_enqueue_style(
                'oras-tickets-frontend',
                ORAS_TICKETS_URL . 'assets/css/tickets-frontend.css',
                array(),
                ORAS_TICKETS_VERSION
            );
        }

        if ( function_exists( 'wp_enqueue_script' ) ) {
            wp_enqueue_script( 'oras-rsvp-frontend', ORAS_TICKETS_URL . 'assets/js/oras-rsvp-frontend.js', array(), ORAS_TICKETS_VERSION, true );
        }

        ob_start();

        echo '<div class="oras-rsvp-block">';
        // Container for AJAX notices
        echo '<div class="oras-rsvp-ajax-notice" aria-live="polite"></div>';

        // Show status messages from redirect
        $flash = '';
        $oras_rsvp = isset( $_GET['oras_rsvp'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_rsvp'] ) ) : '';
        $oras_msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
        if ( 'error' === $oras_rsvp ) {
            $text = self::humanize_error_message( $oras_msg );
            $flash = '<div class="oras-rsvp-notice oras-rsvp-notice-error">' . $text . '</div>';
        }

        if ( ! is_user_logged_in() ) {
            $login = wp_login_url( get_permalink( $event_id ) );
            printf( '<p>%s <a href="%s">%s</a></p>', esc_html__( 'Please log in to RSVP for this event.', 'oras-tickets' ), esc_url( $login ), esc_html__( 'Log in', 'oras-tickets' ) );
            echo wp_kses(
                $flash,
                array(
                    'div' => array(
                        'class' => true,
                    ),
                )
            );
            echo '</div>';
            return $content . ob_get_clean();
        }

        $status = self::get_user_status( $event_id, $user_id );
        $attendance_mode = self::get_user_attendance_mode( $event_id, $user_id );
        $yes_count = self::yes_count( $event_id );
        $selected_mode = '' !== $attendance_mode ? $attendance_mode : Ticket::ATTENDANCE_MODE_ONSITE;

        if ( 'yes' === $status ) {
            echo '<p class="oras-rsvp-status oras-rsvp-status-yes"><strong>' . esc_html( self::get_status_message( 'yes', $attendance_mode ) ) . '</strong></p>';
            echo '<span class="oras-rsvp-badge">' . esc_html( self::get_badge_text( $attendance_mode ) ) . '</span>';
        } elseif ( 'waitlist' === $status ) {
            echo '<p class="oras-rsvp-status oras-rsvp-status-waitlist"><strong>' . esc_html( self::get_status_message( 'waitlist', $attendance_mode ) ) . '</strong></p>';
        } elseif ( 'no' === $status ) {
            echo '<p class="oras-rsvp-status oras-rsvp-status-no">' . esc_html__( 'You are not attending this event.', 'oras-tickets' ) . '</p>';
        } else {
            echo '<p class="oras-rsvp-status oras-rsvp-status-none">' . esc_html__( 'You have not responded to this event yet.', 'oras-tickets' ) . '</p>';
        }

        if ( 0 === $capacity ) {
            printf( '<p class="oras-rsvp-capacity">%s: <strong>%s</strong></p>', esc_html__( 'Capacity', 'oras-tickets' ), esc_html__( 'Unlimited', 'oras-tickets' ) );
        } else {
            printf( '<p class="oras-rsvp-capacity">%s: <strong>%d</strong> / <strong>%d</strong></p>', esc_html__( 'Attending', 'oras-tickets' ), absint( $yes_count ), absint( $capacity ) );
        }

        // Form
        $nonce = wp_create_nonce( 'oras_rsvp_' . $event_id );
        $action_url = admin_url( 'admin-post.php' );

        echo '<form method="post" action="' . esc_url( $action_url ) . '" class="oras-rsvp-form">';
        printf( '<input type="hidden" name="action" value="%s"/>', esc_attr( self::ACTION ) );
        printf( '<input type="hidden" name="event_id" value="%s"/>', esc_attr( (string) $event_id ) );
        printf( '<input type="hidden" name="oras_rsvp_nonce" value="%s"/>', esc_attr( $nonce ) );

        echo '<fieldset class="oras-rsvp-attendance-mode">';
        echo '<legend>' . esc_html__( 'Attendance Type', 'oras-tickets' ) . '</legend>';
        echo '<div class="oras-rsvp-attendance-options">';
        echo '<label class="oras-rsvp-choice"><input type="radio" name="attendance_mode" value="' . esc_attr( Ticket::ATTENDANCE_MODE_ONSITE ) . '" ' . checked( Ticket::ATTENDANCE_MODE_ONSITE, $selected_mode, false ) . ' /> <span>' . esc_html__( 'On-site', 'oras-tickets' ) . '</span></label>';
        echo '<label class="oras-rsvp-choice"><input type="radio" name="attendance_mode" value="' . esc_attr( Ticket::ATTENDANCE_MODE_VIRTUAL ) . '" ' . checked( Ticket::ATTENDANCE_MODE_VIRTUAL, $selected_mode, false ) . ' /> <span>' . esc_html__( 'Virtual', 'oras-tickets' ) . '</span></label>';
        echo '</div>';
        echo '<p class="description oras-rsvp-description">' . esc_html__( 'Choose whether your RSVP is for attending on-site or joining virtually.', 'oras-tickets' ) . '</p>';
        echo '</fieldset>';

        // Buttons
        echo '<p class="oras-rsvp-actions">';
        echo '<button type="submit" name="intent" value="yes" class="oras-rsvp-button oras-rsvp-button-primary">' . esc_html__( 'RSVP Yes', 'oras-tickets' ) . '</button>';
        echo '<button type="submit" name="intent" value="no" class="oras-rsvp-button oras-rsvp-button-secondary">' . esc_html__( 'RSVP No', 'oras-tickets' ) . '</button>';

        $is_full = ( $capacity > 0 && $yes_count >= $capacity );
        if ( $is_full && $waitlist_enabled ) {
            if ( 'waitlist' === $status ) {
                echo '<button type="submit" name="intent" value="leave_waitlist" class="oras-rsvp-button oras-rsvp-button-secondary">' . esc_html__( 'Leave Waitlist', 'oras-tickets' ) . '</button>';
            } else {
                echo '<button type="submit" name="intent" value="waitlist" class="oras-rsvp-button oras-rsvp-button-secondary">' . esc_html__( 'Join Waitlist', 'oras-tickets' ) . '</button>';
            }
        }

        echo '</p>';
        echo '</form>';

        echo '</div>';

        return $content . ob_get_clean();
    }

    public static function handle_post(): void {
        if ( ! is_user_logged_in() ) {
            if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Please log in to RSVP.', 'oras-tickets' ) ) );
            }

            wp_safe_redirect( wp_get_referer() ?: home_url() );
            exit;
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
        $nonce = isset( $_POST['oras_rsvp_nonce'] ) && is_scalar( $_POST['oras_rsvp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['oras_rsvp_nonce'] ) ) : '';
        $raw_intent = isset( $_POST['intent'] ) && is_scalar( $_POST['intent'] ) ? sanitize_text_field( wp_unslash( $_POST['intent'] ) ) : '';
        $posted_attendance_mode = isset( $_POST['attendance_mode'] ) && is_scalar( $_POST['attendance_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['attendance_mode'] ) ) : '';
        $virtual_email = isset( $_POST['virtual_email'] ) && is_scalar( $_POST['virtual_email'] ) ? sanitize_email( wp_unslash( $_POST['virtual_email'] ) ) : '';
        $redirect = get_permalink( $event_id ) ?: home_url();

        $intent = self::normalize_intent( $raw_intent );

        // If intent couldn't be resolved to yes/no/waitlist, return a specific error
        if ( '' === $intent ) {
            if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Invalid RSVP value.', 'oras-tickets' ) ) );
            }

            $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'invalid_value' ) ), $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( $event_id <= 0 || ! wp_verify_nonce( $nonce, 'oras_rsvp_' . $event_id ) ) {
            if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'oras-tickets' ) ) );
            }

            $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'invalid' ) ), $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        $request_attendance_mode = Ticket::normalizeAttendanceMode( $posted_attendance_mode, Ticket::ATTENDANCE_MODE_ONSITE );
        if ( 'yes' === $intent ) {
            if ( '' === $virtual_email ) {
                $current_user = wp_get_current_user();
                if ( $current_user instanceof \WP_User && is_string( $current_user->user_email ) ) {
                    $virtual_email = sanitize_email( $current_user->user_email );
                }
            }

            if ( '' === $virtual_email || ! is_email( $virtual_email ) ) {
                if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                    wp_send_json_error( array( 'message' => esc_html__( 'Please enter a valid email address to receive event details.', 'oras-tickets' ) ) );
                }

                $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'valid_email_required' ) ), $redirect );
                wp_safe_redirect( $redirect );
                exit;
            }
        }

        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== 'tribe_events' ) {
            if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Invalid event.', 'oras-tickets' ) ) );
            }

            $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'invalid' ) ), $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        $user_id = get_current_user_id();
        $meta = get_post_meta( $event_id, self::META_KEY, true );
        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        $operation = DbLock::forEvent(
            $event_id,
            static function () use ( $event_id, $user_id, $intent, $meta, $posted_attendance_mode ): array {
                $capacity = absint( $meta['capacity'] ?? 0 );
                $waitlist_enabled = ! empty( $meta['waitlist_enabled'] );

                $current = self::get_user_status( $event_id, $user_id );
                $current_attendance_mode = self::get_user_attendance_mode( $event_id, $user_id );
                $attendance_mode = Ticket::normalizeAttendanceMode(
                    $posted_attendance_mode,
                    '' !== $current_attendance_mode ? $current_attendance_mode : Ticket::ATTENDANCE_MODE_ONSITE
                );
                $yes_count = self::yes_count( $event_id );
                $waitlist_lifecycle = Waitlist_Store::get_current_waitlist_status( $event_id, $user_id );
                $was_waitlisted = ( 'waitlist' === $current || 'waiting' === $waitlist_lifecycle );

                $new_status = $current;
                $error = false;

                switch ( $intent ) {
                    case 'yes':
                        if ( 'yes' === $current ) {
                            $new_status = 'yes';
                        } else {
                            if ( 0 === $capacity || $yes_count < $capacity ) {
                                $new_status = 'yes';
                            } elseif ( $waitlist_enabled ) {
                                $new_status = 'waitlist';
                            } else {
                                $error = true;
                            }
                        }
                        break;

                    case 'no':
                        $new_status = 'no';
                        break;

                    case 'waitlist':
                        if ( $waitlist_enabled && ( $capacity > 0 && $yes_count >= $capacity ) ) {
                            $new_status = 'waitlist';
                        } else {
                            $error = true;
                        }
                        break;

                    case 'leave_waitlist':
                        if ( 'waitlist' === $current ) {
                            $new_status = 'no';
                        }
                        break;

                    default:
                        $error = true;
                }

                if ( $error ) {
                    return array(
                        'ok'      => false,
                        'message' => esc_html__( 'Unable to update RSVP.', 'oras-tickets' ),
                    );
                }

                if ( 'no' === $intent ) {
                    if ( $was_waitlisted ) {
                        Waitlist_Store::mark_left( $event_id, $user_id, 'frontend-no', $user_id );
                    }

                    $existing = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, true );
                    if ( empty( $existing ) ) {
                        return array(
                            'ok'      => true,
                            'status'  => 'none',
                            'message' => esc_html__( 'RSVP already removed.', 'oras-tickets' ),
                        );
                    }

                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id );
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . '_ts' );
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_ATTENDANCE_SUFFIX );

                    return array(
                        'ok'      => true,
                        'status'  => 'none',
                        'message' => esc_html__( 'RSVP removed.', 'oras-tickets' ),
                        'attendance_mode' => '',
                    );
                }

                update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, $new_status );
                update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_ATTENDANCE_SUFFIX, $attendance_mode );

                if ( $new_status === 'waitlist' ) {
                    $joined_ts = time();
                    update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . '_ts', $joined_ts );
                    Waitlist_Store::mark_waiting( $event_id, $user_id, 'frontend-rsvp', $user_id, $joined_ts );
                } else {
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . '_ts' );

                    if ( 'yes' === $new_status && $was_waitlisted ) {
                        Waitlist_Store::mark_promoted( $event_id, $user_id, 'frontend-rsvp', $user_id );
                    } elseif ( 'no' === $new_status && $was_waitlisted ) {
                        Waitlist_Store::mark_left( $event_id, $user_id, 'frontend-rsvp', $user_id );
                    }
                }

                return array(
                    'ok'      => true,
                    'status'  => $new_status,
                    'attendance_mode' => $attendance_mode,
                    'message' => self::get_status_message( $new_status, $attendance_mode ),
                );
            }
        );

        if ( is_wp_error( $operation ) ) {
            if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                wp_send_json_error( array( 'message' => $operation->get_error_message() ) );
            }

            $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'locked' ) ), $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        $ok = ! empty( $operation['ok'] );
        if ( ! $ok ) {
            if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                wp_send_json_error( array( 'message' => (string) ( $operation['message'] ?? esc_html__( 'Unable to update RSVP.', 'oras-tickets' ) ) ) );
            }

            $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'capacity' ) ), $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
            if ( 'yes' === (string) ( $operation['status'] ?? '' ) ) {
                $attendance_mode = (string) ( $operation['attendance_mode'] ?? $request_attendance_mode );
                $sent = self::send_rsvp_confirmation_email( $event_id, $virtual_email, $attendance_mode );
                if ( ! $sent ) {
                    wp_send_json_error(
                        array(
                            'message' => esc_html__( 'RSVP saved, but we could not send the confirmation email. Please try again.', 'oras-tickets' ),
                        )
                    );
                }
            }

            wp_send_json_success(
                array(
                    'message' => (string) ( $operation['message'] ?? esc_html__( 'Your RSVP was updated.', 'oras-tickets' ) ),
                    'status'  => (string) ( $operation['status'] ?? '' ),
                    'attendance_mode' => (string) ( $operation['attendance_mode'] ?? '' ),
                )
            );
        }

        if ( 'yes' === (string) ( $operation['status'] ?? '' ) ) {
            $attendance_mode = (string) ( $operation['attendance_mode'] ?? $request_attendance_mode );
            $sent = self::send_rsvp_confirmation_email( $event_id, $virtual_email, $attendance_mode );
            if ( ! $sent ) {
                $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'confirmation_email_send_failed' ) ), $redirect );
                wp_safe_redirect( $redirect );
                exit;
            }
        }

        $redirect = add_query_arg( array( 'oras_rsvp' => 'updated' ), $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }

    private static function send_rsvp_confirmation_email( int $event_id, string $recipient_email, string $attendance_mode ): bool {
        if ( $event_id <= 0 || '' === $recipient_email || ! is_email( $recipient_email ) ) {
            return false;
        }

        $normalized_mode = Ticket::normalizeAttendanceMode( $attendance_mode, Ticket::ATTENDANCE_MODE_ONSITE );
        $is_virtual = Ticket::ATTENDANCE_MODE_VIRTUAL === $normalized_mode;

        $event_title = get_the_title( $event_id );
        if ( ! is_string( $event_title ) || '' === trim( $event_title ) ) {
            $event_title = __( 'ORAS Event', 'oras-tickets' );
        }

        $event_url = get_permalink( $event_id );
        if ( ! is_string( $event_url ) || '' === $event_url ) {
            $event_url = home_url();
        }

        $event_datetime = self::get_event_datetime_text( $event_id );
        $event_details = self::get_event_description_text( $event_id );
        $virtual_link = self::get_virtual_join_link( $event_id );
        $onsite_location = self::get_event_location_text( $event_id );
        $agenda_summary = self::get_event_agenda_summary_text( $event_id );

        $subject = sprintf(
            /* translators: %s: event title */
            __( 'Your RSVP details for %s', 'oras-tickets' ),
            $event_title
        );

        $lines = array(
            sprintf( __( 'Thank you for your RSVP.', 'oras-tickets' ) ),
            '',
            sprintf( __( 'Event: %s', 'oras-tickets' ), $event_title ),
            sprintf( __( 'Attendance: %s', 'oras-tickets' ), self::get_attendance_mode_label( $normalized_mode ) ),
            sprintf( __( 'Date & Time: %s', 'oras-tickets' ), $event_datetime ),
            '',
            __( 'Event Details:', 'oras-tickets' ),
            $event_details,
        );

        if ( $is_virtual ) {
            $lines[] = '';
            $lines[] = __( 'Zoom Link:', 'oras-tickets' );
            $lines[] = '' !== $virtual_link ? $virtual_link : __( 'Virtual access link is not currently available. Please contact ORAS support.', 'oras-tickets' );
        } else {
            $lines[] = '';
            $lines[] = sprintf( __( 'Location: %s', 'oras-tickets' ), $onsite_location );
            $lines[] = '';
            $lines[] = __( 'Agenda:', 'oras-tickets' );
            $lines[] = $agenda_summary;
        }

        $lines[] = '';
        $lines[] = __( 'View this event on ORAS.org:', 'oras-tickets' );
        $lines[] = $event_url;

        return (bool) wp_mail(
            $recipient_email,
            $subject,
            implode( "\n", $lines ),
            self::VIRTUAL_EMAIL_HEADERS
        );
    }

    private static function get_event_location_text( int $event_id ): string {
        $location_text = '';

        if ( $event_id > 0 && function_exists( 'tribe_get_venue_id' ) ) {
            $venue_id = (int) tribe_get_venue_id( $event_id );
            if ( $venue_id > 0 ) {
                $venue_name = function_exists( 'tribe_get_venue' ) ? (string) tribe_get_venue( $event_id ) : '';
                $address    = function_exists( 'tribe_get_address' ) ? (string) tribe_get_address( $venue_id ) : '';
                $city       = function_exists( 'tribe_get_city' ) ? (string) tribe_get_city( $venue_id ) : '';
                $state      = function_exists( 'tribe_get_state' ) ? (string) tribe_get_state( $venue_id ) : '';
                $province   = function_exists( 'tribe_get_province' ) ? (string) tribe_get_province( $venue_id ) : '';
                $zip        = function_exists( 'tribe_get_zip' ) ? (string) tribe_get_zip( $venue_id ) : '';
                $country    = function_exists( 'tribe_get_country' ) ? (string) tribe_get_country( $venue_id ) : '';
                $region     = '' !== $state ? $state : $province;

                $parts = array_filter(
                    array( $venue_name, $address, $city, $region, $zip, $country ),
                    static function ( $value ) {
                        return is_string( $value ) && '' !== trim( $value );
                    }
                );
                $location_text = implode( ', ', $parts );
            }
        }

        if ( '' === $location_text && $event_id > 0 && function_exists( 'tribe_get_full_address' ) ) {
            $location_text = (string) tribe_get_full_address( $event_id );
        }

        if ( '' === $location_text && $event_id > 0 && function_exists( 'tribe_get_venue' ) ) {
            $location_text = (string) tribe_get_venue( $event_id );
        }

        if ( '' === $location_text && $event_id > 0 ) {
            $location_text = (string) get_post_meta( $event_id, '_EventVenue', true );
        }

        $location_text = trim( wp_strip_all_tags( $location_text ) );
        if ( '' === $location_text ) {
            return __( 'See event page for location details.', 'oras-tickets' );
        }

        return $location_text;
    }

    private static function get_event_agenda_summary_text( int $event_id ): string {
        $envelope = get_post_meta( $event_id, '_oras_agenda_v1', true );
        if ( ! is_array( $envelope ) ) {
            return __( 'Agenda is available on the event page.', 'oras-tickets' );
        }

        $days = isset( $envelope['days'] ) && is_array( $envelope['days'] ) ? $envelope['days'] : array();
        if ( empty( $days ) ) {
            return __( 'Agenda is available on the event page.', 'oras-tickets' );
        }

        $lines = array();
        foreach ( $days as $day ) {
            if ( ! is_array( $day ) ) {
                continue;
            }

            $day_label = isset( $day['day_label'] ) ? trim( (string) $day['day_label'] ) : '';
            $date      = isset( $day['date'] ) ? trim( (string) $day['date'] ) : '';
            if ( '' !== $day_label || '' !== $date ) {
                $lines[] = trim( $day_label . ( '' !== $date ? ' (' . $date . ')' : '' ) );
            }

            $slots = isset( $day['slots'] ) && is_array( $day['slots'] ) ? $day['slots'] : array();
            foreach ( $slots as $slot ) {
                if ( ! is_array( $slot ) ) {
                    continue;
                }
                $title = isset( $slot['title'] ) ? trim( (string) $slot['title'] ) : '';
                $start = isset( $slot['start'] ) ? trim( (string) $slot['start'] ) : '';
                $end   = isset( $slot['end'] ) ? trim( (string) $slot['end'] ) : '';
                if ( '' === $title && '' === $start && '' === $end ) {
                    continue;
                }
                $time = trim( $start . ( '' !== $end ? '-' . $end : '' ) );
                $lines[] = ' - ' . trim( ( '' !== $time ? '[' . $time . '] ' : '' ) . ( '' !== $title ? $title : __( 'Agenda item', 'oras-tickets' ) ) );
                if ( count( $lines ) >= 25 ) {
                    break 2;
                }
            }
        }

        if ( empty( $lines ) ) {
            return __( 'Agenda is available on the event page.', 'oras-tickets' );
        }

        return implode( "\n", $lines );
    }

    private static function get_event_datetime_text( int $event_id ): string {
        if ( function_exists( 'tribe_get_start_date' ) && function_exists( 'tribe_get_end_date' ) ) {
            $start = tribe_get_start_date( $event_id, true, 'F j, Y g:i A' );
            $end = tribe_get_end_date( $event_id, true, 'F j, Y g:i A T' );
            if ( is_string( $start ) && '' !== $start ) {
                if ( is_string( $end ) && '' !== $end ) {
                    return $start . ' - ' . $end;
                }

                return $start;
            }
        }

        $start_raw = get_post_meta( $event_id, '_EventStartDate', true );
        if ( is_string( $start_raw ) && '' !== $start_raw ) {
            return $start_raw;
        }

        return __( 'See event page for schedule details.', 'oras-tickets' );
    }

    private static function get_event_description_text( int $event_id ): string {
        $post = get_post( $event_id );
        if ( ! $post instanceof \WP_Post ) {
            return __( 'Event details are available on the event page.', 'oras-tickets' );
        }

        $raw = is_string( $post->post_excerpt ) && '' !== trim( $post->post_excerpt )
            ? $post->post_excerpt
            : $post->post_content;
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return __( 'Event details are available on the event page.', 'oras-tickets' );
        }

        $text = trim( wp_strip_all_tags( (string) $raw ) );
        if ( '' === $text ) {
            return __( 'Event details are available on the event page.', 'oras-tickets' );
        }

        if ( strlen( $text ) > 1200 ) {
            return substr( $text, 0, 1197 ) . '...';
        }

        return $text;
    }

    private static function get_virtual_join_link( int $event_id ): string {
        $candidate_keys = array(
            '_tribe_events_zoom_join_url',
            '_EventZoomJoinURL',
            '_EventVirtualURL',
            '_EventVideoSource',
            '_EventGoogleMeetURL',
            '_EventWebexURL',
            '_EventMicrosoftTeamsURL',
        );

        foreach ( $candidate_keys as $key ) {
            $value = get_post_meta( $event_id, $key, true );
            if ( ! is_string( $value ) || '' === $value ) {
                continue;
            }

            $url = esc_url_raw( trim( $value ) );
            if ( '' !== $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                return $url;
            }
        }

        $all_meta = get_post_meta( $event_id );
        if ( ! is_array( $all_meta ) ) {
            return '';
        }

        foreach ( $all_meta as $key => $values ) {
            if ( ! is_string( $key ) ) {
                continue;
            }

            $is_virtual_key = false !== stripos( $key, 'zoom' )
                || false !== stripos( $key, 'join' )
                || false !== stripos( $key, 'virtual' )
                || false !== stripos( $key, 'google' )
                || false !== stripos( $key, 'webex' )
                || false !== stripos( $key, 'microsoft' )
                || false !== stripos( $key, 'teams' );

            if ( ! $is_virtual_key || ! is_array( $values ) ) {
                continue;
            }

            foreach ( $values as $value ) {
                if ( ! is_string( $value ) || '' === $value ) {
                    continue;
                }

                $url = esc_url_raw( trim( $value ) );
                if ( '' !== $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    return $url;
                }
            }
        }

        return '';
    }

    private static function normalize_intent( string $raw_intent ): string {
        $intent = strtolower( trim( $raw_intent ) );

        if ( in_array( $intent, array( 'yes', '1', 'true' ), true ) ) {
            return 'yes';
        }

        if ( in_array( $intent, array( 'no', '0', 'false' ), true ) ) {
            return 'no';
        }

        if ( in_array( $intent, array( 'waitlist', 'leave_waitlist' ), true ) ) {
            return $intent;
        }

        return '';
    }

    public static function get_user_status( int $event_id, int $user_id ): string {
        if ( $user_id <= 0 ) {
            return '';
        }

        $val = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, true );
        if ( in_array( $val, array( 'yes', 'no', 'waitlist' ), true ) ) {
            return (string) $val;
        }

        $waitlist_state = Waitlist_Store::get_current_waitlist_status( $event_id, $user_id );
        if ( 'waiting' === $waitlist_state ) {
            return 'waitlist';
        }

        return '';
    }

    public static function yes_count( int $event_id ): int {
        $users = get_users(
            array(
                'meta_key' => self::USERMETA_PREFIX . $event_id,
                'meta_value' => 'yes',
                'fields' => 'ID',
            )
        );

        if ( ! is_array( $users ) ) {
            return 0;
        }

        return count( $users );
    }

    public static function get_user_attendance_mode( int $event_id, int $user_id ): string {
        if ( $user_id <= 0 ) {
            return '';
        }

        $mode = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_ATTENDANCE_SUFFIX, true );
        if ( ! is_string( $mode ) || '' === $mode ) {
            return '';
        }

        return Ticket::normalizeAttendanceMode( $mode, Ticket::ATTENDANCE_MODE_ONSITE );
    }

    public static function get_attendance_mode_label( string $attendance_mode ): string {
        if ( Ticket::ATTENDANCE_MODE_VIRTUAL === $attendance_mode ) {
            return __( 'Virtual', 'oras-tickets' );
        }

        if ( Ticket::ATTENDANCE_MODE_ONSITE === $attendance_mode ) {
            return __( 'On-site', 'oras-tickets' );
        }

        return __( 'Not specified', 'oras-tickets' );
    }

    private static function get_status_message( string $status, string $attendance_mode ): string {
        $attendance_label = self::get_attendance_mode_label( $attendance_mode );

        if ( __( 'Not specified', 'oras-tickets' ) === $attendance_label ) {
            if ( 'waitlist' === $status ) {
                return __( 'You are on the waitlist for this event.', 'oras-tickets' );
            }

            if ( 'yes' === $status ) {
                return __( 'You are RSVPed for this event.', 'oras-tickets' );
            }

            return __( 'Your RSVP was updated.', 'oras-tickets' );
        }

        $attendance_label = strtolower( $attendance_label );

        switch ( $status ) {
            case 'yes':
                /* translators: %s: attendance mode label. */
                return sprintf( __( 'You are RSVPed for %s attendance.', 'oras-tickets' ), $attendance_label );

            case 'waitlist':
                /* translators: %s: attendance mode label. */
                return sprintf( __( 'You are on the waitlist for %s attendance.', 'oras-tickets' ), $attendance_label );

            default:
                return __( 'Your RSVP was updated.', 'oras-tickets' );
        }
    }

    private static function get_badge_text( string $attendance_mode ): string {
        if ( __( 'Not specified', 'oras-tickets' ) === self::get_attendance_mode_label( $attendance_mode ) ) {
            return __( 'Status: You are RSVPed ✅', 'oras-tickets' );
        }

        /* translators: %s: attendance mode label. */
        return sprintf( __( 'Status: RSVPed for %s ✅', 'oras-tickets' ), self::get_attendance_mode_label( $attendance_mode ) );
    }

    private static function humanize_error_message( string $message_code ): string {
        $code = strtolower( trim( (string) $message_code ) );
        switch ( $code ) {
            case 'valid_email_required':
                return esc_html__( 'Please provide a valid email address to receive event details.', 'oras-tickets' );
            case 'confirmation_email_send_failed':
                return esc_html__( 'RSVP saved, but we could not send your confirmation email. Please try again.', 'oras-tickets' );
            case 'invalid_value':
                return esc_html__( 'Invalid RSVP value.', 'oras-tickets' );
            case 'invalid':
                return esc_html__( 'Security check failed.', 'oras-tickets' );
            case 'capacity':
                return esc_html__( 'Unable to update RSVP due to event capacity.', 'oras-tickets' );
            case 'locked':
                return esc_html__( 'Could not update RSVP right now. Please try again.', 'oras-tickets' );
            case 'error':
            case '':
                return esc_html__( 'Unable to update RSVP.', 'oras-tickets' );
            default:
                return esc_html__( 'Unable to update RSVP.', 'oras-tickets' );
        }
    }
}
