<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Pricing\Price_Resolver;
use ORAS\Tickets\Domain\Ticket_Collection;

if (! defined('ABSPATH')) {
    exit;
}

final class Tickets_Display
{

    private static ?Tickets_Display $instance = null;

    public static function instance(): Tickets_Display
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void
    {
        // Rendering will be injected via the_content filter only.

        // Inject into the main post content for TEC single event pages.
        add_filter('the_content', [$this, 'the_content_filter'], 20);

        // Frontend styles for the tickets table.
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);

        // Handle POST submissions early in the request lifecycle.
        add_action('template_redirect', [$this, 'handle_post'], 10);

        // Revalidate cart items on cart/checkout views.
        add_action('woocommerce_check_cart_items', [$this, 'revalidate_cart_items'], 10);
        add_action('woocommerce_before_checkout_process', [$this, 'revalidate_cart_items'], 10);
        add_action('woocommerce_checkout_process', [$this, 'revalidate_cart_items'], 10);
    }

    /**
     * Enqueue frontend assets for the tickets display.
     */
    public function enqueue_assets(): void
    {
        wp_enqueue_style(
            'oras-tickets-frontend',
            ORAS_TICKETS_URL . 'assets/css/tickets-frontend.css',
            [],
            ORAS_TICKETS_VERSION
        );
    }

    /**
     * Revalidate ORAS ticket items already in the cart.
     */
    public function revalidate_cart_items(): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        if (! function_exists('WC') || ! WC() || ! isset(WC()->cart)) {
            return;
        }

        $now = (int) current_time('timestamp', true);

        $changed = false;

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $event_id_raw = get_post_meta($product_id, '_oras_ticket_event_id', true);
            $index_raw = get_post_meta($product_id, '_oras_ticket_index', true);
            if ($event_id_raw === '' || $index_raw === '') {
                if ($event_id_raw !== '' || $index_raw !== '') {
                    WC()->cart->remove_cart_item($cart_item_key);
                    $changed = true;
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(__('A ticket in your cart is no longer available and was removed.', 'oras-tickets'), 'error');
                    }
                }
                continue;
            }

            $event_id = (int) $event_id_raw;
            $index = (int) $index_raw;
            if ($event_id <= 0 || $index < 0) {
                WC()->cart->remove_cart_item($cart_item_key);
                $changed = true;
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('A ticket in your cart is no longer available and was removed.', 'oras-tickets'), 'error');
                }
                continue;
            }

            $ticket = $this->get_ticket_definition($event_id, $index);
            if (! $ticket) {
                WC()->cart->remove_cart_item($cart_item_key);
                $changed = true;
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('A ticket in your cart is no longer available and was removed.', 'oras-tickets'), 'error');
                }
                continue;
            }

            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (! $product || ! $product->is_purchasable()) {
                WC()->cart->remove_cart_item($cart_item_key);
                $changed = true;
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('A ticket in your cart is no longer available and was removed.', 'oras-tickets'), 'error');
                }
                continue;
            }

            $name = $this->get_ticket_name($ticket, $product);

            $manages = (method_exists($product, 'managing_stock') && $product->managing_stock());

            if (! $product->is_in_stock() && ! $product->backorders_allowed()) {
                if (! $manages) {
                    WC()->cart->remove_cart_item($cart_item_key);
                    $changed = true;
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s is sold out and was removed from your cart.', 'oras-tickets'), $name), 'error');
                    }
                    continue;
                }
            }

            $sale_start = isset($ticket['sale_start']) ? (string) $ticket['sale_start'] : '';
            $sale_end = isset($ticket['sale_end']) ? (string) $ticket['sale_end'] : '';

            if ($sale_start !== '') {
                $start_ts = strtotime($sale_start . ' UTC');
                if ($start_ts && $start_ts > $now) {
                    WC()->cart->remove_cart_item($cart_item_key);
                    $changed = true;
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s is not on sale yet and was removed from your cart.', 'oras-tickets'), $name), 'error');
                    }
                    continue;
                }
            }

            if ($sale_end !== '') {
                $end_ts = strtotime($sale_end . ' UTC');
                if ($end_ts && $end_ts < $now) {
                    WC()->cart->remove_cart_item($cart_item_key);
                    $changed = true;
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s sales have ended and was removed from your cart.', 'oras-tickets'), $name), 'error');
                    }
                    continue;
                }
            }

            $current_qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 0;

            if ($manages) {
                $available = method_exists($product, 'get_stock_quantity') ? (int) $product->get_stock_quantity() : 0;
                // Woo can temporarily reserve stock during checkout; do not remove on 0 here.
                if ($available > 0 && $current_qty > $available) {
                    WC()->cart->set_quantity($cart_item_key, $available, true);
                    $changed = true;
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Quantity for %1$s was reduced to %2$d due to limited availability.', 'oras-tickets'), $name, $available), 'notice');
                    }
                }
            } else {
                if ($current_qty > 10) {
                    WC()->cart->set_quantity($cart_item_key, 10, true);
                    $changed = true;
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Quantity for %s was reduced to 10.', 'oras-tickets'), $name), 'notice');
                    }
                }
            }
        }

        if ($changed && isset(WC()->cart)) {
            WC()->cart->calculate_totals();
        }
    }

    /**
     * Fetch the ticket definition for the given event and index.
     */
    private function get_ticket_definition(int $event_id, int $index): ?array
    {
        if ($event_id <= 0 || $index < 0) {
            return null;
        }

        $collection = Ticket_Collection::load_for_event($event_id);
        $tickets = $collection->all();

        if (! array_key_exists($index, $tickets)) {
            return null;
        }

        $ticket_obj = $tickets[$index];
        $ticket = method_exists($ticket_obj, 'to_array') ? $ticket_obj->to_array() : (is_array($ticket_obj) ? $ticket_obj : []);

        return ! empty($ticket) ? $ticket : null;
    }

    /**
     * Resolve the display name for a ticket.
     *
     * @param array $ticket
     * @param mixed $product
     */
    private function get_ticket_name(array $ticket, $product): string
    {
        if (isset($ticket['name']) && $ticket['name'] !== '') {
            return (string) $ticket['name'];
        }

        if ($product && method_exists($product, 'get_name')) {
            return (string) $product->get_name();
        }

        return __('Ticket', 'oras-tickets');
    }

    /**
     * Return true when the ticket is currently on sale based on its sale window.
     */
    private function is_ticket_on_sale_now(array $ticket, int $now): bool
    {
        $sale_start = isset($ticket['sale_start']) ? (string) $ticket['sale_start'] : '';
        $sale_end = isset($ticket['sale_end']) ? (string) $ticket['sale_end'] : '';

        if ($sale_start !== '') {
            $start_ts = strtotime($sale_start . ' UTC');
            if ($start_ts && $start_ts > $now) {
                return false;
            }
        }

        if ($sale_end !== '') {
            $end_ts = strtotime($sale_end . ' UTC');
            if ($end_ts && $end_ts < $now) {
                return false;
            }
        }

        return true;
    }

    private function get_mapped_product_id(array $map, int $index): int
    {
        $string_key = (string) $index;
        if (isset($map[$string_key])) {
            return absint($map[$string_key]);
        }

        if (isset($map[$index])) {
            return absint($map[$index]);
        }

        return 0;
    }

    /**
     * Filter the main post content and append purchase form for single TEC events.
     */
    public function the_content_filter(string $content): string
    {
        if (! is_singular(Meta::EVENT_POST_TYPE)) {
            return $content;
        }

        if (! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        $event_id = get_the_ID();
        if (! $event_id || $event_id <= 0) {
            return $content;
        }

        $form = $this->render_form_html($event_id);
        return $content . $form;
    }

    /**
     * Return the HTML for the purchase form for the given event.
     */
    private function render_form_html(int $event_id): string
    {
        $collection = Ticket_Collection::load_for_event($event_id);
        if ($collection->count() === 0) {
            return '<p>Tickets not available</p>';
        }

        $map = get_post_meta($event_id, '_oras_tickets_woo_map_v1', true);
        if (! is_array($map) || empty($map)) {
            return '<p>Tickets not available</p>';
        }

        // All sale window comparisons are done in UTC.
        $now = (int) current_time('timestamp', true);
        $tickets = $collection->all();
        $tickets_on_sale = [];
        foreach ($tickets as $index => $ticket_obj) {
            $ticket = method_exists($ticket_obj, 'to_array') ? $ticket_obj->to_array() : (is_array($ticket_obj) ? $ticket_obj : []);
            if (! $this->is_ticket_on_sale_now($ticket, $now)) {
                continue;
            }
            $tickets_on_sale[$index] = $ticket_obj;
        }

        ob_start();

        echo '<section class="oras-tickets-section">';
        echo '<div id="oras-tickets-display" class="oras-tickets-display">';
        echo '<h2>Tickets</h2>';

        if (function_exists('wc_print_notices')) {
            wc_print_notices();
        }

        if (empty($tickets_on_sale)) {
            echo '<p>Tickets are not currently on sale.</p>';
            echo '</div>';
            echo '</section>';
            return (string) ob_get_clean();
        }

        echo '<form method="post" action="' . esc_url(get_permalink($event_id)) . '">';
        echo wp_nonce_field('oras_tickets_add_to_cart', 'oras_tickets_nonce', true, false);
        // marker to make remote HTML checks easier
        echo '<input type="hidden" name="_oras_tickets" value="1" />';

        echo '<table class="oras-tickets-table">';
        echo '<thead><tr><th>Ticket</th><th>Price</th><th>Status</th><th>Qty</th></tr></thead>';
        echo '<tbody>';

        foreach ($tickets_on_sale as $index => $ticket_obj) {
            $ticket = method_exists($ticket_obj, 'to_array') ? $ticket_obj->to_array() : (is_array($ticket_obj) ? $ticket_obj : []);
            $key = (string) $index;

            $product_id = $this->get_mapped_product_id($map, $index);
            if ($product_id <= 0) {
                continue;
            }
            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (! $product) {
                continue;
            }

            $name = isset($ticket['name']) ? esc_html($ticket['name']) : $product->get_name();
            $resolved = Price_Resolver::resolve_ticket_price($ticket);
            $price_raw = $resolved['price'];
            $price_display = $price_raw !== '' && is_numeric($price_raw) ? '$' . number_format((float) $price_raw, 2, '.', '') : esc_html((string) $price_raw);
            $description = isset($ticket['description']) ? esc_html($ticket['description']) : '';

            $sale_start = isset($ticket['sale_start']) ? (string) $ticket['sale_start'] : '';
            $sale_end = isset($ticket['sale_end']) ? (string) $ticket['sale_end'] : '';

            // Ticket definition stores sale window in UTC strings.
            $start_ts = $sale_start !== '' ? strtotime($sale_start . ' UTC') : false;
            $end_ts = $sale_end !== '' ? strtotime($sale_end . ' UTC') : false;

            $status = 'On sale';
            $status_class = 'oras-status--on-sale';
            $disabled = false;
            $disabled_reason = '';
            if ($start_ts && $now < $start_ts) {
                $status = 'Not on sale yet';
                $status_class = 'oras-status--not-yet';
                $disabled = true;
                $disabled_reason = 'Not on sale yet';
            } elseif ($end_ts && $now > $end_ts) {
                $status = 'Sales ended';
                $status_class = 'oras-status--ended';
                $disabled = true;
                $disabled_reason = 'Sales ended';
            }

            $manages = (method_exists($product, 'managing_stock') && $product->managing_stock());
            if ($manages) {
                $max = method_exists($product, 'get_stock_quantity') ? max(0, (int) $product->get_stock_quantity()) : 0;
            } else {
                $max = 10;
            }

            if ($manages && $max <= 0) {
                $status = 'Sold out';
                $status_class = 'oras-status--sold-out';
                $disabled = true;
                $disabled_reason = 'Sold out';
            }

            $stock_note = '';
            if ($status_class === 'oras-status--on-sale') {
                if ($manages) {
                    $stock_qty = (int) $max;
                    if ($stock_qty > 0) {
                        $stock_note = '• ' . $stock_qty . ' left';
                    }
                } else {
                    $stock_note = '• Unlimited';
                }
            }

            echo '<tr>';
            echo '<td><strong>' . $name . '</strong>';
            if ($description !== '') {
                echo '<div class="oras-ticket-desc">' . $description . '</div>';
            }
            if (! empty($resolved['phase_label']) && is_string($resolved['phase_label'])) {
                $phase_label = (string) $resolved['phase_label'];
                if (strtolower($phase_label) !== 'standard') {
                    echo '<div class="oras-ticket-phase">' . esc_html($phase_label) . '</div>';
                }
            }
            if (isset($resolved['phase_end_ts']) && is_int($resolved['phase_end_ts']) && $resolved['phase_end_ts'] > $now) {
                $remaining = max(0, $resolved['phase_end_ts'] - $now);
                $total_minutes = (int) floor($remaining / 60);
                $days = (int) floor($total_minutes / 1440);
                $hours = (int) floor(($total_minutes % 1440) / 60);
                $minutes = (int) ($total_minutes % 60);
                $parts = [];
                if ($days > 0) {
                    $parts[] = $days . 'd';
                }
                if ($hours > 0) {
                    $parts[] = $hours . 'h';
                }
                if ($minutes > 0) {
                    $parts[] = $minutes . 'm';
                }
                if (! empty($parts)) {
                    echo '<div class="oras-ticket-phase-countdown">' . esc_html('Price increases in: ' . implode(' ', $parts)) . '</div>';
                }
            }
            if ($disabled && $disabled_reason !== '') {
                echo '<div class="oras-ticket-status">' . esc_html($disabled_reason) . '</div>';
            }
            echo '</td>';
            echo '<td>' . esc_html($price_display) . '</td>';
            echo '<td><span class="oras-ticket-status-badge ' . esc_attr($status_class) . '">' . esc_html($status);
            if ($stock_note !== '') {
                echo ' <span class="oras-ticket-stock-note">' . esc_html($stock_note) . '</span>';
            }
            echo '</span></td>';

            $input_attrs = '';
            if ($disabled) {
                $input_attrs .= ' disabled';
            }
            $input_max = (! $disabled && $max > 0) ? $max : 0;
            echo '<td>';
            echo '<input type="number" name="oras_qty[' . esc_attr($key) . ']" min="0" value="0" max="' . esc_attr($input_max) . '"' . $input_attrs . ' />';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        // Submit and view cart
        $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#';
        echo '<p><button type="submit" name="oras_tickets_add_to_cart" class="button">Add selected tickets to cart</button> ';
        echo '<a class="button" href="' . esc_url($cart_url) . '">' . esc_html__('View cart', 'oras-tickets') . '</a></p>';
        echo '</form>';

        echo '</div>';
        echo '</section>';

        return (string) ob_get_clean();
    }

    /**
     * Handle POST submission on template_redirect.
     */
    public function handle_post(): void
    {
        if (! isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }

        if (! is_singular(Meta::EVENT_POST_TYPE)) {
            return;
        }

        $event_id = (int) get_queried_object_id();
        if ($event_id <= 0) {
            return;
        }

        if (! isset($_POST['_oras_tickets']) || (string) wp_unslash($_POST['_oras_tickets']) !== '1') {
            return;
        }

        if (! isset($_POST['oras_tickets_nonce'])) {
            return;
        }

        $nonce = (string) wp_unslash($_POST['oras_tickets_nonce']);
        if (! wp_verify_nonce($nonce, 'oras_tickets_add_to_cart')) {
            return;
        }

        // Avoid fatals if Woo cart isn't initialized.
        if (! function_exists('WC') || ! WC() || ! isset(WC()->cart)) {
            return;
        }

        $collection = Ticket_Collection::load_for_event($event_id);
        $tickets = $collection->all();

        $map = get_post_meta($event_id, '_oras_tickets_woo_map_v1', true);
        if (! is_array($map)) {
            $map = [];
        }

        $posted = isset($_POST['oras_qty']) && is_array($_POST['oras_qty']) ? wp_unslash($_POST['oras_qty']) : [];

        $added_any = false;
        $had_error = false;
        $now = (int) current_time('timestamp', true);

        foreach ($posted as $raw_index => $raw_qty) {
            $index = absint($raw_index);
            $qty = absint($raw_qty);

            if ($qty === 0) {
                continue;
            }

            if (! array_key_exists($index, $tickets)) {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Invalid ticket selection.', 'oras-tickets'), 'error');
                }
                $had_error = true;
                continue;
            }

            $ticket_obj = $tickets[$index];
            $ticket = method_exists($ticket_obj, 'to_array') ? $ticket_obj->to_array() : (is_array($ticket_obj) ? $ticket_obj : []);
            $name = isset($ticket['name']) && $ticket['name'] !== '' ? (string) $ticket['name'] : __('Ticket', 'oras-tickets');

            $product_id = $this->get_mapped_product_id($map, $index);
            if ($product_id <= 0) {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(sprintf(__('Ticket %s is not available.', 'oras-tickets'), $name), 'error');
                }
                $had_error = true;
                continue;
            }

            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (! $product) {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(sprintf(__('Ticket %s is not available.', 'oras-tickets'), $name), 'error');
                }
                $had_error = true;
                continue;
            }

            $sale_start = isset($ticket['sale_start']) ? (string) $ticket['sale_start'] : '';
            $sale_end = isset($ticket['sale_end']) ? (string) $ticket['sale_end'] : '';

            if ($sale_start !== '') {
                $start_ts = strtotime($sale_start . ' UTC');
                if ($start_ts && $start_ts > $now) {
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s is not on sale yet.', 'oras-tickets'), $name), 'error');
                    }
                    $had_error = true;
                    continue;
                }
            }

            if ($sale_end !== '') {
                $end_ts = strtotime($sale_end . ' UTC');
                if ($end_ts && $end_ts < $now) {
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s sales have ended.', 'oras-tickets'), $name), 'error');
                    }
                    $had_error = true;
                    continue;
                }
            }

            if (method_exists($product, 'managing_stock') && $product->managing_stock()) {
                $available = method_exists($product, 'get_stock_quantity') ? max(0, (int) $product->get_stock_quantity()) : 0;
                if ($available <= 0) {
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s is sold out.', 'oras-tickets'), $name), 'error');
                    }
                    $had_error = true;
                    continue;
                }

                $qty_to_add = min($qty, $available);
                if ($qty_to_add < $qty) {
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s quantity was capped to remaining stock.', 'oras-tickets'), $name), 'error');
                    }
                    $had_error = true;
                }
            } else {
                $qty_to_add = min($qty, 10);
                if ($qty_to_add < $qty) {
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(sprintf(__('Ticket %s quantity was capped.', 'oras-tickets'), $name), 'error');
                    }
                    $had_error = true;
                }
            }

            if ($qty_to_add <= 0) {
                continue;
            }

            $added = WC()->cart->add_to_cart($product_id, $qty_to_add);
            if (! $added) {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(sprintf(__('Could not add %s to cart.', 'oras-tickets'), $name), 'error');
                }
                $had_error = true;
                continue;
            }

            $added_any = true;
        }

        if ($added_any && function_exists('wc_add_notice')) {
            $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : '';
            $message = sprintf(
                /* translators: %s is the cart URL. */
                __('Tickets added to cart. <a class="button" href="%s">View cart</a>', 'oras-tickets'),
                esc_url($cart_url)
            );
            $allowed = [
                'a' => [
                    'class' => true,
                    'href' => true,
                ],
            ];
            wc_add_notice(wp_kses($message, $allowed), 'success');
        }

        if (! $added_any && ! $had_error && function_exists('wc_add_notice')) {
            wc_add_notice(__('No valid tickets were added.', 'oras-tickets'), 'error');
        }

        wp_safe_redirect(get_permalink($event_id));
        exit;
    }
}
