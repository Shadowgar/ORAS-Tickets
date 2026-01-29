<?php
namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Tickets\Tickets;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Frontend {
  private static ?Frontend $instance = null;

  public static function instance(): Frontend {
    return self::$instance ??= new self();
  }
  private function __construct() {}

  public function init(): void {
    add_filter( 'the_content', [ $this, 'append_tickets_module_to_event' ], 50 );
    add_action( 'template_redirect', [ $this, 'handle_add_to_cart_submit' ] );
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

  public function append_tickets_module_to_event( string $content ): string {
    if ( ! is_singular( Tickets::EVENT_POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
      return $content;
    }

    $event_id = get_the_ID();
    if ( ! $event_id ) {
      return $content;
    }

    $tickets = Tickets::instance()->get_event_tickets( (int) $event_id );
    if ( empty( $tickets ) ) {
      return $content;
    }

    // Only show if at least one ticket has a product_id.
    $has_any_product = false;
    foreach ( $tickets as $t ) {
      if ( ! empty( $t['product_id'] ) ) { $has_any_product = true; break; }
    }
    if ( ! $has_any_product ) {
      return $content;
    }

    $content .= $this->render_module( (int) $event_id, $tickets );
    return $content;
  }

  private function render_module( int $event_id, array $tickets ): string {
    if ( ! function_exists( 'wc_get_product' ) ) {
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
                $price_str   = (string) ( $t['price'] ?? '0' );
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
                  <?php
                    if ( $remaining === null ) {
                      echo esc_html( '—' );
                    } else {
                      echo esc_html( (string) $remaining );
                    }
                  ?>
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

      // Respect stock.
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

    // Redirect back to cart if anything was added, else back to the event.
    if ( $added_any ) {
      wp_safe_redirect( wc_get_cart_url() );
      exit;
    }

    wp_safe_redirect( get_permalink( $event_id ) );
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

    // Convert in site timezone.
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
