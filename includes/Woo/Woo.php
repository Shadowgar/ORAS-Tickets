<?php
namespace ORAS\Tickets\Woo;

use ORAS\Tickets\Tickets\Tickets;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Woo {
  private static ?Woo $instance = null;
  private static array $syncing = []; // guard per event_id

  public static function instance(): Woo {
    return self::$instance ??= new self();
  }
  private function __construct() {}

  public function init(): void {
    // No hooks here yet; we call sync explicitly from save handler.
  }

  /**
   * Create/update Woo products for tickets on an event.
   * Writes product_id back into the event tickets meta.
   */
  public function sync_event_ticket_products( int $event_id ): void {
    if ( isset( self::$syncing[ $event_id ] ) ) {
      return;
    }
    self::$syncing[ $event_id ] = true;

    if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Product_Simple' ) ) {
      unset( self::$syncing[ $event_id ] );
      return;
    }

    $tickets = Tickets::instance()->get_event_tickets( $event_id );
    if ( empty( $tickets ) ) {
      unset( self::$syncing[ $event_id ] );
      return;
    }

    $event_title = (string) get_the_title( $event_id );
    $cat_id      = $this->ensure_ticket_category();
    $active_ids  = [];

    foreach ( $tickets as $i => $t ) {
      if ( ! is_array( $t ) ) continue;

      $key = (string) ( $t['key'] ?? '' );
      if ( $key === '' ) continue;

      $name     = (string) ( $t['name'] ?? '' );
      $price    = (string) ( $t['price'] ?? '0' );
      $capacity = (int) ( $t['capacity'] ?? 0 );
      $desc     = (string) ( $t['description'] ?? '' );

      $existing_product_id = (int) ( $t['product_id'] ?? 0 );

      $product_id = $this->upsert_ticket_product(
        $event_id,
        $event_title,
        $key,
        $name,
        $price,
        $capacity,
        $desc,
        $existing_product_id,
        $cat_id
      );

      if ( $product_id > 0 ) {
        $tickets[ $i ]['product_id'] = $product_id;
        $active_ids[] = $product_id;
      }
    }

    // Save updated product_id mappings back onto the event.
    Tickets::instance()->set_event_tickets( $event_id, $tickets );

    // Optional safety: deactivate orphaned products (tickets removed).
    $this->deactivate_orphaned_products( $event_id, $active_ids );

    unset( self::$syncing[ $event_id ] );
  }

  private function upsert_ticket_product(
    int $event_id,
    string $event_title,
    string $ticket_key,
    string $ticket_name,
    string $price,
    int $capacity,
    string $desc,
    int $existing_product_id,
    int $cat_id
  ): int {
    $ticket_label = trim( $ticket_name ) !== '' ? trim( $ticket_name ) : 'Ticket';
    $product_title = trim( $event_title . ' — ' . $ticket_label );

    $product = null;

    if ( $existing_product_id > 0 ) {
      $p = wc_get_product( $existing_product_id );
      if ( $p && $p->get_type() === 'simple' ) {
        $product = $p;
      }
    }

    if ( ! $product ) {
      $product = new \WC_Product_Simple();
      $product->set_status( 'publish' );
      $product->set_catalog_visibility( 'hidden' );
      $product->set_virtual( true );
    }

    // Normalize price to numeric string.
    $price = preg_replace( '/[^0-9.]/', '', $price );
    if ( $price === '' ) $price = '0';

    $product->set_name( $product_title );
    $product->set_regular_price( $price );
    $product->set_description( $desc );

    // Inventory == capacity (simple, reliable).
    $capacity = max( 0, $capacity );
    $product->set_manage_stock( true );
    $product->set_stock_quantity( $capacity );
    $product->set_stock_status( $capacity > 0 ? 'instock' : 'outofstock' );

    // Helpful categorization (optional).
    if ( $cat_id > 0 ) {
      $product->set_category_ids( [ $cat_id ] );
    }

    // Link back to event + ticket key.
    $product->update_meta_data( '_oras_event_id', $event_id );
    $product->update_meta_data( '_oras_ticket_key', $ticket_key );
    $product->update_meta_data( '_oras_ticket_label', $ticket_label );

    // Keep products out of search results/catalog.
    $product->set_catalog_visibility( 'hidden' );

    $product_id = (int) $product->save();

    return $product_id;
  }

  private function ensure_ticket_category(): int {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
      return 0;
    }

    $slug = 'oras-event-tickets';
    $term = get_term_by( 'slug', $slug, 'product_cat' );
    if ( $term && ! is_wp_error( $term ) ) {
      return (int) $term->term_id;
    }

    $created = wp_insert_term( 'ORAS Event Tickets', 'product_cat', [ 'slug' => $slug ] );
    if ( is_wp_error( $created ) ) {
      return 0;
    }

    return (int) $created['term_id'];
  }

  /**
   * If a ticket was removed from the event, we do NOT delete its old product.
   * We simply set it to draft so it can’t be purchased accidentally.
   */
  private function deactivate_orphaned_products( int $event_id, array $active_product_ids ): void {
    $active_product_ids = array_map( 'intval', $active_product_ids );

    $q = new \WP_Query([
      'post_type'      => 'product',
      'post_status'    => [ 'publish', 'draft', 'private' ],
      'posts_per_page' => 200,
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'   => '_oras_event_id',
          'value' => $event_id,
        ],
      ],
    ]);

    if ( empty( $q->posts ) ) {
      return;
    }

    foreach ( $q->posts as $pid ) {
      $pid = (int) $pid;
      if ( $pid <= 0 ) continue;

      if ( in_array( $pid, $active_product_ids, true ) ) {
        continue;
      }

      // Orphan: set to draft
      wp_update_post([
        'ID'          => $pid,
        'post_status' => 'draft',
      ]);
    }
  }
}
