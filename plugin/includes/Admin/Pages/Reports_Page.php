<?php

namespace ORAS\Tickets\Admin\Pages;

require_once ORAS_TICKETS_DIR . 'includes/Admin/Reports_Aggregator.php';
require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket_Collection.php';

use ORAS\Tickets\Admin\Reports_Aggregator;
use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reports_Page {


	private const NONCE_ACTION = 'oras_tickets_reports';

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$events                   = $this->get_events_with_tickets();
		$default_event_id         = ! empty( $events ) ? (int) $events[0] : 0;
		$selected_event_id        = isset( $_GET['oras_tickets_event_id'] ) ? absint( $_GET['oras_tickets_event_id'] ) : $default_event_id;
		$range_data               = $this->get_date_range_from_request( $_GET );
		$date_range               = $range_data['date_range'];
		$date_range['preset']     = $range_data['range'];
		$date_range['after_raw']  = $range_data['after'];
		$date_range['before_raw'] = $range_data['before'];
		$view                     = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'overview';
		$view                     = in_array( $view, array( 'overview', 'detail' ), true ) ? $view : 'overview';
		$overview_scope           = isset( $_GET['oras_tickets_scope'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_tickets_scope'] ) ) : '';
		$selected_statuses        = $view === 'overview'
		? $this->get_overview_statuses( $overview_scope )
		: $this->get_selected_statuses();
		$overview_scope           = $overview_scope !== '' ? $overview_scope : $this->get_overview_scope_from_statuses( $selected_statuses );
		$overview_scope_label     = $overview_scope === 'refunds'
		? __( 'paid ticket sales including refunds', 'oras-tickets' )
		: ( $overview_scope === 'all'
		? __( 'paid ticket sales including refunds and cancelled orders', 'oras-tickets' )
		: __( 'paid ticket sales', 'oras-tickets' ) );
		$overview_period_label    = $range_data['label'];
		$event_summary_rows       = $this->get_event_summary_rows( $selected_statuses, $date_range, $range_data );
		$tab_args                 = array(
			'page'                  => 'oras-tickets-reports',
			'view'                  => $view,
			'oras_tickets_event_id' => $selected_event_id,
			'oras_tickets_range'    => $range_data['range'],
			'oras_tickets_after'    => $range_data['after'],
			'oras_tickets_before'   => $range_data['before'],
			'oras_tickets_statuses' => $selected_statuses,
			'oras_tickets_scope'    => $overview_scope,
		);
		$overview_url             = add_query_arg( array_merge( $tab_args, array( 'view' => 'overview' ) ), admin_url( 'admin.php' ) );
		$detail_url               = add_query_arg( array_merge( $tab_args, array( 'view' => 'detail' ) ), admin_url( 'admin.php' ) );

		$aggregator = new Reports_Aggregator();
		$aggregates = $selected_event_id > 0 ? $aggregator->get_aggregates( $selected_event_id, $selected_statuses, $date_range ) : array(
			'summary'         => array(
				'gross_sales'                 => 0.0,
				'refunded_mapped_total'       => 0.0,
				'refunded_amount'             => 0.0,
				'net_sales'                   => 0.0,
				'orders_count'                => 0,
				'tickets_sold'                => 0,
				'refunded_qty'                => 0,
				'unattributed_refunds_amount' => 0.0,
				'unattributed_refunds_count'  => 0,
				'adjustments_detected'        => false,
			),
			'by_ticket'       => array(),
			'phase_breakdown' => array(),
		);

		$by_ticket_rows              = isset( $aggregates['by_ticket'] ) && is_array( $aggregates['by_ticket'] ) ? $aggregates['by_ticket'] : array();
		$phase_breakdown             = isset( $aggregates['phase_breakdown'] ) && is_array( $aggregates['phase_breakdown'] ) ? $aggregates['phase_breakdown'] : array();
		$presale_key_by_ticket_index = $selected_event_id > 0 ? $this->get_presale_key_map( $selected_event_id ) : array();
		$presale_total               = 0;
		$after_total                 = 0;
		$ticket_name_by_index        = array();

		foreach ( $by_ticket_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$ticket_index = isset( $row['ticket_index'] ) ? (string) $row['ticket_index'] : '';
			$ticket_name  = isset( $row['ticket_name'] ) ? (string) $row['ticket_name'] : '';
			if ( $ticket_index !== '' && $ticket_name !== '' ) {
				$ticket_name_by_index[ $ticket_index ] = $ticket_name;
			}
			$presale_total += (int) ( $row['presale_qty'] ?? 0 );
			$after_total   += (int) ( $row['after_qty'] ?? 0 );
		}

		?>
	<div class="wrap oras-tickets-reports">
		<h1><?php echo esc_html__( 'ORAS Tickets — Reports', 'oras-tickets' ); ?></h1>
		<p class="description"><?php echo esc_html__( 'Sales & refunds summary for ORAS ticketed events', 'oras-tickets' ); ?></p>
		<hr class="wp-header-end" />

		<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $overview_url ); ?>" class="nav-tab <?php echo $view === 'overview' ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Overview', 'oras-tickets' ); ?>
		</a>
		<a href="<?php echo esc_url( $detail_url ); ?>" class="nav-tab <?php echo $view === 'detail' ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Event Detail', 'oras-tickets' ); ?>
		</a>
		</h2>

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

		<?php if ( $view === 'overview' ) : ?>
		<div class="oras-card">
			<p class="description">
			<?php
			echo esc_html(
				sprintf(
				/* translators: 1: scope label, 2: period label */
					__( 'Showing %1$s from the %2$s.', 'oras-tickets' ),
					$overview_scope_label,
					$overview_period_label
				)
			);
			?>
			</p>
			<div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, 'oras_tickets_reports_nonce' ); ?>
				<input type="hidden" name="action" value="oras_tickets_export_csv" />
				<input type="hidden" name="csv_scope" value="overview" />
				<input type="hidden" name="oras_tickets_range" value="<?php echo esc_attr( $range_data['range'] ); ?>" />
				<input type="hidden" name="oras_tickets_after" value="<?php echo esc_attr( $range_data['after'] ); ?>" />
				<input type="hidden" name="oras_tickets_before" value="<?php echo esc_attr( $range_data['before'] ); ?>" />
				<?php foreach ( $selected_statuses as $status ) : ?>
				<input type="hidden" name="oras_tickets_statuses[]" value="<?php echo esc_attr( $status ); ?>" />
				<?php endforeach; ?>
				<?php submit_button( __( 'Download overview CSV', 'oras-tickets' ), 'secondary', 'submit', false ); ?>
			</form>
			</div>
		</div>
		<?php else : ?>
		<div class="oras-card">
			<div class="oras-grid oras-grid--filters">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="oras-tickets-reports" />
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />

				<table class="form-table" role="presentation">
				<tbody>
					<?php if ( $view === 'detail' ) : ?>
					<tr>
						<th scope="row"><label for="oras_tickets_event_id"><?php echo esc_html__( 'Event', 'oras-tickets' ); ?></label></th>
						<td>
						<?php if ( empty( $events ) ) : ?>
							<p class="description"><?php echo esc_html__( 'No events found.', 'oras-tickets' ); ?></p>
						<?php else : ?>
							<select name="oras_tickets_event_id" id="oras_tickets_event_id" onchange="this.form.submit()">
							<?php
							foreach ( $events as $event_id ) :
								$event_id = (int) $event_id;
								$title    = get_the_title( $event_id );
								?>
								<option value="<?php echo esc_attr( (string) $event_id ); ?>" <?php selected( $selected_event_id, $event_id ); ?>>
								<?php echo esc_html( $title ); ?>
								</option>
							<?php endforeach; ?>
							</select>
						<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
					<th scope="row"><?php echo esc_html__( 'Statuses', 'oras-tickets' ); ?></th>
					<td>
						<div class="oras-status-grid">
						<?php foreach ( $this->get_status_options() as $status_key => $label ) : ?>
							<label>
							<input type="checkbox" name="oras_tickets_statuses[]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $selected_statuses, true ) ); ?> />
							<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
						</div>
					</td>
					</tr>
					<tr>
					<th scope="row"><label for="oras_tickets_range"><?php echo esc_html__( 'Date range', 'oras-tickets' ); ?></label></th>
					<td>
						<select name="oras_tickets_range" id="oras_tickets_range">
						<option value="last_30" <?php selected( $range_data['range'], 'last_30' ); ?>><?php echo esc_html__( 'Last 30 days', 'oras-tickets' ); ?></option>
						<option value="last_90" <?php selected( $range_data['range'], 'last_90' ); ?>><?php echo esc_html__( 'Last 90 days', 'oras-tickets' ); ?></option>
						<option value="last_365" <?php selected( $range_data['range'], 'last_365' ); ?>><?php echo esc_html__( 'Last 365 days', 'oras-tickets' ); ?></option>
						<option value="this_year" <?php selected( $range_data['range'], 'this_year' ); ?>><?php echo esc_html__( 'This year', 'oras-tickets' ); ?></option>
						<option value="custom" <?php selected( $range_data['range'], 'custom' ); ?>><?php echo esc_html__( 'Custom', 'oras-tickets' ); ?></option>
						</select>
						<div class="oras-note">
						<label for="oras_tickets_after"><?php echo esc_html__( 'After', 'oras-tickets' ); ?></label>
						<input type="date" name="oras_tickets_after" id="oras_tickets_after" value="<?php echo esc_attr( $range_data['after'] ); ?>" placeholder="YYYY-MM-DD" />
						<label for="oras_tickets_before"><?php echo esc_html__( 'Before', 'oras-tickets' ); ?></label>
						<input type="date" name="oras_tickets_before" id="oras_tickets_before" value="<?php echo esc_attr( $range_data['before'] ); ?>" placeholder="YYYY-MM-DD" />
						</div>
					</td>
					</tr>
				</tbody>
				</table>

				<div class="oras-filter-actions">
				<?php submit_button( __( 'Apply Filters', 'oras-tickets' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<div class="oras-filter-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, 'oras_tickets_reports_nonce' ); ?>
				<input type="hidden" name="action" value="oras_tickets_export_csv" />
				<input type="hidden" name="oras_tickets_event_id" value="<?php echo esc_attr( (string) $selected_event_id ); ?>" />
				<input type="hidden" name="oras_tickets_range" value="<?php echo esc_attr( $range_data['range'] ); ?>" />
				<input type="hidden" name="oras_tickets_after" value="<?php echo esc_attr( $range_data['after'] ); ?>" />
				<input type="hidden" name="oras_tickets_before" value="<?php echo esc_attr( $range_data['before'] ); ?>" />
				<?php foreach ( $selected_statuses as $status ) : ?>
					<input type="hidden" name="oras_tickets_statuses[]" value="<?php echo esc_attr( $status ); ?>" />
				<?php endforeach; ?>
				<?php submit_button( __( 'Download CSV', 'oras-tickets' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( $view === 'overview' ) : ?>
		<div class="oras-card">
			<h2><?php echo esc_html__( 'Summary', 'oras-tickets' ); ?></h2>
			<?php if ( empty( $event_summary_rows ) ) : ?>
			<p><?php echo esc_html__( 'No ticket sales found for this period.', 'oras-tickets' ); ?></p>
			<?php else : ?>
				<?php
				$summary_orders    = 0;
				$summary_tickets   = 0;
				$summary_gross     = 0.0;
				$summary_refunded  = 0.0;
				$summary_net       = 0.0;
				$summary_last_sale = '';

				foreach ( $event_summary_rows as $row ) {
					$summary_orders   += (int) ( $row['orders'] ?? 0 );
					$summary_tickets  += (int) ( $row['tickets_sold'] ?? 0 );
					$summary_gross    += (float) ( $row['gross_sales'] ?? 0.0 );
					$summary_refunded += (float) ( $row['refunded_amount'] ?? 0.0 );
					$summary_net      += (float) ( $row['net_sales'] ?? 0.0 );

					$last_sale_raw = isset( $row['last_sale'] ) ? (string) $row['last_sale'] : '';
					if ( $last_sale_raw !== '' && $last_sale_raw !== '—' ) {
						if ( $summary_last_sale === '' || $last_sale_raw > $summary_last_sale ) {
							$summary_last_sale = $last_sale_raw;
						}
					}
				}

				if ( $summary_last_sale === '' ) {
					$summary_last_sale = '—';
				}
				?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
				<div>
				<div class="oras-kpi__label"><?php echo esc_html__( 'Orders', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( (string) $summary_orders ); ?></div>
				</div>
				<div>
				<div class="oras-kpi__label"><?php echo esc_html__( 'Tickets', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( (string) $summary_tickets ); ?></div>
				</div>
				<div>
				<div class="oras-kpi__label"><?php echo esc_html__( 'Gross', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( $this->format_money( $summary_gross ) ); ?></div>
				</div>
				<div>
				<div class="oras-kpi__label"><?php echo esc_html__( 'Refunded', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( $this->format_money( $summary_refunded ) ); ?></div>
				</div>
				<div>
				<div class="oras-kpi__label"><?php echo esc_html__( 'Net', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( $this->format_money( $summary_net ) ); ?></div>
				</div>
				<div>
				<div class="oras-kpi__label"><?php echo esc_html__( 'Last sale', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( $summary_last_sale ); ?></div>
				</div>
			</div>
			<?php endif; ?>
		</div>

			<?php if ( ! empty( $event_summary_rows ) ) : ?>
			<div class="oras-card">
			<h2><?php echo esc_html__( 'Event breakdown', 'oras-tickets' ); ?></h2>
			<table class="widefat striped oras-table">
				<thead>
				<tr>
					<th><?php echo esc_html__( 'Event', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'Event date', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'Orders', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'Tickets sold', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'First phase', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'After first phase', 'oras-tickets' ); ?></th>
					<th class="is-right"><?php echo esc_html__( 'Gross', 'oras-tickets' ); ?></th>
					<th class="is-right"><?php echo esc_html__( 'Refunded', 'oras-tickets' ); ?></th>
					<th class="is-right"><?php echo esc_html__( 'Net', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'Last sale', 'oras-tickets' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $event_summary_rows as $row ) : ?>
					<tr>
					<td>
						<a href="<?php echo esc_url( $this->build_event_report_url( (int) $row['event_id'], $selected_statuses, $range_data, 'detail' ) ); ?>">
						<?php echo esc_html( $row['title'] ); ?>
						</a>
					</td>
					<td><?php echo esc_html( $row['event_date'] ); ?></td>
					<td><?php echo esc_html( (string) $row['orders'] ); ?></td>
					<td><?php echo esc_html( (string) $row['tickets_sold'] ); ?></td>
					<td><?php echo esc_html( (string) $row['presale_tickets_sold'] ); ?></td>
					<td><?php echo esc_html( (string) $row['after_presale_tickets_sold'] ); ?></td>
					<td class="is-right"><?php echo esc_html( $this->format_money( $row['gross_sales'] ) ); ?></td>
					<td class="is-right"><?php echo esc_html( $this->format_money( $row['refunded_amount'] ) ); ?></td>
					<td class="is-right"><?php echo esc_html( $this->format_money( $row['net_sales'] ) ); ?></td>
					<td><?php echo esc_html( $row['last_sale'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
		<?php endif; ?>

		<?php if ( $view === 'detail' ) : ?>
			<?php if ( $selected_event_id <= 0 ) : ?>
			<div class="notice notice-info">
			<p><?php echo esc_html__( 'Select an event to view details.', 'oras-tickets' ); ?></p>
			</div>
		<?php else : ?>
			<div class="oras-card">
			<div class="oras-grid oras-grid--kpi">
				<div class="oras-kpi">
				<div class="oras-kpi__label"><?php echo esc_html__( 'Gross sales', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( $this->format_money( $aggregates['summary']['gross_sales'] ) ); ?></div>
				</div>
				<div class="oras-kpi">
				<div class="oras-kpi__label"><?php echo esc_html__( 'Refunded', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( $this->format_money( $aggregates['summary']['refunded_amount'] ) ); ?></div>
				<div class="oras-kpi__sub"><?php echo esc_html__( 'Includes unattributed refunds', 'oras-tickets' ); ?></div>
				</div>
				<div class="oras-kpi">
				<div class="oras-kpi__label"><?php echo esc_html__( 'Net sales', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( $this->format_money( $aggregates['summary']['net_sales'] ) ); ?></div>
				</div>
				<div class="oras-kpi">
				<div class="oras-kpi__label"><?php echo esc_html__( 'Tickets sold', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( (string) $aggregates['summary']['tickets_sold'] ); ?></div>
				<div class="oras-kpi__sub oras-pill">
					<?php echo esc_html__( 'Orders', 'oras-tickets' ); ?>
					<strong><?php echo esc_html( (string) $aggregates['summary']['orders_count'] ); ?></strong>
				</div>
				</div>
				<div class="oras-kpi">
				<div class="oras-kpi__label"><?php echo esc_html__( 'Tickets sold during first pricing phase', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( (string) $presale_total ); ?></div>
				</div>
				<div class="oras-kpi">
				<div class="oras-kpi__label"><?php echo esc_html__( 'Tickets sold after first pricing phase', 'oras-tickets' ); ?></div>
				<div class="oras-kpi__value"><?php echo esc_html( (string) $after_total ); ?></div>
				</div>
			</div>

			<?php if ( ! empty( $aggregates['summary']['unattributed_refunds_amount'] ) ) : ?>
				<p class="description oras-note">
				<?php
				echo esc_html(
					sprintf(
					/* translators: %s: refund amount */
						__( 'Unattributed refunds: %s (amount-only refunds not tied to a ticket line item)', 'oras-tickets' ),
						$this->format_money( (float) $aggregates['summary']['unattributed_refunds_amount'] )
					)
				);
				?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $aggregates['summary']['adjustments_detected'] ) ) : ?>
				<p class="description oras-note">
				<?php echo esc_html__( 'Adjustments detected were excluded from sales totals.', 'oras-tickets' ); ?>
				</p>
			<?php endif; ?>

			<p class="description oras-note">
				<?php echo esc_html__( 'First pricing phase = earliest configured pricing phase per ticket.', 'oras-tickets' ); ?>
			</p>

			<p class="description oras-note">
				<?php
				echo esc_html(
					sprintf(
					/* translators: %s: range label */
						__( 'Showing: %s', 'oras-tickets' ),
						$range_data['label']
					)
				);
				?>
			</p>
			</div>

			<div class="oras-card">
			<h2><?php echo esc_html__( 'Net sales by ticket', 'oras-tickets' ); ?></h2>
			<?php if ( empty( $aggregates['by_ticket'] ) ) : ?>
				<p><?php echo esc_html__( 'No data for selected filters.', 'oras-tickets' ); ?></p>
			<?php else : ?>
				<?php
				$max_net = 0.0;
				foreach ( $aggregates['by_ticket'] as $row ) {
					$max_net = max( $max_net, abs( (float) $row['net'] ) );
				}
				?>
				<div class="oras-bars">
				<?php
				foreach ( $aggregates['by_ticket'] as $row ) :
					$net       = (float) $row['net'];
					$width     = $max_net > 0 ? min( 100, ( abs( $net ) / $max_net ) * 100 ) : 0;
					$bar_class = $net < 0 ? 'oras-bar-fill oras-bar-fill--neg' : 'oras-bar-fill';
					?>
					<div class="oras-bar-row">
					<div><?php echo esc_html( $row['ticket_name'] ); ?></div>
					<div class="oras-bar-track">
						<div class="<?php echo esc_attr( $bar_class ); ?>" style="width:<?php echo esc_attr( (string) $width ); ?>%;"></div>
					</div>
					<div class="is-right"><?php echo esc_html( $this->format_money( $net ) ); ?></div>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>
			</div>

			<div class="oras-card">
			<h2><?php echo esc_html__( 'By ticket type', 'oras-tickets' ); ?></h2>
			<table class="widefat striped oras-table">
				<thead>
				<tr>
					<th><?php echo esc_html__( 'Ticket', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'Sold qty', 'oras-tickets' ); ?></th>
					<th class="is-right"><?php echo esc_html__( 'Gross', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html__( 'Refunded qty', 'oras-tickets' ); ?></th>
					<th class="is-right"><?php echo esc_html__( 'Refunded amount', 'oras-tickets' ); ?></th>
					<th class="is-right"><?php echo esc_html__( 'Net', 'oras-tickets' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $aggregates['by_ticket'] ) ) : ?>
					<tr>
					<td colspan="6"><?php echo esc_html__( 'No data for selected filters.', 'oras-tickets' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $aggregates['by_ticket'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['ticket_name'] ); ?></td>
						<td><?php echo esc_html( (string) $row['sold_qty'] ); ?></td>
						<td class="is-right"><?php echo esc_html( $this->format_money( $row['gross'] ) ); ?></td>
						<td><?php echo esc_html( (string) $row['refunded_qty'] ); ?></td>
						<td class="is-right"><?php echo esc_html( $this->format_money( $row['refunded_amount'] ) ); ?></td>
						<td class="is-right"><?php echo esc_html( $this->format_money( $row['net'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
				<?php if ( ! empty( $aggregates['by_ticket'] ) ) : ?>
				<tfoot>
					<tr>
					<th><?php echo esc_html__( 'Totals', 'oras-tickets' ); ?></th>
					<th><?php echo esc_html( (string) $aggregates['summary']['tickets_sold'] ); ?></th>
					<th class="is-right"><?php echo esc_html( $this->format_money( $aggregates['summary']['gross_sales'] ) ); ?></th>
					<th><?php echo esc_html( (string) $aggregates['summary']['refunded_qty'] ); ?></th>
					<th class="is-right"><?php echo esc_html( $this->format_money( $aggregates['summary']['refunded_amount'] ) ); ?></th>
					<th class="is-right"><?php echo esc_html( $this->format_money( $aggregates['summary']['net_sales'] ) ); ?></th>
					</tr>
				</tfoot>
				<?php endif; ?>
			</table>
			<p class="description oras-note"><?php echo esc_html__( 'Unattributed refunds are amount-only refunds not tied to a ticket line item.', 'oras-tickets' ); ?></p>
			</div>

			<div class="oras-card">
			<h2><?php echo esc_html__( 'Pricing phase breakdown', 'oras-tickets' ); ?></h2>
			<?php if ( empty( $phase_breakdown ) ) : ?>
				<p><?php echo esc_html__( 'No phase data for selected filters.', 'oras-tickets' ); ?></p>
			<?php else : ?>
				<?php
				$ticket_indexes = array_keys( $phase_breakdown );
				usort(
					$ticket_indexes,
					static function ( string $a, string $b ): int {
						if ( $a === '' ) {
							return 1;
						}
						if ( $b === '' ) {
							return -1;
						}
						$a_is_num = ctype_digit( $a );
						$b_is_num = ctype_digit( $b );
						if ( $a_is_num && $b_is_num ) {
							return (int) $a <=> (int) $b;
						}
						return strcmp( $a, $b );
					}
				);
				?>
				<?php
				foreach ( $ticket_indexes as $ticket_index ) :
					$phases       = isset( $phase_breakdown[ $ticket_index ] ) && is_array( $phase_breakdown[ $ticket_index ] ) ? $phase_breakdown[ $ticket_index ] : array();
					$ticket_label = $ticket_name_by_index[ $ticket_index ] ?? '';
					if ( $ticket_label === '' && $ticket_index !== '' ) {
						$ticket_label = sprintf( 'Ticket #%s', $ticket_index );
					}
					if ( $ticket_label === '' ) {
						$ticket_label = __( 'Unknown ticket', 'oras-tickets' );
					}
					$presale_key = $presale_key_by_ticket_index[ $ticket_index ] ?? null;
					?>
				<h3><?php echo esc_html( $ticket_label ); ?></h3>
				<table class="widefat striped oras-table">
					<thead>
					<tr>
						<th><?php echo esc_html__( 'Phase', 'oras-tickets' ); ?></th>
						<th><?php echo esc_html__( 'Qty', 'oras-tickets' ); ?></th>
						<th class="is-right"><?php echo esc_html__( 'Unit price', 'oras-tickets' ); ?></th>
						<th class="is-right"><?php echo esc_html__( 'Gross', 'oras-tickets' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php if ( empty( $phases ) ) : ?>
						<tr>
						<td colspan="4"><?php echo esc_html__( 'No phase data for this ticket.', 'oras-tickets' ); ?></td>
						</tr>
					<?php else : ?>
						<?php
						$phase_rows = array();
						$position   = 0;
						foreach ( $phases as $phase_key => $phase_row ) {
							$phase_label   = isset( $phase_row['label'] ) ? (string) $phase_row['label'] : '';
							$label_check   = strtolower( trim( $phase_label ) );
							$is_regular    = $phase_key === '__none__' || $label_check === 'no phase snapshot';
							$display_label = $is_regular ? __( 'Regular price', 'oras-tickets' ) : $phase_label;
							if ( ! $is_regular && $display_label === '' ) {
								$display_label = $phase_key;
							}
							$phase_qty    = isset( $phase_row['qty'] ) ? (int) $phase_row['qty'] : 0;
							$phase_gross  = isset( $phase_row['gross'] ) ? (float) $phase_row['gross'] : 0.0;
							$phase_rows[] = array(
								'key'        => (string) $phase_key,
								'label'      => $display_label,
								'qty'        => $phase_qty,
								'gross'      => $phase_gross,
								'is_regular' => $is_regular,
								'position'   => $position,
							);
							++$position;
						}

						usort(
							$phase_rows,
							static function ( array $a, array $b ) use ( $presale_key ): int {
								if ( $a['key'] === $presale_key && $b['key'] !== $presale_key ) {
									return -1;
								}
								if ( $b['key'] === $presale_key && $a['key'] !== $presale_key ) {
									return 1;
								}
								if ( $a['is_regular'] && ! $b['is_regular'] ) {
									return 1;
								}
								if ( $b['is_regular'] && ! $a['is_regular'] ) {
									return -1;
								}
								return $a['position'] <=> $b['position'];
							}
						);

						$label_totals = array();
						foreach ( $phase_rows as $phase_row ) {
							if ( $phase_row['is_regular'] ) {
								continue;
							}
							$label                  = $phase_row['label'];
							$label_totals[ $label ] = ( $label_totals[ $label ] ?? 0 ) + 1;
						}
						$label_seen = array();
						?>
						<?php
						foreach ( $phase_rows as $phase_row ) :
							$phase_key   = $phase_row['key'];
							$phase_qty   = $phase_row['qty'];
							$phase_gross = $phase_row['gross'];
							$unit_price  = $phase_qty > 0 ? ( $phase_gross / $phase_qty ) : 0.0;

							if ( $phase_row['is_regular'] ) {
								$display_label = __( 'Regular price', 'oras-tickets' );
							} else {
								$label                = $phase_row['label'];
								$label_seen[ $label ] = ( $label_seen[ $label ] ?? 0 ) + 1;
								if ( ( $label_totals[ $label ] ?? 0 ) > 1 ) {
									$display_label = sprintf( '%s — Phase %d (%s)', $label, (int) $label_seen[ $label ], $phase_key );
								} else {
									$display_label = sprintf( '%s (%s)', $label, $phase_key );
								}
							}
							?>
						<tr>
							<td><?php echo esc_html( $display_label ); ?></td>
							<td><?php echo esc_html( (string) $phase_qty ); ?></td>
							<td class="is-right"><?php echo esc_html( $this->format_money( $unit_price ) ); ?></td>
							<td class="is-right"><?php echo esc_html( $this->format_money( $phase_gross ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				<?php endforeach; ?>
			<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php endif; ?>
	</div>
		<?php
	}

	public function export_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! isset( $_POST['oras_tickets_reports_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['oras_tickets_reports_nonce'] ), self::NONCE_ACTION ) ) {
			return;
		}

		$statuses   = isset( $_POST['oras_tickets_statuses'] ) && is_array( $_POST['oras_tickets_statuses'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['oras_tickets_statuses'] ) )
		: array();
		$range_data = $this->get_date_range_from_request( $_POST );
		$date_range = $range_data['date_range'];
		$csv_scope  = isset( $_POST['csv_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['csv_scope'] ) ) : '';

		if ( $csv_scope === 'overview' ) {
			$filename = 'oras-event-summary-' . gmdate( 'Y-m-d' ) . '.csv';

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			$output = fopen( 'php://output', 'w' );
			if ( ! $output ) {
				return;
			}

			fputcsv(
				$output,
				array(
					'event_id',
					'event_title',
					'event_date',
					'orders',
					'tickets_sold',
					'first_phase',
					'after_first_phase',
					'gross',
					'refunded',
					'net',
					'last_sale',
				)
			);

			$rows = $this->get_event_summary_rows( $statuses, $date_range, $range_data );
			foreach ( $rows as $row ) {
				fputcsv(
					$output,
					array(
						$row['event_id'],
						$row['title'],
						$row['event_date'],
						$row['orders'],
						$row['tickets_sold'],
						$row['presale_tickets_sold'],
						$row['after_presale_tickets_sold'],
						$row['gross_sales'],
						$row['refunded_amount'],
						$row['net_sales'],
						$row['last_sale'],
					)
				);
			}

			fclose( $output );
			exit;
		}

		$event_id = isset( $_POST['oras_tickets_event_id'] ) ? absint( $_POST['oras_tickets_event_id'] ) : 0;
		if ( $event_id <= 0 ) {
			return;
		}

		$presale_key_by_ticket_index = $this->get_presale_key_map( $event_id );

		$filename = 'oras-tickets-event-' . $event_id . '-' . gmdate( 'Ymd-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			return;
		}

		fputcsv(
			$output,
			array(
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
			)
		);

		$aggregator = new Reports_Aggregator();
		$aggregator->iterate_order_items(
			$event_id,
			$statuses,
			$date_range,
			function ( array $row ) use ( $output, $presale_key_by_ticket_index ): void {
				$ticket_index = isset( $row['ticket_index'] ) ? (string) $row['ticket_index'] : '';
				$phase_key    = isset( $row['phase_key'] ) ? (string) $row['phase_key'] : '__none__';
				$phase_label  = isset( $row['phase_label'] ) ? (string) $row['phase_label'] : '';
				if ( $phase_label === '' ) {
					$phase_label = $phase_key === '__none__' ? __( 'No phase snapshot', 'oras-tickets' ) : $phase_key;
				}

				$presale_key = $presale_key_by_ticket_index[ $ticket_index ] ?? null;
				if ( $presale_key === null ) {
					$bucket = 'No phases configured';
				} elseif ( $phase_key === $presale_key ) {
					$bucket = 'Presale';
				} else {
					$bucket = 'After';
				}

				fputcsv(
					$output,
					array(
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
					)
				);
			}
		);

		fclose( $output );
		exit;
	}

	/**
	 * @param array $source
	 * @return array{range:string,after:string,before:string,date_range:array,label:string}
	 */
	private function get_date_range_from_request( array $source ): array {
		$range  = isset( $source['oras_tickets_range'] ) ? sanitize_text_field( wp_unslash( $source['oras_tickets_range'] ) ) : 'last_90';
		$after  = isset( $source['oras_tickets_after'] ) ? sanitize_text_field( wp_unslash( $source['oras_tickets_after'] ) ) : '';
		$before = isset( $source['oras_tickets_before'] ) ? sanitize_text_field( wp_unslash( $source['oras_tickets_before'] ) ) : '';

		$date_range = array();
		$now_ts     = current_time( 'timestamp' );

		switch ( $range ) {
			case 'last_7_days':
				$date_range['after'] = wp_date( 'Y-m-d 00:00:00', $now_ts - ( 7 * DAY_IN_SECONDS ) );
				$label               = __( 'Last 7 days', 'oras-tickets' );
				break;
			case 'last_30':
				$date_range['after'] = wp_date( 'Y-m-d 00:00:00', $now_ts - ( 30 * DAY_IN_SECONDS ) );
				$label               = __( 'Last 30 days', 'oras-tickets' );
				break;
			case 'last_365':
				$date_range['after'] = wp_date( 'Y-m-d 00:00:00', $now_ts - ( 365 * DAY_IN_SECONDS ) );
				$label               = __( 'Last 365 days', 'oras-tickets' );
				break;
			case 'this_year':
				$date_range['after'] = wp_date( 'Y-01-01 00:00:00', $now_ts );
				$label               = __( 'This year', 'oras-tickets' );
				break;
			case 'custom':
				if ( $after !== '' ) {
					$date_range['after'] = $after . ' 00:00:00';
				}
				if ( $before !== '' ) {
					$date_range['before'] = $before . ' 23:59:59';
				}
				$label = __( 'Custom range', 'oras-tickets' );
				break;
			case 'last_90':
			default:
				$range               = 'last_90';
				$date_range['after'] = wp_date( 'Y-m-d 00:00:00', $now_ts - ( 90 * DAY_IN_SECONDS ) );
				$label               = __( 'Last 90 days', 'oras-tickets' );
				break;
		}

		return array(
			'range'      => $range,
			'after'      => $after,
			'before'     => $before,
			'date_range' => $date_range,
			'label'      => $label,
		);
	}

	/**
	 * @return int[]
	 */
	private function get_events_with_tickets(): array {
		$query = get_posts(
			array(
				'post_type'      => Meta::EVENT_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => Meta::META_KEY_TICKETS,
				'meta_compare'   => 'EXISTS',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return is_array( $query ) ? $query : array();
	}

	/**
	 * @return string[]
	 */
	private function get_selected_statuses(): array {
		if ( ! isset( $_GET['oras_tickets_statuses'] ) || ! is_array( $_GET['oras_tickets_statuses'] ) ) {
			return array_keys( $this->get_status_options() );
		}

		$raw   = wp_unslash( $_GET['oras_tickets_statuses'] );
		$clean = array();
		foreach ( $raw as $status ) {
			$clean[] = sanitize_text_field( $status );
		}

		$allowed  = array_keys( $this->get_status_options() );
		$filtered = array_values( array_intersect( $allowed, $clean ) );

		return ! empty( $filtered ) ? $filtered : $allowed;
	}

	/**
	 * @return array<string,string>
	 */
	private function get_status_options(): array {
		return array(
			'processing' => __( 'Processing', 'oras-tickets' ),
			'completed'  => __( 'Completed', 'oras-tickets' ),
			'refunded'   => __( 'Refunded', 'oras-tickets' ),
			'cancelled'  => __( 'Cancelled', 'oras-tickets' ),
		);
	}

	private function format_money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}

		return number_format_i18n( $amount, 2 );
	}

	/**
	 * @param string[]                                                                     $statuses
	 * @param array{after?:string,before?:string}                                          $date_range
	 * @param array{range:string,after:string,before:string,date_range:array,label:string} $range_data
	 * @return array<int,array<string,mixed>>
	 */
	private function get_event_summary_rows( array $statuses, array $date_range, array $range_data ): array {
		$statuses  = $this->normalize_statuses( $statuses );
		$cache_key = $this->get_event_summary_cache_key( $statuses, $date_range, $range_data );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$event_rows         = array();
		$event_phase_keys   = array();
		$event_last_sale_ts = array();
		$page               = 1;
		$per_page           = 50;
		$date_created       = $this->build_date_created_arg( $date_range );

		do {
			$args = array(
				'limit'   => $per_page,
				'page'    => $page,
				'status'  => $statuses,
				'orderby' => 'date',
				'order'   => 'DESC',
			);
			if ( $date_created !== '' ) {
				$args['date_created'] = $date_created;
			}

			$orders = wc_get_orders( $args );
			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
					continue;
				}

				$order_date      = $order->get_date_created();
				$order_ts        = $order_date ? $order_date->getTimestamp() : null;
				$order_event_ids = array();

				$items = $order->get_items( 'line_item' );
				foreach ( $items as $item ) {
					if ( ! $item ) {
						continue;
					}
					$line_event_id = (int) $item->get_meta( '_oras_ticket_event_id', true );
					if ( $line_event_id <= 0 ) {
						continue;
					}

					$qty = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
					if ( $qty <= 0 ) {
						continue;
					}

					$line_total = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
					$phase_key  = (string) $item->get_meta( '_oras_ticket_price_phase_key', true );

					if ( ! isset( $event_rows[ $line_event_id ] ) ) {
						$event_rows[ $line_event_id ] = array(
							'gross_sales'     => 0.0,
							'refunded_amount' => 0.0,
							'tickets_sold'    => 0,
							'orders'          => 0,
							'phase_qty'       => array(),
						);
					}

					$event_rows[ $line_event_id ]['gross_sales']  += $line_total;
					$event_rows[ $line_event_id ]['tickets_sold'] += $qty;

					if ( $phase_key !== '' ) {
						$event_phase_keys[ $line_event_id ][ $phase_key ]        = true;
						$event_rows[ $line_event_id ]['phase_qty'][ $phase_key ] = ( $event_rows[ $line_event_id ]['phase_qty'][ $phase_key ] ?? 0 ) + $qty;
					}

					$order_event_ids[ $line_event_id ] = true;
				}

				if ( $order_ts ) {
					foreach ( $order_event_ids as $order_event_id => $_value ) {
						if ( ! isset( $event_rows[ $order_event_id ] ) ) {
								$event_rows[ $order_event_id ] = array(
									'gross_sales'     => 0.0,
									'refunded_amount' => 0.0,
									'tickets_sold'    => 0,
									'orders'          => 0,
									'phase_qty'       => array(),
								);
						}
						++$event_rows[ $order_event_id ]['orders'];
						if ( ! isset( $event_last_sale_ts[ $order_event_id ] ) || $order_ts > $event_last_sale_ts[ $order_event_id ] ) {
							$event_last_sale_ts[ $order_event_id ] = $order_ts;
						}
					}
				}

				if ( ! method_exists( $order, 'get_refunds' ) ) {
					continue;
				}

				foreach ( $order->get_refunds() as $refund ) {
					if ( ! $refund ) {
						continue;
					}

					$refund_items = method_exists( $refund, 'get_items' ) ? $refund->get_items( 'line_item' ) : array();
					foreach ( $refund_items as $ref_item ) {
						$orig_id = (int) $ref_item->get_meta( '_refunded_item_id' );
						if ( $orig_id <= 0 ) {
							continue;
						}
						$orig_item = $order->get_item( $orig_id );
						if ( ! $orig_item ) {
							continue;
						}
						$line_event_id = (int) $orig_item->get_meta( '_oras_ticket_event_id', true );
						if ( $line_event_id <= 0 ) {
							continue;
						}
						if ( ! isset( $event_rows[ $line_event_id ] ) ) {
							$event_rows[ $line_event_id ] = array(
								'gross_sales'     => 0.0,
								'refunded_amount' => 0.0,
								'tickets_sold'    => 0,
								'orders'          => 0,
								'phase_qty'       => array(),
							);
						}
						$event_rows[ $line_event_id ]['refunded_amount'] += abs( (float) $ref_item->get_total() );
					}
				}
			}

			++$page;
		} while ( ! empty( $orders ) && count( $orders ) === $per_page );

		if ( empty( $event_rows ) ) {
			// Dev note: clear transients if summary rows appear stale.
			set_transient( $cache_key, array(), 300 );
			return array();
		}

		$rows = array();
		foreach ( $event_rows as $event_id => $data ) {
			$tickets_sold = isset( $data['tickets_sold'] ) ? (int) $data['tickets_sold'] : 0;
			if ( $tickets_sold <= 0 ) {
				continue;
			}

			$phase_keys      = isset( $event_phase_keys[ $event_id ] ) ? array_keys( $event_phase_keys[ $event_id ] ) : array();
			$first_phase_key = $this->pick_first_phase_key( $phase_keys );
			$phase_qty       = isset( $data['phase_qty'] ) && is_array( $data['phase_qty'] ) ? $data['phase_qty'] : array();

			$first_phase_qty = $first_phase_key !== null ? (int) ( $phase_qty[ $first_phase_key ] ?? 0 ) : 0;
			$after_qty       = $tickets_sold - $first_phase_qty;

			$event_title = get_the_title( $event_id );
			$event_date  = $this->get_event_date_display( $event_id );
			$last_sale   = isset( $event_last_sale_ts[ $event_id ] ) ? wp_date( 'Y-m-d', $event_last_sale_ts[ $event_id ] ) : '—';

			$rows[] = array(
				'event_id'                   => $event_id,
				'title'                      => $event_title !== '' ? $event_title : (string) $event_id,
				'event_date'                 => $event_date !== '' ? $event_date : '—',
				'orders'                     => isset( $data['orders'] ) ? (int) $data['orders'] : 0,
				'tickets_sold'               => $tickets_sold,
				'presale_tickets_sold'       => $first_phase_qty,
				'after_presale_tickets_sold' => max( 0, $after_qty ),
				'gross_sales'                => isset( $data['gross_sales'] ) ? (float) $data['gross_sales'] : 0.0,
				'refunded_amount'            => isset( $data['refunded_amount'] ) ? (float) $data['refunded_amount'] : 0.0,
				'net_sales'                  => ( isset( $data['gross_sales'] ) ? (float) $data['gross_sales'] : 0.0 ) - ( isset( $data['refunded_amount'] ) ? (float) $data['refunded_amount'] : 0.0 ),
				'last_sale'                  => $last_sale,
				'url'                        => $this->build_event_report_url( $event_id, $statuses, $range_data ),
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$net_a = isset( $a['net_sales'] ) ? (float) $a['net_sales'] : 0.0;
				$net_b = isset( $b['net_sales'] ) ? (float) $b['net_sales'] : 0.0;
				return $net_b <=> $net_a;
			}
		);

		// Dev note: clear transients if summary rows appear stale.
		set_transient( $cache_key, $rows, 300 );

		return $rows;
	}

	/**
	 * @param string[]                                                                     $statuses
	 * @param array{after?:string,before?:string}                                          $date_range
	 * @param array{range:string,after:string,before:string,date_range:array,label:string} $range_data
	 */
	private function get_event_summary_cache_key( array $statuses, array $date_range, array $range_data ): string {
		$filters = array(
			'event_id'   => $event_id,
			'statuses'   => $statuses,
			'date_range' => $date_range,
			'range_data' => array(
				'range'  => $range_data['range'] ?? '',
				'after'  => $range_data['after'] ?? '',
				'before' => $range_data['before'] ?? '',
			),
		);

		return $this->build_cache_key( $filters, 'reports_summary' );
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function build_cache_key( array $filters, string $scope ): string {
		$sorted  = $this->sort_filter_array( $filters );
		$payload = wp_json_encode( $sorted );
		return 'oras_tickets_reports_' . $scope . '_' . md5( (string) $payload );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	private function sort_filter_array( array $filters ): array {
		$normalized = array();
		foreach ( $filters as $key => $value ) {
			if ( is_array( $value ) ) {
				$normalized[ $key ] = $this->sort_filter_array( $value );
			} else {
				$normalized[ $key ] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		if ( $this->is_list_array( $normalized ) ) {
			sort( $normalized, SORT_STRING );
			return array_values( $normalized );
		}

		ksort( $normalized );

		return $normalized;
	}

	/**
	 * @param array<string|int,mixed> $value
	 */
	private function is_list_array( array $value ): bool {
		$keys = array_keys( $value );
		return $keys === array_keys( $keys );
	}

	/**
	 * @param string[] $statuses
	 * @return string[]
	 */
	private function normalize_statuses( array $statuses ): array {
		$allowed  = array_keys( $this->get_status_options() );
		$filtered = array_values( array_intersect( $allowed, $statuses ) );
		return ! empty( $filtered ) ? $filtered : $allowed;
	}

	/**
	 * @return string[]
	 */
	private function get_overview_statuses( string $scope ): array {
		switch ( $scope ) {
			case 'refunds':
				return array( 'processing', 'completed', 'refunded' );
			case 'all':
				return array( 'processing', 'completed', 'refunded', 'cancelled' );
			case 'paid':
			default:
				return array( 'processing', 'completed' );
		}
	}

	private function get_overview_scope_from_statuses( array $statuses ): string {
		$normalized = array_values( array_unique( $this->normalize_statuses( $statuses ) ) );
		sort( $normalized, SORT_STRING );

		$paid    = array( 'completed', 'processing' );
		$refunds = array( 'completed', 'processing', 'refunded' );
		$all     = array( 'cancelled', 'completed', 'processing', 'refunded' );

		if ( $normalized === $paid ) {
			return 'paid';
		}
		if ( $normalized === $refunds ) {
			return 'refunds';
		}
		if ( $normalized === $all ) {
			return 'all';
		}

		return 'paid';
	}

	/**
	 * @param array{after?:string,before?:string} $date_range
	 */
	private function build_date_created_arg( array $date_range ): string {
		$after  = isset( $date_range['after'] ) ? (string) $date_range['after'] : '';
		$before = isset( $date_range['before'] ) ? (string) $date_range['before'] : '';

		if ( $after !== '' && $before !== '' ) {
			return $after . '...' . $before;
		}

		if ( $after !== '' ) {
			return '>=' . $after;
		}

		if ( $before !== '' ) {
			return '<=' . $before;
		}

		return '';
	}

	private function get_event_date_display( int $event_id ): string {
		if ( function_exists( 'tribe_get_start_date' ) ) {
			$start = tribe_get_start_date( $event_id, true, 'Y-m-d' );
			if ( is_string( $start ) && $start !== '' ) {
				return $start;
			}
		}

		return '—';
	}

	/**
	 * @param string[] $phase_keys
	 */
	private function pick_first_phase_key( array $phase_keys ): ?string {
		if ( empty( $phase_keys ) ) {
			return null;
		}

		usort(
			$phase_keys,
			static function ( string $a, string $b ): int {
				$a_lower = strtolower( $a );
				$b_lower = strtolower( $b );
				$a_first = (bool) preg_match( '/phase_1|_1$/', $a_lower );
				$b_first = (bool) preg_match( '/phase_1|_1$/', $b_lower );
				if ( $a_first && ! $b_first ) {
					return -1;
				}
				if ( $b_first && ! $a_first ) {
					return 1;
				}
				return strnatcasecmp( $a, $b );
			}
		);

		return $phase_keys[0] ?? null;
	}

	/**
	 * @param string[]                                                                     $statuses
	 * @param array{range:string,after:string,before:string,date_range:array,label:string} $range_data
	 */
	private function build_event_report_url( int $event_id, array $statuses, array $range_data, string $view = 'detail' ): string {
		$args = array(
			'page'                  => 'oras-tickets-reports',
			'view'                  => $view,
			'oras_tickets_event_id' => $event_id,
			'oras_tickets_range'    => $range_data['range'],
			'oras_tickets_after'    => $range_data['after'],
			'oras_tickets_before'   => $range_data['before'],
			'oras_tickets_statuses' => $statuses,
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Presale is the earliest configured phase key by start datetime per ticket.
	 *
	 * @return array<string,string|null>
	 */
	private function get_presale_key_map( int $event_id ): array {
		$envelope = Ticket_Collection::load_envelope_for_event( $event_id );
		$tickets  = isset( $envelope['tickets'] ) && is_array( $envelope['tickets'] ) ? $envelope['tickets'] : array();
		$presale  = array();

		foreach ( $tickets as $index => $ticket ) {
			if ( ! is_array( $ticket ) ) {
				$presale[ (string) $index ] = null;
				continue;
			}

			$phases       = isset( $ticket['price_phases'] ) && is_array( $ticket['price_phases'] ) ? $ticket['price_phases'] : array();
			$earliest_ts  = null;
			$earliest_key = null;

			foreach ( $phases as $phase ) {
				if ( ! is_array( $phase ) ) {
					continue;
				}

				$phase_key = isset( $phase['key'] ) ? (string) $phase['key'] : '';
				$start_raw = isset( $phase['start'] ) ? (string) $phase['start'] : '';
				if ( $phase_key === '' || $start_raw === '' ) {
					continue;
				}

				$start_dt = $this->parse_site_datetime( $start_raw );
				if ( ! $start_dt ) {
					continue;
				}

				$start_ts = $start_dt->getTimestamp();
				if ( $earliest_ts === null || $start_ts < $earliest_ts ) {
					$earliest_ts  = $start_ts;
					$earliest_key = $phase_key;
				}
			}

			$presale[ (string) $index ] = $earliest_key;
		}

		return $presale;
	}

	private function parse_site_datetime( string $value ): ?\DateTimeInterface {
		$tz      = wp_timezone();
		$formats = array( 'Y-m-d H:i', 'Y-m-d H:i:s' );

		foreach ( $formats as $format ) {
			$dt = \DateTime::createFromFormat( $format, $value, $tz );
			if ( $dt instanceof \DateTimeInterface ) {
				return $dt;
			}
		}

		return null;
	}
}
