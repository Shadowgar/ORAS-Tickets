<?php

namespace ORAS\Tickets\Admin\Pages;

require_once ORAS_TICKETS_DIR . 'includes/Admin/Reports_Aggregator.php';

use ORAS\Tickets\Admin\Reports_Aggregator;
use ORAS\Tickets\Domain\Meta;

if (! defined('ABSPATH')) {
  exit;
}

final class Reports_Page
{

  private const NONCE_ACTION = 'oras_tickets_reports';

  public function render(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      return;
    }

    $events = $this->get_events_with_tickets();
    $default_event_id = ! empty($events) ? (int) $events[0] : 0;
    $selected_event_id = isset($_GET['oras_tickets_event_id']) ? absint($_GET['oras_tickets_event_id']) : $default_event_id;
    $selected_statuses = $this->get_selected_statuses();

    $aggregator = new Reports_Aggregator();
    $aggregates = $selected_event_id > 0 ? $aggregator->get_aggregates($selected_event_id, $selected_statuses, []) : [
      'summary' => [
        'gross_sales' => 0.0,
        'refunded_mapped_total' => 0.0,
        'refunded_amount' => 0.0,
        'net_sales' => 0.0,
        'orders_count' => 0,
        'tickets_sold' => 0,
        'refunded_qty' => 0,
        'unattributed_refunds_amount' => 0.0,
        'unattributed_refunds_count' => 0,
        'adjustments_detected' => false,
      ],
      'by_ticket' => [],
    ];

?>
    <div class="wrap oras-tickets-reports">
      <h1><?php echo esc_html__('ORAS Tickets — Reports', 'oras-tickets'); ?></h1>
      <p class="description"><?php echo esc_html__('Sales & refunds summary for ORAS ticketed events', 'oras-tickets'); ?></p>
      <hr class="wp-header-end" />

      <style>
        .oras-tickets-reports .oras-card {
          background: #fff;
          border: 1px solid #dcdcde;
          border-radius: 8px;
          box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
          padding: 16px;
          margin-bottom: 16px;
        }

        .oras-tickets-reports .oras-grid {
          display: grid;
          gap: 16px;
        }

        .oras-tickets-reports .oras-grid--filters {
          grid-template-columns: 1fr auto;
          align-items: end;
        }

        .oras-tickets-reports .oras-grid--kpi {
          grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .oras-tickets-reports .oras-status-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
          gap: 8px 12px;
        }

        .oras-tickets-reports .oras-filter-actions {
          display: flex;
          align-items: center;
          gap: 10px;
          justify-content: flex-end;
          flex-wrap: wrap;
        }

        .oras-tickets-reports .oras-kpi {
          display: flex;
          flex-direction: column;
          gap: 4px;
        }

        .oras-tickets-reports .oras-kpi__value {
          font-size: 22px;
          font-weight: 600;
          line-height: 1.2;
        }

        .oras-tickets-reports .oras-kpi__label {
          font-size: 12px;
          color: #50575e;
          text-transform: uppercase;
          letter-spacing: .02em;
        }

        .oras-tickets-reports .oras-kpi__sub {
          font-size: 12px;
          color: #646970;
        }

        .oras-tickets-reports .oras-pill {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          padding: 2px 8px;
          border-radius: 999px;
          background: #f0f0f1;
          font-size: 12px;
          color: #3c434a;
        }

        .oras-tickets-reports .oras-table {
          width: 100%;
        }

        .oras-tickets-reports .oras-table th.is-right,
        .oras-tickets-reports .oras-table td.is-right {
          text-align: right;
        }

        .oras-tickets-reports .oras-bars {
          display: flex;
          flex-direction: column;
          gap: 12px;
        }

        .oras-tickets-reports .oras-bar-row {
          display: grid;
          grid-template-columns: 180px 1fr 140px;
          gap: 12px;
          align-items: center;
        }

        .oras-tickets-reports .oras-bar-track {
          position: relative;
          background: #e5e7eb;
          height: 10px;
          border-radius: 999px;
          overflow: hidden;
        }

        .oras-tickets-reports .oras-bar-fill {
          height: 10px;
          background: #2271b1;
          border-radius: 999px;
        }

        .oras-tickets-reports .oras-bar-fill--neg {
          background: #d63638;
        }

        .oras-tickets-reports .oras-note {
          margin-top: 8px;
        }

        @media (max-width: 960px) {
          .oras-tickets-reports .oras-grid--filters {
            grid-template-columns: 1fr;
          }

          .oras-tickets-reports .oras-filter-actions {
            justify-content: flex-start;
          }

          .oras-tickets-reports .oras-bar-row {
            grid-template-columns: 1fr;
          }
        }
      </style>

      <div class="oras-card">
        <div class="oras-grid oras-grid--filters">
          <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="oras-tickets-reports" />

            <table class="form-table" role="presentation">
              <tbody>
                <tr>
                  <th scope="row"><label for="oras_tickets_event_id"><?php echo esc_html__('Event', 'oras-tickets'); ?></label></th>
                  <td>
                    <?php if (empty($events)) : ?>
                      <p class="description"><?php echo esc_html__('No events found.', 'oras-tickets'); ?></p>
                    <?php else : ?>
                      <select name="oras_tickets_event_id" id="oras_tickets_event_id">
                        <?php foreach ($events as $event_id) :
                          $event_id = (int) $event_id;
                          $title = get_the_title($event_id);
                        ?>
                          <option value="<?php echo esc_attr((string) $event_id); ?>" <?php selected($selected_event_id, $event_id); ?>>
                            <?php echo esc_html($title); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr>
                  <th scope="row"><?php echo esc_html__('Statuses', 'oras-tickets'); ?></th>
                  <td>
                    <div class="oras-status-grid">
                      <?php foreach ($this->get_status_options() as $status_key => $label) : ?>
                        <label>
                          <input type="checkbox" name="oras_tickets_statuses[]" value="<?php echo esc_attr($status_key); ?>" <?php checked(in_array($status_key, $selected_statuses, true)); ?> />
                          <?php echo esc_html($label); ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>

            <div class="oras-filter-actions">
              <?php submit_button(__('Apply Filters', 'oras-tickets'), 'primary', 'submit', false); ?>
            </div>
          </form>

          <div class="oras-filter-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <?php wp_nonce_field(self::NONCE_ACTION, 'oras_tickets_reports_nonce'); ?>
              <input type="hidden" name="action" value="oras_tickets_export_csv" />
              <input type="hidden" name="oras_tickets_event_id" value="<?php echo esc_attr((string) $selected_event_id); ?>" />
              <?php foreach ($selected_statuses as $status) : ?>
                <input type="hidden" name="oras_tickets_statuses[]" value="<?php echo esc_attr($status); ?>" />
              <?php endforeach; ?>
              <?php submit_button(__('Download CSV', 'oras-tickets'), 'secondary', 'submit', false); ?>
            </form>
          </div>
        </div>
      </div>

      <div class="oras-card">
        <div class="oras-grid oras-grid--kpi">
          <div class="oras-kpi">
            <div class="oras-kpi__label"><?php echo esc_html__('Gross sales', 'oras-tickets'); ?></div>
            <div class="oras-kpi__value"><?php echo esc_html($this->format_money($aggregates['summary']['gross_sales'])); ?></div>
          </div>
          <div class="oras-kpi">
            <div class="oras-kpi__label"><?php echo esc_html__('Refunded', 'oras-tickets'); ?></div>
            <div class="oras-kpi__value"><?php echo esc_html($this->format_money($aggregates['summary']['refunded_amount'])); ?></div>
            <div class="oras-kpi__sub"><?php echo esc_html__('Includes unattributed refunds', 'oras-tickets'); ?></div>
          </div>
          <div class="oras-kpi">
            <div class="oras-kpi__label"><?php echo esc_html__('Net sales', 'oras-tickets'); ?></div>
            <div class="oras-kpi__value"><?php echo esc_html($this->format_money($aggregates['summary']['net_sales'])); ?></div>
          </div>
          <div class="oras-kpi">
            <div class="oras-kpi__label"><?php echo esc_html__('Tickets sold', 'oras-tickets'); ?></div>
            <div class="oras-kpi__value"><?php echo esc_html((string) $aggregates['summary']['tickets_sold']); ?></div>
            <div class="oras-kpi__sub oras-pill">
              <?php echo esc_html__('Orders', 'oras-tickets'); ?>
              <strong><?php echo esc_html((string) $aggregates['summary']['orders_count']); ?></strong>
            </div>
          </div>
        </div>

        <?php if (! empty($aggregates['summary']['unattributed_refunds_amount'])) : ?>
          <p class="description oras-note">
            <?php
            echo esc_html(
              sprintf(
                /* translators: %s: refund amount */
                __('Unattributed refunds: %s (amount-only refunds not tied to a ticket line item)', 'oras-tickets'),
                $this->format_money((float) $aggregates['summary']['unattributed_refunds_amount'])
              )
            );
            ?>
          </p>
        <?php endif; ?>

        <?php if (! empty($aggregates['summary']['adjustments_detected'])) : ?>
          <p class="description oras-note">
            <?php echo esc_html__('Adjustments detected were excluded from sales totals.', 'oras-tickets'); ?>
          </p>
        <?php endif; ?>
      </div>

      <div class="oras-card">
        <h2><?php echo esc_html__('Net sales by ticket', 'oras-tickets'); ?></h2>
        <?php if (empty($aggregates['by_ticket'])) : ?>
          <p><?php echo esc_html__('No data for selected filters.', 'oras-tickets'); ?></p>
        <?php else : ?>
          <?php
          $max_net = 0.0;
          foreach ($aggregates['by_ticket'] as $row) {
            $max_net = max($max_net, abs((float) $row['net']));
          }
          ?>
          <div class="oras-bars">
            <?php foreach ($aggregates['by_ticket'] as $row) :
              $net = (float) $row['net'];
              $width = $max_net > 0 ? min(100, (abs($net) / $max_net) * 100) : 0;
              $bar_class = $net < 0 ? 'oras-bar-fill oras-bar-fill--neg' : 'oras-bar-fill';
            ?>
              <div class="oras-bar-row">
                <div><?php echo esc_html($row['ticket_name']); ?></div>
                <div class="oras-bar-track">
                  <div class="<?php echo esc_attr($bar_class); ?>" style="width:<?php echo esc_attr((string) $width); ?>%;"></div>
                </div>
                <div class="is-right"><?php echo esc_html($this->format_money($net)); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="oras-card">
        <h2><?php echo esc_html__('By ticket type', 'oras-tickets'); ?></h2>
        <table class="widefat striped oras-table">
          <thead>
            <tr>
              <th><?php echo esc_html__('Ticket', 'oras-tickets'); ?></th>
              <th><?php echo esc_html__('Sold qty', 'oras-tickets'); ?></th>
              <th class="is-right"><?php echo esc_html__('Gross', 'oras-tickets'); ?></th>
              <th><?php echo esc_html__('Refunded qty', 'oras-tickets'); ?></th>
              <th class="is-right"><?php echo esc_html__('Refunded amount', 'oras-tickets'); ?></th>
              <th class="is-right"><?php echo esc_html__('Net', 'oras-tickets'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($aggregates['by_ticket'])) : ?>
              <tr>
                <td colspan="6"><?php echo esc_html__('No data for selected filters.', 'oras-tickets'); ?></td>
              </tr>
            <?php else : ?>
              <?php foreach ($aggregates['by_ticket'] as $row) : ?>
                <tr>
                  <td><?php echo esc_html($row['ticket_name']); ?></td>
                  <td><?php echo esc_html((string) $row['sold_qty']); ?></td>
                  <td class="is-right"><?php echo esc_html($this->format_money($row['gross'])); ?></td>
                  <td><?php echo esc_html((string) $row['refunded_qty']); ?></td>
                  <td class="is-right"><?php echo esc_html($this->format_money($row['refunded_amount'])); ?></td>
                  <td class="is-right"><?php echo esc_html($this->format_money($row['net'])); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <?php if (! empty($aggregates['by_ticket'])) : ?>
            <tfoot>
              <tr>
                <th><?php echo esc_html__('Totals', 'oras-tickets'); ?></th>
                <th><?php echo esc_html((string) $aggregates['summary']['tickets_sold']); ?></th>
                <th class="is-right"><?php echo esc_html($this->format_money($aggregates['summary']['gross_sales'])); ?></th>
                <th><?php echo esc_html((string) $aggregates['summary']['refunded_qty']); ?></th>
                <th class="is-right"><?php echo esc_html($this->format_money($aggregates['summary']['refunded_amount'])); ?></th>
                <th class="is-right"><?php echo esc_html($this->format_money($aggregates['summary']['net_sales'])); ?></th>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
        <p class="description oras-note"><?php echo esc_html__('Unattributed refunds are amount-only refunds not tied to a ticket line item.', 'oras-tickets'); ?></p>
      </div>
    </div>
<?php
  }

  public function export_csv(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      return;
    }

    if (! isset($_POST['oras_tickets_reports_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['oras_tickets_reports_nonce']), self::NONCE_ACTION)) {
      return;
    }

    $event_id = isset($_POST['oras_tickets_event_id']) ? absint($_POST['oras_tickets_event_id']) : 0;
    if ($event_id <= 0) {
      return;
    }

    $statuses = isset($_POST['oras_tickets_statuses']) && is_array($_POST['oras_tickets_statuses'])
      ? array_map('sanitize_text_field', wp_unslash($_POST['oras_tickets_statuses']))
      : [];

    $filename = 'oras-tickets-event-' . $event_id . '-' . gmdate('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    if (! $output) {
      return;
    }

    fputcsv(
      $output,
      [
        'order_id',
        'order_date',
        'order_status',
        'ticket_name',
        'ticket_index',
        'qty',
        'unit_price',
        'line_total',
        'currency',
      ]
    );

    $aggregator = new Reports_Aggregator();
    $aggregator->iterate_order_items(
      $event_id,
      $statuses,
      [],
      function (array $row) use ($output): void {
        fputcsv(
          $output,
          [
            $row['order_id'],
            $row['order_date'],
            $row['order_status'],
            $row['ticket_name'],
            $row['ticket_index'],
            $row['qty'],
            $row['unit_price'],
            $row['line_total'],
            $row['currency'],
          ]
        );
      }
    );

    fclose($output);
    exit;
  }

  /**
   * @return int[]
   */
  private function get_events_with_tickets(): array
  {
    $query = get_posts(
      [
        'post_type' => Meta::EVENT_POST_TYPE,
        'post_status' => ['publish', 'draft', 'future', 'private'],
        'fields' => 'ids',
        'posts_per_page' => 100,
        'no_found_rows' => true,
        'meta_key' => Meta::META_KEY_TICKETS,
        'meta_compare' => 'EXISTS',
        'orderby' => 'date',
        'order' => 'DESC',
      ]
    );

    return is_array($query) ? $query : [];
  }

  /**
   * @return string[]
   */
  private function get_selected_statuses(): array
  {
    if (! isset($_GET['oras_tickets_statuses']) || ! is_array($_GET['oras_tickets_statuses'])) {
      return array_keys($this->get_status_options());
    }

    $raw = wp_unslash($_GET['oras_tickets_statuses']);
    $clean = [];
    foreach ($raw as $status) {
      $clean[] = sanitize_text_field($status);
    }

    $allowed = array_keys($this->get_status_options());
    $filtered = array_values(array_intersect($allowed, $clean));

    return ! empty($filtered) ? $filtered : $allowed;
  }

  /**
   * @return array<string,string>
   */
  private function get_status_options(): array
  {
    return [
      'processing' => __('Processing', 'oras-tickets'),
      'completed' => __('Completed', 'oras-tickets'),
      'refunded' => __('Refunded', 'oras-tickets'),
      'cancelled' => __('Cancelled', 'oras-tickets'),
    ];
  }

  private function format_money(float $amount): string
  {
    if (function_exists('wc_price')) {
      return wp_strip_all_tags(wc_price($amount));
    }

    return number_format_i18n($amount, 2);
  }
}
