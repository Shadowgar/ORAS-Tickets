<?php

namespace ORAS\Tickets\Admin\Metaboxes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_RSVP_Metabox { // NOSONAR legacy WP class naming

    private const META_KEY     = '_oras_rsvp_v1';
    private const NONCE_ACTION = 'oras_rsvp_metabox';
    private const NONCE_NAME   = 'oras_rsvp_metabox_nonce';

    public static function register(): void {
        add_action( 'add_meta_boxes', array( self::class, 'add_metabox' ) );
        add_action( 'save_post_tribe_events', array( self::class, 'save' ), 10, 1 );
    }

    public static function add_metabox(): void {
        add_meta_box(
            'oras_event_rsvp_metabox',
            __( 'RSVP Settings', 'oras-tickets' ),
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

        $envelope = get_post_meta( $post->ID, self::META_KEY, true );
        if ( ! is_array( $envelope ) || ! isset( $envelope['version'] ) || 1 !== (int) $envelope['version'] ) {
            $settings = \ORAS\Tickets\Admin\Pages\Settings_Page::get_settings();
            $envelope = array(
                'version'          => 1,
                'enabled'          => $settings['rsvp']['default_enabled'],
                'capacity'         => $settings['rsvp']['default_capacity'],
                'waitlist_enabled' => $settings['rsvp']['default_waitlist_enabled'],
                'open_at'          => '',
                'close_at'         => '',
            );
        }

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="oras_rsvp_enabled"><?php echo esc_html__( 'Enable RSVP', 'oras-tickets' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="oras_rsvp_enabled" name="oras_rsvp[enabled]" value="1" <?php checked( $envelope['enabled'] ); ?> />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oras_rsvp_capacity"><?php echo esc_html__( 'Capacity', 'oras-tickets' ); ?></label>
                </th>
                <td>
                    <input type="number" id="oras_rsvp_capacity" name="oras_rsvp[capacity]" value="<?php echo esc_attr( $envelope['capacity'] ); ?>" min="0" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oras_rsvp_waitlist_enabled"><?php echo esc_html__( 'Enable Waitlist', 'oras-tickets' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="oras_rsvp_waitlist_enabled" name="oras_rsvp[waitlist_enabled]" value="1" <?php checked( $envelope['waitlist_enabled'] ); ?> />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oras_rsvp_open_at"><?php echo esc_html__( 'Open At', 'oras-tickets' ); ?></label>
                </th>
                <td>
                    <input type="text" id="oras_rsvp_open_at" name="oras_rsvp[open_at]" value="<?php echo esc_attr( $envelope['open_at'] ); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oras_rsvp_close_at"><?php echo esc_html__( 'Close At', 'oras-tickets' ); ?></label>
                </th>
                <td>
                    <input type="text" id="oras_rsvp_close_at" name="oras_rsvp[close_at]" value="<?php echo esc_attr( $envelope['close_at'] ); ?>" />
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save( int $post_id ): void {
if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $input = isset( $_POST['oras_rsvp'] ) && is_array( $_POST['oras_rsvp'] ) ? $_POST['oras_rsvp'] : array();

        $envelope = array(
            'version'          => 1,
            'enabled'          => ! empty( $input['enabled'] ),
            'capacity'         => absint( $input['capacity'] ?? 0 ),
            'waitlist_enabled' => ! empty( $input['waitlist_enabled'] ),
            'open_at'          => sanitize_text_field( $input['open_at'] ?? '' ),
            'close_at'         => sanitize_text_field( $input['close_at'] ?? '' ),
        );

        update_post_meta( $post_id, self::META_KEY, $envelope );
    }
}