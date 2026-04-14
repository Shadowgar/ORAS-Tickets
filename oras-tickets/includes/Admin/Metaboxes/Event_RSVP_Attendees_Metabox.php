<?php

namespace ORAS\Tickets\Admin\Metaboxes;

use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Security\CsvSafety;
use ORAS\Tickets\Support\DbLock;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_RSVP_Attendees_Metabox { // NOSONAR legacy WP class naming

    public static function register(): void {
        add_action( 'add_meta_boxes', array( self::class, 'add_metabox' ) );
        add_action( 'admin_post_oras_rsvp_export', array( self::class, 'handle_export' ) );
        add_action( 'admin_post_oras_rsvp_metabox_promote', array( self::class, 'handle_promote' ) );
    }

    public static function add_metabox(): void {
        global $post;
        if ( ! $post || 'tribe_events' !== $post->post_type ) {
            return;
        }

        $envelope = get_post_meta( $post->ID, '_oras_rsvp_v1', true );
        if ( ! is_array( $envelope ) || ! isset( $envelope['enabled'] ) || ! $envelope['enabled'] ) {
            return;
        }

        add_meta_box(
            'oras_event_rsvp_attendees_metabox',
            __( 'RSVP Attendees', 'oras-tickets' ),
            array( self::class, 'render' ),
            'tribe_events',
            'normal',
            'default'
        );
    }

    public static function render( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $event_id = $post->ID;
        $envelope = get_post_meta( $event_id, '_oras_rsvp_v1', true );
        $capacity = isset( $envelope['capacity'] ) ? (int) $envelope['capacity'] : 0;
        $waitlist_enabled = isset( $envelope['waitlist_enabled'] ) ? $envelope['waitlist_enabled'] : false;

        // Get counts
        $yes_count = self::get_count( $event_id, 'yes' );
        $waitlist_count = self::get_count( $event_id, 'waitlist' );
        $onsite_yes_count = self::get_yes_count_for_attendance_mode( $event_id, 'onsite' );
        $virtual_yes_count = self::get_yes_count_for_attendance_mode( $event_id, 'virtual' );
        $is_full = $capacity > 0 && $yes_count >= $capacity;

        // Get attendees
        $attendees = self::get_attendees( $event_id );

        ?>
        <div id="oras-rsvp-attendees">
            <h4><?php esc_html_e( 'RSVP Stats', 'oras-tickets' ); ?></h4>
            <p>
                <strong><?php esc_html_e( 'Yes Count:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $yes_count ); ?><br>
                <strong><?php esc_html_e( 'On-site RSVPs:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $onsite_yes_count ); ?><br>
                <strong><?php esc_html_e( 'Virtual RSVPs:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $virtual_yes_count ); ?><br>
                <strong><?php esc_html_e( 'Waitlist Count:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $waitlist_count ); ?><br>
                <strong><?php esc_html_e( 'Capacity:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $capacity ); ?><br>
                <strong><?php esc_html_e( 'Is Full:', 'oras-tickets' ); ?></strong> <?php echo $is_full ? esc_html__( 'Yes', 'oras-tickets' ) : esc_html__( 'No', 'oras-tickets' ); ?>
            </p>

            <h4><?php esc_html_e( 'Attendees', 'oras-tickets' ); ?></h4>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'oras-tickets' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'oras-tickets' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'oras-tickets' ); ?></th>
                        <th><?php esc_html_e( 'Attendance Type', 'oras-tickets' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $attendees ) ) : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e( 'No attendees yet.', 'oras-tickets' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $attendees as $attendee ) : ?>
                            <tr>
                                <td><?php echo esc_html( $attendee['name'] ); ?></td>
                                <td><?php echo esc_html( $attendee['email'] ); ?></td>
                                <td><?php echo esc_html( ucfirst( $attendee['status'] ) ); ?></td>
                                <td><?php echo esc_html( Event_RSVP::get_attendance_mode_label( (string) ( $attendee['attendance_mode'] ?? '' ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oras_rsvp_export&event_id=' . $event_id ), 'oras_rsvp_export' ) ); ?>" class="button">
                    <?php esc_html_e( 'Export YES Attendees to CSV', 'oras-tickets' ); ?>
                </a>
                <?php if ( $is_full && $waitlist_count > 0 ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oras_rsvp_metabox_promote&event_id=' . $event_id ), 'oras_rsvp_promote' ) ); ?>" class="button">
                        <?php esc_html_e( 'Promote from Waitlist', 'oras-tickets' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private static function get_count( int $event_id, string $status ): int {
        if ( 'waitlist' === $status ) {
            return Waitlist_Store::count_waiting( $event_id );
        }

        $query = new \WP_User_Query( array(
            'meta_key'   => '_oras_rsvp_event_' . $event_id,
            'meta_value' => $status,
            'count_total' => true,
            'number'     => 1, // Just count, no need to fetch users
        ) );
        return $query->get_total();
    }

    private static function get_attendees( int $event_id ): array {
        $query = new \WP_User_Query( array(
            'meta_key' => '_oras_rsvp_event_' . $event_id,
            'meta_compare' => 'EXISTS',
        ) );
        $users = $query->get_results();

        $attendees = array();
        $positions = array();
        foreach ( $users as $user ) {
            $status = get_user_meta( $user->ID, '_oras_rsvp_event_' . $event_id, true );
            if ( in_array( $status, array( 'yes', 'no' ), true ) ) {
                $positions[ $user->ID ] = count( $attendees );
                $attendees[] = array(
                    'name'   => $user->display_name,
                    'email'  => $user->user_email,
                    'status' => (string) $status,
                    'attendance_mode' => Event_RSVP::get_user_attendance_mode( $event_id, $user->ID ),
                );
            }
        }

        $waitlist_users = Waitlist_Store::get_waiting_users( $event_id );
        foreach ( $waitlist_users as $user ) {
            if ( isset( $positions[ $user->ID ] ) ) {
                $attendees[ $positions[ $user->ID ] ]['status'] = 'waitlist';
                continue;
            }

            $attendees[] = array(
                'name'   => $user->display_name,
                'email'  => $user->user_email,
                'status' => 'waitlist',
                'attendance_mode' => Event_RSVP::get_user_attendance_mode( $event_id, $user->ID ),
            );
        }

        return $attendees;
    }

    private static function get_yes_count_for_attendance_mode( int $event_id, string $attendance_mode ): int {
        $query = new \WP_User_Query( array(
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'   => '_oras_rsvp_event_' . $event_id,
                    'value' => 'yes',
                ),
                array(
                    'key'   => '_oras_rsvp_event_' . $event_id . '_attendance_mode',
                    'value' => $attendance_mode,
                ),
            ),
            'count_total' => true,
            'number'      => 1,
        ) );

        return $query->get_total();
    }

    public static function handle_export(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'oras-tickets' ) );
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'oras_rsvp_export' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'oras-tickets' ) );
        }

        $event_id = (int) ( $_GET['event_id'] ?? 0 );
        if ( ! $event_id ) {
            wp_die( esc_html__( 'Invalid event ID.', 'oras-tickets' ) );
        }

        $query = new \WP_User_Query( array(
            'meta_key'   => '_oras_rsvp_event_' . $event_id,
            'meta_value' => 'yes',
        ) );
        $users = $query->get_results();

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="rsvp-yes-attendees-event-' . $event_id . '.csv"' );

        $fp = fopen( 'php://output', 'w' );
        fputcsv( $fp, CsvSafety::row( array( 'Name', 'Email', 'Status', 'Attendance Type' ) ) );
        foreach ( $users as $user ) {
            $attendance_mode = Event_RSVP::get_user_attendance_mode( $event_id, $user->ID );
            fputcsv( $fp, CsvSafety::row( array( $user->display_name, $user->user_email, 'yes', Event_RSVP::get_attendance_mode_label( $attendance_mode ) ) ) );
        }
        fclose( $fp );
        exit;
    }

    public static function handle_promote(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'oras-tickets' ) );
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'oras_rsvp_promote' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'oras-tickets' ) );
        }

        $event_id = (int) ( $_GET['event_id'] ?? 0 );
        if ( ! $event_id ) {
            wp_die( esc_html__( 'Invalid event ID.', 'oras-tickets' ) );
        }

        $result = DbLock::forEvent(
            $event_id,
            static function () use ( $event_id ) {
                $envelope = get_post_meta( $event_id, '_oras_rsvp_v1', true );
                $capacity = isset( $envelope['capacity'] ) ? (int) $envelope['capacity'] : 0;
                $yes_count = self::get_count( $event_id, 'yes' );

                if ( $capacity > 0 && $yes_count >= $capacity ) {
                    return new \WP_Error( 'oras_rsvp_capacity', esc_html__( 'Event is at capacity.', 'oras-tickets' ) );
                }

                $promoted_user_id = Waitlist_Store::promote_next_waiting( $event_id, get_current_user_id(), 'metabox' );
                if ( $promoted_user_id > 0 ) {
                    update_user_meta( $promoted_user_id, '_oras_rsvp_event_' . $event_id, 'yes' );
                    delete_user_meta( $promoted_user_id, '_oras_rsvp_event_' . $event_id . '_ts' );
                }

                return true;
            }
        );

        if ( is_wp_error( $result ) ) {
            wp_die( $result->get_error_message() );
        }

        wp_safe_redirect( admin_url( 'post.php?post=' . $event_id . '&action=edit' ) );
        exit;
    }
}
