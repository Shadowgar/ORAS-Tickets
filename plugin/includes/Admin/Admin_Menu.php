<?php

namespace ORAS\Tickets\Admin;

require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Dashboard_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Reports_Page.php';
require_once ORAS_TICKETS_DIR . 'includes/Admin/Pages/Settings_Page.php';

use ORAS\Tickets\Admin\Pages\Dashboard_Page;
use ORAS\Tickets\Admin\Pages\Reports_Page;
use ORAS\Tickets\Admin\Pages\Settings_Page;

if (! defined('ABSPATH')) {
  exit;
}

final class Admin_Menu
{

  public function register(): void
  {
    add_action('admin_menu', [$this, 'register_menu']);
    add_action('admin_post_oras_tickets_export_csv', [$this, 'handle_export_csv']);
  }

  public function register_menu(): void
  {
    $capability = 'manage_woocommerce';

    add_menu_page(
      __('ORAS Tickets', 'oras-tickets'),
      __('ORAS Tickets', 'oras-tickets'),
      $capability,
      'oras-tickets',
      [$this, 'render_dashboard'],
      'dashicons-tickets-alt',
      56
    );

    add_submenu_page(
      'oras-tickets',
      __('Dashboard', 'oras-tickets'),
      __('Dashboard', 'oras-tickets'),
      $capability,
      'oras-tickets',
      [$this, 'render_dashboard']
    );

    add_submenu_page(
      'oras-tickets',
      __('Reports', 'oras-tickets'),
      __('Reports', 'oras-tickets'),
      $capability,
      'oras-tickets-reports',
      [$this, 'render_reports']
    );

    add_submenu_page(
      'oras-tickets',
      __('Settings', 'oras-tickets'),
      __('Settings', 'oras-tickets'),
      $capability,
      'oras-tickets-settings',
      [$this, 'render_settings']
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

  public function handle_export_csv(): void
  {
    (new Reports_Page())->export_csv();
  }
}
