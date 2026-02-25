<?php

namespace ORAS\Tickets\Admin;

require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Dashboard_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Reports_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Speaker_Obligations_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Speaker_Reports_Page.php'; // NOSONAR legacy include
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Settings_Page.php'; // NOSONAR legacy include

use ORAS\Tickets\Admin\Pages\Dashboard_Page;
use ORAS\Tickets\Admin\Pages\Reports_Page;
use ORAS\Tickets\Admin\Pages\Speaker_Obligations_Page;
use ORAS\Tickets\Admin\Pages\Speaker_Reports_Page;
use ORAS\Tickets\Admin\Pages\Settings_Page;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin_Menu
{ // NOSONAR legacy WP class naming


    public function register(): void
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_oras_tickets_export_csv', array($this, 'handle_export_csv'));
        add_action('admin_post_oras_tickets_repair_caps', array($this, 'handle_repair_caps'));
        add_action('admin_init', array(Settings_Page::class, 'register_settings'));
        (new Speaker_Obligations_Page())->register();
        (new Speaker_Reports_Page())->register();
    }

    public function register_menu(): void
    {
        $capability = 'oras_tickets_manage_events';

        add_menu_page(
            __('ORAS Tickets', 'oras-tickets'),
            __('ORAS Tickets', 'oras-tickets'),
            $capability,
            'oras-tickets',
            array($this, 'render_dashboard'),
            'dashicons-tickets-alt',
            56
        );

        // Dashboard should be the default/top submenu.
        add_submenu_page(
            'oras-tickets',
            __('Dashboard', 'oras-tickets'),
            __('Dashboard', 'oras-tickets'),
            'oras_tickets_manage_events',
            'oras-tickets',
            array($this, 'render_dashboard')
        );

        // If the Speaker CPT added its own submenu (via show_in_menu), remove it
        // so we can re-insert in the desired position (directly after Dashboard).
        remove_submenu_page('oras-tickets', 'edit.php?post_type=oras_speaker');

        // Speakers (links to the CPT list screen).
        add_submenu_page(
            'oras-tickets',
            __('Speakers', 'oras-tickets'),
            __('Speakers', 'oras-tickets'),
            'oras_tickets_manage_speakers',
            'edit.php?post_type=oras_speaker'
        );

        // Speaker Obligations
        add_submenu_page(
            'oras-tickets',
            __('Speaker Obligations', 'oras-tickets'),
            __('Speaker Obligations', 'oras-tickets'),
            'oras_tickets_manage_speakers',
            'oras-tickets-speaker-obligations',
            array($this, 'render_speaker_obligations')
        );

        // Speaker Reports
        add_submenu_page(
            'oras-tickets',
            __('Speaker Reports', 'oras-tickets'),
            __('Speaker Reports', 'oras-tickets'),
            'oras_tickets_manage_speakers',
            'oras-tickets-speaker-reports',
            array($this, 'render_speaker_reports')
        );

        // Settings
        add_submenu_page(
            'oras-tickets',
            __('Settings', 'oras-tickets'),
            __('Settings', 'oras-tickets'),
            'oras_tickets_manage_settings',
            'oras-tickets-settings',
            array($this, 'render_settings')
        );
    }

    public function render_dashboard(): void
    {
        (new Dashboard_Page())->render();
    }

    public function render_reports(): void
    {
        (new Reports_Page())->render();
    }

    public function render_settings(): void
    {
        (new Settings_Page())->render();
    }

    public function render_speaker_obligations(): void
    {
        (new Speaker_Obligations_Page())->render();
    }

    public function render_speaker_reports(): void
    {
        (new Speaker_Reports_Page())->render();
    }

    public function handle_export_csv(): void
    {
        if (! current_user_can('oras_tickets_export_reports')) {
            wp_die(esc_html__('Not allowed.', 'oras-tickets'), '', array('response' => 403));
        }

        if (! isset($_POST['oras_tickets_reports_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['oras_tickets_reports_nonce']), 'oras_tickets_reports')) {
            wp_die(esc_html__('Invalid request.', 'oras-tickets'), '', array('response' => 400));
        }

        (new Reports_Page())->export_csv();
    }

    public function handle_repair_caps(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'oras-tickets'), '', array('response' => 403));
        }

        if (! isset($_POST['oras_repair_caps_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['oras_repair_caps_nonce']), 'oras_repair_caps')) {
            wp_die(esc_html__('Invalid request.', 'oras-tickets'), '', array('response' => 400));
        }

        \ORAS\Tickets\Capabilities::add_caps();

        $redirect = wp_get_referer() ?: admin_url();
        $redirect = add_query_arg(array('oras_caps' => 'repaired'), $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if ('toplevel_page_oras-tickets' !== $hook_suffix) {
            return;
        }

        wp_enqueue_script(
            'oras-dashboard-rsvp',
            ORAS_TICKETS_URL . 'assets/admin/dashboard-rsvp.js',
            array('jquery'),
            ORAS_TICKETS_VERSION,
            true
        );

        // Add inline script to define the global object
        wp_add_inline_script(
            'oras-dashboard-rsvp',
            'var orasDashboardRsvp = ' . wp_json_encode(
                array(
                    'ajaxUrl'       => admin_url('admin-ajax.php'),
                    'adminPostUrl'  => admin_url('admin-post.php'),
                    'adminBaseUrl'  => admin_url('/'),
                    'nonce'         => wp_create_nonce('oras_rsvp_dashboard'),
                )
            ) . ';',
            'before'
        );
    }
}
