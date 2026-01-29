<?php
namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Tickets\Tickets;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Admin {
  private static ?Admin $instance = null;
  public static function instance(): Admin { return self::$instance ??= new self(); }
  private function __construct() {}

  public function init(): void {
    add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ], 20 );
    add_action( 'save_post', [ $this, 'save_event_tickets' ], 10, 2 );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
  }

  public function register_meta_boxes(): void {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== Tickets::EVENT_POST_TYPE ) {
      return;
    }

    add_meta_box(
      'oras_tickets_metabox',
      'ORAS Tickets',
      [ $this, 'render_tickets_metabox' ],
      Tickets::EVENT_POST_TYPE,
      'normal',
      'high'
    );
  }

  public function render_tickets_metabox( \WP_Post $post ): void {
    $tickets = Tickets::instance()->get_event_tickets( $post->ID );

    wp_nonce_field( 'oras_tickets_save', 'oras_tickets_nonce' );

    echo '<p>Add ticket types for this event. These will later sync to hidden WooCommerce products.</p>';

    echo '<div id="oras-tickets-app" data-event-id="' . esc_attr( (string) $post->ID ) . '"></div>';

    // Pass initial data as JSON in a script tag (safe + simple).
    echo '<script type="application/json" id="oras-tickets-initial">';
    echo wp_json_encode( $tickets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    echo '</script>';

    // Fallback: if JS fails, at least store a hidden field (JS writes to it).
    echo '<input type="hidden" id="oras_tickets_payload" name="oras_tickets_payload" value="" />';
  }

  public function save_event_tickets( int $post_id, \WP_Post $post ): void {
    if ( $post->post_type !== Tickets::EVENT_POST_TYPE ) {
      return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
      return;
    }

    if ( ! isset( $_POST['oras_tickets_nonce'] ) || ! wp_verify_nonce( $_POST['oras_tickets_nonce'], 'oras_tickets_save' ) ) {
      return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
      return;
    }

    // Expect JSON payload from JS.
    $payload = isset( $_POST['oras_tickets_payload'] ) ? (string) wp_unslash( $_POST['oras_tickets_payload'] ) : '';

    // If empty, interpret as "no tickets".
    if ( $payload === '' ) {
      Tickets::instance()->set_event_tickets( $post_id, [] );
      return;
    }

    $decoded = json_decode( $payload, true );

    if ( ! is_array( $decoded ) ) {
      // Don’t wipe existing on malformed input; just bail.
      return;
    }

    Tickets::instance()->set_event_tickets( $post_id, $decoded );
	\ORAS\Tickets\Woo\Woo::instance()->sync_event_ticket_products( $post_id );

  }

  public function enqueue_admin_assets( string $hook ): void {
    // Only load on event edit screens.
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== Tickets::EVENT_POST_TYPE ) {
      return;
    }
    if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
      return;
    }

    wp_enqueue_style(
      'oras-tickets-admin',
      ORAS_TICKETS_URL . 'assets/admin/admin.css',
      [],
      ORAS_TICKETS_VERSION
    );

    wp_enqueue_script(
      'oras-tickets-admin',
      ORAS_TICKETS_URL . 'assets/admin/admin.js',
      [],
      ORAS_TICKETS_VERSION,
      true
    );
  }
}
