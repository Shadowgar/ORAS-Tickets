<?php

namespace ORAS\Tickets\Admin\Pages;

require_once ORAS_TICKETS_DIR . 'includes/Admin/Reports_Aggregator.php';
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket_Collection.php';

use ORAS\Tickets\Admin\Reports_Aggregator;
use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

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
    $range_data = $this->get_date_range_from_request($_GET);
    $date_range = $range_data['date_range'];

    $aggregator = new Reports_Aggregator();
    $aggregates = $selected_event_id > 0 ? $aggregator->get_aggregates($selected_event_id, $selected_statuses, $date_range) : [
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
      'phase_breakdown' => [],
    ];

    $by_ticket_rows = isset($aggregates['by_ticket']) && is_array($aggregates['by_ticket']) ? $aggregates['by_ticket'] : [];
    $phase_breakdown = isset($aggregates['phase_breakdown']) && is_array($aggregates['phase_breakdown']) ? $aggregates['phase_breakdown'] : [];
    $presale_total = 0;
    $after_total = 0;
    $ticket_name_by_index = [];

    foreach ($by_ticket_rows as $row) {
      if (! is_array($row)) {
        continue;
      }
      $ticket_index = isset($row['ticket_index']) ? (string) $row['ticket_index'] : '';
      $ticket_name = isset($row['ticket_name']) ? (string) $row['ticket_name'] : '';
      if ($ticket_index !== '' && $ticket_name !== '') {
        $ticket_name_by_index[$ticket_index] = $ticket_name;
      }
      $presale_total += (int) ($row['presale_qty'] ?? 0);
      $after_total += (int) ($row['after_qty'] ?? 0);
    }

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
                <tr>
                  <th scope="row"><label for="oras_tickets_range"><?php echo esc_html__('Date range', 'oras-tickets'); ?></label></th>
                  <td>
                    <select name="oras_tickets_range" id="oras_tickets_range">
                      <option value="last_30" <?php selected($range_data['range'], 'last_30'); ?>><?php echo esc_html__('Last 30 days', 'oras-tickets'); ?></option>
                      <option value="last_90" <?php selected($range_data['range'], 'last_90'); ?>><?php echo esc_html__('Last 90 days', 'oras-tickets'); ?></option>
                      <option value="last_365" <?php selected($range_data['range'], 'last_365'); ?>><?php echo esc_html__('Last 365 days', 'oras-tickets'); ?></option>
                      <option value="this_year" <?php selected($range_data['range'], 'this_year'); ?>><?php echo esc_html__('This year', 'oras-tickets'); ?></option>
                      <option value="custom" <?php selected($range_data['range'], 'custom'); ?>><?php echo esc_html__('Custom', 'oras-tickets'); ?></option>
                    </select>
                    <div class="oras-note">
                      <label for="oras_tickets_after"><?php echo esc_html__('After', 'oras-tickets'); ?></label>
                      <input type="date" name="oras_tickets_after" id="oras_tickets_after" value="<?php echo esc_attr($range_data['after']); ?>" placeholder="YYYY-MM-DD" />
                      <label for="oras_tickets_before"><?php echo esc_html__('Before', 'oras-tickets'); ?></label>
                      <input type="date" name="oras_tickets_before" id="oras_tickets_before" value="<?php echo esc_attr($range_data['before']); ?>" placeholder="YYYY-MM-DD" />
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
              <input type="hidden" name="oras_tickets_range" value="<?php echo esc_attr($range_data['range']); ?>" />
              <input type="hidden" name="oras_tickets_after" value="<?php echo esc_attr($range_data['after']); ?>" />
              <input type="hidden" name="oras_tickets_before" value="<?php echo esc_attr($range_data['before']); ?>" />
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
          <div class="oras-kpi">
            <div class="oras-kpi__label"><?php echo esc_html__('Presale tickets sold', 'oras-tickets'); ?></div>
            <div class="oras-kpi__value"><?php echo esc_html((string) $presale_total); ?></div>
          </div>
          <div class="oras-kpi">
            <div class="oras-kpi__label"><?php echo esc_html__('After presale tickets sold', 'oras-tickets'); ?></div>
            <div class="oras-kpi__value"><?php echo esc_html((string) $after_total); ?></div>
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

        <p class="description oras-note">
          <?php echo esc_html__('Presale = earliest configured price phase for each ticket.', 'oras-tickets'); ?>
        </p>

        <p class="description oras-note">
          <?php
          echo esc_html(
            sprintf(
              /* translators: %s: range label */
              __('Showing: %s', 'oras-tickets'),
              $range_data['label']
            )
          );
          ?>
        </p>
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
              <th><?php echo esc_html__('Presale', 'oras-tickets'); ?></th>
              <th><?php echo esc_html__('After', 'oras-tickets'); ?></th>
              <th class="is-right"><?php echo esc_html__('Gross', 'oras-tickets'); ?></th>
              <th><?php echo esc_html__('Refunded qty', 'oras-tickets'); ?></th>
              <th class="is-right"><?php echo esc_html__('Refunded amount', 'oras-tickets'); ?></th>
              <th class="is-right"><?php echo esc_html__('Net', 'oras-tickets'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($aggregates['by_ticket'])) : ?>
              <tr>
                <td colspan="8"><?php echo esc_html__('No data for selected filters.', 'oras-tickets'); ?></td>
              </tr>
            <?php else : ?>
              <?php foreach ($aggregates['by_ticket'] as $row) : ?>
                <tr>
                  <td><?php echo esc_html($row['ticket_name']); ?></td>
                  <td><?php echo esc_html((string) $row['sold_qty']); ?></td>
                  <td><?php echo esc_html((string) ($row['presale_qty'] ?? 0)); ?></td>
                  <td><?php echo esc_html((string) ($row['after_qty'] ?? 0)); ?></td>
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
                <th><?php echo esc_html((string) $presale_total); ?></th>
                <th><?php echo esc_html((string) $after_total); ?></th>
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

      <div class="oras-card">
        <h2><?php echo esc_html__('Pricing phase breakdown', 'oras-tickets'); ?></h2>
        <?php if (empty($phase_breakdown)) : ?>
          <p><?php echo esc_html__('No phase data for selected filters.', 'oras-tickets'); ?></p>
        <?php else : ?>
          <?php
          $ticket_indexes = array_keys($phase_breakdown);
          usort(
            $ticket_indexes,
            static function (string $a, string $b): int {
              if ($a === '') {
                return 1;
              }
              if ($b === '') {
                return -1;
              }
              $a_is_num = ctype_digit($a);
              $b_is_num = ctype_digit($b);
              if ($a_is_num && $b_is_num) {
                return (int) $a <=> (int) $b;
              }
              return strcmp($a, $b);
            }
          );
          ?>
          <?php foreach ($ticket_indexes as $ticket_index) :
            $phases = isset($phase_breakdown[$ticket_index]) && is_array($phase_breakdown[$ticket_index]) ? $phase_breakdown[$ticket_index] : [];
            $ticket_label = $ticket_name_by_index[$ticket_index] ?? '';
            if ($ticket_label === '' && $ticket_index !== '') {
              $ticket_label = sprintf('Ticket #%s', $ticket_index);
            }
            if ($ticket_label === '') {
              $ticket_label = __('Unknown ticket', 'oras-tickets');
            }
          ?>
            <h3><?php echo esc_html($ticket_label); ?></h3>
            <table class="widefat striped oras-table">
              <thead>
                <tr>
                  <th><?php echo esc_html__('Phase', 'oras-tickets'); ?></th>
                  <th><?php echo esc_html__('Qty', 'oras-tickets'); ?></th>
                  <th class="is-right"><?php echo esc_html__('Gross', 'oras-tickets'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($phases)) : ?>
                  <tr>
                    <td colspan="3"><?php echo esc_html__('No phase data for this ticket.', 'oras-tickets'); ?></td>
                  </tr>
                <?php else : ?>
                  <?php foreach ($phases as $phase_key => $phase_row) :
                    $phase_label = isset($phase_row['label']) ? (string) $phase_row['label'] : '';
                    if ($phase_label === '') {
                      $phase_label = $phase_key === '__none__' ? __('No phase snapshot', 'oras-tickets') : $phase_key;
                    }
                    $phase_qty = isset($phase_row['qty']) ? (int) $phase_row['qty'] : 0;
                    $phase_gross = isset($phase_row['gross']) ? (float) $phase_row['gross'] : 0.0;
                  ?>
                    <tr>
                      <td><?php echo esc_html($phase_label); ?></td>
                      <td><?php echo esc_html((string) $phase_qty); ?></td>
                      <td class="is-right"><?php echo esc_html($this->format_money($phase_gross)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          <?php endforeach; ?>
        <?php endif; ?>
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
    $range_data = $this->get_date_range_from_request($_POST);
    $date_range = $range_data['date_range'];

    $presale_key_by_ticket_index = $this->get_presale_key_map($event_id);

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
        'phase_key',
        'phase_label',
        'presale_bucket',
      ]
    );

    $aggregator = new Reports_Aggregator();
    $aggregator->iterate_order_items(
      $event_id,
      $statuses,
      $date_range,
      function (array $row) use ($output, $presale_key_by_ticket_index): void {
        $ticket_index = isset($row['ticket_index']) ? (string) $row['ticket_index'] : '';
        $phase_key = isset($row['phase_key']) ? (string) $row['phase_key'] : '__none__';
        $phase_label = isset($row['phase_label']) ? (string) $row['phase_label'] : '';
        if ($phase_label === '') {
          $phase_label = $phase_key === '__none__' ? __('No phase snapshot', 'oras-tickets') : $phase_key;
        }

        $presale_key = $presale_key_by_ticket_index[$ticket_index] ?? null;
        if ($presale_key === null) {
          $bucket = 'No phases configured';
        } elseif ($phase_key === $presale_key) {
          $bucket = 'Presale';
        } else {
          $bucket = 'After';
        }

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
            $phase_key,
            $phase_label,
            $bucket,
          ]
        );
      }
    );

    fclose($output);
    exit;
  }

  /**
   * @param array $source
   * @return array{range:string,after:string,before:string,date_range:array,label:string}
   */
  private function get_date_range_from_request(array $source): array
  {
    $range = isset($source['oras_tickets_range']) ? sanitize_text_field(wp_unslash($source['oras_tickets_range'])) : 'last_90';
    $after = isset($source['oras_tickets_after']) ? sanitize_text_field(wp_unslash($source['oras_tickets_after'])) : '';
    $before = isset($source['oras_tickets_before']) ? sanitize_text_field(wp_unslash($source['oras_tickets_before'])) : '';

    $date_range = [];
    $now_ts = current_time('timestamp');

    switch ($range) {
      case 'last_30':
        $date_range['after'] = wp_date('Y-m-d 00:00:00', $now_ts - (30 * DAY_IN_SECONDS));
        $label = __('Last 30 days', 'oras-tickets');
        break;
      case 'last_365':
        $date_range['after'] = wp_date('Y-m-d 00:00:00', $now_ts - (365 * DAY_IN_SECONDS));
        $label = __('Last 365 days', 'oras-tickets');
        break;
      case 'this_year':
        $date_range['after'] = wp_date('Y-01-01 00:00:00', $now_ts);
        $label = __('This year', 'oras-tickets');
        break;
      case 'custom':
        if ($after !== '') {
          $date_range['after'] = $after . ' 00:00:00';
        }
        if ($before !== '') {
          $date_range['before'] = $before . ' 23:59:59';
        }
        $label = __('Custom range', 'oras-tickets');
        break;
      case 'last_90':
      default:
        $range = 'last_90';
        $date_range['after'] = wp_date('Y-m-d 00:00:00', $now_ts - (90 * DAY_IN_SECONDS));
        $label = __('Last 90 days', 'oras-tickets');
        break;
    }

    return [
      'range' => $range,
      'after' => $after,
      'before' => $before,
      'date_range' => $date_range,
      'label' => $label,
    ];
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

  /**
   * Presale is the earliest configured phase key by start datetime per ticket.
   *
   * @return array<string,string|null>
   */
  private function get_presale_key_map(int $event_id): array
  {
    $envelope = Ticket_Collection::load_envelope_for_event($event_id);
    $tickets = isset($envelope['tickets']) && is_array($envelope['tickets']) ? $envelope['tickets'] : [];
    $presale = [];

    foreach ($tickets as $index => $ticket) {
      if (! is_array($ticket)) {
        $presale[(string) $index] = null;
        continue;
      }

      $phases = isset($ticket['price_phases']) && is_array($ticket['price_phases']) ? $ticket['price_phases'] : [];
      $earliest_ts = null;
      $earliest_key = null;

      foreach ($phases as $phase) {
        if (! is_array($phase)) {
          continue;
        }

        $phase_key = isset($phase['key']) ? (string) $phase['key'] : '';
        $start_raw = isset($phase['start']) ? (string) $phase['start'] : '';
        if ($phase_key === '' || $start_raw === '') {
          continue;
        }

        $start_dt = $this->parse_site_datetime($start_raw);
        if (! $start_dt) {
          continue;
        }

        $start_ts = $start_dt->getTimestamp();
        if ($earliest_ts === null || $start_ts < $earliest_ts) {
          $earliest_ts = $start_ts;
          $earliest_key = $phase_key;
        }
      }

      $presale[(string) $index] = $earliest_key;
    }

    return $presale;
  }

  private function parse_site_datetime(string $value): ?\DateTimeInterface
  {
    $tz = wp_timezone();
    $formats = ['Y-m-d H:i', 'Y-m-d H:i:s'];

    foreach ($formats as $format) {
      $dt = \DateTime::createFromFormat($format, $value, $tz);
      if ($dt instanceof \DateTimeInterface) {
        return $dt;
      }
    }

    return null;
  }
}
