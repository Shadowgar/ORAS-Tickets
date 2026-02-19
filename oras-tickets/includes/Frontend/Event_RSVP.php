<?php

namespace ORAS\Tickets\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_RSVP { // NOSONAR legacy WP class naming

    private const META_KEY = '_oras_rsvp_v1';
    private const USERMETA_PREFIX = '_oras_rsvp_event_';
    private const ACTION = 'oras_rsvp_update';

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

        ob_start();

        echo '<div class="oras-rsvp-block">';

        // Show status messages from redirect
        $flash = '';
        $oras_rsvp = isset( $_GET['oras_rsvp'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_rsvp'] ) ) : '';
        $oras_msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
        if ( 'updated' === $oras_rsvp ) {
            $flash = '<div class="oras-rsvp-notice oras-rsvp-notice-success">' . esc_html__( 'Your RSVP was updated.', 'oras-tickets' ) . '</div>';
        } elseif ( 'error' === $oras_rsvp ) {
            $text = 'error' === $oras_msg ? esc_html__( 'Unable to update RSVP.', 'oras-tickets' ) : esc_html( $oras_msg );
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
        $yes_count = self::yes_count( $event_id );

        if ( 'yes' === $status ) {
            echo '<p class="oras-rsvp-status oras-rsvp-status-yes"><strong>' . esc_html__( "You have RSVP'ed to the event. If you wish to revoke the RSVP click \"RSVP No\".", 'oras-tickets' ) . '</strong></p>';
        } elseif ( 'waitlist' === $status ) {
            echo '<p class="oras-rsvp-status oras-rsvp-status-waitlist"><strong>' . esc_html__( 'You are on the waitlist for this event.', 'oras-tickets' ) . '</strong></p>';
        } elseif ( 'no' === $status ) {
            echo '<p class="oras-rsvp-status oras-rsvp-status-no">' . esc_html__( 'You are not attending this event.', 'oras-tickets' ) . '</p>';
        } else {
            echo '<p class="oras-rsvp-status oras-rsvp-status-none">' . esc_html__( 'You have not responded to this event yet.', 'oras-tickets' ) . '</p>';
        }

        if ( 0 === $capacity ) {
            printf( '<p>%s: <strong>%s</strong></p>', esc_html__( 'Capacity', 'oras-tickets' ), esc_html__( 'Unlimited', 'oras-tickets' ) );
        } else {
            printf( '<p>%s: <strong>%d</strong> / <strong>%d</strong></p>', esc_html__( 'Attending', 'oras-tickets' ), absint( $yes_count ), absint( $capacity ) );
        }

        // Form
        $nonce = wp_create_nonce( 'oras_rsvp_' . $event_id );
        $action_url = admin_url( 'admin-post.php' );

        echo '<form method="post" action="' . esc_url( $action_url ) . '">';
        printf( '<input type="hidden" name="action" value="%s"/>', esc_attr( self::ACTION ) );
        printf( '<input type="hidden" name="event_id" value="%d"/>', esc_attr( $event_id ) );
        printf( '<input type="hidden" name="oras_rsvp_nonce" value="%s"/>', esc_attr( $nonce ) );

        // Buttons
        echo '<p>';
        echo '<button type="submit" name="intent" value="yes">' . esc_html__( 'RSVP Yes', 'oras-tickets' ) . '</button> ';
        echo '<button type="submit" name="intent" value="no">' . esc_html__( 'RSVP No', 'oras-tickets' ) . '</button> ';

        $is_full = ( $capacity > 0 && $yes_count >= $capacity );
        if ( $is_full && $waitlist_enabled ) {
            if ( 'waitlist' === $status ) {
                echo '<button type="submit" name="intent" value="leave_waitlist">' . esc_html__( 'Leave Waitlist', 'oras-tickets' ) . '</button> ';
            } else {
                echo '<button type="submit" name="intent" value="waitlist">' . esc_html__( 'Join Waitlist', 'oras-tickets' ) . '</button> ';
            }
        }

        echo '</p>';
        echo '</form>';

        echo '</div>';

        return $content . ob_get_clean();
    }

    public static function handle_post(): void {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_get_referer() ?: home_url() );
            exit;
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
        $nonce = isset( $_POST['oras_rsvp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['oras_rsvp_nonce'] ) ) : '';
        $intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';

        $redirect = get_permalink( $event_id ) ?: home_url();

        if ( $event_id <= 0 || ! wp_verify_nonce( $nonce, 'oras_rsvp_' . $event_id ) ) {
            $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'invalid' ) ), $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        $user_id = get_current_user_id();
        $meta = get_post_meta( $event_id, self::META_KEY, true );
        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        $capacity = absint( $meta['capacity'] ?? 0 );
        $waitlist_enabled = ! empty( $meta['waitlist_enabled'] );

        $current = self::get_user_status( $event_id, $user_id );
        $yes_count = self::yes_count( $event_id );

        $new_status = $current;
        $error = false;

        switch ( $intent ) {
            case 'yes':
                if ( 'yes' === $current ) {
                    // no-op
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

        if ( ! $error ) {
            update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, $new_status );
            if ( $new_status === 'waitlist' ) {
                update_user_meta( $user_id, self::USERMETA_PREFIX . $event_id . '_ts', time() );
            }
            $redirect = add_query_arg( array( 'oras_rsvp' => 'updated' ), $redirect );
        } else {
            $redirect = add_query_arg( array( 'oras_rsvp' => 'error', 'msg' => rawurlencode( 'capacity' ) ), $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    public static function get_user_status( int $event_id, int $user_id ): string {
        if ( $user_id <= 0 ) {
            return '';
        }

        $val = get_user_meta( $user_id, self::USERMETA_PREFIX . $event_id, true );
        if ( in_array( $val, array( 'yes', 'no', 'waitlist' ), true ) ) {
            return (string) $val;
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
}
