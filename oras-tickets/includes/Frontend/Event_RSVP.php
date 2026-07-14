<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Event_Question_Attention_Store;
use ORAS\Tickets\Event_Questions;
use ORAS\Tickets\Support\DbLock;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_RSVP { // NOSONAR legacy WP class naming

    private const META_KEY = '_oras_rsvp_v1';
    private const USERMETA_PREFIX = '_oras_rsvp_event_';
    private const USERMETA_ATTENDANCE_SUFFIX = '_attendance_mode';
    private const USERMETA_APPROVAL_SUFFIX = '_approval_status';
    private const USERMETA_APPROVED_BY_SUFFIX = '_approved_by';
    private const USERMETA_APPROVED_AT_SUFFIX = '_approved_at';
    private const USERMETA_REJECTION_REASON_SUFFIX = '_rejection_reason';
    private const USERMETA_CONTACT_SUFFIX = '_contact';
    private const USERMETA_CANCEL_TOKEN_HASH_SUFFIX = '_cancel_token_hash';
    private const USERMETA_CANCEL_TOKEN_EXPIRES_SUFFIX = '_cancel_token_expires';
    private const ACTION = 'oras_rsvp_update';
    private const CANCEL_ACTION = 'oras_rsvp_cancel_confirm';
    private const VIRTUAL_EMAIL_HEADERS = array( 'Content-Type: text/html; charset=UTF-8' );

    public const APPROVAL_STATUS_PENDING = 'pending';
    public const APPROVAL_STATUS_APPROVED = 'approved';
    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public static function register(): void {
        // Render after tickets display (tickets appended at priority 20).
        add_filter( 'the_content', array( self::class, 'render_rsvp_block' ), 21 );
        add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_post' ) );
        add_action( 'admin_post_' . self::CANCEL_ACTION, array( self::class, 'handle_cancel_confirmation' ) );
        add_action( 'admin_post_nopriv_' . self::CANCEL_ACTION, array( self::class, 'handle_cancel_confirmation' ) );
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

        if ( self::is_cancellation_confirmation_request() ) {
            $cancel_event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : $event_id;
            $cancel_user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
            $cancel_token = isset( $_GET['token'] ) && is_scalar( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
            echo self::render_cancellation_confirmation( $cancel_event_id, $cancel_user_id, $cancel_token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</div>';
            return $content . ob_get_clean();
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
        $contact = self::get_user_contact_defaults( $event_id, $user_id );
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
        $collapse_form = in_array( $status, array( 'yes', 'waitlist' ), true );

        if ( $collapse_form ) {
            echo '<details class="oras-rsvp-details">';
            echo '<summary>' . esc_html__( 'Change RSVP details', 'oras-tickets' ) . '</summary>';
        }

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

        echo '<fieldset class="oras-rsvp-contact">';
        echo '<legend>' . esc_html__( 'Contact Details', 'oras-tickets' ) . '</legend>';
        echo '<p class="description oras-rsvp-description">' . esc_html__( 'These details help ORAS prepare the event roster.', 'oras-tickets' ) . '</p>';
        echo '<label class="oras-rsvp-field">' . esc_html__( 'First Name', 'oras-tickets' ) . '<input type="text" name="rsvp_first_name" value="' . esc_attr( $contact['first_name'] ) . '" autocomplete="given-name" /></label>';
        echo '<label class="oras-rsvp-field">' . esc_html__( 'Last Name', 'oras-tickets' ) . '<input type="text" name="rsvp_last_name" value="' . esc_attr( $contact['last_name'] ) . '" autocomplete="family-name" /></label>';
        echo '<label class="oras-rsvp-field">' . esc_html__( 'Email', 'oras-tickets' ) . '<input type="email" name="rsvp_email" value="' . esc_attr( $contact['email'] ) . '" autocomplete="email" required /></label>';
        echo '<label class="oras-rsvp-field">' . esc_html__( 'Phone', 'oras-tickets' ) . '<input type="tel" name="rsvp_phone" value="' . esc_attr( $contact['phone'] ) . '" autocomplete="tel" /></label>';
        echo '<label class="oras-rsvp-field">' . esc_html__( 'Note', 'oras-tickets' ) . '<textarea name="rsvp_note" rows="3">' . esc_textarea( $contact['note'] ) . '</textarea></label>';
        echo '</fieldset>';

        $rsvp_questions = Event_Questions::filter_questions( Event_Questions::load_definitions( $event_id ), Event_Questions::APPLIES_RSVP, Event_Questions::ATTENDANCE_ALL );
        if ( ! empty( $rsvp_questions ) ) {
            $stored_answers = isset( $contact[ Event_Questions::RSVP_CONTACT_KEY ] ) && is_array( $contact[ Event_Questions::RSVP_CONTACT_KEY ] )
                ? Event_Questions::snapshots_to_answer_values( $contact[ Event_Questions::RSVP_CONTACT_KEY ] )
                : array();
            echo '<fieldset class="oras-rsvp-event-questions">';
            echo '<legend>' . esc_html__( 'Event Questions', 'oras-tickets' ) . '</legend>';
            echo '<p class="description oras-rsvp-description">' . esc_html__( 'Please answer these event-specific questions for ORAS event planning.', 'oras-tickets' ) . '</p>';
            Event_Questions::render_fields( $rsvp_questions, $stored_answers );
            echo '</fieldset>';
        }

        // Buttons
        echo '<p class="oras-rsvp-actions">';
        echo '<button type="submit" name="intent" value="yes" class="oras-rsvp-button oras-rsvp-button-primary">' . esc_html__( 'Submit RSVP', 'oras-tickets' ) . '</button>';
        echo '<button type="submit" name="intent" value="no" class="oras-rsvp-button oras-rsvp-button-secondary" formnovalidate>' . esc_html__( 'Remove RSVP', 'oras-tickets' ) . '</button>';

        $is_full = ( $capacity > 0 && $yes_count >= $capacity );
        if ( $is_full && $waitlist_enabled ) {
            if ( 'waitlist' === $status ) {
                echo '<button type="submit" name="intent" value="leave_waitlist" class="oras-rsvp-button oras-rsvp-button-secondary" formnovalidate>' . esc_html__( 'Leave Waitlist', 'oras-tickets' ) . '</button>';
            } else {
                echo '<button type="submit" name="intent" value="waitlist" class="oras-rsvp-button oras-rsvp-button-secondary">' . esc_html__( 'Join Waitlist', 'oras-tickets' ) . '</button>';
            }
        }

        echo '</p>';
        echo '</form>';

        if ( $collapse_form ) {
            echo '</details>';
        }

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
        $posted_contact = self::get_posted_contact();
        $virtual_email = isset( $_POST['virtual_email'] ) && is_scalar( $_POST['virtual_email'] ) ? sanitize_email( wp_unslash( $_POST['virtual_email'] ) ) : '';
        if ( '' === $virtual_email && '' !== $posted_contact['email'] ) {
            $virtual_email = $posted_contact['email'];
        }
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

        $question_snapshots = array();
        $rsvp_questions = array();
        if ( in_array( $intent, array( 'yes', 'waitlist' ), true ) ) {
            $rsvp_questions = Event_Questions::filter_questions(
                Event_Questions::load_definitions( $event_id ),
                Event_Questions::APPLIES_RSVP,
                $request_attendance_mode
            );
            if ( ! empty( $rsvp_questions ) ) {
                $raw_answers = isset( $_POST['oras_event_question_answers'] ) && is_array( $_POST['oras_event_question_answers'] )
                    ? wp_unslash( $_POST['oras_event_question_answers'] )
                    : array();
                $validation = Event_Questions::validate_answers( $rsvp_questions, is_array( $raw_answers ) ? $raw_answers : array() );
                if ( $validation instanceof \WP_Error ) {
                    if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
                        wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
                    }

                    $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( $validation->get_error_message() ) ), $redirect );
                    wp_safe_redirect( $redirect );
                    exit;
                }

	                $question_snapshots = Event_Questions::build_answer_snapshots( $rsvp_questions, $raw_answers );
            }
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
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVAL_SUFFIX );
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_BY_SUFFIX );
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_AT_SUFFIX );
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_REJECTION_REASON_SUFFIX );
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_HASH_SUFFIX );
                    delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_EXPIRES_SUFFIX );

                    return array(
                        'ok'      => true,
                        'status'  => 'none',
                        'message' => esc_html__( 'RSVP removed.', 'oras-tickets' ),
                        'attendance_mode' => '',
                    );
                }

                update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, $new_status );
                update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_ATTENDANCE_SUFFIX, $attendance_mode );
                $approval_meta_key = self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVAL_SUFFIX;
                $stored_approval_status = get_user_meta( $user_id, $approval_meta_key, true );
                if ( ! is_string( $stored_approval_status ) || '' === $stored_approval_status ) {
                    $default_approval_status = (
                        Ticket::ATTENDANCE_MODE_VIRTUAL === $attendance_mode
                        && Ticket::ATTENDANCE_MODE_VIRTUAL !== $current_attendance_mode
                    )
                        ? self::APPROVAL_STATUS_PENDING
                        : self::APPROVAL_STATUS_APPROVED;
                    update_user_meta( $user_id, $approval_meta_key, $default_approval_status );
                }

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

        if ( in_array( (string) ( $operation['status'] ?? '' ), array( 'yes', 'waitlist' ), true ) ) {
            if ( ! empty( $question_snapshots ) ) {
                $posted_contact[ Event_Questions::RSVP_CONTACT_KEY ] = $question_snapshots;
            } else {
                unset( $posted_contact[ Event_Questions::RSVP_CONTACT_KEY ] );
            }
            update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CONTACT_SUFFIX, $posted_contact );

            if ( ! empty( $question_snapshots ) && ! empty( $rsvp_questions ) ) {
                Event_Question_Attention_Store::upsert_for_answer_snapshots(
                    $event_id,
                    'rsvp',
                    'user:' . $user_id,
                    array(
                        'user_id'       => $user_id,
                        'attendee_name' => trim( (string) ( $posted_contact['first_name'] ?? '' ) . ' ' . (string) ( $posted_contact['last_name'] ?? '' ) ),
                        'email'         => (string) ( $posted_contact['email'] ?? '' ),
                    ),
                    $rsvp_questions,
                    $question_snapshots
                );
            }
        }

        if ( isset( $_POST['oras_ajax'] ) && ! empty( $_POST['oras_ajax'] ) ) {
            if ( 'yes' === (string) ( $operation['status'] ?? '' ) ) {
                $attendance_mode = (string) ( $operation['attendance_mode'] ?? $request_attendance_mode );
                $sent = self::send_rsvp_confirmation_email( $event_id, $virtual_email, $attendance_mode, $user_id );
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
            $sent = self::send_rsvp_confirmation_email( $event_id, $virtual_email, $attendance_mode, $user_id );
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

    public static function handle_cancel_confirmation(): void {
        $event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
        $user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
        $token = isset( $_POST['token'] ) && is_scalar( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $nonce = isset( $_POST['oras_rsvp_cancel_nonce'] ) && is_scalar( $_POST['oras_rsvp_cancel_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['oras_rsvp_cancel_nonce'] ) ) : '';
        $redirect = get_permalink( $event_id );
        if ( ! is_string( $redirect ) || '' === $redirect ) {
            $redirect = home_url();
        }

        if ( $event_id <= 0 || $user_id <= 0 || '' === $token || ! wp_verify_nonce( $nonce, self::get_cancellation_nonce_action( $event_id, $user_id ) ) ) {
            wp_safe_redirect( add_query_arg( 'oras_rsvp_cancelled', 'failed', $redirect ) );
            exit;
        }

        $result = self::cancel_rsvp_with_token( $event_id, $user_id, $token );
        $status = is_wp_error( $result ) ? 'failed' : '1';

        wp_safe_redirect( add_query_arg( 'oras_rsvp_cancelled', $status, $redirect ) );
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_user_contact_defaults( int $event_id, int $user_id ): array {
        $contact = array(
            'first_name' => '',
            'last_name'  => '',
            'email'      => '',
            'phone'      => '',
            'note'       => '',
            Event_Questions::RSVP_CONTACT_KEY => array(),
        );

        if ( $user_id <= 0 ) {
            return $contact;
        }

        $stored = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CONTACT_SUFFIX, true );
        if ( is_array( $stored ) ) {
            foreach ( $contact as $key => $value ) {
                if ( Event_Questions::RSVP_CONTACT_KEY === $key ) {
                    $contact[ $key ] = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();
                    continue;
                }

                if ( isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ) {
                    $contact[ $key ] = 'email' === $key
                        ? sanitize_email( (string) $stored[ $key ] )
                        : sanitize_text_field( (string) $stored[ $key ] );
                }
            }
        }

        $user = get_user_by( 'id', $user_id );
        if ( $user instanceof \WP_User ) {
            if ( '' === $contact['first_name'] ) {
                $contact['first_name'] = sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) );
            }
            if ( '' === $contact['last_name'] ) {
                $contact['last_name'] = sanitize_text_field( (string) get_user_meta( $user_id, 'last_name', true ) );
            }
            if ( '' === $contact['email'] ) {
                $contact['email'] = sanitize_email( (string) $user->user_email );
            }
            if ( '' === $contact['phone'] ) {
                $contact['phone'] = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_phone', true ) );
            }
        }

        return $contact;
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_posted_contact(): array {
        return array(
            'first_name' => isset( $_POST['rsvp_first_name'] ) && is_scalar( $_POST['rsvp_first_name'] )
                ? sanitize_text_field( wp_unslash( $_POST['rsvp_first_name'] ) )
                : '',
            'last_name'  => isset( $_POST['rsvp_last_name'] ) && is_scalar( $_POST['rsvp_last_name'] )
                ? sanitize_text_field( wp_unslash( $_POST['rsvp_last_name'] ) )
                : '',
            'email'      => isset( $_POST['rsvp_email'] ) && is_scalar( $_POST['rsvp_email'] )
                ? sanitize_email( wp_unslash( $_POST['rsvp_email'] ) )
                : '',
            'phone'      => isset( $_POST['rsvp_phone'] ) && is_scalar( $_POST['rsvp_phone'] )
                ? sanitize_text_field( wp_unslash( $_POST['rsvp_phone'] ) )
                : '',
            'note'       => isset( $_POST['rsvp_note'] ) && is_scalar( $_POST['rsvp_note'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['rsvp_note'] ) )
                : '',
        );
    }

    /**
     * @return array{subject:string,body:string,email:string}
     */
    public static function build_rsvp_confirmation_email( int $event_id, string $recipient_email, string $attendance_mode, int $user_id = 0 ): array {
        if ( $event_id <= 0 || '' === $recipient_email || ! is_email( $recipient_email ) ) {
            return array(
                'subject' => '',
                'body'    => '',
                'email'   => '',
            );
        }

        $normalized_mode = Ticket::normalizeAttendanceMode( $attendance_mode, Ticket::ATTENDANCE_MODE_ONSITE );
        $is_virtual = Ticket::ATTENDANCE_MODE_VIRTUAL === $normalized_mode;

        $event_title = self::get_email_event_title( $event_id );

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

        $details = array(
            array(
                'label' => __( 'Event', 'oras-tickets' ),
                'value' => $event_title,
            ),
            array(
                'label' => __( 'Attendance', 'oras-tickets' ),
                'value' => self::get_attendance_mode_label( $normalized_mode ),
            ),
            array(
                'label' => __( 'Date & Time', 'oras-tickets' ),
                'value' => $event_datetime,
            ),
        );
        $sections = array(
            array(
                'title' => __( 'Event Details', 'oras-tickets' ),
                'body'  => $event_details,
            ),
        );
        $notice = '';

        if ( $is_virtual ) {
            if ( $user_id > 0 && self::APPROVAL_STATUS_APPROVED !== self::get_user_approval_status( $event_id, $user_id ) ) {
                $notice = __( 'Your virtual RSVP is pending board approval. The virtual access link will be sent after approval.', 'oras-tickets' );
            } else {
                $details[] = array(
                    'label' => __( 'Virtual access', 'oras-tickets' ),
                    'value' => '' !== $virtual_link ? $virtual_link : __( 'Virtual access link is not currently available. Please contact ORAS support.', 'oras-tickets' ),
                    'url'   => '' !== $virtual_link ? $virtual_link : '',
                );
            }
        } else {
            $details[] = array(
                'label' => __( 'Location', 'oras-tickets' ),
                'value' => $onsite_location,
            );
            $sections[] = array(
                'title' => __( 'Agenda', 'oras-tickets' ),
                'body'  => $agenda_summary,
            );
        }

        $actions = array(
            array(
                'label' => __( 'View Event', 'oras-tickets' ),
                'url'   => $event_url,
            ),
        );

        if ( $user_id > 0 ) {
            $actions[] = array(
                'label' => __( 'Cancel RSVP', 'oras-tickets' ),
                'url'   => self::create_cancellation_url( $event_id, $user_id ),
                'style' => 'secondary',
            );
        }

        return array(
            'subject' => $subject,
            'body'    => self::build_oras_email_template(
                __( 'Your RSVP is confirmed', 'oras-tickets' ),
                __( 'Thank you for your RSVP. ORAS has recorded your attendance details for this event.', 'oras-tickets' ),
                $details,
                $sections,
                $actions,
                $notice,
                __( 'Need to cancel? Use the cancellation button so someone on the waitlist can take your seat.', 'oras-tickets' )
            ),
            'email'   => $recipient_email,
        );
    }

    private static function get_email_event_title( int $event_id ): string {
        $event_title = get_the_title( $event_id );
        if ( ! is_string( $event_title ) || '' === trim( $event_title ) ) {
            return __( 'ORAS Event', 'oras-tickets' );
        }

        return self::decode_email_header_text( $event_title );
    }

    private static function decode_email_header_text( string $text ): string {
        $decoded = wp_specialchars_decode( trim( $text ), ENT_QUOTES );
        $decoded = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $decoded = preg_replace( '/[\r\n\t ]+/', ' ', $decoded );

        return is_string( $decoded ) && '' !== trim( $decoded ) ? trim( $decoded ) : __( 'ORAS Event', 'oras-tickets' );
    }

    /**
     * @param array<int,array{label:string,value:string,url?:string}> $details
     * @param array<int,array{title:string,body:string}>              $sections
     * @param array<int,array{label:string,url:string,style?:string}> $actions
     */
    private static function build_oras_email_template( string $headline, string $intro, array $details, array $sections = array(), array $actions = array(), string $notice = '', string $footer_note = '' ): string {
        $brand = __( 'Oil Region Astronomical Society', 'oras-tickets' );
        $preheader = wp_strip_all_tags( $intro );

        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif;line-height:1.5;">';
        $html .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html( $preheader ) . '</div>';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7;margin:0;padding:28px 12px;"><tr><td align="center">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d8dee8;box-shadow:0 12px 36px rgba(15,23,42,0.12);">';
        $html .= '<tr><td style="background:#0b1220;padding:28px 32px;color:#ffffff;">';
        $html .= '<div style="font-size:28px;font-weight:800;letter-spacing:0.18em;line-height:1;">ORAS</div>';
        $html .= '<div style="font-size:14px;color:#cbd5e1;margin-top:8px;">' . esc_html( $brand ) . '</div>';
        $html .= '</td></tr>';
        $html .= '<tr><td style="padding:32px;">';
        $html .= '<h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;color:#0f172a;">' . esc_html( $headline ) . '</h1>';
        $html .= '<p style="margin:0 0 24px;font-size:16px;color:#334155;">' . esc_html( $intro ) . '</p>';

        if ( '' !== trim( $notice ) ) {
            $html .= '<div style="margin:0 0 24px;padding:16px 18px;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:15px;">' . esc_html( $notice ) . '</div>';
        }

        if ( ! empty( $details ) ) {
            $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">';
            foreach ( $details as $detail ) {
                $label = isset( $detail['label'] ) ? (string) $detail['label'] : '';
                $value = isset( $detail['value'] ) ? (string) $detail['value'] : '';
                $url = isset( $detail['url'] ) ? esc_url( (string) $detail['url'] ) : '';
                if ( '' === trim( $label ) || '' === trim( $value ) ) {
                    continue;
                }

                $html .= '<tr>';
                $html .= '<td style="width:34%;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">' . esc_html( $label ) . '</td>';
                $html .= '<td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:15px;">';
                if ( '' !== $url ) {
                    $html .= '<a href="' . $url . '" style="color:#1d4ed8;text-decoration:underline;word-break:break-word;">' . esc_html( $value ) . '</a>';
                } else {
                    $html .= nl2br( esc_html( $value ) );
                }
                $html .= '</td></tr>';
            }
            $html .= '</table>';
        }

        foreach ( $sections as $section ) {
            $title = isset( $section['title'] ) ? (string) $section['title'] : '';
            $body = isset( $section['body'] ) ? trim( (string) $section['body'] ) : '';
            if ( '' === $title || '' === $body ) {
                continue;
            }

            $html .= '<div style="margin:0 0 22px;padding:18px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;">';
            $html .= '<h2 style="margin:0 0 10px;font-size:16px;color:#0f172a;">' . esc_html( $title ) . '</h2>';
            $html .= '<div style="font-size:15px;color:#334155;">' . nl2br( esc_html( $body ) ) . '</div>';
            $html .= '</div>';
        }

        if ( ! empty( $actions ) ) {
            $html .= '<div style="margin:28px 0 8px;">';
            foreach ( $actions as $action ) {
                $label = isset( $action['label'] ) ? (string) $action['label'] : '';
                $url = isset( $action['url'] ) ? esc_url( (string) $action['url'] ) : '';
                if ( '' === trim( $label ) || '' === $url ) {
                    continue;
                }

                $is_secondary = isset( $action['style'] ) && 'secondary' === $action['style'];
                $background = $is_secondary ? '#e2e8f0' : '#1e3a8a';
                $color = $is_secondary ? '#0f172a' : '#ffffff';
                $html .= '<a href="' . $url . '" style="display:inline-block;margin:0 10px 10px 0;padding:13px 18px;border-radius:10px;background:' . esc_attr( $background ) . ';color:' . esc_attr( $color ) . ';font-size:15px;font-weight:700;text-decoration:none;">' . esc_html( $label ) . '</a>';
            }
            $html .= '</div>';
        }

        if ( '' !== trim( $footer_note ) ) {
            $html .= '<p style="margin:22px 0 0;font-size:13px;color:#64748b;">' . esc_html( $footer_note ) . '</p>';
        }

        $html .= '</td></tr>';
        $html .= '<tr><td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;">';
        $html .= esc_html__( 'This message was sent by Oil Region Astronomical Society regarding an ORAS event.', 'oras-tickets' );
        $html .= '</td></tr>';
        $html .= '</table></td></tr></table></body></html>';

        return $html;
    }

    private static function send_rsvp_confirmation_email( int $event_id, string $recipient_email, string $attendance_mode, int $user_id = 0 ): bool {
        $email = self::build_rsvp_confirmation_email( $event_id, $recipient_email, $attendance_mode, $user_id );
        if ( '' === $email['email'] || '' === $email['subject'] ) {
            return false;
        }

        return (bool) wp_mail(
            $email['email'],
            $email['subject'],
            $email['body'],
            self::VIRTUAL_EMAIL_HEADERS
        );
    }

    public static function create_cancellation_url( int $event_id, int $user_id ): string {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return '';
        }

        $token = wp_generate_password( 48, false, false );
        update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_HASH_SUFFIX, wp_hash( $token ) );
        update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_EXPIRES_SUFFIX, time() + YEAR_IN_SECONDS );

        $event_url = get_permalink( $event_id );
        if ( ! is_string( $event_url ) || '' === $event_url ) {
            $event_url = home_url();
        }

        return add_query_arg(
            array(
                'oras_rsvp_cancel' => '1',
                'event_id'          => $event_id,
                'user_id'           => $user_id,
                'token'             => $token,
            ),
            $event_url
        );
    }

    public static function validate_cancellation_token( int $event_id, int $user_id, string $token ): bool {
        if ( $event_id <= 0 || $user_id <= 0 || '' === trim( $token ) ) {
            return false;
        }

        $stored_hash = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_HASH_SUFFIX, true );
        $expires = absint( get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_EXPIRES_SUFFIX, true ) );
        if ( ! is_string( $stored_hash ) || '' === $stored_hash || $expires < time() ) {
            return false;
        }

        $status = self::get_user_status( $event_id, $user_id );
        if ( ! in_array( $status, array( 'yes', 'waitlist' ), true ) ) {
            return false;
        }

        return hash_equals( $stored_hash, wp_hash( $token ) );
    }

    public static function render_cancellation_confirmation( int $event_id, int $user_id, string $token ): string {
        $event = get_post( $event_id );
        if ( ! $event instanceof \WP_Post || 'tribe_events' !== $event->post_type || ! self::validate_cancellation_token( $event_id, $user_id, $token ) ) {
            return '<div class="oras-rsvp-notice oras-rsvp-notice-error">' . esc_html__( 'This RSVP cancellation link is invalid or has expired.', 'oras-tickets' ) . '</div>';
        }

        $attendance_mode = self::get_user_attendance_type_for_report( $event_id, $user_id );

        ob_start();
        ?>
        <div class="oras-rsvp-cancel-confirmation">
            <h3><?php echo esc_html__( 'Cancel RSVP', 'oras-tickets' ); ?></h3>
            <p><?php echo esc_html__( 'Please confirm that you want to cancel this RSVP. If the event has a waitlist, ORAS may offer the opened seat to the next waitlisted attendee.', 'oras-tickets' ); ?></p>
            <p><strong><?php echo esc_html__( 'Event:', 'oras-tickets' ); ?></strong> <?php echo esc_html( get_the_title( $event_id ) ); ?></p>
            <p><strong><?php echo esc_html__( 'Attendance:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::get_attendance_mode_label( $attendance_mode ) ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::get_cancellation_nonce_action( $event_id, $user_id ), 'oras_rsvp_cancel_nonce' ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::CANCEL_ACTION ); ?>" />
                <input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
                <button type="submit" class="oras-rsvp-button oras-rsvp-button-secondary"><?php echo esc_html__( 'Cancel RSVP', 'oras-tickets' ); ?></button>
            </form>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public static function cancel_rsvp_with_token( int $event_id, int $user_id, string $token ) {
        if ( ! self::validate_cancellation_token( $event_id, $user_id, $token ) ) {
            return new \WP_Error( 'oras_rsvp_cancel_invalid_token', __( 'Invalid or expired cancellation link.', 'oras-tickets' ) );
        }

        return self::cancel_rsvp_attendee( $event_id, $user_id, 0, 'frontend-cancel', true );
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public static function cancel_rsvp_attendee( int $event_id, int $user_id, int $actor_user_id = 0, string $source = 'system', bool $auto_promote = true ) {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return new \WP_Error( 'oras_rsvp_cancel_invalid', __( 'Invalid event or attendee.', 'oras-tickets' ) );
        }

        $result = DbLock::forEvent(
            $event_id,
            static function () use ( $event_id, $user_id, $actor_user_id, $source, $auto_promote ): array {
                $previous_status = self::get_user_status( $event_id, $user_id );
                $previous_mode = self::get_user_attendance_type_for_report( $event_id, $user_id );
                $waitlist_status = Waitlist_Store::get_current_waitlist_status( $event_id, $user_id );

                if ( ! in_array( $previous_status, array( 'yes', 'waitlist' ), true ) && 'waiting' !== $waitlist_status ) {
                    return array( 'error' => __( 'No active RSVP or waitlist row exists for this attendee.', 'oras-tickets' ) );
                }

                if ( 'waiting' === $waitlist_status || 'waitlist' === $previous_status ) {
                    Waitlist_Store::mark_left( $event_id, $user_id, $source, $actor_user_id );
                }

                update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, 'no' );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . '_ts' );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_ATTENDANCE_SUFFIX );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVAL_SUFFIX );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_BY_SUFFIX );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_AT_SUFFIX );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_REJECTION_REASON_SUFFIX );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_HASH_SUFFIX );
                delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_CANCEL_TOKEN_EXPIRES_SUFFIX );

                self::log_rsvp_lifecycle_message(
                    $event_id,
                    $actor_user_id,
                    $user_id,
                    'rsvp_cancelled',
                    __( 'RSVP cancelled', 'oras-tickets' ),
                    sprintf(
                        /* translators: 1: user ID, 2: previous status, 3: attendance mode */
                        __( 'User #%1$d cancelled or was removed from RSVP status %2$s for %3$s attendance.', 'oras-tickets' ),
                        $user_id,
                        $previous_status,
                        $previous_mode
                    ),
                    true
                );

                $promoted_user_id = 0;
                if ( $auto_promote && 'yes' === $previous_status ) {
                    $promoted = self::promote_next_waitlisted_attendee_unlocked( $event_id, $previous_mode, $actor_user_id, 'waitlist-auto-promote' );
	                    if ( empty( $promoted['error'] ) ) {
                        $promoted_user_id = absint( $promoted['user_id'] ?? 0 );
                    }
                }

                return array(
                    'cancelled'                => true,
                    'user_id'                  => $user_id,
                    'previous_status'          => $previous_status,
                    'previous_attendance_mode' => $previous_mode,
                    'promoted_user_id'         => $promoted_user_id,
                );
            }
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $result['error'] ) ) {
            return new \WP_Error( 'oras_rsvp_cancel_failed', (string) $result['error'] );
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public static function promote_waitlist_user( int $event_id, int $user_id, int $actor_user_id = 0, string $source = 'board-waitlist', bool $require_capacity = true ) {
        $result = DbLock::forEvent(
            $event_id,
            static function () use ( $event_id, $user_id, $actor_user_id, $source, $require_capacity ): array {
                return self::promote_waitlist_user_unlocked( $event_id, $user_id, $actor_user_id, $source, $require_capacity );
            }
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $result['error'] ) ) {
            return new \WP_Error( 'oras_waitlist_promote_failed', (string) $result['error'] );
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public static function open_seat_and_promote_waitlist_user( int $event_id, int $user_id, int $actor_user_id = 0, string $source = 'board-open-seat' ) {
        $result = DbLock::forEvent(
            $event_id,
            static function () use ( $event_id, $user_id, $actor_user_id, $source ): array {
                $meta = get_post_meta( $event_id, self::META_KEY, true );
                if ( ! is_array( $meta ) ) {
                    return array( 'error' => __( 'RSVP settings are missing for this event.', 'oras-tickets' ) );
                }

                $capacity = absint( $meta['capacity'] ?? 0 );
                if ( $capacity > 0 ) {
                    $meta['capacity'] = $capacity + 1;
                    update_post_meta( $event_id, self::META_KEY, $meta );
                }

                return self::promote_waitlist_user_unlocked( $event_id, $user_id, $actor_user_id, $source, false );
            }
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $result['error'] ) ) {
            return new \WP_Error( 'oras_waitlist_open_promote_failed', (string) $result['error'] );
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public static function promote_next_waitlisted_attendee( int $event_id, string $preferred_attendance_mode = '', int $actor_user_id = 0, string $source = 'waitlist-auto-promote' ) {
        $result = DbLock::forEvent(
            $event_id,
            static function () use ( $event_id, $preferred_attendance_mode, $actor_user_id, $source ): array {
                return self::promote_next_waitlisted_attendee_unlocked( $event_id, $preferred_attendance_mode, $actor_user_id, $source );
            }
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $result['error'] ) ) {
            return new \WP_Error( 'oras_waitlist_auto_promote_failed', (string) $result['error'] );
        }

        return $result;
    }

    private static function is_cancellation_confirmation_request(): bool {
        return isset( $_GET['oras_rsvp_cancel'] ) && '1' === (string) wp_unslash( $_GET['oras_rsvp_cancel'] );
    }

    private static function get_cancellation_nonce_action( int $event_id, int $user_id ): string {
        return 'oras_rsvp_cancel_' . max( 0, $event_id ) . '_' . max( 0, $user_id );
    }

    /**
     * @return array<string,mixed>
     */
    private static function promote_next_waitlisted_attendee_unlocked( int $event_id, string $preferred_attendance_mode = '', int $actor_user_id = 0, string $source = 'waitlist-auto-promote' ): array {
        if ( $event_id <= 0 ) {
            return array( 'error' => __( 'Invalid event.', 'oras-tickets' ) );
        }

        if ( ! self::has_capacity_available( $event_id ) ) {
            return array( 'error' => __( 'No capacity available for promotion.', 'oras-tickets' ) );
        }

        $rows = Waitlist_Store::get_event_rows( $event_id, array( 'waiting' ), 250, 'joined_asc' );
        if ( empty( $rows ) ) {
            return array( 'error' => __( 'No users are waiting.', 'oras-tickets' ) );
        }

        $preferred_mode = Ticket::normalizeAttendanceMode( $preferred_attendance_mode, '' );
        $fallback_user_id = 0;
        $matched_user_id = 0;

        foreach ( $rows as $row ) {
            $candidate_user_id = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
            if ( $candidate_user_id <= 0 ) {
                continue;
            }

            if ( 0 === $fallback_user_id ) {
                $fallback_user_id = $candidate_user_id;
            }

            if ( '' !== $preferred_mode && self::get_user_attendance_type_for_report( $event_id, $candidate_user_id ) === $preferred_mode ) {
                $matched_user_id = $candidate_user_id;
                break;
            }
        }

        $selected_user_id = $matched_user_id > 0 ? $matched_user_id : $fallback_user_id;
        if ( $selected_user_id <= 0 ) {
            return array( 'error' => __( 'No eligible waitlist user was found.', 'oras-tickets' ) );
        }

        return self::promote_waitlist_user_unlocked( $event_id, $selected_user_id, $actor_user_id, $source, true );
    }

    /**
     * @return array<string,mixed>
     */
    private static function promote_waitlist_user_unlocked( int $event_id, int $user_id, int $actor_user_id = 0, string $source = 'board-waitlist', bool $require_capacity = true ): array {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return array( 'error' => __( 'Invalid event or waitlist user.', 'oras-tickets' ) );
        }

        $event = get_post( $event_id );
        if ( ! $event instanceof \WP_Post || 'tribe_events' !== $event->post_type ) {
            return array( 'error' => __( 'Invalid event.', 'oras-tickets' ) );
        }

        if ( 'waiting' !== Waitlist_Store::get_current_waitlist_status( $event_id, $user_id ) ) {
            return array( 'error' => __( 'Selected user is not currently on the waitlist.', 'oras-tickets' ) );
        }

        if ( $require_capacity && ! self::has_capacity_available( $event_id ) ) {
            return array( 'error' => __( 'Event is already at capacity.', 'oras-tickets' ) );
        }

        if ( ! Waitlist_Store::promote_user( $event_id, $user_id, $actor_user_id, $source ) ) {
            return array( 'error' => __( 'Unable to promote selected user.', 'oras-tickets' ) );
        }

        update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, 'yes' );
        delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . '_ts' );

        $email_sent = self::send_waitlist_promotion_email( $event_id, $user_id );
        self::log_rsvp_lifecycle_message(
            $event_id,
            $actor_user_id,
            $user_id,
			false !== strpos( $source, 'auto' ) ? 'waitlist_auto_promoted' : 'waitlist_manual_promoted',
            __( 'Moved from waitlist to RSVP confirmed', 'oras-tickets' ),
            sprintf(
                /* translators: 1: user ID, 2: attendance mode */
                __( 'User #%1$d was moved from waitlist to confirmed RSVP for %2$s attendance.', 'oras-tickets' ),
                $user_id,
                self::get_user_attendance_type_for_report( $event_id, $user_id )
            ),
            $email_sent
        );

        return array(
            'promoted'   => true,
            'user_id'    => $user_id,
            'event_id'   => $event_id,
            'email_sent' => $email_sent,
        );
    }

    private static function has_capacity_available( int $event_id ): bool {
        $meta = get_post_meta( $event_id, self::META_KEY, true );
        if ( ! is_array( $meta ) ) {
            return false;
        }

        $capacity = absint( $meta['capacity'] ?? 0 );
        if ( 0 === $capacity ) {
            return true;
        }

        return self::yes_count( $event_id ) < $capacity;
    }

    private static function send_waitlist_promotion_email( int $event_id, int $user_id ): bool {
        $contact = self::get_user_contact_defaults( $event_id, $user_id );
        $recipient_email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
        if ( '' === $recipient_email || ! is_email( $recipient_email ) ) {
            return false;
        }

        $email = self::build_waitlist_promotion_email( $event_id, $user_id, $recipient_email );

        return (bool) wp_mail( $recipient_email, $email['subject'], $email['body'], self::VIRTUAL_EMAIL_HEADERS );
    }

    /**
     * @return array{subject:string,body:string}
     */
    private static function build_waitlist_promotion_email( int $event_id, int $user_id, string $recipient_email ): array {
        $event_title = self::get_email_event_title( $event_id );

        $event_url = get_permalink( $event_id );
        if ( ! is_string( $event_url ) || '' === $event_url ) {
            $event_url = home_url();
        }

        $attendance_mode = self::get_user_attendance_type_for_report( $event_id, $user_id );
        $subject = sprintf(
            /* translators: %s: event title */
            __( 'You were moved from the waitlist for %s', 'oras-tickets' ),
            $event_title
        );
        $details = array(
            array(
                'label' => __( 'Event', 'oras-tickets' ),
                'value' => $event_title,
            ),
            array(
                'label' => __( 'Attendance', 'oras-tickets' ),
                'value' => self::get_attendance_mode_label( $attendance_mode ),
            ),
            array(
                'label' => __( 'Date & Time', 'oras-tickets' ),
                'value' => self::get_event_datetime_text( $event_id ),
            ),
        );
        $notice = '';

        if ( Ticket::ATTENDANCE_MODE_VIRTUAL === $attendance_mode ) {
            if ( self::APPROVAL_STATUS_APPROVED === self::get_user_approval_status( $event_id, $user_id ) ) {
                $virtual_link = self::get_virtual_join_link( $event_id );
                $details[] = array(
                    'label' => __( 'Virtual access', 'oras-tickets' ),
                    'value' => $virtual_link ?: __( 'Virtual access link is not currently available. Please contact ORAS support.', 'oras-tickets' ),
                    'url'   => $virtual_link,
                );
            } else {
                $notice = __( 'Your virtual RSVP is pending board approval. The virtual access link will be sent after approval.', 'oras-tickets' );
            }
        }

        $actions = array(
            array(
                'label' => __( 'View Event', 'oras-tickets' ),
                'url'   => $event_url,
            ),
            array(
                'label' => __( 'Cancel RSVP', 'oras-tickets' ),
                'url'   => self::create_cancellation_url( $event_id, $user_id ),
                'style' => 'secondary',
            ),
        );

        return array(
            'subject' => $subject,
            'body'    => self::build_oras_email_template(
                __( 'You are confirmed from the waitlist', 'oras-tickets' ),
                __( 'A seat opened up and your RSVP has been moved from the waitlist to confirmed.', 'oras-tickets' ),
                $details,
                array(),
                $actions,
                $notice,
                __( 'Need to cancel? Use the cancellation button so someone else on the waitlist can take your seat.', 'oras-tickets' )
            ),
        );
    }

    private static function log_rsvp_lifecycle_message( int $event_id, int $actor_user_id, int $target_user_id, string $related_action_type, string $subject, string $body, bool $email_sent ): void {
        $sender = $actor_user_id > 0 ? get_user_by( 'id', $actor_user_id ) : false;
        $target = $target_user_id > 0 ? get_user_by( 'id', $target_user_id ) : false;
        $target_email = $target instanceof \WP_User ? sanitize_email( (string) $target->user_email ) : '';
        $recipient_count = '' !== $target_email && is_email( $target_email ) ? 1 : 0;

        Communication_Log_Store::insert(
            array(
                'event_id'               => $event_id,
                'sender_user_id'         => $sender instanceof \WP_User ? (int) $sender->ID : 0,
                'sender_display_name'    => $sender instanceof \WP_User ? (string) $sender->display_name : __( 'System', 'oras-tickets' ),
                'sender_email'           => $sender instanceof \WP_User ? (string) $sender->user_email : '',
                'recipient_segment'      => $related_action_type,
                'recipient_count'        => $recipient_count,
                'email_subject'          => $subject,
                'email_body_snapshot'    => $body,
                'sent_at'                => current_time( 'mysql', true ),
                'send_status'            => $email_sent ? 'sent' : 'failed',
                'failed_recipient_count' => $email_sent ? 0 : $recipient_count,
                'related_action_type'    => $related_action_type,
            )
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
                        return '' !== trim( $value );
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

    public static function get_virtual_join_link( int $event_id ): string {
        $candidate_keys = array(
            '_tribe_events_zoom_join_url',
            '_EventZoomJoinURL',
            '_EventZoomMeetingLink',
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

            $url = self::normalize_virtual_join_url( $value );
            if ( '' !== $url ) {
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

                $url = self::normalize_virtual_join_url( $value );
                if ( '' !== $url ) {
                    return $url;
                }
            }
        }

        return '';
    }

    private static function normalize_virtual_join_url( string $value ): string {
        $raw = trim( $value );
        if ( '' === $raw || ! filter_var( $raw, FILTER_VALIDATE_URL ) ) {
            return '';
        }

        $parts = wp_parse_url( $raw );
        $scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        $host = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
            return '';
        }

        if ( false === strpos( $host, '.' ) && 'localhost' !== $host ) {
            return '';
        }

        return esc_url_raw( $raw );
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

    public static function get_user_attendance_type_for_report( int $event_id, int $user_id ): string {
        $mode = self::get_user_attendance_mode( $event_id, $user_id );

        return '' !== $mode ? $mode : Ticket::ATTENDANCE_MODE_ONSITE;
    }

    /**
     * @return string[]
     */
    public static function get_approval_statuses(): array {
        return array(
            self::APPROVAL_STATUS_PENDING,
            self::APPROVAL_STATUS_APPROVED,
            self::APPROVAL_STATUS_REJECTED,
        );
    }

    public static function normalize_approval_status( string $status, string $default = self::APPROVAL_STATUS_APPROVED ): string {
        $normalized = sanitize_key( $status );

        if ( in_array( $normalized, self::get_approval_statuses(), true ) ) {
            return $normalized;
        }

        return in_array( $default, self::get_approval_statuses(), true ) ? $default : self::APPROVAL_STATUS_APPROVED;
    }

    public static function get_user_approval_status( int $event_id, int $user_id ): string {
        if ( $user_id <= 0 ) {
            return self::APPROVAL_STATUS_APPROVED;
        }

        $status = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVAL_SUFFIX, true );

        return is_string( $status ) && '' !== $status
            ? self::normalize_approval_status( $status, self::APPROVAL_STATUS_APPROVED )
            : self::APPROVAL_STATUS_APPROVED;
    }

    public static function get_user_approved_by( int $event_id, int $user_id ): int {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return 0;
        }

        return absint( get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_BY_SUFFIX, true ) );
    }

    public static function get_user_approved_at( int $event_id, int $user_id ): string {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return '';
        }

        $approved_at = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_AT_SUFFIX, true );

        return is_string( $approved_at ) ? $approved_at : '';
    }

    public static function get_user_rejection_reason( int $event_id, int $user_id ): string {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return '';
        }

        $reason = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_REJECTION_REASON_SUFFIX, true );

        return is_string( $reason ) ? $reason : '';
    }

    public static function get_user_approved_by_display( int $event_id, int $user_id ): string {
        $approved_by = self::get_user_approved_by( $event_id, $user_id );
        if ( $approved_by <= 0 ) {
            return '';
        }

        $user = get_user_by( 'id', $approved_by );
        if ( $user instanceof \WP_User ) {
            return (string) $user->display_name;
        }

        return (string) $approved_by;
    }

    /**
     * @return true|\WP_Error
     */
    public static function update_approval_status( int $event_id, int $user_id, string $approval_status, string $rejection_reason = '' ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_manage_rsvps' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
            return new \WP_Error( 'oras_rsvp_approval_forbidden', __( 'You do not have permission to manage RSVPs.', 'oras-tickets' ) );
        }

        $event = get_post( $event_id );
        if ( ! $event instanceof \WP_Post || 'tribe_events' !== $event->post_type ) {
            return new \WP_Error( 'oras_rsvp_approval_invalid_event', __( 'Invalid event.', 'oras-tickets' ) );
        }

        $attendee = get_user_by( 'id', $user_id );
        if ( ! $attendee instanceof \WP_User ) {
            return new \WP_Error( 'oras_rsvp_approval_invalid_user', __( 'Invalid RSVP attendee.', 'oras-tickets' ) );
        }

        if ( '' === self::get_user_status( $event_id, $user_id ) ) {
            return new \WP_Error( 'oras_rsvp_approval_missing_rsvp', __( 'No RSVP record exists for this attendee.', 'oras-tickets' ) );
        }

        $normalized_status = sanitize_key( $approval_status );
        if ( ! in_array( $normalized_status, self::get_approval_statuses(), true ) ) {
            return new \WP_Error( 'oras_rsvp_approval_invalid_status', __( 'Invalid approval status.', 'oras-tickets' ) );
        }

        $approver = wp_get_current_user();
        $approver_id = get_current_user_id();
        $approved_at = current_time( 'mysql', true );
        $reason = sanitize_textarea_field( $rejection_reason );

        update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVAL_SUFFIX, $normalized_status );
        update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_BY_SUFFIX, $approver_id );
        update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_APPROVED_AT_SUFFIX, $approved_at );

        if ( self::APPROVAL_STATUS_REJECTED === $normalized_status && '' !== $reason ) {
            update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_REJECTION_REASON_SUFFIX, $reason );
        } elseif ( self::APPROVAL_STATUS_REJECTED !== $normalized_status ) {
            delete_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . self::USERMETA_REJECTION_REASON_SUFFIX );
        }

        $email_sent = self::send_virtual_approval_status_email( $event_id, $user_id, $normalized_status, $reason );
        self::log_approval_status_change( $event_id, $user_id, $normalized_status, $reason, $approver, $email_sent );

        return true;
    }

    /**
     * @return array{subject:string,body:string,email:string}
     */
    public static function build_virtual_approval_email( int $event_id, int $user_id, string $approval_status, string $rejection_reason = '' ): array {
        $event_title = self::get_email_event_title( $event_id );

        $contact = self::get_user_contact_defaults( $event_id, $user_id );
        $recipient_email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
        $normalized_status = self::normalize_approval_status( $approval_status, self::APPROVAL_STATUS_PENDING );
        $event_url = get_permalink( $event_id );
        if ( ! is_string( $event_url ) || '' === $event_url ) {
            $event_url = home_url();
        }

        if ( self::APPROVAL_STATUS_APPROVED === $normalized_status ) {
            $subject = sprintf(
                /* translators: %s: event title */
                __( 'Your virtual RSVP was approved for %s', 'oras-tickets' ),
                $event_title
            );
            $virtual_link = self::get_virtual_join_link( $event_id );
            $details = array(
                array(
                    'label' => __( 'Event', 'oras-tickets' ),
                    'value' => $event_title,
                ),
                array(
                    'label' => __( 'Date & Time', 'oras-tickets' ),
                    'value' => self::get_event_datetime_text( $event_id ),
                ),
                array(
                    'label' => __( 'Virtual access', 'oras-tickets' ),
                    'value' => $virtual_link ?: __( 'Virtual access link is not currently available. Please contact ORAS support.', 'oras-tickets' ),
                    'url'   => $virtual_link,
                ),
            );
            $actions = array(
                array(
                    'label' => __( 'Join Virtual Event', 'oras-tickets' ),
                    'url'   => $virtual_link,
                ),
                array(
                    'label' => __( 'View Event', 'oras-tickets' ),
                    'url'   => $event_url,
                    'style' => 'secondary',
                ),
            );
            $body = self::build_oras_email_template(
                __( 'Virtual RSVP approved', 'oras-tickets' ),
                __( 'Your virtual RSVP has been approved. Use the virtual access button below when it is time to join the event.', 'oras-tickets' ),
                $details,
                array(),
                $actions
            );
        } elseif ( self::APPROVAL_STATUS_REJECTED === $normalized_status ) {
            $subject = sprintf(
                /* translators: %s: event title */
                __( 'Your virtual RSVP was not approved for %s', 'oras-tickets' ),
                $event_title
            );
            $details = array(
                array(
                    'label' => __( 'Event', 'oras-tickets' ),
                    'value' => $event_title,
                ),
            );
            $sections = array();
            $reason = trim( sanitize_textarea_field( $rejection_reason ) );
            if ( '' !== $reason ) {
                $sections[] = array(
                    'title' => __( 'Reason', 'oras-tickets' ),
                    'body'  => $reason,
                );
            }
            $body = self::build_oras_email_template(
                __( 'Virtual RSVP not approved', 'oras-tickets' ),
                __( 'Your virtual RSVP was not approved. The virtual access link is not included in this message.', 'oras-tickets' ),
                $details,
                $sections,
                array(
                    array(
                        'label' => __( 'View Event', 'oras-tickets' ),
                        'url'   => $event_url,
                    ),
                )
            );
        } else {
            $subject = sprintf(
                /* translators: %s: event title */
                __( 'Your virtual RSVP is pending for %s', 'oras-tickets' ),
                $event_title
            );
            $body = self::build_oras_email_template(
                __( 'Virtual RSVP pending review', 'oras-tickets' ),
                __( 'Your virtual RSVP has been returned to pending review. The virtual access link will be sent only if the RSVP is approved.', 'oras-tickets' ),
                array(
                    array(
                        'label' => __( 'Event', 'oras-tickets' ),
                        'value' => $event_title,
                    ),
                ),
                array(),
                array(
                    array(
                        'label' => __( 'View Event', 'oras-tickets' ),
                        'url'   => $event_url,
                    ),
                )
            );
        }

        return array(
            'subject' => $subject,
            'body'    => $body,
            'email'   => $recipient_email,
        );
    }

    private static function send_virtual_approval_status_email( int $event_id, int $user_id, string $approval_status, string $rejection_reason = '' ): bool {
        $attendance_mode = self::get_user_attendance_type_for_report( $event_id, $user_id );
        if ( Ticket::ATTENDANCE_MODE_VIRTUAL !== $attendance_mode ) {
            return true;
        }

        $email = self::build_virtual_approval_email( $event_id, $user_id, $approval_status, $rejection_reason );
        if ( '' === $email['email'] || ! is_email( $email['email'] ) ) {
            return false;
        }

        return (bool) wp_mail( $email['email'], $email['subject'], $email['body'], self::VIRTUAL_EMAIL_HEADERS );
    }

    private static function log_approval_status_change( int $event_id, int $user_id, string $approval_status, string $rejection_reason, \WP_User $approver, bool $email_sent ): void {
        $attendance_mode = self::get_user_attendance_type_for_report( $event_id, $user_id );
        if ( Ticket::ATTENDANCE_MODE_VIRTUAL !== $attendance_mode ) {
            return;
        }

        $email = self::build_virtual_approval_email( $event_id, $user_id, $approval_status, $rejection_reason );
        $related_action_type = 'virtual_rsvp_' . $approval_status;
        $recipient_count = '' !== $email['email'] && is_email( $email['email'] ) ? 1 : 0;

        Communication_Log_Store::insert(
            array(
                'event_id'               => $event_id,
                'sender_user_id'         => (int) $approver->ID,
                'sender_display_name'    => (string) $approver->display_name,
                'sender_email'           => (string) $approver->user_email,
                'recipient_segment'      => 'virtual_rsvp_' . $approval_status,
                'recipient_count'        => $recipient_count,
                'email_subject'          => $email['subject'],
                'email_body_snapshot'    => $email['body'],
                'sent_at'                => current_time( 'mysql', true ),
                'send_status'            => $email_sent ? 'sent' : 'failed',
                'failed_recipient_count' => $email_sent ? 0 : $recipient_count,
                'related_action_type'    => $related_action_type,
            )
        );
    }

    public static function get_approval_status_label( string $approval_status ): string {
        switch ( self::normalize_approval_status( $approval_status, self::APPROVAL_STATUS_APPROVED ) ) {
            case self::APPROVAL_STATUS_PENDING:
                return __( 'Pending', 'oras-tickets' );

            case self::APPROVAL_STATUS_REJECTED:
                return __( 'Rejected', 'oras-tickets' );

            case self::APPROVAL_STATUS_APPROVED:
            default:
                return __( 'Approved', 'oras-tickets' );
        }
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
