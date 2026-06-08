<?php

namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Domain\Ticket_Collection;
use ORAS\Tickets\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tickets_Metabox { // NOSONAR legacy WP class naming






    private static ?Tickets_Metabox $instance = null;

    private function assetVersion( string $relative_path ): string {
        $full_path = ORAS_TICKETS_DIR . ltrim( $relative_path, '/' );
        $mtime     = file_exists( $full_path ) ? filemtime( $full_path ) : false;

        if ( false === $mtime ) {
            return ORAS_TICKETS_VERSION;
        }

        return ORAS_TICKETS_VERSION . '.' . (string) $mtime;
    }

    public static function instance(): Tickets_Metabox {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {
        add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
        add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_admin_notices' ) );
    }

    public function enqueue_assets(): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== Meta::EVENT_POST_TYPE ) {
            return;
        }

        // Some The Events Calendar admin contexts report non-standard screen bases.
        // Once we've confirmed tribe_events post type, proceed with enqueue.

        wp_enqueue_script(
            'oras-tickets-metabox',
            ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.js',
            array(),
            $this->assetVersion( 'assets/admin/tickets-metabox.js' ),
            true
        );

        wp_enqueue_style(
            'oras-tickets-metabox',
            ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.css',
            array(),
            $this->assetVersion( 'assets/admin/tickets-metabox.css' )
        );
    }

    public function register_metabox(): void {
        add_meta_box(
            'oras_tickets_metabox',
            'ORAS Tickets',
            array( $this, 'render_metabox' ),
            Meta::EVENT_POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $envelope = Ticket_Collection::load_envelope_for_event( $post->ID );
        $tickets  = $envelope['tickets'] ?? array();

        // Nonce
        wp_nonce_field( 'oras_tickets_metabox', 'oras_tickets_metabox_nonce' );
        echo '<input type="hidden" name="oras_tickets_present" value="1" />';

        ?>
        <div id="oras-tickets-metabox">
            <div class="oras-ticket-toolbar">
                <button type="button" id="oras-add-ticket" class="button button-secondary oras-add-ticket-button">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <span><?php echo esc_html__( 'Add Ticket', 'oras-tickets' ); ?></span>
                </button>
            </div>

            <div class="notice notice-error inline oras-ticket-validation" id="oras-ticket-validation" hidden>
                <p></p>
            </div>

            <div class="oras-tickets-layout oras-tickets-layout-flex">
                <div class="oras-tickets-tabs oras-tickets-rail">
                    <div class="oras-tickets-rail-title"><?php echo esc_html__( 'Tickets', 'oras-tickets' ); ?></div>
                    <ul id="oras-ticket-tabs" class="oras-ticket-tabs-list">
                        <?php
                        foreach ( $tickets as $index => $data ) :
                            $tab_name   = isset( $data['name'] ) ? (string) $data['name'] : '';
                            $tab_label  = $tab_name !== '' ? $tab_name : sprintf( 'Ticket #%d', (int) $index );
                            $price      = isset( $data['price'] ) ? (string) $data['price'] : '0.00';
                            $attendance_mode  = $this->get_ticket_attendance_mode( is_array( $data ) ? $data : array() );
                            $attendance_label = $this->get_ticket_attendance_label( $attendance_mode );
                            $sale_start = isset( $data['sale_start'] ) ? (string) $data['sale_start'] : '';
                            $sale_end   = isset( $data['sale_end'] ) ? (string) $data['sale_end'] : '';
                            $now_ts     = current_time( 'timestamp' );
                            $start_ts   = null;
                            $end_ts     = null;
                            if ( $sale_start !== '' ) {
                                $start_dt = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
if ( $start_dt instanceof \DateTimeInterface ) {
                                    $start_ts = $start_dt->getTimestamp();
                                }
                            }
                            if ( $sale_end !== '' ) {
                                $end_dt = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                                if ( $end_dt instanceof \DateTimeInterface ) {
                                    $end_ts = $end_dt->getTimestamp();
                                }
                            }
                            if ( null === $start_ts && null === $end_ts ) {
                                $sale_status = 'Always';
                            } elseif ( null !== $start_ts && $now_ts < $start_ts ) {
                                $sale_status = 'Scheduled';
                            } elseif ( null !== $end_ts && $now_ts > $end_ts ) {
                                $sale_status = 'Ended';
                            } else {
                                $sale_status = 'On sale';
                            }
                            ?>
                            <li class="oras-ticket-tab-item">
                                <button type="button" class="button oras-ticket-tab" data-index="<?php echo esc_attr( (string) $index ); ?>">
                                    <span class="oras-ticket-tab-title"><?php echo esc_html( $tab_label ); ?></span>
                                    <span class="oras-ticket-tab-meta"><?php echo esc_html( $price . ' · ' . $attendance_label . ' · ' . $sale_status ); ?></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div id="oras-tickets-empty" class="oras-tickets-empty <?php echo empty( $tickets ) ? '' : 'is-hidden'; ?>">
                        <p class="oras-tickets-empty-text"><?php echo esc_html__( 'No tickets yet.', 'oras-tickets' ); ?></p>
                    </div>
                    <p class="oras-add-ticket-row">
                        <button type="button" class="button oras-add-ticket-trigger"><?php echo esc_html__( 'Add Ticket', 'oras-tickets' ); ?></button>
                    </p>
                </div>

                <div class="oras-ticket-panels oras-tickets-panels oras-ticket-panels-wrap">
                    <table class="widefat oras-tickets-table" id="oras-tickets-table">
                        <tbody class="oras-tickets-tbody">
                            <?php
                            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
                            $is_first_panel = true;
                            foreach ( $tickets as $index => $data ) :
                                $name           = isset( $data['name'] ) ? $data['name'] : '';
                                $price          = isset( $data['price'] ) ? $data['price'] : '0.00';
                                $price_phases   = isset( $data['price_phases'] ) && is_array( $data['price_phases'] ) ? $data['price_phases'] : array();
                                $capacity       = isset( $data['capacity'] ) ? $data['capacity'] : 0;
                                $sale_start     = isset( $data['sale_start'] ) ? $data['sale_start'] : '';
                                $sale_end       = isset( $data['sale_end'] ) ? $data['sale_end'] : '';
                                $description    = isset( $data['description'] ) ? $data['description'] : '';
                                $attendance_mode  = $this->get_ticket_attendance_mode( is_array( $data ) ? $data : array() );
                                $attendance_label = $this->get_ticket_attendance_label( $attendance_mode );
                                $hide_sold_out  = ! empty( $data['hide_sold_out'] );
                                $idx            = esc_attr( (string) $index );
                                $sale_start_val = $sale_start !== '' ? str_replace( ' ', 'T', $sale_start ) : '';
                                $sale_end_val   = $sale_end !== '' ? str_replace( ' ', 'T', $sale_end ) : '';
                                $panel_class    = $is_first_panel ? 'is-active' : 'is-hidden';
                                ?>
                                <tr class="oras-ticket-row oras-ticket-row-card" data-index="<?php echo $idx; ?>">
                                    <td class="oras-ticket-cell">
                                        <div class="oras-ticket-panel <?php echo esc_attr( $panel_class ); ?>" data-index="<?php echo $idx; ?>">
                                            <?php
                                            // Compute sale status for header summary
                                            $now_ts = current_time( 'timestamp' );
                                            $start_ts = null;
                                            $end_ts = null;
                                            if ( $sale_start !== '' ) {
                                                $start_dt = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
                                                if ( $start_dt instanceof \DateTimeInterface ) {
                                                    $start_ts = $start_dt->getTimestamp();
                                                }
                                            }
                                            if ( $sale_end !== '' ) {
                                                $end_dt = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                                                if ( $end_dt instanceof \DateTimeInterface ) {
                                                    $end_ts = $end_dt->getTimestamp();
                                                }
                                            }
                                            if ( null === $start_ts && null === $end_ts ) {
                                                $sale_status = 'Always';
                                            } elseif ( null !== $start_ts && $now_ts < $start_ts ) {
                                                $sale_status = 'Scheduled';
                                            } elseif ( null !== $end_ts && $now_ts > $end_ts ) {
                                                $sale_status = 'Ended';
                                            } else {
                                                $sale_status = 'On sale';
                                            }
                                            ?>

                                            <div class="oras-card__header">
                                                <div class="oras-card__title">
                                                    <span class="oras-card__name"><?php echo esc_html( $name !== '' ? $name : sprintf( __( 'Ticket #%d', 'oras-tickets' ), (int) $idx ) ); ?></span>
                                                    <span class="oras-card__meta"><?php echo esc_html( '$' . $price . ' · ' . $attendance_label . ' · ' . $sale_status ); ?></span>
                                                </div>
                                                <div class="oras-card__actions">
                                                    <button type="button" class="button oras-card-toggle" data-index="<?php echo $idx; ?>" aria-expanded="<?php echo $is_first_panel ? 'true' : 'false'; ?>"><?php echo esc_html__( 'Edit', 'oras-tickets' ); ?></button>
                                                    <button type="button" class="oras-remove-ticket button" title="<?php echo esc_attr__( 'Remove ticket', 'oras-tickets' ); ?>"><?php echo esc_html__( 'Remove', 'oras-tickets' ); ?></button>
                                                </div>
                                            </div>

                                            <div class="oras-card__body">
                                            <div class="panel-wrap oras-ticket-data">
                                                <ul class="oras-ticket-data-tabs wc-tabs">
                                                    <li class="general_tab"><a href="#oras_ticket_<?php echo $idx; ?>_general">General</a></li>
                                                    <li class="inventory_tab"><a href="#oras_ticket_<?php echo $idx; ?>_inventory">Inventory</a></li>
                                                    <li class="sale_window_tab"><a href="#oras_ticket_<?php echo $idx; ?>_sale_window">Sale window</a></li>
                                                    <li class="pricing_tab"><a href="#oras_ticket_<?php echo $idx; ?>_pricing">Pricing</a></li>
                                                    <li class="pricing_phases_tab"><a href="#oras_ticket_<?php echo $idx; ?>_pricing_phases">Pricing phases</a></li>
                                                </ul>
                                                <div id="oras_ticket_<?php echo $idx; ?>_general" class="panel woocommerce_options_panel">
                                                    <div class="oras-field-block">
                                                        <span class="oras-field-label"><strong>Name</strong></span><br />
                                                        <input type="text" class="oras-ticket-name-input oras-input-full" name="oras_tickets_tickets[<?php echo $idx; ?>][name]" value="<?php echo esc_attr( $name ); ?>" />
                                                    </div>
                                                    <div>
                                                        <span class="oras-field-label"><strong>Description</strong></span><br />
                                                        <textarea class="oras-textarea-full" name="oras_tickets_tickets[<?php echo $idx; ?>][description]" rows="3"><?php echo esc_textarea( $description ); ?></textarea>
                                                    </div>
                                                    <div class="oras-field-block oras-field-block-spaced">
                                                        <span class="oras-field-label"><strong><?php echo esc_html__( 'Ticket Type', 'oras-tickets' ); ?></strong></span><br />
                                                        <select class="oras-input-full oras-ticket-attendance-mode" name="oras_tickets_tickets[<?php echo $idx; ?>][attendance_mode]">
                                                            <option value="<?php echo esc_attr( Ticket::ATTENDANCE_MODE_ONSITE ); ?>" <?php selected( Ticket::ATTENDANCE_MODE_ONSITE, $attendance_mode ); ?>><?php echo esc_html__( 'On-site', 'oras-tickets' ); ?></option>
                                                            <option value="<?php echo esc_attr( Ticket::ATTENDANCE_MODE_VIRTUAL ); ?>" <?php selected( Ticket::ATTENDANCE_MODE_VIRTUAL, $attendance_mode ); ?>><?php echo esc_html__( 'Virtual', 'oras-tickets' ); ?></option>
                                                        </select>
                                                        <p class="description oras-help-text"><?php echo esc_html__( 'Choose whether this ticket grants on-site attendance or virtual access.', 'oras-tickets' ); ?></p>
                                                    </div>
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_inventory" class="panel woocommerce_options_panel oras-panel-hidden">
                                                    <span class="oras-field-label"><strong><?php echo esc_html__( 'Stock', 'oras-tickets' ); ?></strong></span><br />
                                                    <input type="number" min="0" class="oras-input-full" name="oras_tickets_tickets[<?php echo $idx; ?>][capacity]" value="<?php echo esc_attr( $capacity ); ?>" />
                                                    <p class="description oras-help-text"><?php echo esc_html__( '0 = unlimited', 'oras-tickets' ); ?></p>
                                                    <div class="oras-field-block oras-field-block-spaced">
                                                        <span class="oras-field-label"><strong>Hide sold out</strong></span><br />
                                                        <label>
                                                            <input type="checkbox" name="oras_tickets_tickets[<?php echo $idx; ?>][hide_sold_out]" value="1" <?php checked( $hide_sold_out ); ?> />
                                                            Hide when sold out
                                                        </label>
                                                    </div>
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_sale_window" class="panel woocommerce_options_panel oras-panel-hidden">
                                                    <div class="oras-field-block">
                                                        <span class="oras-field-label"><strong>Sale start</strong></span><br />
                                                        <input type="datetime-local" class="oras-input-full" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_start]" value="<?php echo esc_attr( $sale_start_val ); ?>" />
                                                    </div>
                                                    <div>
                                                        <span class="oras-field-label"><strong>Sale end</strong></span><br />
                                                        <input type="datetime-local" class="oras-input-full" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_end]" value="<?php echo esc_attr( $sale_end_val ); ?>" />
                                                    </div>
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_pricing" class="panel woocommerce_options_panel oras-panel-hidden">
                                                    <span class="oras-field-label"><strong>Price</strong></span><br />
                                                    <input type="text" class="oras-input-full" name="oras_tickets_tickets[<?php echo $idx; ?>][price]" value="<?php echo esc_attr( $price ); ?>" />
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_pricing_phases" class="panel woocommerce_options_panel oras-panel-hidden">
                                                    <div class="oras-phase-section">
                                                        <div class="oras-phase-header">Pricing phases</div>
                                                        <div class="oras-phase-toolbar">
                                                            <div class="oras-phase-help">Use phases to set time-based prices (UTC).</div>
                                                            <button type="button" class="button oras-phase-add" data-ticket-index="<?php echo $idx; ?>">Add phase</button>
                                                        </div>
                                                        <div class="oras-phase-list">
                                                            <?php if ( ! empty( $price_phases ) ) : ?>
                                                                <?php
                                                                foreach ( $price_phases as $phase_index => $phase ) :
                                                                    if ( ! is_array( $phase ) ) {
                                                                        continue;
                                                                    }
                                                                    $phase_idx   = esc_attr( (string) $phase_index );
                                                                    $phase_key   = isset( $phase['key'] ) ? (string) $phase['key'] : '';
                                                                    $phase_label = isset( $phase['label'] ) ? (string) $phase['label'] : '';
                                                                    $phase_price = isset( $phase['price'] ) ? (string) $phase['price'] : '';
                                                                    $phase_start = isset( $phase['start'] ) ? (string) $phase['start'] : '';
                                                                    $phase_end   = isset( $phase['end'] ) ? (string) $phase['end'] : '';
                                                                    $phase_start_val = $phase_start !== '' ? str_replace( ' ', 'T', $phase_start ) : '';
                                                                    $phase_end_val   = $phase_end !== '' ? str_replace( ' ', 'T', $phase_end ) : '';
                                                                    ?>
                                                                    <div class="oras-phase-item is-collapsed" data-phase-index="<?php echo $phase_idx; ?>">
                                                                        <div class="oras-phase-cardhead">
                                                                            <div class="oras-phase-cardtitle">Phase</div>
                                                                            <button type="button" class="button oras-phase-toggle">Advanced</button>
                                                                        </div>
                                                                        <div class="oras-phase-row oras-phase-row-main">
                                                                            <div>
                                                                                <span class="oras-field-label">Key</span>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][key]" value="<?php echo esc_attr( $phase_key ); ?>" />
                                                                            </div>
                                                                            <div>
                                                                                <span class="oras-field-label">Label</span>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][label]" value="<?php echo esc_attr( $phase_label ); ?>" />
                                                                            </div>
                                                                            <div>
                                                                                <span class="oras-field-label">Price</span>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][price]" value="<?php echo esc_attr( $phase_price ); ?>" />
                                                                            </div>
                                                                        </div>
                                                                        <div class="oras-phase-row oras-phase-row-advanced">
                                                                            <div>
                                                                                <span class="oras-field-label">Start (UTC)</span>
                                                                                <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][start]" value="<?php echo esc_attr( $phase_start_val ); ?>" />
                                                                            </div>
                                                                            <div>
                                                                                <span class="oras-field-label">End (UTC)</span>
                                                                                <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][end]" value="<?php echo esc_attr( $phase_end_val ); ?>" />
                                                                            </div>
                                                                            <div class="oras-phase-actions">
                                                                                <button type="button" class="button oras-phase-remove">Remove</button>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <template class="oras-phase-template" data-ticket-index="<?php echo $idx; ?>">
                                                            <div class="oras-phase-item is-collapsed" data-phase-index="__PHASE__">
                                                                <div class="oras-phase-cardhead">
                                                                    <div class="oras-phase-cardtitle">Phase</div>
                                                                    <button type="button" class="button oras-phase-toggle">Advanced</button>
                                                                </div>
                                                                <div class="oras-phase-row oras-phase-row-main">
                                                                    <div>
                                                                        <span class="oras-field-label">Key</span>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][key]" value="" />
                                                                    </div>
                                                                    <div>
                                                                        <span class="oras-field-label">Label</span>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][label]" value="" />
                                                                    </div>
                                                                    <div>
                                                                        <span class="oras-field-label">Price</span>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][price]" value="" />
                                                                    </div>
                                                                </div>
                                                                <div class="oras-phase-row oras-phase-row-advanced">
                                                                    <div>
                                                                        <span class="oras-field-label">Start (UTC)</span>
                                                                        <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][start]" value="" />
                                                                    </div>
                                                                    <div>
                                                                        <span class="oras-field-label">End (UTC)</span>
                                                                        <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][end]" value="" />
                                                                    </div>
                                                                    <div class="oras-phase-actions">
                                                                        <button type="button" class="button oras-phase-remove">Remove</button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="oras-ticket-actions">
                                                <input type="hidden" name="oras_tickets_index[]" value="<?php echo $idx; ?>" />
                                            </div>
                                        </div>
                                </div>
                                <!-- /.oras-card__body -->
                                    </td>
                                </tr>
                                <?php
                                $is_first_panel = false;
                            endforeach;
                            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="oras-ticket-summary-wrap">
                <h4><?php echo esc_html__( 'Tickets', 'oras-tickets' ); ?></h4>
                <table class="widefat striped oras-tickets-summary" id="oras-tickets-summary">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Name', 'oras-tickets' ); ?></th>
                            <th><?php echo esc_html__( 'Price', 'oras-tickets' ); ?></th>
                            <th><?php echo esc_html__( 'Type', 'oras-tickets' ); ?></th>
                            <th><?php echo esc_html__( 'Inventory', 'oras-tickets' ); ?></th>
                            <th><?php echo esc_html__( 'Sale Window', 'oras-tickets' ); ?></th>
                            <th><?php echo esc_html__( 'Status', 'oras-tickets' ); ?></th>
                            <th><?php echo esc_html__( 'Actions', 'oras-tickets' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $tickets ) ) : ?>
                            <tr class="oras-ticket-summary-empty">
                                <td colspan="7"><?php echo esc_html__( 'No tickets yet.', 'oras-tickets' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php
                            foreach ( $tickets as $index => $data ) :
                                $name = isset( $data['name'] ) ? (string) $data['name'] : '';
                                if ( '' === $name ) {
                                    $name = sprintf( __( 'Ticket #%d', 'oras-tickets' ), (int) $index );
                                }

                                $price      = isset( $data['price'] ) ? (string) $data['price'] : '0.00';
                                $attendance_mode  = $this->get_ticket_attendance_mode( is_array( $data ) ? $data : array() );
                                $attendance_label = $this->get_ticket_attendance_label( $attendance_mode );
                                $capacity   = isset( $data['capacity'] ) ? absint( $data['capacity'] ) : 0;
                                $inventory  = $capacity > 0 ? (string) $capacity : __( 'Unlimited', 'oras-tickets' );
                                $sale_start = isset( $data['sale_start'] ) ? (string) $data['sale_start'] : '';
                                $sale_end   = isset( $data['sale_end'] ) ? (string) $data['sale_end'] : '';

                                if ( '' === $sale_start && '' === $sale_end ) {
                                    $sale_window = __( 'Always', 'oras-tickets' );
                                } elseif ( '' !== $sale_start && '' !== $sale_end ) {
                                    /* translators: 1: sale start datetime, 2: sale end datetime */
                                    $sale_window = sprintf( __( '%1$s to %2$s', 'oras-tickets' ), $sale_start, $sale_end );
                                } elseif ( '' !== $sale_start ) {
                                    /* translators: %s: sale start datetime */
                                    $sale_window = sprintf( __( 'Starts %s', 'oras-tickets' ), $sale_start );
                                } else {
                                    /* translators: %s: sale end datetime */
                                    $sale_window = sprintf( __( 'Ends %s', 'oras-tickets' ), $sale_end );
                                }

                                $remaining_data = $this->get_remaining_for_ticket( $post->ID, (string) $index );
                                if ( $remaining_data['is_unlimited'] ) {
                                    $status = __( 'Unlimited', 'oras-tickets' );
                                } elseif ( null !== $remaining_data['remaining'] && (int) $remaining_data['remaining'] > 0 ) {
                                    $status = __( 'Available', 'oras-tickets' );
                                } else {
                                    $status = __( 'Sold out', 'oras-tickets' );
                                }
                                ?>
                                <tr data-ticket-index="<?php echo esc_attr( (string) $index ); ?>">
                                    <td><?php echo esc_html( $name ); ?></td>
                                    <td><?php echo esc_html( '$' . $price ); ?></td>
                                    <td><?php echo esc_html( $attendance_label ); ?></td>
                                    <td><?php echo esc_html( $inventory ); ?></td>
                                    <td><?php echo esc_html( $sale_window ); ?></td>
                                    <td><?php echo esc_html( $status ); ?></td>
                                    <td class="oras-ticket-summary-actions">
                                        <button type="button" class="button button-small oras-ticket-summary-edit" data-index="<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Edit', 'oras-tickets' ); ?></button>
                                        <button type="button" class="button button-small oras-ticket-summary-remove" data-index="<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Remove', 'oras-tickets' ); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Template row (uses <template> so it won't be submitted) -->
            <template id="oras-ticket-template">
                <tr class="oras-ticket-row oras-ticket-row-card" data-index="__INDEX__">
                    <td class="oras-ticket-cell">
                        <div class="oras-ticket-panel is-hidden" data-index="__INDEX__">
                            <div class="oras-card__header">
                                <div class="oras-card__title">
                                    <span class="oras-card__name"><?php echo esc_html__( 'New Ticket', 'oras-tickets' ); ?></span>
                                    <span class="oras-card__meta">$0.00 · <?php echo esc_html__( 'Choose type', 'oras-tickets' ); ?> · <?php echo esc_html__( 'Always', 'oras-tickets' ); ?></span>
                                </div>
                                <div class="oras-card__actions">
                                    <button type="button" class="button oras-card-toggle" data-index="__INDEX__" aria-expanded="true"><?php echo esc_html__( 'Edit', 'oras-tickets' ); ?></button>
                                    <button type="button" class="oras-remove-ticket button" title="<?php echo esc_attr__( 'Remove ticket', 'oras-tickets' ); ?>"><?php echo esc_html__( 'Remove', 'oras-tickets' ); ?></button>
                                </div>
                            </div>
                            <div class="oras-card__body">
                            <div class="panel-wrap oras-ticket-data">
                                <ul class="oras-ticket-data-tabs wc-tabs">
                                    <li class="general_tab"><a href="#oras_ticket___INDEX___general">General</a></li>
                                    <li class="inventory_tab"><a href="#oras_ticket___INDEX___inventory">Inventory</a></li>
                                    <li class="sale_window_tab"><a href="#oras_ticket___INDEX___sale_window">Sale window</a></li>
                                    <li class="pricing_tab"><a href="#oras_ticket___INDEX___pricing">Pricing</a></li>
                                    <li class="pricing_phases_tab"><a href="#oras_ticket___INDEX___pricing_phases">Pricing phases</a></li>
                                </ul>
                                <div id="oras_ticket___INDEX___general" class="panel woocommerce_options_panel">
                                    <div class="oras-field-block">
                                        <span class="oras-field-label"><strong>Name</strong></span><br />
                                        <input type="text" class="oras-ticket-name-input oras-input-full" name="oras_tickets_tickets[__INDEX__][name]" value="" />
                                    </div>
                                    <div>
                                        <span class="oras-field-label"><strong>Description</strong></span><br />
                                        <textarea class="oras-textarea-full" name="oras_tickets_tickets[__INDEX__][description]" rows="3"></textarea>
                                    </div>
                                    <div class="oras-field-block oras-field-block-spaced">
                                        <span class="oras-field-label"><strong><?php echo esc_html__( 'Ticket Type', 'oras-tickets' ); ?></strong></span><br />
                                        <select class="oras-input-full oras-ticket-attendance-mode" name="oras_tickets_tickets[__INDEX__][attendance_mode]">
                                            <option value=""><?php echo esc_html__( 'Select ticket type', 'oras-tickets' ); ?></option>
                                            <option value="<?php echo esc_attr( Ticket::ATTENDANCE_MODE_ONSITE ); ?>"><?php echo esc_html__( 'On-site', 'oras-tickets' ); ?></option>
                                            <option value="<?php echo esc_attr( Ticket::ATTENDANCE_MODE_VIRTUAL ); ?>"><?php echo esc_html__( 'Virtual', 'oras-tickets' ); ?></option>
                                        </select>
                                        <p class="description oras-help-text"><?php echo esc_html__( 'Choose whether this ticket grants on-site attendance or virtual access.', 'oras-tickets' ); ?></p>
                                    </div>
                                </div>
                                <div id="oras_ticket___INDEX___inventory" class="panel woocommerce_options_panel oras-panel-hidden">
                                    <span class="oras-field-label"><strong><?php echo esc_html__( 'Stock', 'oras-tickets' ); ?></strong></span><br />
                                    <input type="number" min="0" class="oras-input-full" name="oras_tickets_tickets[__INDEX__][capacity]" value="0" />
                                    <p class="description oras-help-text"><?php echo esc_html__( '0 = unlimited', 'oras-tickets' ); ?></p>
                                    <div class="oras-field-block oras-field-block-spaced">
                                        <span class="oras-field-label"><strong>Hide sold out</strong></span><br />
                                        <label>
                                            <input type="checkbox" name="oras_tickets_tickets[__INDEX__][hide_sold_out]" value="1" />
                                            Hide when sold out
                                        </label>
                                    </div>
                                </div>
                                <div id="oras_ticket___INDEX___sale_window" class="panel woocommerce_options_panel oras-panel-hidden">
                                    <div class="oras-field-block">
                                        <span class="oras-field-label"><strong>Sale start</strong></span><br />
                                        <input type="datetime-local" class="oras-input-full" name="oras_tickets_tickets[__INDEX__][sale_start]" value="" />
                                    </div>
                                    <div>
                                        <span class="oras-field-label"><strong>Sale end</strong></span><br />
                                        <input type="datetime-local" class="oras-input-full" name="oras_tickets_tickets[__INDEX__][sale_end]" value="" />
                                    </div>
                                </div>
                                <div id="oras_ticket___INDEX___pricing" class="panel woocommerce_options_panel oras-panel-hidden">
                                    <span class="oras-field-label"><strong>Price</strong></span><br />
                                    <input type="text" class="oras-input-full" name="oras_tickets_tickets[__INDEX__][price]" value="0.00" />
                                </div>
                                <div id="oras_ticket___INDEX___pricing_phases" class="panel woocommerce_options_panel oras-panel-hidden">
                                    <div class="oras-phase-section">
                                        <div class="oras-phase-header">Pricing phases</div>
                                        <div class="oras-phase-toolbar">
                                            <div class="oras-phase-help">Use phases to set time-based prices (UTC).</div>
                                            <button type="button" class="button oras-phase-add" data-ticket-index="__INDEX__">Add phase</button>
                                        </div>
                                        <div class="oras-phase-list"></div>
                                        <template class="oras-phase-template" data-ticket-index="__INDEX__">
                                            <div class="oras-phase-item is-collapsed" data-phase-index="__PHASE__">
                                                <div class="oras-phase-cardhead">
                                                    <div class="oras-phase-cardtitle">Phase</div>
                                                    <button type="button" class="button oras-phase-toggle">Advanced</button>
                                                </div>
                                                <div class="oras-phase-row oras-phase-row-main">
                                                    <div>
                                                        <span class="oras-field-label">Key</span>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][key]" value="" />
                                                    </div>
                                                    <div>
                                                        <span class="oras-field-label">Label</span>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][label]" value="" />
                                                    </div>
                                                    <div>
                                                        <span class="oras-field-label">Price</span>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][price]" value="" />
                                                    </div>
                                                </div>
                                                <div class="oras-phase-row oras-phase-row-advanced">
                                                    <div>
                                                        <span class="oras-field-label">Start (UTC)</span>
                                                        <input type="datetime-local" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][start]" value="" />
                                                    </div>
                                                    <div>
                                                        <span class="oras-field-label">End (UTC)</span>
                                                        <input type="datetime-local" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][end]" value="" />
                                                    </div>
                                                    <div class="oras-phase-actions">
                                                        <button type="button" class="button oras-phase-remove">Remove</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div class="oras-ticket-actions">
                                <input type="hidden" name="oras_tickets_index[]" value="__INDEX__" />
                            </div>
                            </div>
                        </div>
                    </td>
                </tr>
            </template>

        </div>



        <?php
    }

    /**
     * @return array{display:string|int,remaining:int|null,is_unlimited:bool}
     */
    private function get_remaining_for_ticket( int $event_id, string $index ): array {
        $map = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $product_id = isset( $map[ $index ] ) ? absint( $map[ $index ] ) : 0;
        if ( $product_id <= 0 ) {
            return array(
                'display'      => '—',
                'remaining'    => null,
                'is_unlimited' => false,
            );
        }

        $manage_stock = null;
        $stock_qty    = null;

        if ( function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                if ( method_exists( $product, 'get_manage_stock' ) ) {
                    $manage_stock = (bool) $product->get_manage_stock();
                }
                if ( method_exists( $product, 'get_stock_quantity' ) ) {
                    $stock_qty = $product->get_stock_quantity();
                }
            }
        }

        if ( null === $manage_stock ) {
            $manage_stock = (string) get_post_meta( $product_id, '_manage_stock', true ) === 'yes';
        }
        if ( null === $stock_qty ) {
            $stock_qty = get_post_meta( $product_id, '_stock', true );
        }

        if ( ! $manage_stock ) {
            return array(
                'display'      => esc_html__( 'Unlimited', 'oras-tickets' ),
                'remaining'    => null,
                'is_unlimited' => true,
            );
        }

        $remaining = max( 0, (int) $stock_qty );
        return array(
            'display'      => $remaining,
            'remaining'    => $remaining,
            'is_unlimited' => false,
        );
    }

    /**
     * Check if an event has TEC recurrence metadata.
     *
     * @param int $event_id Event post ID.
     * @return bool True if recurrence is detected.
     */
    private function has_tec_recurrence( int $event_id ): bool {
        if ( ! empty( get_post_meta( $event_id, '_EventRecurrence', true ) ) ) {
            return true;
        }

        if ( ! empty( get_post_meta( $event_id, '_tribe_blocks_recurrence_rules', true ) ) ) {
            return true;
        }

        if ( (int) get_post_meta( $event_id, '_EventRecurrenceID', true ) > 0 ) {
            return true;
        }

        return false;
    }

    public function save_post( int $post_id, \WP_Post $post ): void {
        // Only save for event post type
        if ( $post->post_type !== Meta::EVENT_POST_TYPE ) {
            return;
        }

        // Autosave / revision guard
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['oras_tickets_metabox_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['oras_tickets_metabox_nonce'] ), 'oras_tickets_metabox' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $metabox_present = isset( $_POST['oras_tickets_present'] ) && (string) wp_unslash( $_POST['oras_tickets_present'] ) === '1';

        if ( isset( $_POST['oras_tickets_tickets'] ) && is_array( $_POST['oras_tickets_tickets'] ) ) {
            $posted_tickets = wp_unslash( $_POST['oras_tickets_tickets'] );
        } elseif ( $metabox_present ) {
            $posted_tickets = array();
        } else {
            return;
        }

        $existing_envelope = Ticket_Collection::load_envelope_for_event( $post_id );
        $existing_tickets  = isset( $existing_envelope['tickets'] ) && is_array( $existing_envelope['tickets'] )
            ? $existing_envelope['tickets']
            : array();

        $raw           = $posted_tickets;
        $clean_tickets = array();

        // Determine posted index order if provided.
        $posted_indices = isset( $_POST['oras_tickets_index'] ) && is_array( $_POST['oras_tickets_index'] )
            ? wp_unslash( $_POST['oras_tickets_index'] )
            : null;

        if ( is_array( $posted_indices ) ) {
            // Rebuild tickets in the posted order using numeric incremental keys.
            foreach ( $posted_indices as $idx ) {
                $idx_int = absint( $idx );
                if ( $idx_int === 0 && (string) $idx !== '0' ) {
                    // non-numeric index, skip
                    continue;
                }
                $idx = (string) $idx_int;
                if ( $idx === '' || ! isset( $raw[ $idx ] ) || ! is_array( $raw[ $idx ] ) ) {
                    continue;
                }
                $fields = $raw[ $idx ];
                $name   = isset( $fields['name'] ) ? sanitize_text_field( $fields['name'] ) : '';
                // Price: normalize, ensure non-negative, two decimals
                $price_raw   = isset( $fields['price'] ) ? str_replace( ',', '.', $fields['price'] ) : '0';
                $price_float = floatval( $price_raw );
                if ( $price_float < 0 ) {
                    $price_float = 0.0;
                }
                $price = number_format( $price_float, 2, '.', '' );
                // Capacity: absolute int
                $capacity   = isset( $fields['capacity'] ) ? absint( $fields['capacity'] ) : 0;
                $sale_start = isset( $fields['sale_start'] ) ? sanitize_text_field( $fields['sale_start'] ) : '';
                $sale_end   = isset( $fields['sale_end'] ) ? sanitize_text_field( $fields['sale_end'] ) : '';
                // Accept datetime-local format (YYYY-MM-DDTHH:MM) and convert to storage format (YYYY-MM-DD HH:MM).
                if ( $sale_start !== '' ) {
                    $sale_start = str_replace( 'T', ' ', $sale_start );
                    $sale_start = trim( $sale_start );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_start ) ) {
                        $sale_start = '';
                    }
}
                if ( $sale_end !== '' ) {
                    $sale_end = str_replace( 'T', ' ', $sale_end );
                    $sale_end = trim( $sale_end );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_end ) ) {
                        $sale_end = '';
                    }
                }
                // If both present and out of order, swap to ensure start <= end
                if ( $sale_start !== '' && $sale_end !== '' ) {
                    $dt1 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
                    $dt2 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                    if ( $dt1 instanceof \DateTimeInterface && $dt2 instanceof \DateTimeInterface ) {
                        if ( $dt2->getTimestamp() < $dt1->getTimestamp() ) {
                            // swap
                            $tmp        = $sale_start;
                            $sale_start = $sale_end;
$sale_end   = $tmp;
                        }
                    }
                }
                $description     = isset( $fields['description'] ) ? sanitize_textarea_field( $fields['description'] ) : '';
                $existing_ticket  = isset( $existing_tickets[ $idx ] ) && is_array( $existing_tickets[ $idx ] ) ? $existing_tickets[ $idx ] : array();
                $attendance_mode = $this->sanitize_ticket_attendance_mode( $fields, $existing_ticket );
                $hide_sold_out   = isset( $fields['hide_sold_out'] ) && ( $fields['hide_sold_out'] === '1' || $fields['hide_sold_out'] === 1 );

                // Skip empty-default rows: name empty, description empty, sale dates empty, hide_sold_out false, capacity <=0, price <=0
                if ( $name === '' && $description === '' && $sale_start === '' && $sale_end === '' && ! $hide_sold_out && $capacity <= 0 && $price_float <= 0.0 ) {
                    continue;
                }

                $initial_capacity = null;
                if ( isset( $existing_tickets[ $idx ] ) && is_array( $existing_tickets[ $idx ] ) && array_key_exists( 'initial_capacity', $existing_tickets[ $idx ] ) ) {
                    $initial_capacity = absint( $existing_tickets[ $idx ]['initial_capacity'] );
                } else {
                    $initial_capacity = $capacity;
                }

                $price_phases_clean = array();
                $price_phases_raw   = isset( $fields['price_phases'] ) ? $fields['price_phases'] : null;
                if ( is_array( $price_phases_raw ) ) {
                    foreach ( $price_phases_raw as $phase_fields ) {
                        if ( ! is_array( $phase_fields ) ) {
                            continue;
                        }
                        $phase_key       = isset( $phase_fields['key'] ) ? sanitize_text_field( $phase_fields['key'] ) : '';
                        $phase_label     = isset( $phase_fields['label'] ) ? sanitize_text_field( $phase_fields['label'] ) : '';
                        $phase_price_raw = isset( $phase_fields['price'] ) ? str_replace( ',', '.', $phase_fields['price'] ) : '';
                        $phase_price     = is_numeric( $phase_price_raw )
                            ? number_format( (float) $phase_price_raw, 2, '.', '' )
                            : sanitize_text_field( $phase_price_raw );
                        $phase_start     = isset( $phase_fields['start'] ) ? sanitize_text_field( $phase_fields['start'] ) : '';
                        $phase_end       = isset( $phase_fields['end'] ) ? sanitize_text_field( $phase_fields['end'] ) : '';
                        if ( $phase_start !== '' ) {
                            $phase_start = str_replace( 'T', ' ', $phase_start );
                            $phase_start = trim( $phase_start );
                            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $phase_start ) ) {
                                $phase_start = '';
                            }
                        }
                        if ( $phase_end !== '' ) {
                            $phase_end = str_replace( 'T', ' ', $phase_end );
                            $phase_end = trim( $phase_end );
                            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $phase_end ) ) {
                                $phase_end = '';
                            }
                        }

                        $price_phases_clean[] = array(
                            'key'   => $phase_key,
                            'label' => $phase_label,
                            'price' => $phase_price,
                            'start' => $phase_start,
                            'end'   => $phase_end,
                        );
                    }
                }

                $ticket_row = array(
                    'name'             => $name,
                    'price'            => $price,
                    'capacity'         => $capacity,
                    'initial_capacity' => $initial_capacity,
                    'sale_start'       => $sale_start,
                    'sale_end'         => $sale_end,
                    'description'      => $description,
                    'attendance_mode'  => $attendance_mode,
                    'hide_sold_out'    => $hide_sold_out,
                );

                if ( is_array( $price_phases_raw ) ) {
                    $ticket_row['price_phases'] = $price_phases_clean;
                }

                $clean_tickets[] = $ticket_row;
            }
        } else {
            // Fallback: preserve posted order of the tickets values.
            $values   = array_values( $raw );
            $position = 0;
            foreach ( $values as $fields ) {
                if ( ! is_array( $fields ) ) {
                    continue;
                }

                $name = isset( $fields['name'] ) ? sanitize_text_field( $fields['name'] ) : '';
                // Price: normalize, ensure non-negative, two decimals
                $price_raw   = isset( $fields['price'] ) ? str_replace( ',', '.', $fields['price'] ) : '0';
                $price_float = floatval( $price_raw );
                if ( $price_float < 0 ) {
                    $price_float = 0.0;
                }
                $price = number_format( $price_float, 2, '.', '' );
                // Capacity: absolute int
                $capacity   = isset( $fields['capacity'] ) ? absint( $fields['capacity'] ) : 0;
                $sale_start = isset( $fields['sale_start'] ) ? sanitize_text_field( $fields['sale_start'] ) : '';
                $sale_end   = isset( $fields['sale_end'] ) ? sanitize_text_field( $fields['sale_end'] ) : '';
                // Accept datetime-local format (YYYY-MM-DDTHH:MM) and convert to storage format (YYYY-MM-DD HH:MM).
                if ( $sale_start !== '' ) {
                    $sale_start = str_replace( 'T', ' ', $sale_start );
                    $sale_start = trim( $sale_start );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_start ) ) {
                        $sale_start = '';
                    }
                }
                if ( $sale_end !== '' ) {
                    $sale_end = str_replace( 'T', ' ', $sale_end );
                    $sale_end = trim( $sale_end );
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_end ) ) {
                        $sale_end = '';
                    }
                }
                // If both present and out of order, swap to ensure start <= end
                if ( $sale_start !== '' && $sale_end !== '' ) {
                    $dt1 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
                    $dt2 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                    if ( $dt1 instanceof \DateTimeInterface && $dt2 instanceof \DateTimeInterface ) {
                        if ( $dt2->getTimestamp() < $dt1->getTimestamp() ) {
                            // swap
                            $tmp        = $sale_start;
                            $sale_start = $sale_end;
                            $sale_end   = $tmp;
}
                    }
                }
                $description     = isset( $fields['description'] ) ? sanitize_textarea_field( $fields['description'] ) : '';
                $existing_ticket  = isset( $existing_tickets[ $position ] ) && is_array( $existing_tickets[ $position ] ) ? $existing_tickets[ $position ] : array();
                $attendance_mode = $this->sanitize_ticket_attendance_mode( $fields, $existing_ticket );
                $hide_sold_out   = isset( $fields['hide_sold_out'] ) && ( $fields['hide_sold_out'] === '1' || $fields['hide_sold_out'] === 1 );

                // Skip empty-default rows
                if ( $name === '' && $description === '' && $sale_start === '' && $sale_end === '' && ! $hide_sold_out && $capacity <= 0 && $price_float <= 0.0 ) {
                    continue;
                }

                $initial_capacity = null;
                if ( isset( $existing_tickets[ $position ] ) && is_array( $existing_tickets[ $position ] ) && array_key_exists( 'initial_capacity', $existing_tickets[ $position ] ) ) {
                    $initial_capacity = absint( $existing_tickets[ $position ]['initial_capacity'] );
                } else {
                    $initial_capacity = $capacity;
                }

                $price_phases_clean = array();
                $price_phases_raw   = isset( $fields['price_phases'] ) ? $fields['price_phases'] : null;
                if ( is_array( $price_phases_raw ) ) {
                    foreach ( $price_phases_raw as $phase_fields ) {
                        if ( ! is_array( $phase_fields ) ) {
                            continue;
                        }
                        $phase_key       = isset( $phase_fields['key'] ) ? sanitize_text_field( $phase_fields['key'] ) : '';
                        $phase_label     = isset( $phase_fields['label'] ) ? sanitize_text_field( $phase_fields['label'] ) : '';
                        $phase_price_raw = isset( $phase_fields['price'] ) ? str_replace( ',', '.', $phase_fields['price'] ) : '';
                        $phase_price     = is_numeric( $phase_price_raw )
                            ? number_format( (float) $phase_price_raw, 2, '.', '' )
                            : sanitize_text_field( $phase_price_raw );
                        $phase_start     = isset( $phase_fields['start'] ) ? sanitize_text_field( $phase_fields['start'] ) : '';
                        $phase_end       = isset( $phase_fields['end'] ) ? sanitize_text_field( $phase_fields['end'] ) : '';
                        if ( $phase_start !== '' ) {
                            $phase_start = str_replace( 'T', ' ', $phase_start );
                            $phase_start = trim( $phase_start );
                            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $phase_start ) ) {
                                $phase_start = '';
                            }
                        }
                        if ( $phase_end !== '' ) {
                            $phase_end = str_replace( 'T', ' ', $phase_end );
                            $phase_end = trim( $phase_end );
                            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $phase_end ) ) {
                                $phase_end = '';
                            }
                        }

                        $price_phases_clean[] = array(
                            'key'   => $phase_key,
                            'label' => $phase_label,
                            'price' => $phase_price,
                            'start' => $phase_start,
                            'end'   => $phase_end,
                        );
                    }
                }

                $ticket_row = array(
                    'name'             => $name,
                    'price'            => $price,
                    'capacity'         => $capacity,
                    'initial_capacity' => $initial_capacity,
                    'sale_start'       => $sale_start,
                    'sale_end'         => $sale_end,
                    'description'      => $description,
                    'attendance_mode'  => $attendance_mode,
                    'hide_sold_out'    => $hide_sold_out,
                );

                if ( is_array( $price_phases_raw ) ) {
                    $ticket_row['price_phases'] = $price_phases_clean;
                }

                $clean_tickets[] = $ticket_row;
                ++$position;
            }
        }

        $envelope = array(
            'schema'  => 1,
            'tickets' => $clean_tickets,
        );

        $did_normalize       = false;
        $envelope['tickets'] = $this->normalize_price_phase_keys( $envelope['tickets'], $did_normalize );
        if ( $did_normalize ) {
            add_filter(
                'redirect_post_location',
                static function ( string $location ): string {
                    return add_query_arg( 'oras_phase_keys_normalized', '1', $location );
                }
            );
        }

        $has_recurrence = $this->has_tec_recurrence( $post_id );

        // Recurrence Guardrail: If event has recurrence and ticket save is attempted, disable ORAS ticketing
        if ( $has_recurrence && isset( $_POST['oras_tickets_tickets'] ) ) {
            $envelope['tickets'] = array(); // Disable ORAS ticketing by clearing tickets

            // Prove guardrail fired
            update_post_meta( $post_id, '_oras_guardrail_last_fired', current_time( 'mysql' ) );
            update_post_meta( $post_id, '_oras_guardrail_reason', 'recurrence_conflict' );

            // Set one-time admin notice
            $user_id = get_current_user_id();
            $key     = 'oras_tickets_guardrail_notice_' . $user_id;
            set_transient(
                $key,
                array(
                    'event_id' => (int) $post_id,
                    'message'  => 'ORAS Tickets cannot be used on recurring events. Disable recurrence or use RSVP mode.',
                ),
                120
            );
        }

        Ticket_Collection::save_for_event( $post_id, $envelope );
        Logger::instance()->log( "Saved tickets from metabox for event {$post_id} (count=" . count( $clean_tickets ) . ')' );
    }

    private function get_ticket_attendance_mode( array $ticket ): string {
        if ( array_key_exists( 'attendance_mode', $ticket ) ) {
            return Ticket::normalizeAttendanceMode( (string) $ticket['attendance_mode'], Ticket::ATTENDANCE_MODE_VIRTUAL );
        }

        return Ticket::ATTENDANCE_MODE_VIRTUAL;
    }

    private function get_ticket_attendance_label( string $attendance_mode ): string {
        return Ticket::ATTENDANCE_MODE_VIRTUAL === $attendance_mode
            ? __( 'Virtual', 'oras-tickets' )
            : __( 'On-site', 'oras-tickets' );
    }

    private function sanitize_ticket_attendance_mode( array $fields, array $existing_ticket ): string {
        $default = ! empty( $existing_ticket )
            ? $this->get_ticket_attendance_mode( $existing_ticket )
            : Ticket::ATTENDANCE_MODE_ONSITE;

        $raw = isset( $fields['attendance_mode'] ) ? (string) $fields['attendance_mode'] : '';

        return Ticket::normalizeAttendanceMode( $raw, $default );
    }

    public function maybe_show_admin_notices(): void {
        $this->maybe_show_phase_key_notice();
        $this->maybe_show_recurrence_guardrail_notice();
    }

    public function maybe_show_phase_key_notice(): void {
        if ( ! isset( $_GET['oras_phase_keys_normalized'] ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== Meta::EVENT_POST_TYPE ) {
            return;
        }

        echo '<div class="notice notice-info is-dismissible">';
        echo '<p>' . esc_html__( 'Duplicate or empty pricing phase keys were detected and normalized to unique keys.', 'oras-tickets' ) . '</p>';
        echo '</div>';
    }

    public function maybe_show_recurrence_guardrail_notice(): void {
        $user_id = get_current_user_id();
        $key     = 'oras_tickets_guardrail_notice_' . $user_id;
        $data    = get_transient( $key );

        // Backward compatibility: migrate old per-event transients
        if ( ! $data ) {
            $all_options = wp_load_alloptions();
            $prefix      = '_transient_' . $key . '_';
            foreach ( $all_options as $option_key => $value ) {
                if ( strpos( $option_key, $prefix ) === 0 ) {
                    $data = maybe_unserialize( $value );
                    $key  = str_replace( '_transient_', '', $option_key );
                    break; // Use the first one found
                }
            }
        }

        if ( ! $data || ! is_array( $data ) || ! isset( $data['event_id'], $data['message'] ) ) {
            return;
        }

        // Delete the transient so it only shows once
        delete_transient( $key );

        $edit_url = admin_url( 'post.php?post=' . (int) $data['event_id'] . '&action=edit' );

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>' . esc_html( $data['message'] ) . ' <a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit Event', 'oras-tickets' ) . '</a></p>';
        echo '</div>';
    }

    /**
     * @return array{value:string,safe:bool,normalized:bool}
     */
    public static function normalize_legacy_phase_datetime_value( string $raw ): array {
        $trimmed = trim( $raw );

        if ( $trimmed === '' ) {
            return array(
                'value'      => '',
                'safe'       => true,
                'normalized' => false,
            );
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $trimmed ) ) {
            return array(
                'value'      => $trimmed,
                'safe'       => true,
                'normalized' => $trimmed !== $raw,
            );
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $trimmed ) ) {
            return array(
                'value'      => str_replace( 'T', ' ', $trimmed ),
                'safe'       => true,
                'normalized' => true,
            );
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $trimmed ) ) {
            return array(
                'value'      => $trimmed . ' 00:00',
                'safe'       => true,
                'normalized' => true,
            );
        }

        return array(
            'value'      => $raw,
            'safe'       => false,
            'normalized' => false,
        );
    }

    /**
     * @return array{events_scanned:int,events_updated:int,fields_updated:int,fields_skipped:int}
     */
    public function repair_all_price_phase_datetimes(): array {
        $event_ids = get_posts(
            array(
                'post_type'              => Meta::EVENT_POST_TYPE,
                'post_status'            => 'any',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    array(
                        'key'     => Meta::META_KEY_TICKETS,
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        $stats = array(
            'events_scanned' => 0,
            'events_updated' => 0,
            'fields_updated' => 0,
            'fields_skipped' => 0,
        );

        foreach ( $event_ids as $event_id ) {
            $event_id = (int) $event_id;
            if ( $event_id <= 0 ) {
                continue;
            }

            ++$stats['events_scanned'];

            $envelope = Ticket_Collection::load_envelope_for_event( $event_id );
            $tickets  = isset( $envelope['tickets'] ) && is_array( $envelope['tickets'] ) ? $envelope['tickets'] : array();
            $changed  = false;

            foreach ( $tickets as $ticket_index => $ticket ) {
                if ( ! is_array( $ticket ) || empty( $ticket['price_phases'] ) || ! is_array( $ticket['price_phases'] ) ) {
                    continue;
                }

                foreach ( $ticket['price_phases'] as $phase_index => $phase ) {
                    if ( ! is_array( $phase ) ) {
                        continue;
                    }

                    foreach ( array( 'start', 'end' ) as $field ) {
                        $raw_value = isset( $phase[ $field ] ) && is_scalar( $phase[ $field ] ) ? (string) $phase[ $field ] : '';
                        $result    = self::normalize_legacy_phase_datetime_value( $raw_value );

                        if ( ! $result['safe'] ) {
                            if ( trim( $raw_value ) !== '' ) {
                                ++$stats['fields_skipped'];
                            }
                            continue;
                        }

                        if ( ! $result['normalized'] ) {
                            continue;
                        }

                        $tickets[ $ticket_index ]['price_phases'][ $phase_index ][ $field ] = $result['value'];
                        ++$stats['fields_updated'];
                        $changed = true;
                    }
                }
            }

            if ( ! $changed ) {
                continue;
            }

            $envelope['tickets'] = $tickets;
            Ticket_Collection::save_for_event( $event_id, $envelope );
            ++$stats['events_updated'];
        }

        return $stats;
    }

    /**
     * @param array<int,array<string,mixed>> $tickets
     */
    private function normalize_price_phase_keys( array $tickets, bool &$did_normalize ): array {
        foreach ( $tickets as $ticket_index => $ticket ) {
            if ( ! is_array( $ticket ) ) {
                continue;
            }

            $phases = $ticket['price_phases'] ?? null;
            if ( ! is_array( $phases ) ) {
                continue;
            }

            $seen = array();
            foreach ( $phases as $phase_index => $phase ) {
                if ( ! is_array( $phase ) ) {
                    continue;
                }

                $raw_key = isset( $phase['key'] ) ? (string) $phase['key'] : '';
                $key     = sanitize_key( $raw_key );
                if ( $key === '' ) {
                    $label_key = isset( $phase['label'] ) ? sanitize_key( (string) $phase['label'] ) : '';
                    $key       = $label_key !== '' ? $label_key : 'phase';
                }

                $base = $key;
                $n    = 2;
                while ( isset( $seen[ $key ] ) ) {
                    $key = $base . '_' . $n;
                    ++$n;
                }

                if ( $key !== $raw_key ) {
                    $phases[ $phase_index ]['key'] = $key;
                    $did_normalize                 = true;
                }

                $seen[ $key ] = true;
            }

            $tickets[ $ticket_index ]['price_phases'] = $phases;
        }

        return $tickets;
    }
}
