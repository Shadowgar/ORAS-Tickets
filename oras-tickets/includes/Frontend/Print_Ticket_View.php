<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$event_title = isset( $data['event_title'] ) ? (string) $data['event_title'] : '';
$event_start = isset( $data['event_start'] ) ? (string) $data['event_start'] : '';
$event_id    = isset( $data['event_id'] ) ? (int) $data['event_id'] : 0;
$order_id    = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;
$items       = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

$format_datetime = static function ( ?string $iso ): string {
    if ( ! is_string( $iso ) || $iso === '' ) {
        return __( 'TBD', 'oras-tickets' );
    }

    $timestamp = strtotime( $iso );
    if ( ! $timestamp ) {
        return __( 'TBD', 'oras-tickets' );
    }

    $date_format = (string) get_option( 'date_format' );
    $time_format = (string) get_option( 'time_format' );
    $format      = trim( $date_format . ' ' . $time_format );

    return wp_date( $format !== '' ? $format : 'M j, Y g:i a', $timestamp );
};

$format_price = static function ( float $price, string $currency ): string {
    if ( function_exists( 'wc_price' ) ) {
        return (string) wc_price( $price, array( 'currency' => $currency ) );
    }

    return esc_html( number_format( $price, 2 ) );
};

$wc_order       = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
$purchaser_name = '';
$purchase_date  = '';
if ( $wc_order && $wc_order instanceof \WC_Order ) {
    $user           = $wc_order->get_user();
    $display_name   = $user ? (string) $user->display_name : '';
    $first          = (string) $wc_order->get_billing_first_name();
    $last           = (string) $wc_order->get_billing_last_name();
    $billing_name   = trim( $first . ' ' . $last );
    $purchaser_name = $display_name !== '' ? $display_name : $billing_name;

    $date_created  = $wc_order->get_date_created();
    $purchase_date = $date_created ? $date_created->date( 'c' ) : '';
}

$location_text = '';
if ( $event_id > 0 && function_exists( 'tribe_get_venue_id' ) ) {
    $venue_id = (int) tribe_get_venue_id( $event_id );
    if ( $venue_id > 0 ) {
        $venue_name = function_exists( 'tribe_get_venue' ) ? (string) tribe_get_venue( $event_id ) : '';
        $address    = function_exists( 'tribe_get_address' ) ? (string) tribe_get_address( $venue_id ) : '';
        $city       = function_exists( 'tribe_get_city' ) ? (string) tribe_get_city( $venue_id ) : '';
        $state      = function_exists( 'tribe_get_state' ) ? (string) tribe_get_state( $venue_id ) : '';
        $province   = function_exists( 'tribe_get_province' ) ? (string) tribe_get_province( $venue_id ) : '';
        $zip        = function_exists( 'tribe_get_zip' ) ? (string) tribe_get_zip( $venue_id ) : '';
        $country    = function_exists( 'tribe_get_country' ) ? (string) tribe_get_country( $venue_id ) : '';

        $region = $state !== '' ? $state : $province;
        $parts  = array_filter(
            array(
                $venue_name,
                $address,
                $city,
                $region,
                $zip,
                $country,
            ),
            static function ( $part ): bool {
                return '' !== $part;
            }
        );

        $location_text = implode( ', ', $parts );
    }
}

if ( $location_text === '' && $event_id > 0 && function_exists( 'tribe_get_full_address' ) ) {
    $location_text = (string) tribe_get_full_address( $event_id );
}

if ( $location_text === '' && $event_id > 0 && function_exists( 'tribe_get_venue' ) ) {
    $location_text = (string) tribe_get_venue( $event_id );
}

if ( $location_text === '' && $event_id > 0 ) {
    $location_text = (string) get_post_meta( $event_id, '_EventVenue', true );
}

$location_text = wp_strip_all_tags( $location_text );

$event_start_display   = $format_datetime( $event_start );
$purchase_date_display = $format_datetime( $purchase_date );
$printed_name          = $purchaser_name !== '' ? $purchaser_name : __( 'Guest', 'oras-tickets' );

?>
<div class="oras-ticket-print">
    <?php foreach ( $items as $item ) : ?>
        <?php
        $ticket_name = isset( $item['ticket_name'] ) ? (string) $item['ticket_name'] : '';
        $item_id     = isset( $item['item_id'] ) ? (int) $item['item_id'] : 0;
        $quantity    = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
        $price       = isset( $item['unit_price'] ) ? (float) $item['unit_price'] : 0.0;
        $currency    = isset( $item['currency'] ) ? (string) $item['currency'] : '';
        $phase_label = isset( $item['phase_label'] ) ? (string) $item['phase_label'] : '';
        ?>

        <?php for ( $i = 0; $i < $quantity; $i++ ) : ?>
            <?php
            $sequence_label = sprintf(
                /* translators: 1: current ticket number, 2: total tickets */
                __( 'Ticket %1$d of %2$d', 'oras-tickets' ),
                $i + 1,
                $quantity
            );

            $checkin_token = '';
            $verify_url    = '';
            if ( $event_id > 0 && $order_id > 0 && $item_id > 0 && class_exists( '\\ORAS\\Tickets\\Security\\TicketCheckinToken' ) ) {
                $issued        = \ORAS\Tickets\Security\TicketCheckinToken::issue( $order_id, $event_id, $item_id, $i + 1 );
                $checkin_token = isset( $issued['token'] ) ? (string) $issued['token'] : '';
                $verify_url    = isset( $issued['verify_url'] ) ? (string) $issued['verify_url'] : '';
            }
            ?>
            <article class="oras-ticket-card">
                <header class="oras-ticket-header">
                    <div class="oras-ticket-brand">ORAS</div>
                    <div class="oras-ticket-event">
                        <div class="oras-ticket-event-title"><?php echo esc_html( $event_title ); ?></div>
                        <div class="oras-ticket-event-meta">
                            <span class="oras-ticket-event-time"><?php echo esc_html( $event_start_display ); ?></span>
                            <?php if ( $location_text !== '' ) : ?>
                                <span class="oras-ticket-event-location"><?php echo esc_html( $location_text ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="oras-ticket-mark">Ticket</div>
                </header>

                <div class="oras-ticket-body">
                    <div class="oras-ticket-main">
                        <div class="oras-ticket-row">
                            <div class="oras-ticket-label"><?php echo esc_html__( 'Ticket name', 'oras-tickets' ); ?></div>
                            <div class="oras-ticket-value"><?php echo esc_html( $ticket_name ); ?></div>
                        </div>
                        <div class="oras-ticket-row">
                            <div class="oras-ticket-label"><?php echo esc_html__( 'Order number', 'oras-tickets' ); ?></div>
                            <div class="oras-ticket-value">#<?php echo esc_html( (string) $order_id ); ?></div>
                        </div>
                        <div class="oras-ticket-row">
                            <div class="oras-ticket-label"><?php echo esc_html__( 'Purchaser', 'oras-tickets' ); ?></div>
                            <div class="oras-ticket-value"><?php echo esc_html( $printed_name ); ?></div>
                        </div>
                        <div class="oras-ticket-row">
                            <div class="oras-ticket-label"><?php echo esc_html__( 'Purchase date', 'oras-tickets' ); ?></div>
                            <div class="oras-ticket-value"><?php echo esc_html( $purchase_date_display ); ?></div>
                        </div>
                        <div class="oras-ticket-row">
                            <div class="oras-ticket-label"><?php echo esc_html__( 'Price', 'oras-tickets' ); ?></div>
                            <div class="oras-ticket-value oras-ticket-price"><?php echo wp_kses_post( $format_price( $price, $currency ) ); ?></div>
                        </div>
                        <div class="oras-ticket-row">
                            <div class="oras-ticket-label"><?php echo esc_html__( 'Pricing phase', 'oras-tickets' ); ?></div>
                            <div class="oras-ticket-value"><?php echo esc_html( $phase_label ); ?></div>
                        </div>
                        <?php if ( $checkin_token !== '' ) : ?>
                            <div class="oras-ticket-row">
                                <div class="oras-ticket-label"><?php echo esc_html__( 'Check-in token', 'oras-tickets' ); ?></div>
                                <div class="oras-ticket-value"><?php echo esc_html( $checkin_token ); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="oras-ticket-stub">
                        <div class="oras-ticket-stub-title"><?php echo esc_html__( 'Admit One', 'oras-tickets' ); ?></div>
                        <div class="oras-ticket-stub-seq"><?php echo esc_html( $sequence_label ); ?></div>
                        <div class="oras-ticket-stub-barcode" aria-hidden="true"></div>
                        <div class="oras-ticket-stub-order">#<?php echo esc_html( (string) $order_id ); ?></div>
                        <div class="oras-ticket-stub-event"><?php echo esc_html( $event_title ); ?></div>
                        <?php if ( $verify_url !== '' ) : ?>
                            <div class="oras-ticket-stub-order"><?php echo esc_html__( 'Verify URL', 'oras-tickets' ); ?></div>
                            <div class="oras-ticket-stub-event"><?php echo esc_html( $verify_url ); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="oras-ticket-footer">
                    <span class="oras-ticket-footer-label"><?php echo esc_html__( 'Printed for', 'oras-tickets' ); ?></span>
                    <span class="oras-ticket-footer-value"><?php echo esc_html( $printed_name ); ?></span>
                </div>
            </article>
        <?php endfor; ?>
    <?php endforeach; ?>
</div>
