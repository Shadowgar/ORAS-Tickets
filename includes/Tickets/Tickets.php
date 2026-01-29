<?php
namespace ORAS\Tickets\Tickets;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Tickets {
  public const EVENT_POST_TYPE = 'tribe_events';
  public const META_KEY = '_oras_tickets';

  private static ?Tickets $instance = null;
  public static function instance(): Tickets { return self::$instance ??= new self(); }
  private function __construct() {}

  public function init(): void {
    // Intentionally empty for now.
  }

  /**
   * Returns normalized tickets array.
   * Each ticket:
   * - key (string)
   * - name (string)
   * - price (string|float)
   * - capacity (int)
   * - sale_start (string, YYYY-MM-DD or '')
   * - sale_end (string, YYYY-MM-DD or '')
   * - description (string)
   * - product_id (int)  (set later when we create Woo products)
   */
  public function get_event_tickets( int $event_id ): array {
    $raw = get_post_meta( $event_id, self::META_KEY, true );

    if ( empty( $raw ) || ! is_array( $raw ) ) {
      return [];
    }

    // Normalize.
    $out = [];
    foreach ( $raw as $t ) {
      if ( ! is_array( $t ) ) continue;

      $out[] = [
        'key'         => isset( $t['key'] ) ? (string) $t['key'] : '',
        'name'        => isset( $t['name'] ) ? (string) $t['name'] : '',
        'price'       => isset( $t['price'] ) ? (string) $t['price'] : '0',
        'capacity'    => isset( $t['capacity'] ) ? (int) $t['capacity'] : 0,
        'sale_start'  => isset( $t['sale_start'] ) ? (string) $t['sale_start'] : '',
        'sale_end'    => isset( $t['sale_end'] ) ? (string) $t['sale_end'] : '',
        'description' => isset( $t['description'] ) ? (string) $t['description'] : '',
        'product_id'  => isset( $t['product_id'] ) ? (int) $t['product_id'] : 0,
      ];
    }

    return $out;
  }

  public function set_event_tickets( int $event_id, array $tickets ): void {
    $sanitized = [];

    foreach ( $tickets as $t ) {
      if ( ! is_array( $t ) ) continue;

      $key = isset( $t['key'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $t['key'] ) : '';
      if ( $key === '' ) {
        // Must have stable key.
        continue;
      }

      $name = isset( $t['name'] ) ? sanitize_text_field( (string) $t['name'] ) : '';
	  $price = isset( $t['price'] ) ? (string) $t['price'] : '0';
      $price = str_replace( ',', '.', $price ); // allow "75,50"
      $price = preg_replace( '/[^0-9.]/', '', $price );
      if ( $price === '' ) {
      $price = '0';
      }

     if ( function_exists( 'wc_format_decimal' ) ) {
     $price = (string) wc_format_decimal( $price, 2 ); // always "xx.xx"
     } else {
  // Fallback: allow a single dot, keep at most 2 decimals
  $parts = explode( '.', $price, 3 );
  if ( count( $parts ) > 1 ) {
    $price = $parts[0] . '.' . substr( $parts[1], 0, 2 );
  } else {
    $price = $parts[0];
  }
}


      $capacity = isset( $t['capacity'] ) ? (int) $t['capacity'] : 0;
      if ( $capacity < 0 ) $capacity = 0;

      $sale_start = isset( $t['sale_start'] ) ? sanitize_text_field( (string) $t['sale_start'] ) : '';
      $sale_end   = isset( $t['sale_end'] ) ? sanitize_text_field( (string) $t['sale_end'] ) : '';

      // Simple date format constraint (YYYY-MM-DD) or empty.
      $sale_start = $this->normalize_date( $sale_start );
      $sale_end   = $this->normalize_date( $sale_end );

      $desc = isset( $t['description'] ) ? sanitize_textarea_field( (string) $t['description'] ) : '';

      $product_id = isset( $t['product_id'] ) ? (int) $t['product_id'] : 0;
      if ( $product_id < 0 ) $product_id = 0;

      $sanitized[] = [
        'key'         => $key,
        'name'        => $name,
        'price'       => $price,
        'capacity'    => $capacity,
        'sale_start'  => $sale_start,
        'sale_end'    => $sale_end,
        'description' => $desc,
        'product_id'  => $product_id,
      ];
    }

    update_post_meta( $event_id, self::META_KEY, $sanitized );
  }

  private function normalize_date( string $date ): string {
    $date = trim( $date );
    if ( $date === '' ) return '';
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return '';
    return $date;
  }
}
