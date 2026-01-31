<?php
namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Tickets\Tickets;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Frontend {
  private static ?Frontend $instance = null;
  private bool $did_auto_insert = false;

  public static function instance(): Frontend {
    return self::$instance ??= new self();
  }
  private function __construct() {}

  public function init(): void {
    // Auto-insert below event description in TEC v2 views.
    add_action(
      'tribe_template_after_include:events/v2/single-event/description',
      [ $this, 'render_after_event_description' ]
    );

    // Shortcode (still useful for templates/builders).
    add_shortcode( 'oras_tickets', [ $this, 'shortcode_oras_tickets' ] );

    // Handle add-to-cart posts.
    add_action( 'template_redirect', [ $this, 'handle_add_to_cart_submit' ] );

    // Front-end styles.
    add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
  }

  public function enqueue_assets(): void {
    if ( ! is_singular( Tickets::EVENT_POST_TYPE ) ) {
      return;
    }

    wp_enqueue_style(
      'oras-tickets-frontend',
      ORAS_TICKETS_URL . 'assets/frontend/frontend.css',
      [],
      ORAS_TICKETS_VERSION
    );
  }

  /**
   * Render tickets below the TEC v2 event description template.
   * Safe guards:
   * - single tribe_events only
   * - runs once
   * - does not double-insert if shortcode already present
   */
  public function render_after_event_description(): void {
    if ( $this->did_auto_insert ) {
      return;
    }

    if ( ! is_singular( Tickets::EVENT_POST_TYPE ) ) {
      return;
    }

    // Prevent double insert if user placed shortcode manually.
    $post = get_post( get_the_ID() );
    if ( $post && stripos( (string) $post->post_content, '[oras_tickets' ) !== false ) {
      return;
    }

    // Only insert if this event actually has synced ticket products.
    $event_id = (int) get_the_ID();
    if ( $event_id <= 0 ) {
      return;
    }

    $tickets = Tickets::instance()->get_event_tickets( $event_id );
    if ( empty( $tickets ) ) {
      return;
    }

    $has_any_product = false;
    foreach ( $tickets as $t ) {
      if ( (int) ( $t['product_id'] ?? 0 ) > 0 ) {
        $has_any_product = true;
        break;
      }
    }
    if ( ! $has_any_product ) {
      return;
    }

    $this->did_auto_insert = true;
    echo do_shortcode( '[oras_tickets]' );
  }

  public function shortcode_oras_tickets( $atts = [] ): string {
    $event_id = 0;

    if ( is_singular( Tickets::EVENT_POST_TYPE ) ) {
      $event_id = (int) get_the_ID();
    }

    // Allow [oras_tickets event_id="123"]
    if ( is_array( $atts ) && isset( $atts['event_id'] ) ) {
      $event_id = (int) $atts['event_id'];
    }

    if ( $event_id <= 0 ) {
      return '';
    }

    return $this->render_module( $event_id );
  }

  private function render_module( int $event_id ): string {
    if ( ! function_exists( 'wc_get_product' ) ) {
      return '';
    }

    $tickets = Tickets::instance()->get_event_tickets( $event_id );
    if ( empty( $tickets ) ) {
      return '';
    }

    // Only show if at least one ticket has a product_id.
    $has_any_product = false;
    foreach ( $tickets as $t ) {
      if ( (int) ( $t['product_id'] ?? 0 ) > 0 ) { $has_any_product = true; break; }
    }
    if ( ! $has_any_product ) {
      return '';
    }

    $now = current_time( 'timestamp' );

    ob_start();
    ?>
    <div class="oras-tickets-frontend">
      <h2>Tickets</h2>

      <form method="post" class="oras-tickets-form">
        <?php wp_nonce_field( 'oras_tickets_add_to_cart', 'oras_tickets_nonce' ); ?>
        <input type="hidden" name="oras_tickets_action" value="add_to_cart">
        <input type="hidden" name="oras_event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">

        <table class="oras-tickets-table">
          <thead>
            <tr>
              <th>Ticket</th>
              <th>Price</th>
              <th>Remaining</th>
              <th>Qty</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ( $tickets as $t ): ?>
              <?php
                $ticket_name = (string) ( $t['name'] ?? '' );
                $sale_start  = (string) ( $t['sale_start'] ?? '' );
                $sale_end    = (string) ( $t['sale_end'] ?? '' );
                $product_id  = (int) ( $t['product_id'] ?? 0 );

                if ( $product_id <= 0 ) {
                  continue;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                  continue;
                }

                $remaining = null;
                if ( $product->managing_stock() ) {
                  $remaining = (int) $product->get_stock_quantity();
                }

                $is_in_window = $this->is_in_sale_window( $now, $sale_start, $sale_end );
                $is_sold_out  = ( $remaining !== null && $remaining <= 0 );

                $disabled = ( ! $is_in_window || $is_sold_out );
              ?>
              <tr class="<?php echo $disabled ? 'is-disabled' : ''; ?>">
                <td>
                  <strong><?php echo esc_html( $ticket_name !== '' ? $ticket_name : $product->get_name() ); ?></strong>
                </td>
                <td>
                  <?php echo wp_kses_post( $product->get_price_html() ); ?>
                </td>
                <td>
                  <?php echo esc_html( $remaining === null ? '—' : (string) $remaining ); ?>
                </td>
                <td>
                  <input
                    type="number"
                    min="0"
                    step="1"
                    name="oras_qty[<?php echo esc_attr( (string) $product_id ); ?>]"
                    value="0"
                    <?php echo $disabled ? 'disabled' : ''; ?>
                  >
                  <?php if ( ! $is_in_window ): ?>
                    <div class="oras-ticket-note">
                      Not on sale<?php echo $this->sale_window_label( $sale_start, $sale_end ); ?>
                    </div>
                  <?php elseif ( $is_sold_out ): ?>
                    <div class="oras-ticket-note">Sold out</div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="oras-tickets-actions">
          <button type="submit" class="button">Add selected tickets to cart</button>
        </div>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public function handle_add_to_cart_submit(): void {
    if ( ! function_exists( 'WC' ) ) {
      return;
    }
    if ( empty( $_POST['oras_tickets_action'] ) || $_POST['oras_tickets_action'] !== 'add_to_cart' ) {
      return;
    }

    $nonce = isset( $_POST['oras_tickets_nonce'] ) ? (string) wp_unslash( $_POST['oras_tickets_nonce'] ) : '';
    if ( ! wp_verify_nonce( $nonce, 'oras_tickets_add_to_cart' ) ) {
      return;
    }

    $event_id = isset( $_POST['oras_event_id'] ) ? (int) $_POST['oras_event_id'] : 0;
    if ( $event_id <= 0 ) {
      return;
    }

    $tickets = Tickets::instance()->get_event_tickets( $event_id );
    $allowed = [];
    foreach ( $tickets as $t ) {
      $pid = (int) ( $t['product_id'] ?? 0 );
      if ( $pid > 0 ) {
        $allowed[ $pid ] = [
          'sale_start' => (string) ( $t['sale_start'] ?? '' ),
          'sale_end'   => (string) ( $t['sale_end'] ?? '' ),
        ];
      }
    }

    $qty_map = isset( $_POST['oras_qty'] ) && is_array( $_POST['oras_qty'] )
      ? (array) $_POST['oras_qty']
      : [];

    $now = current_time( 'timestamp' );
    $added_any = false;

    foreach ( $qty_map as $pid => $qty_raw ) {
      $pid = (int) $pid;
      $qty = (int) $qty_raw;

      if ( $pid <= 0 || $qty <= 0 ) continue;
      if ( ! isset( $allowed[ $pid ] ) ) continue;

      $window = $allowed[ $pid ];
      if ( ! $this->is_in_sale_window( $now, $window['sale_start'], $window['sale_end'] ) ) {
        continue;
      }

      $product = wc_get_product( $pid );
      if ( ! $product ) continue;

      if ( $product->managing_stock() ) {
        $stock = (int) $product->get_stock_quantity();
        if ( $stock <= 0 ) continue;
        if ( $qty > $stock ) $qty = $stock;
      }

      $ok = WC()->cart->add_to_cart( $pid, $qty );
      if ( $ok ) {
        $added_any = true;
      }
    }

    wp_safe_redirect( $added_any ? wc_get_cart_url() : get_permalink( $event_id ) );
    exit;
  }

  private function is_in_sale_window( int $now_ts, string $start, string $end ): bool {
    $start_ts = $this->ymd_to_ts( $start, 'start' );
    $end_ts   = $this->ymd_to_ts( $end, 'end' );

    if ( $start_ts && $now_ts < $start_ts ) return false;
    if ( $end_ts && $now_ts > $end_ts ) return false;

    return true;
  }

  private function ymd_to_ts( string $ymd, string $mode ): int {
    $ymd = trim( $ymd );
    if ( $ymd === '' ) return 0;
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) return 0;

    $time = ( $mode === 'end' ) ? '23:59:59' : '00:00:00';
    $dt = date_create( $ymd . ' ' . $time, wp_timezone() );
    if ( ! $dt ) return 0;
    return (int) $dt->getTimestamp();
  }

  private function sale_window_label( string $start, string $end ): string {
    $start = trim( $start );
    $end = trim( $end );

    if ( $start === '' && $end === '' ) return '';
    if ( $start !== '' && $end === '' ) return ' (starts ' . esc_html( $start ) . ')';
    if ( $start === '' && $end !== '' ) return ' (ends ' . esc_html( $end ) . ')';
    return ' (' . esc_html( $start ) . ' to ' . esc_html( $end ) . ')';
  }
}
