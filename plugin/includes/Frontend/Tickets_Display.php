<?php
namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tickets_Display {

    private static ?Tickets_Display $instance = null;
    private bool $rendered = false;

    public static function instance(): Tickets_Display {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {
        // Hook after TEC templates are included; check template path and render on single event pages.
        // Primary preferred hook: fires after the event content/description in some TEC installs.
        add_action( 'tribe_events_single_event_after_the_content', [ $this, 'render_from_single_event_hook' ], 20 );

        // Fallback: render after template include if the primary hook is not available.
        add_action( 'tribe_template_after_include', [ $this, 'maybe_render' ], 20, 1 );
    }

    public function maybe_render( string $template ): void {
        if ( $this->rendered ) {
            return;
        }

        // Ensure the included template path is the v2 single event template.
        $tpl = (string) $template;
        $tpl_low = strtolower( $tpl );

        $is_single_event_tpl = false;
        // Match exact v2 path ending
        if ( preg_match( '~/views/v2/single-event\.php$~', $tpl_low ) ) {
            $is_single_event_tpl = true;
        }
        // Or match filename fallback
        if ( ! $is_single_event_tpl && basename( $tpl_low ) === 'single-event.php' ) {
            $is_single_event_tpl = true;
        }

        if ( ! $is_single_event_tpl ) {
            return;
        }

        if ( ! is_singular( Meta::EVENT_POST_TYPE ) ) {
            return;
        }

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) {
            return;
        }

        $this->rendered = true;
        $this->render_for_event( $event_id );
    }

    public function render_from_single_event_hook(): void {
        if ( $this->rendered ) {
            return;
        }

        if ( ! is_singular( Meta::EVENT_POST_TYPE ) ) {
            return;
        }

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) {
            return;
        }

        $this->rendered = true;
        $this->render_for_event( $event_id );
    }

    private function render_for_event( int $event_id ): void {
        $envelope = Ticket_Collection::load_for_event( $event_id );
        $tickets = isset( $envelope['tickets'] ) && is_array( $envelope['tickets'] ) ? $envelope['tickets'] : [];

        if ( empty( $tickets ) ) {
            return;
        }

        // Minimal markup: container + table of name, price, capacity.
        echo '<section class="oras-tickets-section">';
        echo '<div id="oras-tickets-display" class="oras-tickets-display">';
        echo '<h2>Tickets</h2>';
        echo '<table class="oras-tickets-table">';
        echo '<thead><tr><th>Name</th><th>Price</th><th>Status</th><th>Capacity</th></tr></thead>';
        echo '<tbody>';

        foreach ( $tickets as $ticket ) {
            if ( ! is_array( $ticket ) ) {
                continue;
            }

            $name = isset( $ticket['name'] ) ? esc_html( $ticket['name'] ) : '';
            $price_raw = isset( $ticket['price'] ) ? $ticket['price'] : '';
            if ( $price_raw !== '' && is_numeric( $price_raw ) ) {
                $price = '$' . number_format( (float) $price_raw, 2, '.', '' );
            } else {
                $price = $price_raw !== '' ? (string) $price_raw : '';
            }

            // Capacity semantics for Phase 1.4:
            // - Negative values are clamped to 0
            // - capacity <= 0 is treated as Unlimited
            $capacity_raw = isset( $ticket['capacity'] ) ? intval( $ticket['capacity'] ) : 0;
            if ( $capacity_raw < 0 ) {
                $capacity_raw = 0;
            }
            $is_unlimited = $capacity_raw <= 0;
            $capacity_text = $is_unlimited ? 'Unlimited' : (string) $capacity_raw;

            // Sale window parsing (site timezone). Strings are expected as "Y-m-d H:i".
            $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
            $sale_end = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';
            $now = (int) current_time( 'timestamp' );
            $start_ts = null;
            $end_ts = null;
            if ( $sale_start !== '' ) {
                $dt = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
                if ( $dt instanceof \DateTimeInterface ) {
                    $start_ts = (int) $dt->getTimestamp();
                }
            }
            if ( $sale_end !== '' ) {
                $dt2 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                if ( $dt2 instanceof \DateTimeInterface ) {
                    $end_ts = (int) $dt2->getTimestamp();
                }
            }

            // Determine status per rules.
            if ( $start_ts !== null && $now < $start_ts ) {
                $status = 'Not on sale yet';
            } elseif ( $end_ts !== null && $now > $end_ts ) {
                $status = 'Sale ended';
            } else {
                $status = 'On sale';
            }

            // For Phase 1.4 we do not track purchases yet; sold-out is not representable.
            // Capacity semantics: negative values are clamped to 0; capacity == 0 means Unlimited; capacity > 0 shows available number.

            echo '<tr>';
            echo '<td>' . $name . '</td>';
            echo '<td>' . esc_html( $price );

            // Optionally show sale window text formatted for site timezone using wp_date().
            // We parsed $start_ts and $end_ts above using \DateTime with wp_timezone().
            $sale_subline = '';
            if ( $start_ts !== null || $end_ts !== null ) {
                if ( $start_ts !== null && $end_ts !== null ) {
                    $start_label = wp_date( 'm/d/Y g:i A', $start_ts );
                    $end_label = wp_date( 'm/d/Y g:i A', $end_ts );
                    $sale_subline = 'Sales: ' . $start_label . ' – ' . $end_label;
                } elseif ( $start_ts !== null ) {
                    $start_label = wp_date( 'm/d/Y g:i A', $start_ts );
                    $sale_subline = 'Sales start: ' . $start_label;
                } else {
                    $end_label = wp_date( 'm/d/Y g:i A', $end_ts );
                    $sale_subline = 'Sales end: ' . $end_label;
                }
            }

            if ( $sale_subline !== '' ) {
                echo '<div class="oras-ticket-sale"><small>' . esc_html( $sale_subline ) . '</small></div>';
            }

            echo '</td>';
            echo '<td>' . esc_html( $status ) . '</td>';
            echo '<td>' . esc_html( $capacity_text ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }
}
