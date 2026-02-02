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
            <table class="widefat" id="oras-tickets-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Price</th>
                        <th><?php echo esc_html__('Stock', 'oras-tickets'); ?></th>
                        <th>Sale start</th>
                        <th>Sale end</th>
                        <th>Description</th>
                        <th>Hide sold out</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $index => $data) :
                        $name = isset($data['name']) ? $data['name'] : '';
                        $price = isset($data['price']) ? $data['price'] : '0.00';
                        $capacity = isset($data['capacity']) ? $data['capacity'] : 0;
                        $sale_start = isset($data['sale_start']) ? $data['sale_start'] : '';
                        $sale_end = isset($data['sale_end']) ? $data['sale_end'] : '';
                        $description = isset($data['description']) ? $data['description'] : '';
                        $hide_sold_out = ! empty($data['hide_sold_out']);
                        $idx = esc_attr((string) $index);
                    ?>
                        <tr class="oras-ticket-row" data-index="<?php echo $idx; ?>">
                            <td>
                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][name]" value="<?php echo esc_attr($name); ?>" />
                            </td>
                            <td>
                                <input type="text" name="oras_tickets_tickets[<?php echo $idx; ?>][price]" value="<?php echo esc_attr($price); ?>" />
                            </td>
                            <td>
                                <input type="number" min="0" name="oras_tickets_tickets[<?php echo $idx; ?>][capacity]" value="<?php echo esc_attr($capacity); ?>" />
                                <p class="description"><?php echo esc_html__('0 = unlimited', 'oras-tickets'); ?></p>
                            </td>
                            <td>
                                <?php
                                $sale_start_val = $sale_start !== '' ? str_replace(' ', 'T', $sale_start) : '';
                                ?>
                                <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_start]" value="<?php echo esc_attr($sale_start_val); ?>" />
                            </td>
                            <td>
                                <?php
                                $sale_end_val = $sale_end !== '' ? str_replace(' ', 'T', $sale_end) : '';
                                ?>
                                <input type="datetime-local" name="oras_tickets_tickets[<?php echo $idx; ?>][sale_end]" value="<?php echo esc_attr($sale_end_val); ?>" />
                            </td>
                            <td>
                                <textarea name="oras_tickets_tickets[<?php echo $idx; ?>][description]" rows="2"><?php echo esc_textarea($description); ?></textarea>
                            </td>
                            <td>
                                <input type="checkbox" name="oras_tickets_tickets[<?php echo $idx; ?>][hide_sold_out]" value="1" <?php checked($hide_sold_out); ?> />
                            </td>
                            <td>
                                <button type="button" class="oras-remove-ticket button">Remove</button>
                                <input type="hidden" name="oras_tickets_index[]" value="<?php echo $idx; ?>" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" id="oras-add-ticket" class="button">Add Ticket</button>
            </p>

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
                <tr class="oras-ticket-row" data-index="__INDEX__">
                    <td><input type="text" name="oras_tickets_tickets[__INDEX__][name]" value="" /></td>
                    <td><input type="text" name="oras_tickets_tickets[__INDEX__][price]" value="0.00" /></td>
                    <td>
                        <input type="number" min="0" name="oras_tickets_tickets[__INDEX__][capacity]" value="0" />
                        <p class="description"><?php echo esc_html__('0 = unlimited', 'oras-tickets'); ?></p>
                    </td>
                    <td><input type="datetime-local" name="oras_tickets_tickets[__INDEX__][sale_start]" value="" /></td>
                    <td><input type="datetime-local" name="oras_tickets_tickets[__INDEX__][sale_end]" value="" /></td>
                    <td><textarea name="oras_tickets_tickets[__INDEX__][description]" rows="2"></textarea></td>
                    <td><input type="checkbox" name="oras_tickets_tickets[__INDEX__][hide_sold_out]" value="1" /></td>
                    <td><button type="button" class="oras-remove-ticket button">Remove</button>
                        <input type="hidden" name="oras_tickets_index[]" value="__INDEX__" />
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
            // Clear tickets if none provided
            Ticket_Collection::save_for_event($post_id, ['schema' => 1, 'tickets' => []]);
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

                $clean_tickets[] = [
                    'name' => $name,
                    'price' => $price,
                    'capacity' => $capacity,
                    'initial_capacity' => $initial_capacity,
                    'sale_start' => $sale_start,
                    'sale_end' => $sale_end,
                    'description' => $description,
                    'hide_sold_out' => $hide_sold_out,
                ];
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

                $clean_tickets[] = [
                    'name' => $name,
                    'price' => $price,
                    'capacity' => $capacity,
                    'initial_capacity' => $initial_capacity,
                    'sale_start' => $sale_start,
                    'sale_end' => $sale_end,
                    'description' => $description,
                    'hide_sold_out' => $hide_sold_out,
                ];
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
