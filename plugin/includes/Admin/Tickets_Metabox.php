<?php

namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;
use ORAS\Tickets\Support\Logger;

if (! defined('ABSPATH')) {
    exit;
}

final class Tickets_Metabox
{

    private static ?Tickets_Metabox $instance = null;

    public static function instance(): Tickets_Metabox
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_post'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook_suffix): void
    {
        if (! function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (! $screen || ! isset($screen->post_type) || $screen->post_type !== Meta::EVENT_POST_TYPE) {
            return;
        }

        // Only load on the post editor screens. Accept either screen base === 'post'
        // (covers editors) or hook suffix explicitly for post edit/new pages.
        $is_editor = (isset($screen->base) && $screen->base === 'post') || in_array($hook_suffix, ['post.php', 'post-new.php'], true);
        if (! $is_editor) {
            return;
        }

        wp_enqueue_script(
            'oras-tickets-metabox',
            ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.js',
            [],
            ORAS_TICKETS_VERSION,
            true
        );

        wp_enqueue_style(
            'oras-tickets-metabox',
            ORAS_TICKETS_URL . 'assets/admin/tickets-metabox.css',
            [],
            ORAS_TICKETS_VERSION
        );
    }

    public function register_metabox(): void
    {
        add_meta_box(
            'oras_tickets_metabox',
            'ORAS Tickets',
            [$this, 'render_metabox'],
            Meta::EVENT_POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_metabox(\WP_Post $post): void
    {
        if (! current_user_can('edit_post', $post->ID)) {
            return;
        }

        $envelope = Ticket_Collection::load_envelope_for_event($post->ID);
        $tickets  = $envelope['tickets'] ?? [];

        // Nonce
        wp_nonce_field('oras_tickets_metabox', 'oras_tickets_metabox_nonce');

?>
        <div id="oras-tickets-metabox">
            <div class="oras-tickets-layout" style="display:flex; gap:16px; align-items:flex-start;">
                <div class="oras-tickets-tabs" style="width:220px; border:1px solid #dcdcde; background:#f6f7f7; padding:8px;">
                    <div style="font-weight:600; margin:4px 4px 8px;">Tickets</div>
                    <ul id="oras-ticket-tabs" style="list-style:none; margin:0; padding:0;">
                        <?php foreach ($tickets as $index => $data) :
                            $tab_name = isset($data['name']) ? (string) $data['name'] : '';
                            $tab_label = $tab_name !== '' ? $tab_name : sprintf('Ticket #%d', (int) $index);
                            $price = isset($data['price']) ? (string) $data['price'] : '0.00';
                            $sale_start = isset($data['sale_start']) ? (string) $data['sale_start'] : '';
                            $sale_end = isset($data['sale_end']) ? (string) $data['sale_end'] : '';
                            $now_ts = current_time('timestamp');
                            $start_ts = null;
                            $end_ts = null;
                            if ($sale_start !== '') {
                                $start_dt = \DateTime::createFromFormat('Y-m-d H:i', $sale_start, wp_timezone());
                                if ($start_dt instanceof \DateTimeInterface) {
                                    $start_ts = $start_dt->getTimestamp();
                                }
                            }
                            if ($sale_end !== '') {
                                $end_dt = \DateTime::createFromFormat('Y-m-d H:i', $sale_end, wp_timezone());
                                if ($end_dt instanceof \DateTimeInterface) {
                                    $end_ts = $end_dt->getTimestamp();
                                }
                            }
                            if (null === $start_ts && null === $end_ts) {
                                $sale_status = 'Always';
                            } elseif (null !== $start_ts && $now_ts < $start_ts) {
                                $sale_status = 'Scheduled';
                            } elseif (null !== $end_ts && $now_ts > $end_ts) {
                                $sale_status = 'Ended';
                            } else {
                                $sale_status = 'On sale';
                            }
                            $idx = esc_attr((string) $index);
                        ?>
                            <li style="margin:0 0 6px;">
                                <button type="button" class="button oras-ticket-tab" data-index="<?php echo $idx; ?>" style="width:100%; text-align:left;">
                                    <span class="oras-ticket-tab-title"><?php echo esc_html($tab_label); ?></span>
                                    <span class="oras-ticket-tab-meta"><?php echo esc_html($price . ' · ' . $sale_status); ?></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div id="oras-tickets-empty" style="margin:8px 4px; <?php echo empty($tickets) ? '' : 'display:none;'; ?>">
                        <p style="margin:0 0 8px;">No tickets yet.</p>
                    </div>
                    <p style="margin:8px 4px 0;">
                        <button type="button" id="oras-add-ticket" class="button">Add Ticket</button>
                    </p>
                </div>

                <div class="oras-ticket-panels oras-tickets-panels" style="flex:1; min-width:0;">
                    <table class="widefat" id="oras-tickets-table" style="border:none; background:transparent; box-shadow:none; width:100%; display:block;">
                        <tbody style="display:block;">
                            <?php
                            $is_first_panel = true;
                            foreach ($tickets as $index => $data) :
                                $name = isset($data['name']) ? $data['name'] : '';
                                $price = isset($data['price']) ? $data['price'] : '0.00';
                                $price_phases = isset($data['price_phases']) && is_array($data['price_phases']) ? $data['price_phases'] : [];
                                $capacity = isset($data['capacity']) ? $data['capacity'] : 0;
                                $sale_start = isset($data['sale_start']) ? $data['sale_start'] : '';
                                $sale_end = isset($data['sale_end']) ? $data['sale_end'] : '';
                                $description = isset($data['description']) ? $data['description'] : '';
                                $hide_sold_out = ! empty($data['hide_sold_out']);
                                $idx = esc_attr((string) $index);
                                $sale_start_val = $sale_start !== '' ? str_replace(' ', 'T', $sale_start) : '';
                                $sale_end_val = $sale_end !== '' ? str_replace(' ', 'T', $sale_end) : '';
                                $panel_class = $is_first_panel ? 'is-active' : 'is-hidden';
                                $panel_style = $is_first_panel ? '' : 'display:none;';
                            ?>
                                <tr class="oras-ticket-row" data-index="<?php echo $idx; ?>" style="display:block; margin:0 0 12px; border:1px solid #dcdcde; background:#fff; padding:12px;">
                                    <td style="display:block; padding:0; border:none;">
                                        <div class="oras-ticket-panel <?php echo esc_attr($panel_class); ?>" data-index="<?php echo $idx; ?>" <?php echo $panel_style !== '' ? ' style="' . esc_attr($panel_style) . '"' : ''; ?>>
                                            <div class="panel-wrap oras-ticket-data">
                                                <ul class="oras-ticket-data-tabs wc-tabs">
                                                    <li class="general_tab"><a href="#oras_ticket_<?php echo $idx; ?>_general">General</a></li>
                                                    <li class="inventory_tab"><a href="#oras_ticket_<?php echo $idx; ?>_inventory">Inventory</a></li>
                                                    <li class="sale_window_tab"><a href="#oras_ticket_<?php echo $idx; ?>_sale_window">Sale window</a></li>
                                                    <li class="pricing_tab"><a href="#oras_ticket_<?php echo $idx; ?>_pricing">Pricing</a></li>
                                                    <li class="pricing_phases_tab"><a href="#oras_ticket_<?php echo $idx; ?>_pricing_phases">Pricing phases</a></li>
                                                </ul>
                                                <div id="oras_ticket_<?php echo $idx; ?>_general" class="panel woocommerce_options_panel">
                                                    <div style="margin-bottom:12px;">
                                                        <label><strong>Name</strong></label><br />
                                                        <input type="text" class="oras-ticket-name-input" name="oras_tickets_tickets[<?php echo $idx; ?>][name]" value="<?php echo esc_attr($name); ?>" style="width:100%;" />
                                                    </div>
                                                    <div>
                                                        <label><strong>Description</strong></label><br />
                                                        <textarea name="oras_tickets_tickets[<?php echo $idx; ?>][description]" rows="3" style="width:100%;"><?php echo esc_textarea($description); ?></textarea>
                                                    </div>
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_inventory" class="panel woocommerce_options_panel" style="display:none;">
                                                    <label><strong><?php echo esc_html__('Stock', 'oras-tickets'); ?></strong></label><br />
                                                    <input type="number" min="0" name="oras_tickets_tickets[<?php echo $idx; ?>][capacity]" value="<?php echo esc_attr($capacity); ?>" style="width:100%;" />
                                                    <p class="description" style="margin:4px 0 0;"><?php echo esc_html__('0 = unlimited', 'oras-tickets'); ?></p>
                                                    <div style="margin-top:12px;">
                                                        <label><strong>Hide sold out</strong></label><br />
                                                        <label>
                                                            <input type="checkbox" name="oras_tickets_tickets[<?php echo $idx; ?>][hide_sold_out]" value="1" <?php checked($hide_sold_out); ?> />
                                                            Hide when sold out
                                                        </label>
                                                    </div>
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_sale_window" class="panel woocommerce_options_panel" style="display:none;">
                                                    <div style="margin-bottom:12px;">
                                                        <label><strong>Sale start</strong></label><br />
                                                        <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_start]" value="<?php echo esc_attr($sale_start_val); ?>" style="width:100%;" />
                                                    </div>
                                                    <div>
                                                        <label><strong>Sale end</strong></label><br />
                                                        <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_end]" value="<?php echo esc_attr($sale_end_val); ?>" style="width:100%;" />
                                                    </div>
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_pricing" class="panel woocommerce_options_panel" style="display:none;">
                                                    <label><strong>Price</strong></label><br />
                                                    <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price]" value="<?php echo esc_attr($price); ?>" style="width:100%;" />
                                                </div>
                                                <div id="oras_ticket_<?php echo $idx; ?>_pricing_phases" class="panel woocommerce_options_panel" style="display:none;">
                                                    <div class="oras-phase-section">
                                                        <div class="oras-phase-header" style="font-weight:600; margin-bottom:8px;">Pricing phases</div>
                                                        <div class="oras-phase-toolbar">
                                                            <div class="oras-phase-help">Use phases to set time-based prices (UTC).</div>
                                                            <button type="button" class="button oras-phase-add" data-ticket-index="<?php echo $idx; ?>">Add phase</button>
                                                        </div>
                                                        <div class="oras-phase-list">
                                                            <?php if (! empty($price_phases)) : ?>
                                                                <?php foreach ($price_phases as $phase_index => $phase) :
                                                                    if (! is_array($phase)) {
                                                                        continue;
                                                                    }
                                                                    $phase_idx = esc_attr((string) $phase_index);
                                                                    $phase_key = isset($phase['key']) ? (string) $phase['key'] : '';
                                                                    $phase_label = isset($phase['label']) ? (string) $phase['label'] : '';
                                                                    $phase_price = isset($phase['price']) ? (string) $phase['price'] : '';
                                                                    $phase_start = isset($phase['start']) ? (string) $phase['start'] : '';
                                                                    $phase_end = isset($phase['end']) ? (string) $phase['end'] : '';
                                                                ?>
                                                                    <div class="oras-phase-item is-collapsed" data-phase-index="<?php echo $phase_idx; ?>">
                                                                        <div class="oras-phase-cardhead">
                                                                            <div class="oras-phase-cardtitle">Phase</div>
                                                                            <button type="button" class="button oras-phase-toggle">Advanced</button>
                                                                        </div>
                                                                        <div class="oras-phase-row oras-phase-row-main">
                                                                            <div>
                                                                                <label>Key</label>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][key]" value="<?php echo esc_attr($phase_key); ?>" />
                                                                            </div>
                                                                            <div>
                                                                                <label>Label</label>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][label]" value="<?php echo esc_attr($phase_label); ?>" />
                                                                            </div>
                                                                            <div>
                                                                                <label>Price</label>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][price]" value="<?php echo esc_attr($phase_price); ?>" />
                                                                            </div>
                                                                        </div>
                                                                        <div class="oras-phase-row oras-phase-row-advanced">
                                                                            <div>
                                                                                <label>Start (UTC)</label>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][start]" placeholder="YYYY-MM-DD HH:MM" value="<?php echo esc_attr($phase_start); ?>" />
                                                                            </div>
                                                                            <div>
                                                                                <label>End (UTC)</label>
                                                                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][<?php echo $phase_idx; ?>][end]" placeholder="YYYY-MM-DD HH:MM" value="<?php echo esc_attr($phase_end); ?>" />
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
                                                                        <label>Key</label>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][key]" value="" />
                                                                    </div>
                                                                    <div>
                                                                        <label>Label</label>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][label]" value="" />
                                                                    </div>
                                                                    <div>
                                                                        <label>Price</label>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][price]" value="" />
                                                                    </div>
                                                                </div>
                                                                <div class="oras-phase-row oras-phase-row-advanced">
                                                                    <div>
                                                                        <label>Start (UTC)</label>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][start]" placeholder="YYYY-MM-DD HH:MM" value="" />
                                                                    </div>
                                                                    <div>
                                                                        <label>End (UTC)</label>
                                                                        <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price_phases][__PHASE__][end]" placeholder="YYYY-MM-DD HH:MM" value="" />
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
                                            <div class="oras-ticket-actions" style="margin-top:12px;">
                                                <button type="button" class="oras-remove-ticket button">Remove</button>
                                                <input type="hidden" name="oras_tickets_index[]" value="<?php echo $idx; ?>" />
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php
                                $is_first_panel = false;
                            endforeach;
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <hr />

            <h4>Ticket Sales Summary</h4>
            <table class="widefat striped" id="oras-tickets-summary">
                <thead>
                    <tr>
                        <th>Index</th>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Remaining</th>
                        <th>Sold</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)) : ?>
                        <tr>
                            <td colspan="6">No tickets yet.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($tickets as $index => $data) :
                            $name = isset($data['name']) ? (string) $data['name'] : '';
                            $price = isset($data['price']) ? (string) $data['price'] : '0.00';
                            $remaining_data = $this->get_remaining_for_ticket($post->ID, (string) $index);
                            $remaining_display = $remaining_data['display'];
                            $remaining_value = $remaining_data['remaining'];
                            $is_unlimited = $remaining_data['is_unlimited'];
                            $initial_capacity = isset($data['initial_capacity']) ? absint($data['initial_capacity']) : null;

                            if ($is_unlimited) {
                                $status = 'Unlimited';
                            } elseif ((int) $remaining_value > 0) {
                                $status = 'Available';
                            } else {
                                $status = 'Sold out';
                            }

                            if (! $is_unlimited && null !== $initial_capacity && $initial_capacity > 0 && null !== $remaining_value) {
                                $sold = max(0, $initial_capacity - (int) $remaining_value);
                            } else {
                                $sold = '—';
                            }
                        ?>
                            <tr>
                                <td><?php echo esc_html((string) $index); ?></td>
                                <td><?php echo esc_html($name); ?></td>
                                <td><?php echo esc_html($price); ?></td>
                                <td><?php echo esc_html((string) $remaining_display); ?></td>
                                <td><?php echo esc_html((string) $sold); ?></td>
                                <td><?php echo esc_html($status); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Template row (uses <template> so it won't be submitted) -->
            <template id="oras-ticket-template">
                <tr class="oras-ticket-row" data-index="__INDEX__" style="display:block; margin:0 0 12px; border:1px solid #dcdcde; background:#fff; padding:12px;">
                    <td style="display:block; padding:0; border:none;">
                        <div class="oras-ticket-panel is-hidden" data-index="__INDEX__" style="display:none;">
                            <div class="panel-wrap oras-ticket-data">
                                <ul class="oras-ticket-data-tabs wc-tabs">
                                    <li class="general_tab"><a href="#oras_ticket___INDEX___general">General</a></li>
                                    <li class="inventory_tab"><a href="#oras_ticket___INDEX___inventory">Inventory</a></li>
                                    <li class="sale_window_tab"><a href="#oras_ticket___INDEX___sale_window">Sale window</a></li>
                                    <li class="pricing_tab"><a href="#oras_ticket___INDEX___pricing">Pricing</a></li>
                                    <li class="pricing_phases_tab"><a href="#oras_ticket___INDEX___pricing_phases">Pricing phases</a></li>
                                </ul>
                                <div id="oras_ticket___INDEX___general" class="panel woocommerce_options_panel">
                                    <div style="margin-bottom:12px;">
                                        <label><strong>Name</strong></label><br />
                                        <input type="text" class="oras-ticket-name-input" name="oras_tickets_tickets[__INDEX__][name]" value="" style="width:100%;" />
                                    </div>
                                    <div>
                                        <label><strong>Description</strong></label><br />
                                        <textarea name="oras_tickets_tickets[__INDEX__][description]" rows="3" style="width:100%;"></textarea>
                                    </div>
                                </div>
                                <div id="oras_ticket___INDEX___inventory" class="panel woocommerce_options_panel" style="display:none;">
                                    <label><strong><?php echo esc_html__('Stock', 'oras-tickets'); ?></strong></label><br />
                                    <input type="number" min="0" name="oras_tickets_tickets[__INDEX__][capacity]" value="0" style="width:100%;" />
                                    <p class="description" style="margin:4px 0 0;"><?php echo esc_html__('0 = unlimited', 'oras-tickets'); ?></p>
                                    <div style="margin-top:12px;">
                                        <label><strong>Hide sold out</strong></label><br />
                                        <label>
                                            <input type="checkbox" name="oras_tickets_tickets[__INDEX__][hide_sold_out]" value="1" />
                                            Hide when sold out
                                        </label>
                                    </div>
                                </div>
                                <div id="oras_ticket___INDEX___sale_window" class="panel woocommerce_options_panel" style="display:none;">
                                    <div style="margin-bottom:12px;">
                                        <label><strong>Sale start</strong></label><br />
                                        <input type="datetime-local" name="oras_tickets_tickets[__INDEX__][sale_start]" value="" style="width:100%;" />
                                    </div>
                                    <div>
                                        <label><strong>Sale end</strong></label><br />
                                        <input type="datetime-local" name="oras_tickets_tickets[__INDEX__][sale_end]" value="" style="width:100%;" />
                                    </div>
                                </div>
                                <div id="oras_ticket___INDEX___pricing" class="panel woocommerce_options_panel" style="display:none;">
                                    <label><strong>Price</strong></label><br />
                                    <input type="text" name="oras_tickets_tickets[__INDEX__][price]" value="0.00" style="width:100%;" />
                                </div>
                                <div id="oras_ticket___INDEX___pricing_phases" class="panel woocommerce_options_panel" style="display:none;">
                                    <div class="oras-phase-section">
                                        <div class="oras-phase-header" style="font-weight:600; margin-bottom:8px;">Pricing phases</div>
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
                                                        <label>Key</label>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][key]" value="" />
                                                    </div>
                                                    <div>
                                                        <label>Label</label>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][label]" value="" />
                                                    </div>
                                                    <div>
                                                        <label>Price</label>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][price]" value="" />
                                                    </div>
                                                </div>
                                                <div class="oras-phase-row oras-phase-row-advanced">
                                                    <div>
                                                        <label>Start (UTC)</label>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][start]" placeholder="YYYY-MM-DD HH:MM" value="" />
                                                    </div>
                                                    <div>
                                                        <label>End (UTC)</label>
                                                        <input type="text" name="oras_tickets_tickets[__INDEX__][price_phases][__PHASE__][end]" placeholder="YYYY-MM-DD HH:MM" value="" />
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
                            <div class="oras-ticket-actions" style="margin-top:12px;">
                                <button type="button" class="oras-remove-ticket button">Remove</button>
                                <input type="hidden" name="oras_tickets_index[]" value="__INDEX__" />
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
    private function get_remaining_for_ticket(int $event_id, string $index): array
    {
        $map = get_post_meta($event_id, '_oras_tickets_woo_map_v1', true);
        if (! is_array($map)) {
            $map = [];
        }

        $product_id = isset($map[$index]) ? absint($map[$index]) : 0;
        if ($product_id <= 0) {
            return [
                'display' => '—',
                'remaining' => null,
                'is_unlimited' => false,
            ];
        }

        $manage_stock = null;
        $stock_qty = null;

        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) {
                if (method_exists($product, 'get_manage_stock')) {
                    $manage_stock = (bool) $product->get_manage_stock();
                }
                if (method_exists($product, 'get_stock_quantity')) {
                    $stock_qty = $product->get_stock_quantity();
                }
            }
        }

        if (null === $manage_stock) {
            $manage_stock = (string) get_post_meta($product_id, '_manage_stock', true) === 'yes';
        }
        if (null === $stock_qty) {
            $stock_qty = get_post_meta($product_id, '_stock', true);
        }

        if (! $manage_stock) {
            return [
                'display' => esc_html__('Unlimited', 'oras-tickets'),
                'remaining' => null,
                'is_unlimited' => true,
            ];
        }

        $remaining = max(0, (int) $stock_qty);
        return [
            'display' => $remaining,
            'remaining' => $remaining,
            'is_unlimited' => false,
        ];
    }

    public function save_post(int $post_id, \WP_Post $post): void
    {
        // Only save for event post type
        if ($post->post_type !== Meta::EVENT_POST_TYPE) {
            return;
        }

        // Autosave / revision guard
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_autosave($post_id)) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        if (! isset($_POST['oras_tickets_metabox_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['oras_tickets_metabox_nonce']), 'oras_tickets_metabox')) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        if (! isset($_POST['oras_tickets_tickets']) || ! is_array($_POST['oras_tickets_tickets'])) {
            return;
        }

        $existing_envelope = Ticket_Collection::load_envelope_for_event($post_id);
        $existing_tickets = isset($existing_envelope['tickets']) && is_array($existing_envelope['tickets'])
            ? $existing_envelope['tickets']
            : [];

        $raw = wp_unslash($_POST['oras_tickets_tickets']);
        $clean_tickets = [];

        // Determine posted index order if provided.
        $posted_indices = isset($_POST['oras_tickets_index']) && is_array($_POST['oras_tickets_index'])
            ? wp_unslash($_POST['oras_tickets_index'])
            : null;

        if (is_array($posted_indices)) {
            // Rebuild tickets in the posted order using numeric incremental keys.
            foreach ($posted_indices as $idx) {
                $idx_int = absint($idx);
                if ($idx_int === 0 && (string) $idx !== '0') {
                    // non-numeric index, skip
                    continue;
                }
                $idx = (string) $idx_int;
                if ($idx === '' || ! isset($raw[$idx]) || ! is_array($raw[$idx])) {
                    continue;
                }
                $fields = $raw[$idx];
                $name = isset($fields['name']) ? sanitize_text_field($fields['name']) : '';
                // Price: normalize, ensure non-negative, two decimals
                $price_raw = isset($fields['price']) ? str_replace(',', '.', $fields['price']) : '0';
                $price_float = floatval($price_raw);
                if ($price_float < 0) {
                    $price_float = 0.0;
                }
                $price = number_format($price_float, 2, '.', '');
                // Capacity: absolute int
                $capacity = isset($fields['capacity']) ? absint($fields['capacity']) : 0;
                $sale_start = isset($fields['sale_start']) ? sanitize_text_field($fields['sale_start']) : '';
                $sale_end = isset($fields['sale_end']) ? sanitize_text_field($fields['sale_end']) : '';
                // Accept datetime-local format (YYYY-MM-DDTHH:MM) and convert to storage format (YYYY-MM-DD HH:MM).
                if ($sale_start !== '') {
                    $sale_start = str_replace('T', ' ', $sale_start);
                    $sale_start = trim($sale_start);
                    if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_start)) {
                        $sale_start = '';
                    }
                }
                if ($sale_end !== '') {
                    $sale_end = str_replace('T', ' ', $sale_end);
                    $sale_end = trim($sale_end);
                    if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_end)) {
                        $sale_end = '';
                    }
                }
                // If both present and out of order, swap to ensure start <= end
                if ($sale_start !== '' && $sale_end !== '') {
                    $dt1 = \DateTime::createFromFormat('Y-m-d H:i', $sale_start, wp_timezone());
                    $dt2 = \DateTime::createFromFormat('Y-m-d H:i', $sale_end, wp_timezone());
                    if ($dt1 instanceof \DateTimeInterface && $dt2 instanceof \DateTimeInterface) {
                        if ($dt2->getTimestamp() < $dt1->getTimestamp()) {
                            // swap
                            $tmp = $sale_start;
                            $sale_start = $sale_end;
                            $sale_end = $tmp;
                        }
                    }
                }
                $description = isset($fields['description']) ? sanitize_textarea_field($fields['description']) : '';
                $hide_sold_out = isset($fields['hide_sold_out']) && ($fields['hide_sold_out'] === '1' || $fields['hide_sold_out'] === 1);

                // Skip empty-default rows: name empty, description empty, sale dates empty, hide_sold_out false, capacity <=0, price <=0
                if ($name === '' && $description === '' && $sale_start === '' && $sale_end === '' && ! $hide_sold_out && $capacity <= 0 && $price_float <= 0.0) {
                    continue;
                }

                $initial_capacity = null;
                if (isset($existing_tickets[$idx]) && is_array($existing_tickets[$idx]) && array_key_exists('initial_capacity', $existing_tickets[$idx])) {
                    $initial_capacity = absint($existing_tickets[$idx]['initial_capacity']);
                } else {
                    $initial_capacity = $capacity;
                }

                $price_phases_clean = [];
                $price_phases_raw = isset($fields['price_phases']) ? $fields['price_phases'] : null;
                if (is_array($price_phases_raw)) {
                    foreach ($price_phases_raw as $phase_fields) {
                        if (! is_array($phase_fields)) {
                            continue;
                        }
                        $phase_key = isset($phase_fields['key']) ? sanitize_text_field($phase_fields['key']) : '';
                        $phase_label = isset($phase_fields['label']) ? sanitize_text_field($phase_fields['label']) : '';
                        $phase_price_raw = isset($phase_fields['price']) ? str_replace(',', '.', $phase_fields['price']) : '';
                        $phase_price = is_numeric($phase_price_raw)
                            ? number_format((float) $phase_price_raw, 2, '.', '')
                            : sanitize_text_field($phase_price_raw);
                        $phase_start = isset($phase_fields['start']) ? sanitize_text_field($phase_fields['start']) : '';
                        $phase_end = isset($phase_fields['end']) ? sanitize_text_field($phase_fields['end']) : '';

                        $price_phases_clean[] = [
                            'key' => $phase_key,
                            'label' => $phase_label,
                            'price' => $phase_price,
                            'start' => $phase_start,
                            'end' => $phase_end,
                        ];
                    }
                }

                $ticket_row = [
                    'name' => $name,
                    'price' => $price,
                    'capacity' => $capacity,
                    'initial_capacity' => $initial_capacity,
                    'sale_start' => $sale_start,
                    'sale_end' => $sale_end,
                    'description' => $description,
                    'hide_sold_out' => $hide_sold_out,
                ];

                if (is_array($price_phases_raw)) {
                    $ticket_row['price_phases'] = $price_phases_clean;
                }

                $clean_tickets[] = $ticket_row;
            }
        } else {
            // Fallback: preserve posted order of the tickets values.
            $values = array_values($raw);
            $position = 0;
            foreach ($values as $fields) {
                if (! is_array($fields)) {
                    continue;
                }

                $name = isset($fields['name']) ? sanitize_text_field($fields['name']) : '';
                // Price: normalize, ensure non-negative, two decimals
                $price_raw = isset($fields['price']) ? str_replace(',', '.', $fields['price']) : '0';
                $price_float = floatval($price_raw);
                if ($price_float < 0) {
                    $price_float = 0.0;
                }
                $price = number_format($price_float, 2, '.', '');
                // Capacity: absolute int
                $capacity = isset($fields['capacity']) ? absint($fields['capacity']) : 0;
                $sale_start = isset($fields['sale_start']) ? sanitize_text_field($fields['sale_start']) : '';
                $sale_end = isset($fields['sale_end']) ? sanitize_text_field($fields['sale_end']) : '';
                // Accept datetime-local format (YYYY-MM-DDTHH:MM) and convert to storage format (YYYY-MM-DD HH:MM).
                if ($sale_start !== '') {
                    $sale_start = str_replace('T', ' ', $sale_start);
                    $sale_start = trim($sale_start);
                    if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_start)) {
                        $sale_start = '';
                    }
                }
                if ($sale_end !== '') {
                    $sale_end = str_replace('T', ' ', $sale_end);
                    $sale_end = trim($sale_end);
                    if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $sale_end)) {
                        $sale_end = '';
                    }
                }
                // If both present and out of order, swap to ensure start <= end
                if ($sale_start !== '' && $sale_end !== '') {
                    $dt1 = \DateTime::createFromFormat('Y-m-d H:i', $sale_start, wp_timezone());
                    $dt2 = \DateTime::createFromFormat('Y-m-d H:i', $sale_end, wp_timezone());
                    if ($dt1 instanceof \DateTimeInterface && $dt2 instanceof \DateTimeInterface) {
                        if ($dt2->getTimestamp() < $dt1->getTimestamp()) {
                            // swap
                            $tmp = $sale_start;
                            $sale_start = $sale_end;
                            $sale_end = $tmp;
                        }
                    }
                }
                $description = isset($fields['description']) ? sanitize_textarea_field($fields['description']) : '';
                $hide_sold_out = isset($fields['hide_sold_out']) && ($fields['hide_sold_out'] === '1' || $fields['hide_sold_out'] === 1);

                // Skip empty-default rows
                if ($name === '' && $description === '' && $sale_start === '' && $sale_end === '' && ! $hide_sold_out && $capacity <= 0 && $price_float <= 0.0) {
                    continue;
                }

                $initial_capacity = null;
                if (isset($existing_tickets[$position]) && is_array($existing_tickets[$position]) && array_key_exists('initial_capacity', $existing_tickets[$position])) {
                    $initial_capacity = absint($existing_tickets[$position]['initial_capacity']);
                } else {
                    $initial_capacity = $capacity;
                }

                $price_phases_clean = [];
                $price_phases_raw = isset($fields['price_phases']) ? $fields['price_phases'] : null;
                if (is_array($price_phases_raw)) {
                    foreach ($price_phases_raw as $phase_fields) {
                        if (! is_array($phase_fields)) {
                            continue;
                        }
                        $phase_key = isset($phase_fields['key']) ? sanitize_text_field($phase_fields['key']) : '';
                        $phase_label = isset($phase_fields['label']) ? sanitize_text_field($phase_fields['label']) : '';
                        $phase_price_raw = isset($phase_fields['price']) ? str_replace(',', '.', $phase_fields['price']) : '';
                        $phase_price = is_numeric($phase_price_raw)
                            ? number_format((float) $phase_price_raw, 2, '.', '')
                            : sanitize_text_field($phase_price_raw);
                        $phase_start = isset($phase_fields['start']) ? sanitize_text_field($phase_fields['start']) : '';
                        $phase_end = isset($phase_fields['end']) ? sanitize_text_field($phase_fields['end']) : '';

                        $price_phases_clean[] = [
                            'key' => $phase_key,
                            'label' => $phase_label,
                            'price' => $phase_price,
                            'start' => $phase_start,
                            'end' => $phase_end,
                        ];
                    }
                }

                $ticket_row = [
                    'name' => $name,
                    'price' => $price,
                    'capacity' => $capacity,
                    'initial_capacity' => $initial_capacity,
                    'sale_start' => $sale_start,
                    'sale_end' => $sale_end,
                    'description' => $description,
                    'hide_sold_out' => $hide_sold_out,
                ];

                if (is_array($price_phases_raw)) {
                    $ticket_row['price_phases'] = $price_phases_clean;
                }

                $clean_tickets[] = $ticket_row;
                $position++;
            }
        }

        $envelope = [
            'schema' => 1,
            'tickets' => $clean_tickets,
        ];

        Ticket_Collection::save_for_event($post_id, $envelope);
        Logger::instance()->log("Saved tickets from metabox for event {$post_id} (count=" . count($clean_tickets) . ")");
    }
}
