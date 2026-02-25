<?php

namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Domain\Meta;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_Speakers_Metabox { // NOSONAR legacy WP class naming

    private const META_KEY     = '_oras_speakers_v1';
    private const NONCE_ACTION = 'oras_speakers_metabox';
    private const NONCE_NAME   = 'oras_speakers_metabox_nonce';
    private const POST_TYPE    = Meta::EVENT_POST_TYPE;

    private const COMPENSATION_MEMBERSHIP = 'membership';
    private const COMPENSATION_FEE        = 'fee';
    private const COMPENSATION_NONE       = 'none';

    public function register(): void {
        add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets( string $hook_suffix ): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== self::POST_TYPE ) {
            return;
        }

        $is_editor = ( $screen->base === 'post' ) || in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
        if ( ! $is_editor ) {
            return;
        }

        wp_enqueue_script(
            'oras-tickets-event-speakers-metabox',
            ORAS_TICKETS_URL . 'assets/admin/event-speakers-metabox.js',
            array(),
            ORAS_TICKETS_VERSION,
            true
        );
    }

    public function register_metabox(): void {
        add_meta_box(
            'oras_event_speakers_metabox',
            __( 'Speakers for this Event', 'oras-tickets' ),
            array( $this, 'render_metabox' ),
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $assignments = get_post_meta( $post->ID, self::META_KEY, true );
        if ( ! is_array( $assignments ) ) {
            $assignments = array();
        }

        $next_index = 0;
        foreach ( array_keys( $assignments ) as $key ) {
            $key_int = (int) $key;
            if ( $key_int >= $next_index ) {
                $next_index = $key_int + 1;
            }
        }

        $speakers = get_posts(
            array(
                'post_type'        => 'oras_speaker',
                'post_status'      => 'publish',
                'numberposts'      => 300,
                'orderby'          => 'title',
                'order'            => 'ASC',
                'suppress_filters' => false,
            )
        );

        $template = $this->render_row_template( $speakers );

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

        ?>
    <div id="oras-event-speakers-metabox">
        <div class="oras-event-speakers-toolbar">
        <button type="button" class="button" id="oras-add-speaker-row"><?php echo esc_html__( 'Add Speaker', 'oras-tickets' ); ?></button>
        </div>
        <div class="oras-event-speakers-rows" data-next-index="<?php echo esc_attr( (string) $next_index ); ?>">
        <?php foreach ( $assignments as $index => $assignment ) : ?>
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_row( $speakers, $assignment, (int) $index ); ?>
        <?php endforeach; ?>
        </div>
        <script type="text/template" id="oras-speaker-row-template">
        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php echo $template; ?>
        </script>
    </div>
        <?php
    }

    private function render_row_template( array $speakers ): string {
        return $this->render_row( $speakers, array(), '__INDEX__' );
    }

    private function render_row( array $speakers, array $assignment, $index ): string {
        $speaker_id        = isset( $assignment['speaker_id'] ) ? (int) $assignment['speaker_id'] : 0;
        $role              = isset( $assignment['role'] ) ? (string) $assignment['role'] : '';
        $is_primary        = ! empty( $assignment['is_primary'] );
        $compensation_type = isset( $assignment['compensation_type'] ) ? (string) $assignment['compensation_type'] : self::COMPENSATION_NONE;
        $fee_amount        = isset( $assignment['fee_amount'] ) ? (float) $assignment['fee_amount'] : 0.0;
        $pmpro_level_id    = isset( $assignment['pmpro_level_id'] ) ? (int) $assignment['pmpro_level_id'] : 0;
        $fulfilled         = ! empty( $assignment['fulfilled'] );
        $fulfilled_date    = isset( $assignment['fulfilled_date'] ) ? (string) $assignment['fulfilled_date'] : '';
        $internal_notes    = isset( $assignment['internal_notes'] ) ? (string) $assignment['internal_notes'] : '';

        if ( ! in_array( $compensation_type, array( self::COMPENSATION_MEMBERSHIP, self::COMPENSATION_FEE, self::COMPENSATION_NONE ), true ) ) {
            $compensation_type = self::COMPENSATION_NONE;
        }

        $index_attr = is_int( $index ) ? (string) $index : (string) $index;

        ob_start();
        ?>
    <div class="oras-event-speaker-row" data-index="<?php echo esc_attr( $index_attr ); ?>">
        <?php
        $speaker_name = '';
        if ( $speaker_id > 0 ) {
            $spost = get_post( $speaker_id );
            if ( $spost instanceof \WP_Post ) {
                $speaker_name = $spost->post_title;
            }
        }
        ?>
        <div class="oras-card__header">
            <div class="oras-card__title">
                <span class="oras-card__name"><?php echo esc_html( $speaker_name ?: 'Speaker' ); ?></span>
                <span class="oras-card__meta"><?php echo esc_html( $role ); ?></span>
            </div>
            <div class="oras-card__actions">
                <button type="button" class="button oras-card-toggle" data-index="<?php echo esc_attr( $index_attr ); ?>"><?php echo esc_html__( 'Edit', 'oras-tickets' ); ?></button>
                <button type="button" class="button oras-remove-speaker-row"><?php echo esc_html__( 'Remove', 'oras-tickets' ); ?></button>
            </div>
        </div>
        <div class="oras-card__body">
        <div class="oras-event-speaker-grid">
        <div class="oras-event-speaker-field">
            <span class="oras-field-label"><?php echo esc_html__( 'Speaker', 'oras-tickets' ); ?></span>
            <select name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][speaker_id]">
            <option value="0"><?php echo esc_html__( 'Select speaker', 'oras-tickets' ); ?></option>
            <?php foreach ( $speakers as $speaker ) : ?>
                <?php
                if ( ! $speaker instanceof \WP_Post ) {
                    continue;
                }
                $speaker_value = (int) $speaker->ID;
                ?>
                <option value="<?php echo esc_attr( (string) $speaker_value ); ?>" <?php selected( $speaker_id, $speaker_value ); ?>>
                <?php echo esc_html( $speaker->post_title ); ?>
                </option>
            <?php endforeach; ?>
            </select>
        </div>

        <div class="oras-event-speaker-field">
            <span class="oras-field-label"><?php echo esc_html__( 'Role', 'oras-tickets' ); ?></span>
            <input type="text" name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][role]" value="<?php echo esc_attr( $role ); ?>" />
        </div>

        <div class="oras-event-speaker-field">
            <span class="oras-field-label"><?php echo esc_html__( 'Primary', 'oras-tickets' ); ?></span>
            <label>
            <input type="checkbox" name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][is_primary]" value="1" <?php checked( $is_primary ); ?> />
                <?php echo esc_html__( 'Yes', 'oras-tickets' ); ?>
            </label>
        </div>

        <div class="oras-event-speaker-field">
            <span class="oras-field-label"><?php echo esc_html__( 'Compensation', 'oras-tickets' ); ?></span>
            <select class="oras-event-speaker-compensation" name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][compensation_type]">
            <option value="none" <?php selected( $compensation_type, self::COMPENSATION_NONE ); ?>><?php echo esc_html__( 'None', 'oras-tickets' ); ?></option>
            <option value="fee" <?php selected( $compensation_type, self::COMPENSATION_FEE ); ?>><?php echo esc_html__( 'Fee', 'oras-tickets' ); ?></option>
            <option value="membership" <?php selected( $compensation_type, self::COMPENSATION_MEMBERSHIP ); ?>><?php echo esc_html__( 'Membership', 'oras-tickets' ); ?></option>
            </select>
        </div>

        <div class="oras-event-speaker-field" data-compensation="fee">
            <span class="oras-field-label"><?php echo esc_html__( 'Fee Amount', 'oras-tickets' ); ?></span>
            <input type="number" step="0.01" min="0" name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][fee_amount]" value="<?php echo esc_attr( (string) $fee_amount ); ?>" />
        </div>

        <div class="oras-event-speaker-field" data-compensation="membership">
            <span class="oras-field-label"><?php echo esc_html__( 'PMPro Level ID', 'oras-tickets' ); ?></span>
            <input type="number" step="1" min="0" name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][pmpro_level_id]" value="<?php echo esc_attr( (string) $pmpro_level_id ); ?>" />
        </div>

        <div class="oras-event-speaker-field">
            <span class="oras-field-label"><?php echo esc_html__( 'Fulfilled', 'oras-tickets' ); ?></span>
            <label>
            <input type="checkbox" name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][fulfilled]" value="1" <?php checked( $fulfilled ); ?> />
                <?php echo esc_html__( 'Yes', 'oras-tickets' ); ?>
            </label>
        </div>

        <div class="oras-event-speaker-field">
            <span class="oras-field-label"><?php echo esc_html__( 'Fulfilled Date', 'oras-tickets' ); ?></span>
            <input type="date" name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][fulfilled_date]" value="<?php echo esc_attr( $fulfilled_date ); ?>" />
        </div>

        <div class="oras-event-speaker-field oras-event-speaker-notes">
            <span class="oras-field-label"><?php echo esc_html__( 'Internal Notes', 'oras-tickets' ); ?></span>
            <textarea name="oras_speakers_assignments[<?php echo esc_attr( $index_attr ); ?>][internal_notes]" rows="3"><?php echo esc_textarea( $internal_notes ); ?></textarea>
        </div>
        </div>
        <div class="oras-event-speaker-actions">
        </div>
        </div>
    </div>
        <?php

        return (string) ob_get_clean();
    }

    public function save_post( int $post_id, \WP_Post $post ): void {
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

        if ( $post->post_type !== self::POST_TYPE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['oras_speakers_assignments'] ) || ! is_array( $_POST['oras_speakers_assignments'] ) ) {
            return;
        }

        $rows = $_POST['oras_speakers_assignments'];

        $assignments = array();

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $speaker_id = isset( $row['speaker_id'] ) ? (int) $row['speaker_id'] : 0;
            if ( $speaker_id <= 0 ) {
                continue;
            }

            $role              = isset( $row['role'] ) ? sanitize_text_field( wp_unslash( $row['role'] ) ) : '';
            $is_primary        = ! empty( $row['is_primary'] );
            $compensation_type = isset( $row['compensation_type'] )
            ? sanitize_key( wp_unslash( $row['compensation_type'] ) )
            : self::COMPENSATION_NONE;

            if ( ! in_array( $compensation_type, array( self::COMPENSATION_MEMBERSHIP, self::COMPENSATION_FEE, self::COMPENSATION_NONE ), true ) ) {
                $compensation_type = self::COMPENSATION_NONE;
            }

            $fee_amount = 0.0;
            if ( $compensation_type === self::COMPENSATION_FEE ) {
                $fee_raw    = isset( $row['fee_amount'] ) ? (float) wp_unslash( $row['fee_amount'] ) : 0.0;
                $fee_amount = max( 0.0, $fee_raw );
            }

            $pmpro_level_id = 0;
            if ( $compensation_type === self::COMPENSATION_MEMBERSHIP ) {
                $pmpro_level_id = isset( $row['pmpro_level_id'] ) ? max( 0, (int) $row['pmpro_level_id'] ) : 0;
            }

            $fulfilled      = ! empty( $row['fulfilled'] );
            $fulfilled_date = isset( $row['fulfilled_date'] ) ? sanitize_text_field( wp_unslash( $row['fulfilled_date'] ) ) : '';
            $internal_notes = isset( $row['internal_notes'] ) ? sanitize_textarea_field( wp_unslash( $row['internal_notes'] ) ) : '';

            $assignments[] = array(
                'speaker_id'        => $speaker_id,
                'role'              => $role,
                'is_primary'        => $is_primary,
                'compensation_type' => $compensation_type,
                'fee_amount'        => $fee_amount,
                'pmpro_level_id'    => $pmpro_level_id,
                'fulfilled'         => $fulfilled,
                'fulfilled_date'    => $fulfilled_date,
                'internal_notes'    => $internal_notes,
            );
        }

        if ( empty( $assignments ) ) {
            delete_post_meta( $post_id, self::META_KEY );
            return;
        }

        update_post_meta( $post_id, self::META_KEY, $assignments );
    }
}
