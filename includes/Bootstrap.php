<?php
namespace ORAS\Tickets;

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class Bootstrap {
  private static ?Bootstrap $instance = null;

  public static function instance(): Bootstrap {
    return self::$instance ??= new self();
  }

  private function __construct() {}

  public function init(): void {
    // Hard dependencies: TEC + Woo. We fail gracefully.
    if ( ! $this->has_tec() ) {
      add_action( 'admin_notices', [ $this, 'notice_missing_tec' ] );
      return;
    }
    if ( ! $this->has_woo() ) {
      add_action( 'admin_notices', [ $this, 'notice_missing_woo' ] );
      return;
    }

    // Load modules (empty stubs for now).
    require_once ORAS_TICKETS_PATH . 'includes/Admin/Admin.php';
    require_once ORAS_TICKETS_PATH . 'includes/Frontend/Frontend.php';
    require_once ORAS_TICKETS_PATH . 'includes/Woo/Woo.php';
    require_once ORAS_TICKETS_PATH . 'includes/Tickets/Tickets.php';

    \ORAS\Tickets\Admin\Admin::instance()->init();
    \ORAS\Tickets\Frontend\Frontend::instance()->init();
    \ORAS\Tickets\Woo\Woo::instance()->init();
    \ORAS\Tickets\Tickets\Tickets::instance()->init();
  }

  private function has_tec(): bool {
    // TEC defines Tribe__Events__Main in common installs.
    return class_exists( '\Tribe__Events__Main' ) || defined( 'TRIBE_EVENTS_FILE' );
  }

  private function has_woo(): bool {
    return class_exists( '\WooCommerce' ) || function_exists( 'WC' );
  }

  public function notice_missing_tec(): void {
    echo '<div class="notice notice-error"><p><strong>ORAS Tickets:</strong> The Events Calendar is required.</p></div>';
  }

  public function notice_missing_woo(): void {
    echo '<div class="notice notice-error"><p><strong>ORAS Tickets:</strong> WooCommerce is required.</p></div>';
  }
}
