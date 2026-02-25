<?php

namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Admin\Metaboxes\Event_Agenda_Metabox;
use ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Metabox;
use ORAS\Tickets\Domain\Meta;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Event_Addon_Metabox { // NOSONAR legacy WP class naming

    private const META_BOX_ID = 'oras-events-addon';

    public function register(): void {
        add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 40 );
        add_action( 'add_meta_boxes', array( $this, 'remove_legacy_metaboxes' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register_metabox(): void {
        add_meta_box(
            self::META_BOX_ID,
            'ORAS EVENTS ADDON',
            array( $this, 'render_metabox' ),
            Meta::EVENT_POST_TYPE,
            'normal',
            'default'
        );
    }

    public function remove_legacy_metaboxes(): void {
        remove_meta_box( 'oras_tickets_metabox', Meta::EVENT_POST_TYPE, 'normal' );
        remove_meta_box( 'oras_event_agenda_metabox', Meta::EVENT_POST_TYPE, 'normal' );
        remove_meta_box( 'oras_event_rsvp_metabox', Meta::EVENT_POST_TYPE, 'normal' );
        remove_meta_box( 'oras_event_speakers_metabox', Meta::EVENT_POST_TYPE, 'normal' );
        remove_meta_box( 'oras_event_rsvp_attendees_metabox', Meta::EVENT_POST_TYPE, 'normal' );
    }

    public function enqueue_assets( string $hook_suffix ): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        // Some environments (or editor integrations) can yield an empty screen->post_type
        // during the `admin_enqueue_scripts` hook. Try multiple fallbacks to determine
        // whether we're on the event editor before deciding not to enqueue assets.
        $post_type = null;
        if ( $screen && ! empty( $screen->post_type ) ) {
            $post_type = $screen->post_type;
        }
        // Query string may provide post_type (e.g., edit.php?post_type=tribe_events)
        if ( empty( $post_type ) && ! empty( $_GET['post_type'] ) ) {
            $post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
        }
        // If we have a post ID in the query, derive its type
        if ( empty( $post_type ) && ! empty( $_GET['post'] ) ) {
            $maybe_id = absint( wp_unslash( $_GET['post'] ) );
            if ( $maybe_id ) {
                $post_type = get_post_type( $maybe_id );
            }
        }
        // Final fallback
        if ( empty( $post_type ) ) {
            $post_type = get_post_type();
        }

        if ( empty( $post_type ) || Meta::EVENT_POST_TYPE !== $post_type ) {
            return;
        }

        $is_editor = ( 'post' === $screen->base ) || in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
        if ( ! $is_editor ) {
            return;
        }

        wp_enqueue_style(
            'oras-event-addon-metabox',
            ORAS_TICKETS_URL . 'assets/admin/event-addon-metabox.css',
            array(),
            ORAS_TICKETS_VERSION
        );

        wp_enqueue_script(
            'oras-event-addon-metabox',
            ORAS_TICKETS_URL . 'assets/admin/event-addon-metabox.js',
            array(),
            ORAS_TICKETS_VERSION,
            true
        );

        // Also ensure per-feature admin assets are available when the unified metabox
        // is rendered. This guarantees ticket and speaker styles/scripts apply even
        // in contexts where their own enqueue detection may not run.
        if ( ! wp_style_is( 'oras-tickets-metabox', 'enqueued' ) ) {
            wp_enqueue_style(
                'oras-tickets-metabox',
                ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.css',
                array(),
                ORAS_TICKETS_VERSION
            );
        }
        if ( ! wp_script_is( 'oras-tickets-metabox', 'enqueued' ) ) {
            wp_enqueue_script(
                'oras-tickets-metabox',
                ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.js',
                array(),
                ORAS_TICKETS_VERSION,
                true
            );
        }

        if ( ! wp_script_is( 'oras-tickets-event-speakers-metabox', 'enqueued' ) ) {
            wp_enqueue_script(
                'oras-tickets-event-speakers-metabox',
                ORAS_TICKETS_URL . 'assets/admin/event-speakers-metabox.js',
                array(),
                ORAS_TICKETS_VERSION,
                true
            );
        }
    }

    public function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }
        ?>
        <div id="oras-events-addon" class="oras-events-addon" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
            <style>
                /* Inline fallback to ensure styles are visible even if enqueued asset is blocked */
                #oras-events-addon.oras-events-addon .oras-events-addon__header { border-left: 4px solid #0073aa; padding-left: 12px; }
            </style>
            <div class="oras-events-addon__header">
                <div class="oras-events-addon__title">
                    <h3 class="oras-events-addon__h"><?php echo esc_html__( 'ORAS Events Addon', 'oras-tickets' ); ?></h3>
                    <p class="description"><?php echo esc_html__( 'Manage tickets, agenda, RSVP and speakers from a single, compact interface.', 'oras-tickets' ); ?></p>
                </div>
                <div class="oras-events-addon__status">
                    <!-- placeholder for status badges -->
                </div>
            </div>

            <style>
                /* Critical inline styles (scoped) to ensure redesigned layout shows even if
                   enqueued assets fail to load in some editor contexts. Kept minimal and
                   scoped to #oras-events-addon so it does not leak. */
                #oras-events-addon.oras-events-addon { font-family: inherit; }
                #oras-events-addon .oras-events-addon__tabs { display:flex; gap:8px; margin-bottom:12px; }
                #oras-events-addon .oras-events-addon__tab { padding:6px 10px; }

                /* CSS-only tabs using radios: hide the radios and style labels as tabs */
                #oras-events-addon input.oras-tab-radio { position:absolute; left:-9999px; }
                #oras-events-addon .oras-tab-label { cursor:pointer; display:inline-block; padding:6px 10px; border:1px solid transparent; border-radius:4px; background:transparent; margin-right:6px; }
                #oras-events-addon input.oras-tab-radio:checked + .oras-tab-label { background:#f7f7f7; border-color:#e1e1e1; }

                /* Map radios to panels: hide all panels by default, show when corresponding radio is checked */
                #oras-events-addon .oras-events-addon__panel { display:none; }
                #oras-events-addon input#oras-tab-tickets:checked ~ .oras-events-addon__panels #oras-panel-tickets,
                #oras-events-addon input#oras-tab-agenda:checked ~ .oras-events-addon__panels #oras-panel-agenda,
                #oras-events-addon input#oras-tab-rsvp:checked ~ .oras-events-addon__panels #oras-panel-rsvp,
                #oras-events-addon input#oras-tab-speakers:checked ~ .oras-events-addon__panels #oras-panel-speakers { display:block; }

                /* Hide legacy left rail inside the embedded ticket metabox and make panels full width */
                #oras-events-addon #oras-tickets-metabox .oras-tickets-tabs { display: none !important; }
                #oras-events-addon #oras-tickets-metabox .oras-ticket-panels { width: 100% !important; }
                #oras-events-addon #oras-tickets-metabox .oras-ticket-row.oras-ticket-row-card { margin-bottom: 12px !important; }

                /* Card header/body visuals */
                #oras-events-addon .oras-card__header{ display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border:1px solid #e6e6e6; border-radius:4px 4px 0 0; background:#fff; }
                #oras-events-addon .oras-card__body{ border:1px solid #e6e6e6; border-top:0; padding:12px; border-radius:0 0 4px 4px; background:#fff; }
                #oras-events-addon .oras-card__title{ display:flex; flex-direction:column; }
                #oras-events-addon .oras-card__name{ font-weight:700; }
                #oras-events-addon .oras-card__meta{ font-size:12px; color:#666; }

                /* Ensure ticket panels are visible by default so users see fields without JS */
                #oras-events-addon .oras-ticket-panel { display:block !important; }

                /* Simple responsive tweaks */
                @media (max-width:720px){ #oras-events-addon .oras-events-addon__tabs{flex-wrap:wrap;} }
            </style>

            <!-- Tab switching uses CSS-only radios to avoid relying on inline scripts which may be escaped by WP -->

            <!-- Radios are placed as siblings of the panels so CSS sibling selectors can show/hide panels reliably -->
            <input type="radio" id="oras-tab-tickets" name="oras_events_tab" class="oras-tab-radio" checked>
            <input type="radio" id="oras-tab-agenda" name="oras_events_tab" class="oras-tab-radio">
            <input type="radio" id="oras-tab-rsvp" name="oras_events_tab" class="oras-tab-radio">
            <input type="radio" id="oras-tab-speakers" name="oras_events_tab" class="oras-tab-radio">

            <div class="oras-events-addon__tabs" role="tablist" aria-orientation="horizontal">
                <label for="oras-tab-tickets" class="oras-tab-label"><?php echo esc_html__( 'Tickets', 'oras-tickets' ); ?></label>
                <label for="oras-tab-agenda" class="oras-tab-label"><?php echo esc_html__( 'Agenda', 'oras-tickets' ); ?></label>
                <label for="oras-tab-rsvp" class="oras-tab-label"><?php echo esc_html__( 'RSVP', 'oras-tickets' ); ?></label>
                <label for="oras-tab-speakers" class="oras-tab-label"><?php echo esc_html__( 'Speakers', 'oras-tickets' ); ?></label>
            </div>

            <div class="oras-events-addon__panels">
                <div id="oras-panel-tickets" class="oras-events-addon__panel is-active" data-panel="tickets" role="tabpanel">
                    <div class="postbox oras-events-addon__card">
                        <div class="inside">
                            <?php Tickets_Metabox::instance()->render_metabox( $post ); ?>
                        </div>
                    </div>
                </div>

                <div id="oras-panel-agenda" class="oras-events-addon__panel" data-panel="agenda" role="tabpanel" hidden>
                    <div class="postbox oras-events-addon__card">
                        <div class="inside">
                            <?php Event_Agenda_Metabox::render( $post ); ?>
                        </div>
                    </div>
                </div>

                <div id="oras-panel-rsvp" class="oras-events-addon__panel" data-panel="rsvp" role="tabpanel" hidden>
                    <div class="postbox oras-events-addon__card">
                        <div class="inside">
                            <?php Event_RSVP_Metabox::render( $post ); ?>
                        </div>
                    </div>
                </div>

                <div id="oras-panel-speakers" class="oras-events-addon__panel" data-panel="speakers" role="tabpanel" hidden>
                    <div class="postbox oras-events-addon__card">
                        <div class="inside">
                            <?php ( new Event_Speakers_Metabox() )->render_metabox( $post ); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
