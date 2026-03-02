<?php

namespace ORAS\Tickets\Admin\Metaboxes;

use ORAS\Tickets\Domain\Meta;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


final class Event_Door_Prizes_Metabox { // NOSONAR legacy WP class naming

    private const NONCE_ACTION = 'oras_door_prizes_metabox';
    private const NONCE_NAME   = 'oras_door_prizes_metabox_nonce';

    public static function register(): void {
        add_action( 'save_post_tribe_events', array( self::class, 'save' ), 10, 1 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
    }

    public static function enqueue_assets(): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || Meta::EVENT_POST_TYPE !== $screen->post_type ) {
            return;
        }

        wp_enqueue_style(
            'oras-door-prizes-metabox',
            ORAS_TICKETS_URL . 'assets/admin/event-door-prizes-metabox.css',
            array(),
            ORAS_TICKETS_VERSION
        );

        wp_enqueue_script(
            'oras-door-prizes-metabox',
            ORAS_TICKETS_URL . 'assets/admin/event-door-prizes-metabox.js',
            array(),
            ORAS_TICKETS_VERSION,
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function load_envelope( int $event_id ): array {
        $envelope = get_post_meta( $event_id, Meta::META_KEY_DOOR_PRIZES, true );
        if ( ! is_array( $envelope ) ) {
            return array(
                'schema' => 1,
                'items'  => array(),
            );
        }

        $schema = isset( $envelope['schema'] ) ? absint( $envelope['schema'] ) : 1;
        $items  = isset( $envelope['items'] ) && is_array( $envelope['items'] ) ? $envelope['items'] : array();

        return array(
            'schema' => $schema > 0 ? $schema : 1,
            'items'  => $items,
        );
    }

    public static function render( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $envelope = self::load_envelope( $post->ID );
        $items    = isset( $envelope['items'] ) && is_array( $envelope['items'] ) ? $envelope['items'] : array();

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        echo '<input type="hidden" name="oras_door_prizes_present" value="1" />';
        ?>
        <div id="oras-door-prizes-metabox" class="oras-door-prizes-metabox">
            <p class="description"><?php echo esc_html__( 'Add event door prizes with audience visibility and display mode.', 'oras-tickets' ); ?></p>

            <table class="widefat striped" id="oras-door-prizes-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__( 'Prize', 'oras-tickets' ); ?></th>
                        <th><?php echo esc_html__( 'Donor', 'oras-tickets' ); ?></th>
                        <th><?php echo esc_html__( 'Value', 'oras-tickets' ); ?></th>
                        <th><?php echo esc_html__( 'External Link', 'oras-tickets' ); ?></th>
                        <th><?php echo esc_html__( 'Image URL', 'oras-tickets' ); ?></th>
                        <th><?php echo esc_html__( 'Visibility', 'oras-tickets' ); ?></th>
                        <th><?php echo esc_html__( 'Display', 'oras-tickets' ); ?></th>
                        <th><?php echo esc_html__( 'Actions', 'oras-tickets' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $index => $item ) : ?>
                        <?php
                        if ( ! is_array( $item ) ) {
                            continue;
                        }
                        $title       = isset( $item['title'] ) ? (string) $item['title'] : '';
                        $donor       = isset( $item['donor'] ) ? (string) $item['donor'] : '';
                        $value       = isset( $item['value'] ) ? (string) $item['value'] : '';
                        $external_link = isset( $item['external_link'] ) ? (string) $item['external_link'] : '';
                        $image_url   = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';
                        $visibility  = isset( $item['visibility'] ) ? (string) $item['visibility'] : 'public';
                        $display_mode = isset( $item['display_mode'] ) ? (string) $item['display_mode'] : 'inline';
                        ?>
                        <tr class="oras-door-prize-row" data-index="<?php echo esc_attr( (string) $index ); ?>">
                            <td><input type="text" class="regular-text" name="oras_door_prizes_items[<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( $title ); ?>" /></td>
                            <td><input type="text" class="regular-text" name="oras_door_prizes_items[<?php echo esc_attr( (string) $index ); ?>][donor]" value="<?php echo esc_attr( $donor ); ?>" /></td>
                            <td><input type="text" class="regular-text" name="oras_door_prizes_items[<?php echo esc_attr( (string) $index ); ?>][value]" value="<?php echo esc_attr( $value ); ?>" /></td>
                            <td><input type="url" class="regular-text" name="oras_door_prizes_items[<?php echo esc_attr( (string) $index ); ?>][external_link]" value="<?php echo esc_attr( $external_link ); ?>" /></td>
                            <td><input type="url" class="regular-text" name="oras_door_prizes_items[<?php echo esc_attr( (string) $index ); ?>][image_url]" value="<?php echo esc_attr( $image_url ); ?>" /></td>
                            <td>
                                <select name="oras_door_prizes_items[<?php echo esc_attr( (string) $index ); ?>][visibility]">
                                    <option value="public" <?php selected( $visibility, 'public' ); ?>><?php echo esc_html__( 'Public', 'oras-tickets' ); ?></option>
                                    <option value="members" <?php selected( $visibility, 'members' ); ?>><?php echo esc_html__( 'Members', 'oras-tickets' ); ?></option>
                                    <option value="internal" <?php selected( $visibility, 'internal' ); ?>><?php echo esc_html__( 'Internal', 'oras-tickets' ); ?></option>
                                </select>
                            </td>
                            <td>
                                <select name="oras_door_prizes_items[<?php echo esc_attr( (string) $index ); ?>][display_mode]">
                                    <option value="inline" <?php selected( $display_mode, 'inline' ); ?>><?php echo esc_html__( 'Inline', 'oras-tickets' ); ?></option>
                                    <option value="hover" <?php selected( $display_mode, 'hover' ); ?>><?php echo esc_html__( 'Hover', 'oras-tickets' ); ?></option>
                                    <option value="modal" <?php selected( $display_mode, 'modal' ); ?>><?php echo esc_html__( 'Modal', 'oras-tickets' ); ?></option>
                                </select>
                            </td>
                            <td><button type="button" class="button oras-door-prize-remove"><?php echo esc_html__( 'Remove', 'oras-tickets' ); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button button-secondary" id="oras-door-prize-add"><?php echo esc_html__( 'Add Door Prize', 'oras-tickets' ); ?></button>
            </p>

            <template id="oras-door-prize-template">
                <tr class="oras-door-prize-row" data-index="__INDEX__">
                    <td><input type="text" class="regular-text" name="oras_door_prizes_items[__INDEX__][title]" value="" /></td>
                    <td><input type="text" class="regular-text" name="oras_door_prizes_items[__INDEX__][donor]" value="" /></td>
                    <td><input type="text" class="regular-text" name="oras_door_prizes_items[__INDEX__][value]" value="" /></td>
                    <td><input type="url" class="regular-text" name="oras_door_prizes_items[__INDEX__][external_link]" value="" /></td>
                    <td><input type="url" class="regular-text" name="oras_door_prizes_items[__INDEX__][image_url]" value="" /></td>
                    <td>
                        <select name="oras_door_prizes_items[__INDEX__][visibility]">
                            <option value="public"><?php echo esc_html__( 'Public', 'oras-tickets' ); ?></option>
                            <option value="members"><?php echo esc_html__( 'Members', 'oras-tickets' ); ?></option>
                            <option value="internal"><?php echo esc_html__( 'Internal', 'oras-tickets' ); ?></option>
                        </select>
                    </td>
                    <td>
                        <select name="oras_door_prizes_items[__INDEX__][display_mode]">
                            <option value="inline"><?php echo esc_html__( 'Inline', 'oras-tickets' ); ?></option>
                            <option value="hover"><?php echo esc_html__( 'Hover', 'oras-tickets' ); ?></option>
                            <option value="modal"><?php echo esc_html__( 'Modal', 'oras-tickets' ); ?></option>
                        </select>
                    </td>
                    <td><button type="button" class="button oras-door-prize-remove"><?php echo esc_html__( 'Remove', 'oras-tickets' ); ?></button></td>
                </tr>
            </template>
        </div>
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

        $metabox_present = isset( $_POST['oras_door_prizes_present'] ) && '1' === (string) wp_unslash( $_POST['oras_door_prizes_present'] );

        if ( isset( $_POST['oras_door_prizes_items'] ) && is_array( $_POST['oras_door_prizes_items'] ) ) {
            $raw_items = wp_unslash( $_POST['oras_door_prizes_items'] );
        } elseif ( $metabox_present ) {
            $raw_items = array();
        } else {
            return;
        }

        $items = array();
        foreach ( $raw_items as $raw_item ) {
            if ( ! is_array( $raw_item ) ) {
                continue;
            }

            $title        = isset( $raw_item['title'] ) ? sanitize_text_field( $raw_item['title'] ) : '';
            $donor        = isset( $raw_item['donor'] ) ? sanitize_text_field( $raw_item['donor'] ) : '';
            $value        = isset( $raw_item['value'] ) ? sanitize_text_field( $raw_item['value'] ) : '';
            $external_link = isset( $raw_item['external_link'] ) ? esc_url_raw( (string) $raw_item['external_link'] ) : '';
            $image_url    = isset( $raw_item['image_url'] ) ? esc_url_raw( (string) $raw_item['image_url'] ) : '';
            $visibility   = isset( $raw_item['visibility'] ) ? sanitize_key( (string) $raw_item['visibility'] ) : 'public';
            $display_mode = isset( $raw_item['display_mode'] ) ? sanitize_key( (string) $raw_item['display_mode'] ) : 'inline';

            if ( '' === $title && '' === $donor && '' === $value && '' === $external_link && '' === $image_url ) {
                continue;
            }

            if ( ! in_array( $visibility, array( 'public', 'members', 'internal' ), true ) ) {
                $visibility = 'public';
            }

            if ( ! in_array( $display_mode, array( 'inline', 'hover', 'modal' ), true ) ) {
                $display_mode = 'inline';
            }

            $items[] = array(
                'title'        => $title,
                'donor'        => $donor,
                'value'        => $value,
                'external_link' => $external_link,
                'image_url'    => $image_url,
                'visibility'   => $visibility,
                'display_mode' => $display_mode,
            );
        }

        update_post_meta(
            $post_id,
            Meta::META_KEY_DOOR_PRIZES,
            array(
                'schema' => 1,
                'items'  => $items,
            )
        );
    }
}
