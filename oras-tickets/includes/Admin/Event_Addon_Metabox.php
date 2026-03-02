<?php

namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Admin\Metaboxes\Event_Agenda_Metabox;
use ORAS\Tickets\Admin\Metaboxes\Event_Door_Prizes_Metabox;
use ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Metabox;
use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if (! defined('ABSPATH')) {
    exit;
}

final class Event_Addon_Metabox
{ // NOSONAR legacy WP class naming

    private const META_BOX_ID = 'oras-events-addon';

    private function assetVersion(string $relative_path): string
    {
        $full_path = ORAS_TICKETS_DIR . ltrim($relative_path, '/');
        $mtime     = file_exists($full_path) ? filemtime($full_path) : false;

        if (false === $mtime) {
            return ORAS_TICKETS_VERSION;
        }

        return ORAS_TICKETS_VERSION . '.' . (string) $mtime;
    }

    public function register(): void
    {
        add_action('add_meta_boxes', array($this, 'register_metabox'), 40);
        add_action('add_meta_boxes', array($this, 'remove_legacy_metaboxes'), 100);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function register_metabox(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            'ORAS EVENTS ADDON',
            array($this, 'render_metabox'),
            Meta::EVENT_POST_TYPE,
            'normal',
            'default'
        );
    }

    public function remove_legacy_metaboxes(): void
    {
        \remove_meta_box('oras_tickets_metabox', Meta::EVENT_POST_TYPE, 'normal');
        \remove_meta_box('oras_event_agenda_metabox', Meta::EVENT_POST_TYPE, 'normal');
        \remove_meta_box('oras_event_rsvp_metabox', Meta::EVENT_POST_TYPE, 'normal');
        \remove_meta_box('oras_event_speakers_metabox', Meta::EVENT_POST_TYPE, 'normal');
        \remove_meta_box('oras_event_rsvp_attendees_metabox', Meta::EVENT_POST_TYPE, 'normal');
    }

    public function enqueue_assets(): void
    {
        if (! function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();

        // Some environments (or editor integrations) can yield an empty screen->post_type
        // during the `admin_enqueue_scripts` hook. Try multiple fallbacks to determine
        // whether we're on the event editor before deciding not to enqueue assets.
        $post_type = null;
        if ($screen && ! empty($screen->post_type)) {
            $post_type = $screen->post_type;
        }

        if (empty($post_type) && ! empty($_GET['post_type'])) {
            $post_type = sanitize_key(wp_unslash($_GET['post_type']));
        }

        if (empty($post_type) && ! empty($_GET['post'])) {
            $maybe_id = absint(wp_unslash($_GET['post']));
            if ($maybe_id) {
                $post_type = \get_post_type($maybe_id);
            }
        }

        if (empty($post_type)) {
            $post_type = \get_post_type();
        }

        if (empty($post_type) || Meta::EVENT_POST_TYPE !== $post_type) {
            return;
        }

        // Some The Events Calendar admin contexts report non-standard screen bases.
        // Once we've confirmed tribe_events post type, proceed with enqueue.

        wp_enqueue_style(
            'oras-event-addon-metabox',
            ORAS_TICKETS_URL . 'assets/admin/event-addon-metabox.css',
            array(),
            $this->assetVersion('assets/admin/event-addon-metabox.css')
        );

        wp_enqueue_script(
            'oras-event-addon-metabox',
            ORAS_TICKETS_URL . 'assets/admin/event-addon-metabox.js',
            array(),
            $this->assetVersion('assets/admin/event-addon-metabox.js'),
            true
        );

        // Ensure per-feature assets are present when rendered inside the unified metabox.
        if (! \wp_style_is('oras-tickets-metabox', 'enqueued')) {
            wp_enqueue_style(
                'oras-tickets-metabox',
                ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.css',
                array(),
                $this->assetVersion('assets/admin/tickets-metabox.css')
            );
        }

        if (! \wp_script_is('oras-tickets-metabox', 'enqueued')) {
            wp_enqueue_script(
                'oras-tickets-metabox',
                ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.js',
                array(),
                $this->assetVersion('assets/admin/tickets-metabox.js'),
                true
            );
        }

        if (! \wp_script_is('oras-tickets-event-speakers-metabox', 'enqueued')) {
            wp_enqueue_script(
                'oras-tickets-event-speakers-metabox',
                ORAS_TICKETS_URL . 'assets/admin/event-speakers-metabox.js',
                array(),
                $this->assetVersion('assets/admin/event-speakers-metabox.js'),
                true
            );
        }

        if (! \wp_script_is('oras-door-prizes-metabox', 'enqueued')) {
            wp_enqueue_script(
                'oras-door-prizes-metabox',
                ORAS_TICKETS_URL . 'assets/admin/event-door-prizes-metabox.js',
                array(),
                $this->assetVersion('assets/admin/event-door-prizes-metabox.js'),
                true
            );
        }

        if (! \wp_style_is('oras-door-prizes-metabox', 'enqueued')) {
            wp_enqueue_style(
                'oras-door-prizes-metabox',
                ORAS_TICKETS_URL . 'assets/admin/event-door-prizes-metabox.css',
                array(),
                $this->assetVersion('assets/admin/event-door-prizes-metabox.css')
            );
        }
    }

    public function render_metabox(\WP_Post $post): void
    {
        if (! current_user_can('edit_post', $post->ID)) {
            return;
        }

        $ticket_envelope = Ticket_Collection::load_envelope_for_event($post->ID);
        $ticket_rows     = isset($ticket_envelope['tickets']) && is_array($ticket_envelope['tickets']) ? $ticket_envelope['tickets'] : array();
        $ticket_count    = count($ticket_rows);

        $agenda_envelope = get_post_meta($post->ID, '_oras_agenda_v1', true);
        $agenda_days     = is_array($agenda_envelope) && isset($agenda_envelope['days']) && is_array($agenda_envelope['days']) ? $agenda_envelope['days'] : array();
        $agenda_count    = count($agenda_days);

        $rsvp_envelope = get_post_meta($post->ID, '_oras_rsvp_v1', true);
        $rsvp_enabled  = is_array($rsvp_envelope) && ! empty($rsvp_envelope['enabled']);

        $speaker_envelope = get_post_meta($post->ID, '_oras_speakers_v1', true);
        $speaker_count    = is_array($speaker_envelope) ? count($speaker_envelope) : 0;

        $door_prizes_envelope = Event_Door_Prizes_Metabox::load_envelope($post->ID);
        $door_prize_items     = isset($door_prizes_envelope['items']) && is_array($door_prizes_envelope['items']) ? $door_prizes_envelope['items'] : array();
        $door_prize_count     = count($door_prize_items);
        $save_event_label     = __('Save Event', 'oras-tickets');
        $saving_event_label   = __('Saving…', 'oras-tickets');
?>
        <div id="oras-events-addon" class="oras-events-addon" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <header class="oras-events-addon__header">
                <div class="oras-events-addon__header-main">
                    <h2 class="oras-events-addon__title"><?php echo esc_html__('ORAS Events Addon', 'oras-tickets'); ?></h2>
                    <p class="description"><?php echo esc_html__('Manage tickets, agenda, RSVP, and speakers from one admin panel.', 'oras-tickets'); ?></p>
                </div>

                <div class="oras-events-addon__badges" aria-label="<?php echo esc_attr__('Event addon status', 'oras-tickets'); ?>">
                    <span class="oras-events-addon__badge"><?php echo esc_html(sprintf(__('%d Tickets', 'oras-tickets'), $ticket_count)); ?></span>
                    <span class="oras-events-addon__badge"><?php echo esc_html(sprintf(__('%d Days', 'oras-tickets'), $agenda_count)); ?></span>
                    <span class="oras-events-addon__badge"><?php echo esc_html(sprintf(__('%d Speakers', 'oras-tickets'), $speaker_count)); ?></span>
                    <span class="oras-events-addon__badge"><?php echo esc_html(sprintf(__('%d Door Prizes', 'oras-tickets'), $door_prize_count)); ?></span>
                    <span class="oras-events-addon__badge <?php echo $rsvp_enabled ? 'is-success' : 'is-muted'; ?>">
                        <?php echo esc_html($rsvp_enabled ? __('RSVP Enabled', 'oras-tickets') : __('RSVP Disabled', 'oras-tickets')); ?>
                    </span>
                    <button type="button" class="button button-primary oras-events-addon-save-trigger" data-saving-label="<?php echo esc_attr($saving_event_label); ?>" data-default-label="<?php echo esc_attr($save_event_label); ?>"><?php echo esc_html($save_event_label); ?></button>
                </div>
            </header>

            <div class="oras-events-addon__tabs nav-tab-wrapper" role="tablist" aria-label="<?php echo esc_attr__('ORAS events sections', 'oras-tickets'); ?>">
                <button type="button" id="oras-events-tab-tickets" class="nav-tab oras-events-addon__tab is-active" data-tab="tickets" role="tab" aria-controls="oras-events-panel-tickets" aria-selected="true" tabindex="0"><?php echo esc_html__('Tickets', 'oras-tickets'); ?></button>
                <button type="button" id="oras-events-tab-agenda" class="nav-tab oras-events-addon__tab" data-tab="agenda" role="tab" aria-controls="oras-events-panel-agenda" aria-selected="false" tabindex="-1"><?php echo esc_html__('Agenda', 'oras-tickets'); ?></button>
                <button type="button" id="oras-events-tab-rsvp" class="nav-tab oras-events-addon__tab" data-tab="rsvp" role="tab" aria-controls="oras-events-panel-rsvp" aria-selected="false" tabindex="-1"><?php echo esc_html__('RSVP', 'oras-tickets'); ?></button>
                <button type="button" id="oras-events-tab-speakers" class="nav-tab oras-events-addon__tab" data-tab="speakers" role="tab" aria-controls="oras-events-panel-speakers" aria-selected="false" tabindex="-1"><?php echo esc_html__('Speakers', 'oras-tickets'); ?></button>
                <button type="button" id="oras-events-tab-door-prizes" class="nav-tab oras-events-addon__tab" data-tab="door-prizes" role="tab" aria-controls="oras-events-panel-door-prizes" aria-selected="false" tabindex="-1"><?php echo esc_html__('Door Prizes', 'oras-tickets'); ?></button>
            </div>

            <div class="oras-events-addon__panels">
                <section id="oras-events-panel-tickets" class="oras-events-addon__panel is-active" data-panel="tickets" role="tabpanel" aria-labelledby="oras-events-tab-tickets">
                    <div class="oras-events-addon__panel-inner">
                        <?php Tickets_Metabox::instance()->render_metabox($post); ?>
                    </div>
                </section>

                <section id="oras-events-panel-agenda" class="oras-events-addon__panel" data-panel="agenda" role="tabpanel" aria-labelledby="oras-events-tab-agenda" hidden>
                    <div class="oras-events-addon__panel-inner">
                        <?php Event_Agenda_Metabox::render($post); ?>
                    </div>
                </section>

                <section id="oras-events-panel-rsvp" class="oras-events-addon__panel" data-panel="rsvp" role="tabpanel" aria-labelledby="oras-events-tab-rsvp" hidden>
                    <div class="oras-events-addon__panel-inner">
                        <?php Event_RSVP_Metabox::render($post); ?>
                    </div>
                </section>

                <section id="oras-events-panel-speakers" class="oras-events-addon__panel" data-panel="speakers" role="tabpanel" aria-labelledby="oras-events-tab-speakers" hidden>
                    <div class="oras-events-addon__panel-inner">
                        <?php (new Event_Speakers_Metabox())->render_metabox($post); ?>
                    </div>
                </section>

                <section id="oras-events-panel-door-prizes" class="oras-events-addon__panel" data-panel="door-prizes" role="tabpanel" aria-labelledby="oras-events-tab-door-prizes" hidden>
                    <div class="oras-events-addon__panel-inner">
                        <?php Event_Door_Prizes_Metabox::render($post); ?>
                    </div>
                </section>
            </div>

            <div class="oras-events-addon__save-bar">
                <button type="button" class="button button-primary oras-events-addon-save-trigger" data-saving-label="<?php echo esc_attr($saving_event_label); ?>" data-default-label="<?php echo esc_attr($save_event_label); ?>"><?php echo esc_html($save_event_label); ?></button>
            </div>
        </div>
<?php
    }
}
