<?php

namespace ORAS\Tickets\Api;

if (! defined('ABSPATH')) {
  exit;
}

final class Member_Hub_Tickets
{

  public function register(): void
  {
    add_action('rest_api_init', [$this, 'register_routes']);
  }

  public function register_routes(): void
  {
    register_rest_route(
      'oras-tickets/v1',
      '/me/tickets',
      [
        'methods' => 'GET',
        'callback' => [$this, 'get_my_tickets'],
        'permission_callback' => 'is_user_logged_in',
        'args' => [
          'scope' => [
            'default' => 'upcoming',
            'sanitize_callback' => [$this, 'sanitize_scope'],
          ],
          'group_by' => [
            'default' => 'none',
            'sanitize_callback' => [$this, 'sanitize_group_by'],
          ],
          'page' => [
            'default' => 1,
            'sanitize_callback' => 'absint',
          ],
          'per_page' => [
            'default' => 20,
            'sanitize_callback' => 'absint',
          ],
        ],
      ]
    );

    register_rest_route(
      'oras-tickets/v1',
      '/me/tickets/summary',
      [
        'methods' => 'GET',
        'callback' => [$this, 'get_my_tickets_summary'],
        'permission_callback' => 'is_user_logged_in',
      ]
    );
  }

  public function get_my_tickets(\WP_REST_Request $request): \WP_REST_Response
  {
    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) {
      return rest_ensure_response(
        [
          'items' => [],
          'page' => 1,
          'per_page' => 0,
          'order_count' => 0,
          'order_pages' => 0,
        ]
      );
    }

    if (! function_exists('wc_get_orders')) {
      return rest_ensure_response(
        [
          'items' => [],
          'page' => 1,
          'per_page' => 0,
          'order_count' => 0,
          'order_pages' => 0,
        ]
      );
    }

    $scope = $this->sanitize_scope($request->get_param('scope'));
    $group_by = $this->sanitize_group_by($request->get_param('group_by'));
    $page = max(1, (int) $request->get_param('page'));
    $per_page = min(100, max(1, (int) $request->get_param('per_page')));

    $paged = $this->get_paged_orders($user_id, $page, $per_page);
    $orders = $paged['orders'];
    $total = $paged['total'];
    $max_pages = $paged['max_pages'];

    $now = (int) current_time('timestamp', true);
    $scope_for_items = $group_by === 'event' ? 'all' : $scope;
    $grouped = $this->get_ticket_groups($orders, $scope_for_items, $now);

    if ($group_by === 'event') {
      $events = $this->group_items_by_event($grouped['items'], $now);

      return rest_ensure_response(
        [
          'upcoming' => $events['upcoming'],
          'past' => $events['past'],
          'meta' => [
            'page' => $page,
            'per_page' => $per_page,
            'order_count' => $total,
            'order_pages' => $max_pages,
          ],
        ]
      );
    }

    return rest_ensure_response(
      [
        'items' => $grouped['items'],
        'page' => $page,
        'per_page' => $per_page,
        'order_count' => $total,
        'order_pages' => $max_pages,
      ]
    );
  }

  public function get_my_tickets_summary(\WP_REST_Request $request): \WP_REST_Response
  {
    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) {
      return rest_ensure_response(
        [
          'upcoming_count' => 0,
          'past_count' => 0,
          'last_purchase_date' => null,
        ]
      );
    }

    if (! function_exists('wc_get_orders')) {
      return rest_ensure_response(
        [
          'upcoming_count' => 0,
          'past_count' => 0,
          'last_purchase_date' => null,
        ]
      );
    }

    $orders = $this->get_all_orders($user_id);
    $now = (int) current_time('timestamp', true);
    $grouped = $this->get_ticket_groups($orders, 'all', $now);

    $upcoming = 0;
    $past = 0;
    foreach ($grouped['items'] as $item) {
      $bucket = $this->bucket_event((int) $item['event_id'], $item['event_start'], $now);
      if ($bucket === 'past') {
        $past++;
      } else {
        $upcoming++;
      }
    }

    $last_purchase_date = $grouped['last_purchase_ts'] > 0
      ? wp_date('c', $grouped['last_purchase_ts'])
      : null;

    return rest_ensure_response(
      [
        'upcoming_count' => $upcoming,
        'past_count' => $past,
        'last_purchase_date' => $last_purchase_date,
      ]
    );
  }

  public function sanitize_scope($value): string
  {
    $allowed = ['upcoming', 'past', 'all'];
    $value = is_string($value) ? strtolower($value) : '';

    return in_array($value, $allowed, true) ? $value : 'upcoming';
  }

  public function sanitize_group_by($value): string
  {
    $allowed = ['event', 'none'];
    $value = is_string($value) ? strtolower($value) : '';

    return in_array($value, $allowed, true) ? $value : 'none';
  }

  /**
   * @return string[]
   */
  private function get_allowed_statuses(): array
  {
    return ['completed', 'processing', 'refunded'];
  }

  private function get_item_event_id($item): int
  {
    if (! $item || ! method_exists($item, 'get_meta')) {
      return 0;
    }

    $event_id = $item->get_meta('_oras_ticket_event_id', true);
    if ($event_id === '' || (int) $event_id <= 0) {
      $product_id = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
      if ($product_id > 0) {
        $event_id = get_post_meta($product_id, '_oras_ticket_event_id', true);
      }
    }

    return (int) $event_id;
  }

  /**
   * @param \WC_Order[] $orders
   * @return array{items:array<int,array<string,mixed>>,last_purchase_ts:int}
   */
  private function get_ticket_groups(array $orders, string $scope, int $now): array
  {
    $groups = [];
    $last_purchase_ts = 0;

    foreach ($orders as $order) {
      if (! $order instanceof \WC_Order) {
        continue;
      }

      $order_id = (int) $order->get_id();
      if ($order_id <= 0) {
        continue;
      }

      $order_date = $order->get_date_created();
      $order_date_iso = $order_date ? $order_date->date('c') : null;
      $order_date_ts = $order_date ? (int) $order_date->getTimestamp() : 0;

      $items = $order->get_items('line_item');
      foreach ($items as $item) {
        $event_id = $this->get_item_event_id($item);
        if ($event_id <= 0) {
          continue;
        }

        $event_start = $this->get_event_start($event_id);
        $event_ts = $event_start !== null ? strtotime($event_start) : 0;
        if (! $this->matches_scope($scope, $event_ts, $event_start, $now)) {
          continue;
        }

        if ($order_date_ts > 0 && $order_date_ts > $last_purchase_ts) {
          $last_purchase_ts = $order_date_ts;
        }

        $key = $order_id . ':' . $event_id;
        if (! isset($groups[$key])) {
          $groups[$key] = [
            'event_id' => $event_id,
            'event_title' => (string) get_the_title($event_id),
            'event_start' => $event_start,
            'event_url' => (string) get_permalink($event_id),
            'order_id' => $order_id,
            'order_status' => (string) $order->get_status(),
            'order_date' => $order_date_iso,
            'order_total' => (string) $order->get_total(),
            'qty' => 0,
            'order_view_url' => $this->get_order_view_url($order_id),
          ];
        }

        $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0;
        $groups[$key]['qty'] += max(0, $qty);
      }
    }

    return [
      'items' => array_values($groups),
      'last_purchase_ts' => $last_purchase_ts,
    ];
  }

  /**
   * @param array<int,array<string,mixed>> $items
   * @return array{upcoming:array<int,array<string,mixed>>,past:array<int,array<string,mixed>>}
   */
  private function group_items_by_event(array $items, int $now): array
  {
    $events = [];

    foreach ($items as $item) {
      $event_id = isset($item['event_id']) ? (int) $item['event_id'] : 0;
      if ($event_id <= 0) {
        continue;
      }

      if (! isset($events[$event_id])) {
        $events[$event_id] = [
          'event_id' => $event_id,
          'event_title' => isset($item['event_title']) ? (string) $item['event_title'] : '',
          'event_start' => $item['event_start'] ?? null,
          'event_url' => isset($item['event_url']) ? (string) $item['event_url'] : '',
          'total_qty' => 0,
          'orders' => [],
        ];
      }

      $events[$event_id]['total_qty'] += isset($item['qty']) ? (int) $item['qty'] : 0;
      $events[$event_id]['orders'][] = [
        'order_id' => isset($item['order_id']) ? (int) $item['order_id'] : 0,
        'order_status' => isset($item['order_status']) ? (string) $item['order_status'] : '',
        'order_date' => $item['order_date'] ?? null,
        'order_total' => isset($item['order_total']) ? (string) $item['order_total'] : '',
        'qty' => isset($item['qty']) ? (int) $item['qty'] : 0,
        'order_view_url' => isset($item['order_view_url']) ? (string) $item['order_view_url'] : '',
      ];
    }

    $upcoming = [];
    $past = [];

    foreach ($events as $event_id => $event) {
      $bucket = $this->bucket_event($event_id, $event['event_start'], $now);
      if ($bucket === 'past') {
        $past[] = $event;
      } else {
        $upcoming[] = $event;
      }
    }

    return [
      'upcoming' => $upcoming,
      'past' => $past,
    ];
  }

  private function get_event_start(int $event_id): ?string
  {
    if ($event_id <= 0) {
      return null;
    }

    if (function_exists('tribe_get_start_date')) {
      $start = tribe_get_start_date($event_id, false, 'c');
      if (is_string($start) && $start !== '') {
        return $start;
      }
    }

    $raw = get_post_meta($event_id, '_EventStartDateUTC', true);
    if ($raw === '') {
      $raw = get_post_meta($event_id, '_EventStartDate', true);
    }

    if (! is_string($raw) || $raw === '') {
      return null;
    }

    $timestamp = strtotime($raw);
    if (! $timestamp) {
      return null;
    }

    return wp_date('c', $timestamp);
  }

  private function matches_scope(string $scope, int $event_ts, ?string $event_start, int $now): bool
  {
    if ($scope === 'all') {
      return true;
    }

    if ($event_ts <= 0 && $event_start === null) {
      return $scope === 'upcoming';
    }

    if ($scope === 'upcoming') {
      return $event_ts >= $now;
    }

    return $event_ts < $now;
  }

  private function bucket_event(int $event_id, $event_start, int $now): string
  {
    if ($event_id <= 0) {
      return 'upcoming';
    }

    $event_ts = is_string($event_start) && $event_start !== '' ? strtotime($event_start) : 0;
    if ($event_ts <= 0) {
      return 'upcoming';
    }

    return $event_ts < $now ? 'past' : 'upcoming';
  }

  /**
   * @return array{orders:\WC_Order[],total:int,max_pages:int}
   */
  private function get_paged_orders(int $user_id, int $page, int $per_page): array
  {
    $args = $this->get_order_args($user_id, $page, $per_page, true);
    $results = wc_get_orders($args);
    $orders = [];
    $total = 0;
    $max_pages = 0;

    if (is_object($results) && isset($results->orders)) {
      $orders = is_array($results->orders) ? $results->orders : [];
      $total = isset($results->total) ? (int) $results->total : 0;
      $max_pages = isset($results->max_num_pages) ? (int) $results->max_num_pages : 0;
    } elseif (is_array($results)) {
      $orders = $results;
      $total = count($orders);
      $max_pages = (int) ceil($total / max(1, $per_page));
    }

    return [
      'orders' => $orders,
      'total' => $total,
      'max_pages' => $max_pages,
    ];
  }

  /**
   * @return \WC_Order[]
   */
  private function get_all_orders(int $user_id): array
  {
    $orders = [];
    $page = 1;
    $per_page = 100;

    do {
      $args = $this->get_order_args($user_id, $page, $per_page, false);
      $batch = wc_get_orders($args);
      if (empty($batch)) {
        break;
      }

      foreach ($batch as $order) {
        if ($order instanceof \WC_Order) {
          $orders[] = $order;
        }
      }

      $page++;
    } while (count($batch) === $per_page);

    return $orders;
  }

  /**
   * @return array<string,mixed>
   */
  private function get_order_args(int $user_id, int $page, int $per_page, bool $paginate): array
  {
    return [
      'customer_id' => $user_id,
      'limit' => $per_page,
      'page' => $page,
      'status' => $this->get_allowed_statuses(),
      'orderby' => 'date',
      'order' => 'DESC',
      'paginate' => $paginate,
    ];
  }

  private function get_order_view_url(int $order_id): string
  {
    if ($order_id <= 0 || ! function_exists('wc_get_endpoint_url')) {
      return '';
    }

    $base = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';
    if ($base === '') {
      $myaccount_id = (int) get_option('woocommerce_myaccount_page_id');
      $base = $myaccount_id > 0 ? (string) get_permalink($myaccount_id) : '';
    }

    if ($base === '') {
      return '';
    }

    return (string) wc_get_endpoint_url('view-order', $order_id, $base);
  }
}
