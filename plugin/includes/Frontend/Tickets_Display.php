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
        echo '<thead><tr><th>Name</th><th>Price</th><th>Capacity</th></tr></thead>';
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
            $capacity = isset( $ticket['capacity'] ) ? intval( $ticket['capacity'] ) : 0;

            echo '<tr>';
            echo '<td>' . $name . '</td>';
            echo '<td>' . $price;

            // Optionally show sale window text (no enforcement).
            $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
            $sale_end = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';
            if ( $sale_start !== '' || $sale_end !== '' ) {
                echo '<div class="oras-ticket-sale">';
                if ( $sale_start !== '' ) {
                    echo '<small>Starts: ' . esc_html( $sale_start ) . '</small>';
                }
                if ( $sale_end !== '' ) {
                    echo '<small> Ends: ' . esc_html( $sale_end ) . '</small>';
                }
                echo '</div>';
            }

            echo '</td>';
            echo '<td>' . esc_html( (string) $capacity ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }
}
