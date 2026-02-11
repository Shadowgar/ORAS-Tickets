<?php

if (! defined('ABSPATH')) {
  exit;
}

$event_title = isset($data['event_title']) ? (string) $data['event_title'] : '';
$event_start = isset($data['event_start']) ? (string) $data['event_start'] : '';
$event_id = isset($data['event_id']) ? (int) $data['event_id'] : 0;
$order_id = isset($data['order_id']) ? (int) $data['order_id'] : 0;
$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

$esc = static function ($value): string {
  return esc_html((string) $value);
};

$format_datetime = static function (?string $iso): string {
  if (! is_string($iso) || $iso === '') {
    return __('TBD', 'oras-tickets');
  }

  $timestamp = strtotime($iso);
  if (! $timestamp) {
    return __('TBD', 'oras-tickets');
  }

  $date_format = (string) get_option('date_format');
  $time_format = (string) get_option('time_format');
  $format = trim($date_format . ' ' . $time_format);

  return wp_date($format !== '' ? $format : 'M j, Y g:i a', $timestamp);
};

$format_price = static function (float $price, string $currency): string {
  if (function_exists('wc_price')) {
    return (string) wc_price($price, ['currency' => $currency]);
  }

  return esc_html(number_format($price, 2));
};

$order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
$purchaser_name = '';
$purchase_date = '';
if ($order && $order instanceof \WC_Order) {
  $user = $order->get_user();
  $display_name = $user ? (string) $user->display_name : '';
  $first = (string) $order->get_billing_first_name();
  $last = (string) $order->get_billing_last_name();
  $billing_name = trim($first . ' ' . $last);
  $purchaser_name = $display_name !== '' ? $display_name : $billing_name;

  $date_created = $order->get_date_created();
  $purchase_date = $date_created ? $date_created->date('c') : '';
}

$location_text = '';
if ($event_id > 0 && function_exists('tribe_get_venue_id')) {
  $venue_id = (int) tribe_get_venue_id($event_id);
  if ($venue_id > 0) {
    $venue_name = function_exists('tribe_get_venue') ? (string) tribe_get_venue($event_id) : '';
    $address = function_exists('tribe_get_address') ? (string) tribe_get_address($venue_id) : '';
    $city = function_exists('tribe_get_city') ? (string) tribe_get_city($venue_id) : '';
    $state = function_exists('tribe_get_state') ? (string) tribe_get_state($venue_id) : '';
    $province = function_exists('tribe_get_province') ? (string) tribe_get_province($venue_id) : '';
    $zip = function_exists('tribe_get_zip') ? (string) tribe_get_zip($venue_id) : '';
    $country = function_exists('tribe_get_country') ? (string) tribe_get_country($venue_id) : '';

    $region = $state !== '' ? $state : $province;
    $parts = array_filter(
      [
        $venue_name,
        $address,
        $city,
        $region,
        $zip,
        $country,
      ],
      static function ($part): bool {
        return is_string($part) && $part !== '';
      }
    );

    $location_text = implode(', ', $parts);
  }
}

if ($location_text === '' && $event_id > 0 && function_exists('tribe_get_full_address')) {
  $location_text = (string) tribe_get_full_address($event_id);
}

if ($location_text === '' && $event_id > 0 && function_exists('tribe_get_venue')) {
  $location_text = (string) tribe_get_venue($event_id);
}

if ($location_text === '' && $event_id > 0) {
  $location_text = (string) get_post_meta($event_id, '_EventVenue', true);
}

$location_text = wp_strip_all_tags($location_text);

$event_start_display = $format_datetime($event_start);
$purchase_date_display = $format_datetime($purchase_date);
$printed_name = $purchaser_name !== '' ? $purchaser_name : __('Guest', 'oras-tickets');

?>
<div class="oras-ticket-print">
  <?php foreach ($items as $item) : ?>
    <?php
    $ticket_name = isset($item['ticket_name']) ? (string) $item['ticket_name'] : '';
    $quantity = isset($item['quantity']) ? max(1, (int) $item['quantity']) : 1;
    $price = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
    $currency = isset($item['currency']) ? (string) $item['currency'] : '';
    $phase_label = isset($item['phase_label']) ? (string) $item['phase_label'] : '';
    ?>

    <?php for ($i = 0; $i < $quantity; $i++) : ?>
      <?php
      $sequence_label = sprintf(
        /* translators: 1: current ticket number, 2: total tickets */
        __('Ticket %1$d of %2$d', 'oras-tickets'),
        $i + 1,
        $quantity
      );
      ?>
      <article class="oras-ticket-card">
        <header class="oras-ticket-header">
          <div class="oras-ticket-brand">ORAS</div>
          <div class="oras-ticket-event">
            <div class="oras-ticket-event-title"><?php echo $esc($event_title); ?></div>
            <div class="oras-ticket-event-meta">
              <span class="oras-ticket-event-time"><?php echo $esc($event_start_display); ?></span>
              <?php if ($location_text !== '') : ?>
                <span class="oras-ticket-event-location"><?php echo esc_html($location_text); ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="oras-ticket-mark">Ticket</div>
        </header>

        <div class="oras-ticket-body">
          <div class="oras-ticket-main">
            <div class="oras-ticket-row">
              <div class="oras-ticket-label"><?php echo esc_html__('Ticket name', 'oras-tickets'); ?></div>
              <div class="oras-ticket-value"><?php echo $esc($ticket_name); ?></div>
            </div>
            <div class="oras-ticket-row">
              <div class="oras-ticket-label"><?php echo esc_html__('Order number', 'oras-tickets'); ?></div>
              <div class="oras-ticket-value">#<?php echo $esc((string) $order_id); ?></div>
            </div>
            <div class="oras-ticket-row">
              <div class="oras-ticket-label"><?php echo esc_html__('Purchaser', 'oras-tickets'); ?></div>
              <div class="oras-ticket-value"><?php echo $esc($printed_name); ?></div>
            </div>
            <div class="oras-ticket-row">
              <div class="oras-ticket-label"><?php echo esc_html__('Purchase date', 'oras-tickets'); ?></div>
              <div class="oras-ticket-value"><?php echo $esc($purchase_date_display); ?></div>
            </div>
            <div class="oras-ticket-row">
              <div class="oras-ticket-label"><?php echo esc_html__('Price', 'oras-tickets'); ?></div>
              <div class="oras-ticket-value oras-ticket-price"><?php echo wp_kses_post($format_price($price, $currency)); ?></div>
            </div>
            <div class="oras-ticket-row">
              <div class="oras-ticket-label"><?php echo esc_html__('Pricing phase', 'oras-tickets'); ?></div>
              <div class="oras-ticket-value"><?php echo $esc($phase_label); ?></div>
            </div>
          </div>

          <div class="oras-ticket-stub">
            <div class="oras-ticket-stub-title"><?php echo esc_html__('Admit One', 'oras-tickets'); ?></div>
            <div class="oras-ticket-stub-seq"><?php echo $esc($sequence_label); ?></div>
            <div class="oras-ticket-stub-barcode" aria-hidden="true"></div>
            <div class="oras-ticket-stub-order">#<?php echo $esc((string) $order_id); ?></div>
            <div class="oras-ticket-stub-event"><?php echo $esc($event_title); ?></div>
          </div>
        </div>

        <div class="oras-ticket-footer">
          <span class="oras-ticket-footer-label"><?php echo esc_html__('Printed for', 'oras-tickets'); ?></span>
          <span class="oras-ticket-footer-value"><?php echo $esc($printed_name); ?></span>
        </div>
      </article>
    <?php endfor; ?>
  <?php endforeach; ?>
</div>