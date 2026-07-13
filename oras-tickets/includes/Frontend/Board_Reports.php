<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Communication_Recipients;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Reporting\Board_Report_Exporter;
use ORAS\Tickets\Reporting\Board_Report_Service;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Board_Reports {

	private const NONCE_ACTION = 'oras_board_reports_export';
	private const COMMUNICATION_NONCE_ACTION = 'oras_board_reports_communication';
	private const COMMUNICATION_ACTION = 'oras_board_reports_send_communication';
	private const APPROVAL_NONCE_ACTION = 'oras_board_reports_rsvp_approval';
	private const APPROVAL_ACTION = 'oras_board_reports_update_rsvp_approval';
	private const WAITLIST_NONCE_ACTION = 'oras_board_reports_waitlist';
	private const WAITLIST_ACTION = 'oras_board_reports_update_waitlist';
	private const TAB_TICKET_SALES = 'ticket_sales';
	private const TAB_RSVPS = 'rsvps';
	private const TAB_COMMUNICATIONS = 'communications';
	private const TAB_ATTENDEES = 'attendees';
	private const TAB_STATISTICS = 'statistics';

	public static function register(): void {
		add_shortcode( 'oras_board_reports', array( self::class, 'render_shortcode' ) );
		add_action( 'admin_post_oras_board_reports_export_csv', array( self::class, 'handle_export_csv' ) );
		add_action( 'admin_post_oras_board_reports_export_spreadsheet', array( self::class, 'handle_export_spreadsheet' ) );
		add_action( 'admin_post_oras_board_reports_export_pdf', array( self::class, 'handle_export_pdf' ) );
		add_action( 'admin_post_' . self::COMMUNICATION_ACTION, array( self::class, 'handle_send_communication' ) );
		add_action( 'admin_post_' . self::APPROVAL_ACTION, array( self::class, 'handle_update_rsvp_approval' ) );
		add_action( 'admin_post_' . self::WAITLIST_ACTION, array( self::class, 'handle_update_waitlist' ) );
	}

	/**
	 * @param array<string,mixed> $atts
	 */
	public static function render_shortcode( array $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( (string) get_permalink() );
			return '<p>' . esc_html__( 'Please sign in to view board reports.', 'oras-tickets' ) . ' <a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Sign in', 'oras-tickets' ) . '</a></p>';
		}

		if ( ! current_user_can( 'oras_tickets_view_board_dashboard' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return '<p>' . esc_html__( 'You do not have permission to view board reports.', 'oras-tickets' ) . '</p>';
		}

		$active_tab = self::get_active_tab();
		$page_id = self::get_context_page_id();

		ob_start();
		?>
		<div class="oras-board-reports">
			<style>
				.oras-board-reports {
					margin: 24px 0;
				}
				.oras-board-reports .oras-board-reports__notice {
					background: rgba(10, 16, 28, 0.75);
					border-left: 4px solid #2d7dbf;
					margin: 0 0 16px;
					padding: 10px 12px;
					color: #e5ecf5;
				}
				.oras-board-reports .oras-board-reports__tabs {
					display: flex;
					flex-wrap: wrap;
					gap: 8px;
					margin: 18px 0 16px;
					border-bottom: 1px solid #dcdcde;
				}
				.oras-board-reports .oras-board-reports__tab {
					display: inline-flex;
					align-items: center;
					min-height: 40px;
					margin-bottom: -1px;
					padding: 0 14px;
					border: 1px solid #dcdcde;
					border-radius: 8px 8px 0 0;
					background: #f6f7f7;
					color: #1d2327;
					text-decoration: none;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__tab:hover,
				.oras-board-reports .oras-board-reports__tab:focus {
					background: #ffffff;
					color: #0a4b78;
				}
				.oras-board-reports .oras-board-reports__tab[aria-current="page"] {
					background: #ffffff;
					border-bottom-color: #ffffff;
					color: #0a4b78;
				}
				.oras-board-reports .oras-board-reports__placeholder {
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
					padding: 18px;
				}
				.oras-board-reports .oras-board-reports__placeholder h3 {
					margin-top: 0;
				}
				.oras-board-reports .oras-board-reports__filters {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
					gap: 12px;
					align-items: end;
					margin: 16px 0;
					padding: 14px;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
				}
				.oras-board-reports label {
					display: grid;
					gap: 5px;
					font-weight: 600;
					color: #1f2937;
				}
				.oras-board-reports input,
				.oras-board-reports select {
					max-width: 100%;
					min-height: 36px;
					color: #111827;
					background: #ffffff;
				}
				.oras-board-reports .oras-board-reports__actions {
					grid-column: 1 / -1;
					display: flex;
					gap: 8px;
					flex-wrap: wrap;
					justify-content: flex-end;
					align-items: center;
				}
				.oras-board-reports .oras-board-reports__actions .button {
					display: inline-flex;
					align-items: center;
					justify-content: center;
					min-height: 40px;
					padding: 0 18px;
					border: 1px solid #35516e;
					border-radius: 6px;
					background: #1f3953;
					color: #f5f8fc;
					text-decoration: none;
					font-weight: 600;
					line-height: 1;
					white-space: nowrap;
					text-align: center;
					box-shadow: none;
				}
				.oras-board-reports .oras-board-reports__actions .button:hover,
				.oras-board-reports .oras-board-reports__actions .button:focus {
					background: #2a4d70;
					border-color: #466a8e;
					color: #ffffff;
				}
				.oras-board-reports .oras-board-reports__actions .button.button-primary {
					background: #2d7dbf;
					border-color: #2d7dbf;
					color: #ffffff;
				}
				.oras-board-reports .oras-board-reports__actions .button.button-primary:hover,
				.oras-board-reports .oras-board-reports__actions .button.button-primary:focus {
					background: #3892d8;
					border-color: #3892d8;
				}
				.oras-board-reports .oras-board-reports__table-wrap {
					overflow-x: auto;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
				}
				.oras-board-reports table {
					width: 100%;
					border-collapse: collapse;
					min-width: 900px;
				}
				.oras-board-reports th,
				.oras-board-reports td {
					border-top: 1px solid #f0f0f1;
					padding: 9px 8px;
					text-align: left;
					vertical-align: top;
				}
				.oras-board-reports th {
					border-top: 0;
					color: #1d2327;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__empty {
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
					padding: 18px;
				}
				.oras-board-reports .oras-board-reports__inline-actions {
					display: flex;
					flex-wrap: wrap;
					gap: 6px;
				}
				.oras-board-reports .oras-board-reports__inline-actions form {
					margin: 0;
				}
				.oras-board-reports .oras-board-reports__inline-actions .button {
					min-height: 32px;
					padding: 0 10px;
				}
				.oras-board-reports details summary {
					cursor: pointer;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__answer-row > td {
					padding: 0 8px 14px;
					background: #f8fafc;
				}
				.oras-board-reports .oras-board-reports__question-answers {
					margin: 10px 0 0;
					padding: 14px;
					border: 1px solid #d8dee9;
					border-radius: 10px;
					background: #ffffff;
					color: #111827;
				}
				.oras-board-reports .oras-board-reports__question-answers-summary {
					cursor: pointer;
					font-weight: 800;
					color: #0f172a;
				}
				.oras-board-reports .oras-board-reports__question-answers[open] .oras-board-reports__question-answers-summary {
					margin-bottom: 12px;
				}
				.oras-board-reports .oras-board-reports__question-answers-title {
					margin-bottom: 10px;
					font-size: 0.95rem;
					font-weight: 800;
					color: #0f172a;
				}
				.oras-board-reports .oras-board-reports__question-answers dl {
					display: grid;
					grid-template-columns: minmax(180px, 0.35fr) minmax(220px, 1fr);
					gap: 8px 14px;
					margin: 0;
				}
				.oras-board-reports .oras-board-reports__question-answers dt,
				.oras-board-reports .oras-board-reports__question-answers dd {
					margin: 0;
					padding: 9px 10px;
					border-radius: 8px;
				}
				.oras-board-reports .oras-board-reports__question-answers dt {
					background: #eef2f7;
					color: #1f2937;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__question-answers dd {
					background: #ffffff;
					border: 1px solid #e5e7eb;
					color: #111827;
					white-space: pre-wrap;
				}
				html.oras-dark-on .oras-board-reports label,
				html[data-wp-dark-mode-active] .oras-board-reports label,
				body.wp-dark-mode-active .oras-board-reports label {
					color: #e6edf7;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__tab,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__tab,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__tab {
					background: #142238;
					border-color: #3a4f68;
					color: #e6edf7;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__tab[aria-current="page"],
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__tab[aria-current="page"],
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__tab[aria-current="page"] {
					background: #0c1624;
					border-bottom-color: #0c1624;
					color: #ffffff;
				}
				html.oras-dark-on .oras-board-reports input,
				html.oras-dark-on .oras-board-reports select,
				html[data-wp-dark-mode-active] .oras-board-reports input,
				html[data-wp-dark-mode-active] .oras-board-reports select,
				body.wp-dark-mode-active .oras-board-reports input,
				body.wp-dark-mode-active .oras-board-reports select {
					color: #f4f7fc;
					background: #0c1624;
					border-color: #3a4f68;
				}
				html.oras-dark-on .oras-board-reports input::placeholder,
				html[data-wp-dark-mode-active] .oras-board-reports input::placeholder,
				body.wp-dark-mode-active .oras-board-reports input::placeholder {
					color: #c0cfdf;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__answer-row > td,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__answer-row > td,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__answer-row > td {
					background: rgba(2, 6, 23, 0.36);
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers {
					background: rgba(15, 23, 42, 0.9);
					border-color: rgba(148, 163, 184, 0.35);
					color: #e6edf7;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers-summary,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers-summary,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers-summary {
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers-title,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers-title,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers-title {
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers dt,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers dt,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers dt {
					background: rgba(51, 65, 85, 0.78);
					color: #e2e8f0;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers dd,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers dd,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers dd {
					background: rgba(2, 6, 23, 0.72);
					border-color: rgba(148, 163, 184, 0.28);
					color: #f8fafc;
				}
				html:not(.oras-dark-on) .oras-board-reports label {
					color: #1f2937;
				}
				html:not(.oras-dark-on) .oras-board-reports input,
				html:not(.oras-dark-on) .oras-board-reports select {
					color: #111827;
					background: #ffffff;
				}
				@media (max-width: 900px) {
					.oras-board-reports .oras-board-reports__actions {
						justify-content: stretch;
					}
					.oras-board-reports .oras-board-reports__actions .button {
						flex: 1 1 100%;
						text-align: center;
					}
					.oras-board-reports .oras-board-reports__question-answers dl {
						grid-template-columns: 1fr;
					}
				}
			</style>

			<h2><?php echo esc_html__( 'Board Reports / Event Management Dashboard', 'oras-tickets' ); ?></h2>
			<p class="oras-board-reports__notice"><?php echo esc_html__( 'This report excludes payment method, transaction, card, and accounting details.', 'oras-tickets' ); ?></p>
			<?php self::render_rsvp_approval_notice(); ?>
			<?php self::render_waitlist_notice(); ?>
			<?php self::render_tabs( $active_tab ); ?>
			<?php if ( self::TAB_TICKET_SALES === $active_tab ) : ?>
				<?php self::render_ticket_sales_tab( $page_id ); ?>
			<?php elseif ( self::TAB_RSVPS === $active_tab ) : ?>
				<?php self::render_rsvps_tab( $page_id ); ?>
			<?php elseif ( self::TAB_COMMUNICATIONS === $active_tab ) : ?>
				<?php self::render_communications_tab( $page_id ); ?>
			<?php elseif ( self::TAB_ATTENDEES === $active_tab ) : ?>
				<?php self::render_attendees_tab( $page_id ); ?>
			<?php elseif ( self::TAB_STATISTICS === $active_tab ) : ?>
				<?php self::render_statistics_tab( $page_id ); ?>
			<?php else : ?>
				<?php self::render_placeholder_tab( $active_tab ); ?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private static function render_ticket_sales_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$types = $service->get_report_types();
		if ( ! isset( $types[ $filters['type'] ] ) ) {
			$filters['type'] = Board_Report_Service::TYPE_TICKETS;
		}
		$requires_event = self::type_requires_event( $filters['type'] );

		$events = $service->get_events();
		if ( $requires_event && $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		} elseif ( ! $requires_event ) {
			$filters['event_id'] = 0;
		}

		$rows = $service->get_rows( $filters['type'], $filters );
		$spreadsheet_export_url = self::build_export_url( $filters, 'spreadsheet' );
		$pdf_export_url = self::build_export_url( $filters, 'pdf' );
		?>

			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_TICKET_SALES ); ?>" />
				<label>
					<?php echo esc_html__( 'Report', 'oras-tickets' ); ?>
					<select name="oras_board_report_type">
						<?php foreach ( $types as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<?php if ( $requires_event ) : ?>
					<label>
						<?php echo esc_html__( 'Event', 'oras-tickets' ); ?>
						<select name="oras_board_event_id">
							<?php foreach ( $events as $event ) : ?>
								<option value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php selected( $filters['event_id'], (int) $event->ID ); ?>><?php echo esc_html( get_the_title( $event ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>

				<label>
					<?php echo esc_html__( 'After', 'oras-tickets' ); ?>
					<input type="date" name="oras_board_after" value="<?php echo esc_attr( $filters['after'] ); ?>" />
				</label>

				<label>
					<?php echo esc_html__( 'Before', 'oras-tickets' ); ?>
					<input type="date" name="oras_board_before" value="<?php echo esc_attr( $filters['before'] ); ?>" />
				</label>

				<label>
					<?php echo esc_html__( 'Status', 'oras-tickets' ); ?>
					<select name="oras_board_status">
						<?php foreach ( self::get_status_options( $filters['type'] ) as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php echo esc_html__( 'Search', 'oras-tickets' ); ?>
					<input type="search" name="oras_board_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone, item', 'oras-tickets' ); ?>" />
				</label>

				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show Report', 'oras-tickets' ); ?></button>
					<?php if ( current_user_can( 'oras_tickets_export_reports' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
						<a class="button button-secondary" href="<?php echo esc_url( $spreadsheet_export_url ); ?>"><?php echo esc_html__( 'Create Spreadsheet', 'oras-tickets' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $pdf_export_url ); ?>"><?php echo esc_html__( 'Create PDF', 'oras-tickets' ); ?></a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No matching rows found for this report.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<div class="oras-board-reports__table-wrap">
						<table>
							<thead>
								<tr>
									<?php foreach ( Board_Report_Exporter::COLUMNS as $label ) : ?>
										<th><?php echo esc_html( $label ); ?></th>
									<?php endforeach; ?>
									<th><?php echo esc_html__( 'Details', 'oras-tickets' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<?php foreach ( Board_Report_Exporter::COLUMNS as $key => $label ) : ?>
											<td><?php echo esc_html( self::format_report_cell( $row, $key ) ); ?></td>
										<?php endforeach; ?>
										<td><?php self::render_question_answers_details( $row ); ?></td>
									</tr>
									<?php self::render_question_answers_table_row( $row, count( Board_Report_Exporter::COLUMNS ) + 1 ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php
		}

	private static function render_waitlist_section( int $event_id ): void {
		$rows = Waitlist_Store::get_event_rows( $event_id, array( 'waiting' ), 250, 'joined_asc' );
		$position = 1;
		?>
		<section class="oras-board-reports__placeholder">
			<h3><?php echo esc_html__( 'Waitlist Queue', 'oras-tickets' ); ?></h3>
			<p><?php echo esc_html__( 'Board users can promote waitlisted RSVPs when capacity opens, open one additional RSVP seat, or remove a waitlist entry.', 'oras-tickets' ); ?></p>
			<?php if ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No one is currently waiting for this event.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<div class="oras-board-reports__table-wrap">
					<table>
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Position', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Name', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Email', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Attendance Type', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Approval Status', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Joined Date', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Contact Note', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Actions', 'oras-tickets' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$user_id = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
								$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
								$contact = self::get_waitlist_contact( $event_id, $user_id );
								$attendance_mode = Event_RSVP::get_user_attendance_type_for_report( $event_id, $user_id );
								$approval_status = Event_RSVP::get_user_approval_status( $event_id, $user_id );
								?>
								<tr>
									<td><?php echo esc_html( (string) $position ); ?></td>
									<td><?php echo esc_html( $contact['name'] !== '' ? $contact['name'] : ( $user instanceof \WP_User ? (string) $user->display_name : 'User #' . $user_id ) ); ?></td>
									<td><?php echo esc_html( $contact['email'] !== '' ? $contact['email'] : ( $user instanceof \WP_User ? (string) $user->user_email : '' ) ); ?></td>
									<td><?php echo esc_html( Event_RSVP::get_attendance_mode_label( $attendance_mode ) ); ?></td>
									<td><?php echo esc_html( Event_RSVP::get_approval_status_label( $approval_status ) ); ?></td>
									<td><?php echo esc_html( isset( $row->joined_at ) ? (string) $row->joined_at : '' ); ?></td>
									<td><?php echo esc_html( $contact['note'] ); ?></td>
									<td><?php self::render_waitlist_row_actions( $event_id, $user_id, $row, $contact, $attendance_mode, $approval_status ); ?></td>
								</tr>
								<?php ++$position; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @return array{name:string,email:string,phone:string,note:string}
	 */
	private static function get_waitlist_contact( int $event_id, int $user_id ): array {
		$contact = array(
			'name'  => '',
			'email' => '',
			'phone' => '',
			'note'  => '',
		);
		if ( $event_id <= 0 || $user_id <= 0 ) {
			return $contact;
		}

		$stored = get_user_meta( $user_id, '_oras_rsvp_event_' . $event_id . '_contact', true );
		if ( is_array( $stored ) ) {
			$first = isset( $stored['first_name'] ) && is_scalar( $stored['first_name'] ) ? sanitize_text_field( (string) $stored['first_name'] ) : '';
			$last = isset( $stored['last_name'] ) && is_scalar( $stored['last_name'] ) ? sanitize_text_field( (string) $stored['last_name'] ) : '';
			$contact['name'] = trim( $first . ' ' . $last );
			$contact['email'] = isset( $stored['email'] ) && is_scalar( $stored['email'] ) ? sanitize_email( (string) $stored['email'] ) : '';
			$contact['phone'] = isset( $stored['phone'] ) && is_scalar( $stored['phone'] ) ? sanitize_text_field( (string) $stored['phone'] ) : '';
			$contact['note'] = isset( $stored['note'] ) && is_scalar( $stored['note'] ) ? sanitize_textarea_field( (string) $stored['note'] ) : '';
		}

		return $contact;
	}

	/**
	 * @param object               $row
	 * @param array<string,string> $contact
	 */
	private static function render_waitlist_row_actions( int $event_id, int $user_id, object $row, array $contact, string $attendance_mode, string $approval_status ): void {
		?>
		<div class="oras-board-reports__inline-actions">
			<details>
				<summary><?php echo esc_html__( 'View Details', 'oras-tickets' ); ?></summary>
				<p><strong><?php echo esc_html__( 'User ID:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $user_id ); ?></p>
				<p><strong><?php echo esc_html__( 'Phone:', 'oras-tickets' ); ?></strong> <?php echo esc_html( $contact['phone'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Attendance mode:', 'oras-tickets' ); ?></strong> <?php echo esc_html( Event_RSVP::get_attendance_mode_label( $attendance_mode ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approval status:', 'oras-tickets' ); ?></strong> <?php echo esc_html( Event_RSVP::get_approval_status_label( $approval_status ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Source:', 'oras-tickets' ); ?></strong> <?php echo esc_html( isset( $row->source ) ? (string) $row->source : '' ); ?></p>
			</details>
			<?php if ( current_user_can( 'oras_tickets_manage_rsvps' ) && $event_id > 0 && $user_id > 0 ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
				<?php self::render_waitlist_action_form( $event_id, $user_id, 'promote', __( 'Promote', 'oras-tickets' ) ); ?>
				<?php self::render_waitlist_action_form( $event_id, $user_id, 'open_seat_promote', __( 'Open Seat + Promote', 'oras-tickets' ) ); ?>
				<?php self::render_waitlist_action_form( $event_id, $user_id, 'remove', __( 'Remove from Waitlist', 'oras-tickets' ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_waitlist_action_form( int $event_id, int $user_id, string $waitlist_action, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::WAITLIST_NONCE_ACTION, 'oras_board_waitlist_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::WAITLIST_ACTION ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<input type="hidden" name="waitlist_action" value="<?php echo esc_attr( $waitlist_action ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_current_url() ); ?>" />
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_communications_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$segment = self::get_communication_segment_from_request();
		$recipients = ( new Communication_Recipients( $service ) )->resolve( $filters['event_id'], $segment );
		$recipient_count = count( $recipients );
		$history_filters = self::get_communication_history_filters( $filters['event_id'] );
		$history_rows = Communication_Log_Store::query( $history_filters );
		$detail = self::get_communication_detail();
		?>
			<?php self::render_communication_notice(); ?>
			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_COMMUNICATIONS ); ?>" />
				<?php self::render_event_filter( $events, $filters['event_id'] ); ?>
				<label>
					<?php echo esc_html__( 'Recipient Segment', 'oras-tickets' ); ?>
					<select name="oras_comm_segment">
						<?php foreach ( Communication_Recipients::get_segments() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $segment, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Preview Recipients', 'oras-tickets' ); ?></button>
				</div>
			</form>

			<div class="oras-board-reports__placeholder">
				<h3><?php echo esc_html__( 'Recipient Count Preview', 'oras-tickets' ); ?></h3>
				<p><strong><?php echo esc_html( (string) $recipient_count ); ?></strong> <?php echo esc_html__( 'valid deduplicated recipient(s) found for the selected segment.', 'oras-tickets' ); ?></p>
			</div>

			<?php if ( current_user_can( 'oras_tickets_send_notifications' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
				<form class="oras-board-reports__filters" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::COMMUNICATION_ACTION ); ?>" />
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $filters['event_id'] ); ?>" />
					<input type="hidden" name="recipient_segment" value="<?php echo esc_attr( $segment ); ?>" />
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_current_dashboard_url( self::TAB_COMMUNICATIONS, $filters['event_id'], $segment ) ); ?>" />
					<?php wp_nonce_field( self::COMMUNICATION_NONCE_ACTION, 'oras_board_communication_nonce' ); ?>
					<label>
						<?php echo esc_html__( 'Subject', 'oras-tickets' ); ?>
						<input type="text" name="email_subject" required maxlength="200" />
					</label>
					<label style="grid-column:1 / -1;">
						<?php echo esc_html__( 'Message', 'oras-tickets' ); ?>
						<textarea name="email_message" rows="8" required></textarea>
					</label>
					<label style="grid-column:1 / -1;">
						<input type="checkbox" name="confirm_send" value="1" required />
						<?php echo esc_html__( 'I confirm this message should be sent to the selected recipient segment.', 'oras-tickets' ); ?>
					</label>
					<div class="oras-board-reports__actions">
						<button class="button button-primary" type="submit" <?php disabled( 0, $recipient_count ); ?>><?php echo esc_html__( 'Send Email', 'oras-tickets' ); ?></button>
					</div>
				</form>
			<?php else : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'You do not have permission to send event communications.', 'oras-tickets' ); ?></div>
			<?php endif; ?>

			<h3><?php echo esc_html__( 'Communication History', 'oras-tickets' ); ?></h3>
			<?php self::render_communication_history_filters( $page_id, $events, $history_filters ); ?>
			<?php self::render_communication_history_table( $history_rows ); ?>
			<?php if ( is_array( $detail ) ) : ?>
				<section class="oras-board-reports__placeholder">
					<h3><?php echo esc_html__( 'Communication Details', 'oras-tickets' ); ?></h3>
					<p><strong><?php echo esc_html__( 'Subject:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) ( $detail['email_subject'] ?? '' ) ); ?></p>
					<p><strong><?php echo esc_html__( 'Body Snapshot:', 'oras-tickets' ); ?></strong></p>
					<pre><?php echo esc_html( (string) ( $detail['email_body_snapshot'] ?? '' ) ); ?></pre>
				</section>
			<?php endif; ?>
		<?php
	}

	private static function render_attendees_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$rows = $service->get_unified_attendees( $filters['event_id'], $filters );
		$ticket_export_filters = array_merge(
			$filters,
			array(
				'type'   => Board_Report_Service::TYPE_TICKETS,
				'status' => $filters['ticket_status'],
			)
		);
		$spreadsheet_export_url = self::build_export_url(
			$ticket_export_filters,
			'spreadsheet'
		);
		$pdf_export_url = self::build_export_url(
			$ticket_export_filters,
			'pdf'
		);
		?>
			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_ATTENDEES ); ?>" />

				<?php self::render_event_filter( $events, $filters['event_id'] ); ?>
				<label>
					<?php echo esc_html__( 'Source', 'oras-tickets' ); ?>
					<select name="oras_board_attendee_source">
						<?php foreach ( self::get_attendee_source_options() as $source => $label ) : ?>
							<option value="<?php echo esc_attr( $source ); ?>" <?php selected( $filters['attendee_source'], $source ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Ticket Status', 'oras-tickets' ); ?>
					<select name="oras_board_ticket_status">
						<?php foreach ( self::get_status_options( Board_Report_Service::TYPE_TICKETS ) as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['ticket_status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Attendance Type', 'oras-tickets' ); ?>
					<select name="oras_board_attendance_type">
						<?php foreach ( self::get_attendance_type_options() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['attendance_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Approval Status', 'oras-tickets' ); ?>
					<select name="oras_board_approval_status">
						<?php foreach ( self::get_approval_status_options() as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['approval_status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Search', 'oras-tickets' ); ?>
					<input type="search" name="oras_board_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone, item', 'oras-tickets' ); ?>" />
				</label>
				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show Attendees', 'oras-tickets' ); ?></button>
					<?php if ( current_user_can( 'oras_tickets_export_reports' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
						<a class="button button-secondary" href="<?php echo esc_url( $spreadsheet_export_url ); ?>"><?php echo esc_html__( 'Export Ticket Sales Spreadsheet', 'oras-tickets' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $pdf_export_url ); ?>"><?php echo esc_html__( 'Export Ticket Sales PDF', 'oras-tickets' ); ?></a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( empty( $events ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No events are available for attendee reporting.', 'oras-tickets' ); ?></div>
			<?php elseif ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No matching attendees found for this event.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<div class="oras-board-reports__table-wrap">
					<table>
						<thead>
								<tr>
									<th><?php echo esc_html__( 'Name', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Email', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Source', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Item / Status', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Qty', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Attendance Type', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Approval Status', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Phone', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Note', 'oras-tickets' ); ?></th>
									<th><?php echo esc_html__( 'Details', 'oras-tickets' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( self::row_scalar( $row, 'name' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'email' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'source' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'item_label' ) . self::format_status_suffix( self::row_scalar( $row, 'order_status' ) ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'quantity' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'attendance_label' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'approval_label' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'phone' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'note' ) ); ?></td>
										<td><?php self::render_question_answers_details( $row ); ?></td>
									</tr>
									<?php self::render_question_answers_table_row( $row, 10 ); ?>
								<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			<?php
		}

	private static function render_statistics_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}
		$stats = $service->get_event_statistics( $filters['event_id'] );
		?>
			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_STATISTICS ); ?>" />
				<?php self::render_event_filter( $events, $filters['event_id'] ); ?>
				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show Statistics', 'oras-tickets' ); ?></button>
				</div>
			</form>

			<?php if ( empty( $events ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No events are available for statistics.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<div class="oras-board-reports__table-wrap">
					<table>
						<tbody>
							<?php foreach ( self::get_statistics_rows( $stats ) as $label => $value ) : ?>
								<tr>
									<th><?php echo esc_html( $label ); ?></th>
									<td><?php echo esc_html( $value ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php
		}

	private static function render_rsvps_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		$filters['type'] = Board_Report_Service::TYPE_RSVP;
		$filters['status'] = 'all';

		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$rows = $service->get_rows( Board_Report_Service::TYPE_RSVP, $filters );
		$spreadsheet_export_url = self::build_export_url( $filters, 'spreadsheet' );
		$pdf_export_url = self::build_export_url( $filters, 'pdf' );
		?>

			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_RSVPS ); ?>" />
				<input type="hidden" name="oras_board_report_type" value="<?php echo esc_attr( Board_Report_Service::TYPE_RSVP ); ?>" />

				<label>
					<?php echo esc_html__( 'Event', 'oras-tickets' ); ?>
					<select name="oras_board_event_id">
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php selected( $filters['event_id'], (int) $event->ID ); ?>><?php echo esc_html( get_the_title( $event ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php echo esc_html__( 'Attendance Type', 'oras-tickets' ); ?>
					<select name="oras_board_attendance_type">
						<?php foreach ( self::get_attendance_type_options() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['attendance_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php echo esc_html__( 'Approval Status', 'oras-tickets' ); ?>
					<select name="oras_board_approval_status">
						<?php foreach ( self::get_approval_status_options() as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['approval_status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php echo esc_html__( 'Search', 'oras-tickets' ); ?>
					<input type="search" name="oras_board_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone, note', 'oras-tickets' ); ?>" />
				</label>

				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show RSVPs', 'oras-tickets' ); ?></button>
					<?php if ( current_user_can( 'oras_tickets_export_reports' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
						<a class="button button-secondary" href="<?php echo esc_url( $spreadsheet_export_url ); ?>"><?php echo esc_html__( 'Create Spreadsheet', 'oras-tickets' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $pdf_export_url ); ?>"><?php echo esc_html__( 'Create PDF', 'oras-tickets' ); ?></a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( empty( $events ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No events are available for RSVP reporting.', 'oras-tickets' ); ?></div>
			<?php elseif ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No matching RSVP rows found for this event.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<div class="oras-board-reports__table-wrap">
					<table>
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Name', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Email', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Phone', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'RSVP Status', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Attendance Type', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Approval Status', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Approved By', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Approved Date', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Source', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Note', 'oras-tickets' ); ?></th>
								<th><?php echo esc_html__( 'Actions', 'oras-tickets' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( self::row_scalar( $row, 'name' ) ); ?></td>
									<td><?php echo esc_html( self::row_scalar( $row, 'email' ) ); ?></td>
									<td><?php echo esc_html( self::row_scalar( $row, 'phone' ) ); ?></td>
										<td><?php echo esc_html( self::get_rsvp_status_label( self::row_scalar( $row, 'order_status' ) ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'attendance_label' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'approval_label' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'approved_by' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'approved_at' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'source' ) ); ?></td>
										<td><?php echo esc_html( self::row_scalar( $row, 'note' ) ); ?></td>
										<td><?php self::render_rsvp_row_actions( $row ); ?></td>
									</tr>
									<?php self::render_question_answers_table_row( $row, 11 ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $events ) && $filters['event_id'] > 0 ) : ?>
					<?php self::render_waitlist_section( $filters['event_id'] ); ?>
				<?php endif; ?>
			<?php
		}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_rsvp_row_actions( array $row ): void {
		$event_id = absint( $row['event_id'] ?? 0 );
		$user_id = absint( $row['user_id'] ?? 0 );
		$approval_status = Event_RSVP::normalize_approval_status( self::row_scalar( $row, 'approval_status' ), Event_RSVP::APPROVAL_STATUS_APPROVED );
		$rejection_reason = self::row_scalar( $row, 'rejection_reason' );

		?>
		<div class="oras-board-reports__inline-actions">
			<details>
				<summary><?php echo esc_html__( 'View Details', 'oras-tickets' ); ?></summary>
				<p><strong><?php echo esc_html__( 'Attendance mode:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'attendance_label' ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approval status:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'approval_label' ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approved by:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'approved_by' ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approved date:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'approved_at' ) ); ?></p>
					<?php if ( '' !== $rejection_reason ) : ?>
						<p><strong><?php echo esc_html__( 'Rejection reason:', 'oras-tickets' ); ?></strong> <?php echo esc_html( $rejection_reason ); ?></p>
					<?php endif; ?>
					<p><strong><?php echo esc_html__( 'User ID:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $user_id ); ?></p>
				</details>
			<?php if ( current_user_can( 'oras_tickets_manage_rsvps' ) && $event_id > 0 && $user_id > 0 ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
				<?php if ( Event_RSVP::APPROVAL_STATUS_APPROVED !== $approval_status ) : ?>
					<?php self::render_rsvp_approval_action_form( $event_id, $user_id, Event_RSVP::APPROVAL_STATUS_APPROVED, __( 'Approve', 'oras-tickets' ) ); ?>
				<?php endif; ?>
				<?php if ( Event_RSVP::APPROVAL_STATUS_REJECTED !== $approval_status ) : ?>
					<?php self::render_rsvp_approval_action_form( $event_id, $user_id, Event_RSVP::APPROVAL_STATUS_REJECTED, __( 'Reject', 'oras-tickets' ), true ); ?>
				<?php endif; ?>
				<?php if ( Event_RSVP::APPROVAL_STATUS_PENDING !== $approval_status ) : ?>
					<?php self::render_rsvp_approval_action_form( $event_id, $user_id, Event_RSVP::APPROVAL_STATUS_PENDING, __( 'Return to Pending', 'oras-tickets' ) ); ?>
				<?php else : ?>
					<button type="button" class="button button-secondary" disabled><?php echo esc_html__( 'Return to Pending', 'oras-tickets' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_rsvp_approval_action_form( int $event_id, int $user_id, string $approval_status, string $label, bool $include_reason = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::APPROVAL_NONCE_ACTION, 'oras_board_rsvp_approval_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::APPROVAL_ACTION ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<input type="hidden" name="approval_status" value="<?php echo esc_attr( $approval_status ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_current_url() ); ?>" />
			<?php if ( $include_reason ) : ?>
				<input type="hidden" name="rejection_reason" value="" />
			<?php endif; ?>
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_rsvp_approval_notice(): void {
		$status = isset( $_GET['oras_rsvp_approval_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_rsvp_approval_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$message = 'updated' === $status
			? __( 'RSVP approval status updated.', 'oras-tickets' )
			: __( 'Unable to update RSVP approval status.', 'oras-tickets' );
		?>
		<p class="oras-board-reports__notice"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	private static function render_waitlist_notice(): void {
		$status = isset( $_GET['oras_waitlist_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_waitlist_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$message = 'updated' === $status
			? __( 'Waitlist action completed.', 'oras-tickets' )
			: __( 'Unable to complete waitlist action.', 'oras-tickets' );
		?>
		<p class="oras-board-reports__notice"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	public static function handle_update_rsvp_approval(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_manage_rsvps' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if (
			! isset( $_POST['oras_board_rsvp_approval_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_board_rsvp_approval_nonce'] ) ), self::APPROVAL_NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$approval_status = isset( $_POST['approval_status'] ) ? sanitize_key( wp_unslash( $_POST['approval_status'] ) ) : '';
		$rejection_reason = isset( $_POST['rejection_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rejection_reason'] ) ) : '';
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::get_current_dashboard_url( self::TAB_RSVPS, $event_id );

		$result = Event_RSVP::update_approval_status( $event_id, $user_id, $approval_status, $rejection_reason );
		$status = is_wp_error( $result ) ? 'failed' : 'updated';

		wp_safe_redirect( add_query_arg( 'oras_rsvp_approval_status', $status, $redirect ) );
		exit;
	}

	public static function handle_update_waitlist(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_manage_rsvps' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if (
			! isset( $_POST['oras_board_waitlist_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_board_waitlist_nonce'] ) ), self::WAITLIST_NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$waitlist_action = isset( $_POST['waitlist_action'] ) && is_scalar( $_POST['waitlist_action'] ) ? sanitize_key( wp_unslash( $_POST['waitlist_action'] ) ) : '';
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::get_current_dashboard_url( self::TAB_RSVPS, $event_id );
		$actor_user_id = get_current_user_id();

		if ( 'promote' === $waitlist_action ) {
			$result = Event_RSVP::promote_waitlist_user( $event_id, $user_id, $actor_user_id, 'board-waitlist', true );
		} elseif ( 'open_seat_promote' === $waitlist_action ) {
			$result = Event_RSVP::open_seat_and_promote_waitlist_user( $event_id, $user_id, $actor_user_id, 'board-open-seat' );
		} elseif ( 'remove' === $waitlist_action ) {
			$result = Event_RSVP::cancel_rsvp_attendee( $event_id, $user_id, $actor_user_id, 'board-waitlist-remove', false );
		} else {
			$result = new \WP_Error( 'oras_waitlist_invalid_action', __( 'Invalid waitlist action.', 'oras-tickets' ) );
		}

		$status = is_wp_error( $result ) ? 'failed' : 'updated';

		wp_safe_redirect( add_query_arg( 'oras_waitlist_status', $status, $redirect ) );
		exit;
	}

	public static function handle_export_csv(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_export_reports' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$rows = $service->get_rows( $filters['type'], $filters );
		$filename = 'oras-board-' . sanitize_key( $filters['type'] ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		( new Board_Report_Exporter() )->output_csv( $rows, $filename );
		exit;
	}

	public static function handle_export_spreadsheet(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_export_reports' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$rows = $service->get_rows( $filters['type'], $filters );
		$filename = 'oras-board-' . sanitize_key( $filters['type'] ) . '-' . gmdate( 'Y-m-d' ) . '.xls';

		( new Board_Report_Exporter() )->output_spreadsheet( $rows, $filename );
		exit;
	}

	public static function handle_export_pdf(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_export_reports' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$rows = $service->get_rows( $filters['type'], $filters );
		$filename = 'oras-board-' . sanitize_key( $filters['type'] ) . '-' . gmdate( 'Y-m-d' ) . '.pdf';

		( new Board_Report_Exporter() )->output_pdf( $rows, $filename );
		exit;
	}

	public static function handle_send_communication(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_send_notifications' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if (
			! isset( $_POST['oras_board_communication_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_board_communication_nonce'] ) ), self::COMMUNICATION_NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$segment = isset( $_POST['recipient_segment'] ) ? Communication_Recipients::normalize_segment( sanitize_key( wp_unslash( $_POST['recipient_segment'] ) ) ) : Communication_Recipients::SEGMENT_ALL_ATTENDEES;
		$subject = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		$message = isset( $_POST['email_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_message'] ) ) : '';
		$confirmed = isset( $_POST['confirm_send'] ) && '1' === (string) wp_unslash( $_POST['confirm_send'] );
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::get_current_dashboard_url( self::TAB_COMMUNICATIONS, $event_id, $segment );

		if ( $event_id <= 0 || '' === $subject || '' === $message || ! $confirmed ) {
			self::redirect_communication_result( $redirect, 'failed', 0 );
		}

			$sender = wp_get_current_user();
			$sender_user_id = get_current_user_id();
			$sender_display_name = (string) $sender->display_name;
			$sender_email = (string) $sender->user_email;
		$recipients = ( new Communication_Recipients() )->resolve( $event_id, $segment );
		$recipient_count = count( $recipients );

		$failed_count = 0;
		$body = self::append_email_footer( $message, $sender, $event_id );
		if ( $recipient_count <= 0 ) {
			$failed_count = 0;
			$status = 'failed';
		} else {
			foreach ( $recipients as $recipient ) {
				$email = isset( $recipient['email'] ) && is_scalar( $recipient['email'] ) ? sanitize_email( (string) $recipient['email'] ) : '';
				if ( '' === $email || ! is_email( $email ) ) {
					++$failed_count;
					continue;
				}
				$sent = wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
				if ( ! $sent ) {
					++$failed_count;
				}
			}

			$status = 0 === $failed_count ? 'sent' : ( $failed_count >= $recipient_count ? 'failed' : 'partial' );
		}

		$log_id = Communication_Log_Store::insert(
			array(
				'event_id'               => $event_id,
				'sender_user_id'         => $sender_user_id,
				'sender_display_name'    => $sender_display_name,
				'sender_email'           => $sender_email,
				'recipient_segment'      => $segment,
				'recipient_count'        => $recipient_count,
				'email_subject'          => $subject,
				'email_body_snapshot'    => $message,
				'sent_at'                => current_time( 'mysql', true ),
				'send_status'            => $status,
				'failed_recipient_count' => $failed_count,
				'related_action_type'    => self::get_related_action_type( $segment ),
			)
		);

		self::redirect_communication_result( $redirect, $status, $log_id );
	}

	private static function get_active_tab(): string {
		$tab = isset( $_GET['oras_board_tab'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_tab'] ) ) : self::TAB_TICKET_SALES;
		$tabs = self::get_dashboard_tabs();

		return isset( $tabs[ $tab ] ) ? $tab : self::TAB_TICKET_SALES;
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_dashboard_tabs(): array {
		return array(
			self::TAB_TICKET_SALES  => __( 'Ticket Sales', 'oras-tickets' ),
			self::TAB_RSVPS         => __( 'RSVPs', 'oras-tickets' ),
			self::TAB_COMMUNICATIONS => __( 'Communications', 'oras-tickets' ),
			self::TAB_ATTENDEES     => __( 'Attendees', 'oras-tickets' ),
			self::TAB_STATISTICS    => __( 'Event Statistics', 'oras-tickets' ),
		);
	}

	private static function render_tabs( string $active_tab ): void {
		?>
		<nav class="oras-board-reports__tabs" aria-label="<?php echo esc_attr__( 'Event Management Dashboard sections', 'oras-tickets' ); ?>">
			<?php foreach ( self::get_dashboard_tabs() as $tab => $label ) : ?>
				<a
					class="oras-board-reports__tab"
					href="<?php echo esc_url( self::build_tab_url( $tab ) ); ?>"
					<?php if ( $active_tab === $tab ) : ?>
						aria-current="page"
					<?php endif; ?>
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private static function render_placeholder_tab( string $active_tab ): void {
		$placeholders = array(
			self::TAB_RSVPS          => array(
				'title' => __( 'RSVPs', 'oras-tickets' ),
				'body'  => __( 'RSVP management will be added in Phase 1C.', 'oras-tickets' ),
			),
			self::TAB_COMMUNICATIONS => array(
				'title' => __( 'Communications', 'oras-tickets' ),
				'body'  => __( 'Communications tools will be added in Phase 1D.', 'oras-tickets' ),
			),
			self::TAB_ATTENDEES      => array(
				'title' => __( 'Attendees', 'oras-tickets' ),
				'body'  => __( 'Attendee management will be added in Phase 1C.', 'oras-tickets' ),
			),
			self::TAB_STATISTICS     => array(
				'title' => __( 'Event Statistics', 'oras-tickets' ),
				'body'  => __( 'Event statistics will be added in Phase 1C.', 'oras-tickets' ),
			),
		);
		$placeholder = $placeholders[ $active_tab ] ?? $placeholders[ self::TAB_RSVPS ];
		?>
		<section class="oras-board-reports__placeholder" aria-live="polite">
			<h3><?php echo esc_html( $placeholder['title'] ); ?></h3>
			<p><?php echo esc_html( $placeholder['body'] ); ?></p>
		</section>
		<?php
	}

	private static function build_tab_url( string $tab ): string {
		$args = $_GET;
		$args['oras_board_tab'] = $tab;

		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				unset( $args[ $key ] );
				continue;
			}

			$args[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		return add_query_arg( $args, self::get_form_action_url() );
	}

	private static function get_communication_segment_from_request(): string {
		$segment = isset( $_GET['oras_comm_segment'] ) ? sanitize_key( wp_unslash( $_GET['oras_comm_segment'] ) ) : Communication_Recipients::SEGMENT_ALL_ATTENDEES;

		return Communication_Recipients::normalize_segment( $segment );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_communication_history_filters( int $event_id ): array {
		$segment = isset( $_GET['oras_comm_history_segment'] ) ? sanitize_key( wp_unslash( $_GET['oras_comm_history_segment'] ) ) : '';
		$after = isset( $_GET['oras_comm_after'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_comm_after'] ) ) : '';
		$before = isset( $_GET['oras_comm_before'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_comm_before'] ) ) : '';

		return array(
			'event_id'           => $event_id,
			'sender_user_id'     => isset( $_GET['oras_comm_sender_id'] ) ? absint( $_GET['oras_comm_sender_id'] ) : 0,
			'recipient_segment'  => isset( Communication_Recipients::get_segments()[ $segment ] ) ? $segment : '',
			'search'             => isset( $_GET['oras_comm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_comm_search'] ) ) : '',
			'after'              => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ? $after : '',
			'before'             => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ? $before : '',
			'limit'              => 50,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function get_communication_detail(): ?array {
		$detail_id = isset( $_GET['oras_comm_detail'] ) ? absint( $_GET['oras_comm_detail'] ) : 0;
		if ( $detail_id <= 0 ) {
			return null;
		}

		return Communication_Log_Store::get( $detail_id );
	}

	private static function render_communication_notice(): void {
		$status = isset( $_GET['oras_comm_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_comm_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$message = __( 'Communication send attempt was recorded.', 'oras-tickets' );
		if ( 'sent' === $status ) {
			$message = __( 'Communication sent and logged.', 'oras-tickets' );
		} elseif ( 'partial' === $status ) {
			$message = __( 'Communication partially sent; failures were logged.', 'oras-tickets' );
		} elseif ( 'failed' === $status ) {
			$message = __( 'Communication was not sent; the failed attempt was logged when possible.', 'oras-tickets' );
		}

		echo '<div class="oras-board-reports__notice">' . esc_html( $message ) . '</div>';
	}

	/**
	 * @param array<int,\WP_Post> $events
	 * @param array<string,mixed> $filters
	 */
	private static function render_communication_history_filters( int $page_id, array $events, array $filters ): void {
		?>
		<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
			<?php if ( $page_id > 0 ) : ?>
				<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
			<?php endif; ?>
			<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_COMMUNICATIONS ); ?>" />
			<?php self::render_event_filter( $events, absint( $filters['event_id'] ?? 0 ) ); ?>
			<label>
				<?php echo esc_html__( 'Sender User ID', 'oras-tickets' ); ?>
				<input type="number" min="1" name="oras_comm_sender_id" value="<?php echo esc_attr( (string) absint( $filters['sender_user_id'] ?? 0 ) ); ?>" />
			</label>
			<label>
				<?php echo esc_html__( 'Subject Search', 'oras-tickets' ); ?>
				<input type="search" name="oras_comm_search" value="<?php echo esc_attr( (string) ( $filters['search'] ?? '' ) ); ?>" />
			</label>
			<label>
				<?php echo esc_html__( 'Recipient Segment', 'oras-tickets' ); ?>
				<select name="oras_comm_history_segment">
					<option value=""><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
					<?php foreach ( Communication_Recipients::get_segments() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) ( $filters['recipient_segment'] ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<?php echo esc_html__( 'After', 'oras-tickets' ); ?>
				<input type="date" name="oras_comm_after" value="<?php echo esc_attr( (string) ( $filters['after'] ?? '' ) ); ?>" />
			</label>
			<label>
				<?php echo esc_html__( 'Before', 'oras-tickets' ); ?>
				<input type="date" name="oras_comm_before" value="<?php echo esc_attr( (string) ( $filters['before'] ?? '' ) ); ?>" />
			</label>
			<div class="oras-board-reports__actions">
				<button class="button button-primary" type="submit"><?php echo esc_html__( 'Filter History', 'oras-tickets' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function render_communication_history_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<div class="oras-board-reports__empty">' . esc_html__( 'No communication history found.', 'oras-tickets' ) . '</div>';
			return;
		}
		?>
		<div class="oras-board-reports__table-wrap">
			<table>
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Date Sent', 'oras-tickets' ); ?></th>
						<th><?php echo esc_html__( 'Sent By', 'oras-tickets' ); ?></th>
						<th><?php echo esc_html__( 'Recipient Segment', 'oras-tickets' ); ?></th>
						<th><?php echo esc_html__( 'Recipient Count', 'oras-tickets' ); ?></th>
						<th><?php echo esc_html__( 'Subject', 'oras-tickets' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'oras-tickets' ); ?></th>
						<th><?php echo esc_html__( 'View Details', 'oras-tickets' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( self::row_scalar( $row, 'sent_at' ) ); ?></td>
							<td><?php echo esc_html( self::row_scalar( $row, 'sender_display_name' ) . ' <' . self::row_scalar( $row, 'sender_email' ) . '>' ); ?></td>
							<td><?php echo esc_html( Communication_Recipients::get_segment_label( self::row_scalar( $row, 'recipient_segment' ) ) ); ?></td>
							<td><?php echo esc_html( self::row_scalar( $row, 'recipient_count' ) ); ?></td>
							<td><?php echo esc_html( self::row_scalar( $row, 'email_subject' ) ); ?></td>
							<td><?php echo esc_html( self::row_scalar( $row, 'send_status' ) ); ?></td>
							<td><a href="<?php echo esc_url( self::build_communication_detail_url( absint( $row['id'] ?? 0 ) ) ); ?>"><?php echo esc_html__( 'View Details', 'oras-tickets' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function get_current_dashboard_url( string $tab, int $event_id, string $segment = '' ): string {
		$args = array(
			'oras_board_tab'      => $tab,
			'oras_board_event_id' => $event_id,
		);
		if ( '' !== $segment ) {
			$args['oras_comm_segment'] = $segment;
		}

		return add_query_arg( $args, self::get_form_action_url() );
	}

	private static function build_communication_detail_url( int $detail_id ): string {
		$args = $_GET;
		$args['oras_board_tab'] = self::TAB_COMMUNICATIONS;
		$args['oras_comm_detail'] = $detail_id;

		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				unset( $args[ $key ] );
				continue;
			}

			$args[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		return add_query_arg( $args, self::get_form_action_url() );
	}

	private static function redirect_communication_result( string $redirect, string $status, int $log_id ): void {
		$args = array(
			'oras_comm_status' => sanitize_key( $status ),
		);
		if ( $log_id > 0 ) {
			$args['oras_comm_log'] = $log_id;
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	private static function append_email_footer( string $message, \WP_User $sender, int $event_id ): string {
		$footer = "\n\n--\n" . __( 'This message was sent by Oil Region Astronomical Society regarding an ORAS event.', 'oras-tickets' );
		$show_sender = (bool) apply_filters( 'oras_tickets_show_sender_name_in_email_footer', false, $sender, $event_id );
		if ( $show_sender && '' !== (string) $sender->display_name ) {
			$footer .= "\n" . sprintf(
				/* translators: %s: sender display name. */
				__( 'Sent by: %s', 'oras-tickets' ),
				(string) $sender->display_name
			);
		}

		return rtrim( $message ) . $footer;
	}

	private static function get_related_action_type( string $segment ): string {
		if ( Communication_Recipients::SEGMENT_EVENT_CANCELLATION === $segment ) {
			return 'event_cancellation';
		}

		if ( Communication_Recipients::SEGMENT_EVENT_UPDATE === $segment ) {
			return 'event_update';
		}

		return 'mass_email';
	}

	/**
	 * @return array{type:string,event_id:int,after:string,before:string,search:string,status:string,attendance_type:string,approval_status:string,attendee_source:string,ticket_status:string,rsvp_status:string}
	 */
	private static function get_filters_from_request(): array {
		$type = isset( $_GET['oras_board_report_type'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_report_type'] ) ) : Board_Report_Service::TYPE_TICKETS;
		$after = isset( $_GET['oras_board_after'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_after'] ) ) : '';
		$before = isset( $_GET['oras_board_before'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_before'] ) ) : '';
		$attendance_type = isset( $_GET['oras_board_attendance_type'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_attendance_type'] ) ) : 'all';
		if ( ! isset( self::get_attendance_type_options()[ $attendance_type ] ) ) {
			$attendance_type = 'all';
		}
		$approval_status = isset( $_GET['oras_board_approval_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_approval_status'] ) ) : 'all';
		if ( ! isset( self::get_approval_status_options()[ $approval_status ] ) ) {
			$approval_status = 'all';
		}
		$attendee_source = isset( $_GET['oras_board_attendee_source'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_attendee_source'] ) ) : 'all';
		if ( ! isset( self::get_attendee_source_options()[ $attendee_source ] ) ) {
			$attendee_source = 'all';
		}
		$ticket_status = isset( $_GET['oras_board_ticket_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_ticket_status'] ) ) : 'all';
		if ( ! isset( self::get_status_options( Board_Report_Service::TYPE_TICKETS )[ $ticket_status ] ) ) {
			$ticket_status = 'all';
		}
		$rsvp_status = isset( $_GET['oras_board_rsvp_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_rsvp_status'] ) ) : 'all';
		if ( ! isset( self::get_status_options( Board_Report_Service::TYPE_RSVP )[ $rsvp_status ] ) ) {
			$rsvp_status = 'all';
		}

		return array(
			'type'            => $type,
			'event_id'        => isset( $_GET['oras_board_event_id'] ) ? absint( $_GET['oras_board_event_id'] ) : 0,
			'after'           => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ? $after : '',
			'before'          => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ? $before : '',
			'search'          => isset( $_GET['oras_board_search'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_search'] ) ) : '',
			'status'          => isset( $_GET['oras_board_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_status'] ) ) : 'all',
			'attendance_type' => $attendance_type,
			'approval_status' => $approval_status,
			'attendee_source' => $attendee_source,
			'ticket_status'   => $ticket_status,
			'rsvp_status'     => $rsvp_status,
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private static function build_export_url( array $filters, string $format = 'csv' ): string {
		$action = 'oras_board_reports_export_csv';
		if ( 'spreadsheet' === $format ) {
			$action = 'oras_board_reports_export_spreadsheet';
		} elseif ( 'pdf' === $format ) {
			$action = 'oras_board_reports_export_pdf';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'                 => $action,
					'oras_board_report_type' => $filters['type'],
					'oras_board_event_id'    => $filters['event_id'],
					'oras_board_after'       => $filters['after'],
					'oras_board_before'      => $filters['before'],
					'oras_board_search'      => $filters['search'],
					'oras_board_status'      => $filters['status'],
					'oras_board_attendance_type' => $filters['attendance_type'],
					'oras_board_approval_status' => $filters['approval_status'],
					'oras_board_attendee_source' => $filters['attendee_source'],
					'oras_board_ticket_status' => $filters['ticket_status'],
					'oras_board_rsvp_status'  => $filters['rsvp_status'],
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_status_options( string $type ): array {
		if ( Board_Report_Service::TYPE_RSVP === $type ) {
			return array(
				'all'      => __( 'All RSVP rows', 'oras-tickets' ),
				'yes'      => __( 'RSVP Yes', 'oras-tickets' ),
				'waitlist' => __( 'Waitlist', 'oras-tickets' ),
			);
		}

		return array(
			'all'        => __( 'All order statuses', 'oras-tickets' ),
			'completed'  => __( 'Completed', 'oras-tickets' ),
			'processing' => __( 'Processing', 'oras-tickets' ),
			'on-hold'    => __( 'On hold', 'oras-tickets' ),
			'pending'    => __( 'Pending', 'oras-tickets' ),
			'refunded'   => __( 'Refunded', 'oras-tickets' ),
			'cancelled'  => __( 'Cancelled', 'oras-tickets' ),
			'failed'     => __( 'Failed', 'oras-tickets' ),
		);
	}

	private static function type_requires_event( string $type ): bool {
		return in_array(
			$type,
			array( Board_Report_Service::TYPE_TICKETS, Board_Report_Service::TYPE_RSVP ),
			true
		);
	}

	/**
	 * @param array<int,\WP_Post> $events
	 */
	private static function render_event_filter( array $events, int $selected_event_id ): void {
		?>
		<label>
			<?php echo esc_html__( 'Event', 'oras-tickets' ); ?>
			<select name="oras_board_event_id">
				<?php foreach ( $events as $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php selected( $selected_event_id, (int) $event->ID ); ?>><?php echo esc_html( get_the_title( $event ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_attendee_source_options(): array {
		return array(
			'all'     => __( 'All', 'oras-tickets' ),
			'tickets' => __( 'Ticket Holders', 'oras-tickets' ),
			'rsvps'   => __( 'RSVPs', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_attendance_type_options(): array {
		return array(
			'all'                         => __( 'All', 'oras-tickets' ),
			Ticket::ATTENDANCE_MODE_ONSITE => __( 'On Site', 'oras-tickets' ),
			Ticket::ATTENDANCE_MODE_VIRTUAL => __( 'Virtual', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_approval_status_options(): array {
		return array(
			'all'                             => __( 'All', 'oras-tickets' ),
			Event_RSVP::APPROVAL_STATUS_PENDING => __( 'Pending', 'oras-tickets' ),
			Event_RSVP::APPROVAL_STATUS_APPROVED => __( 'Approved', 'oras-tickets' ),
			Event_RSVP::APPROVAL_STATUS_REJECTED => __( 'Rejected', 'oras-tickets' ),
		);
	}

	private static function get_rsvp_status_label( string $status ): string {
		if ( 'waitlist' === $status ) {
			return __( 'Waitlist', 'oras-tickets' );
		}

		if ( 'yes' === $status ) {
			return __( 'RSVP Yes', 'oras-tickets' );
		}

		return '' !== $status ? $status : __( 'Unknown', 'oras-tickets' );
	}

	private static function format_status_suffix( string $status ): string {
		if ( '' === $status ) {
			return '';
		}

		return ' (' . $status . ')';
	}

	/**
	 * @param array<string,mixed> $stats
	 * @return array<string,string>
	 */
	private static function get_statistics_rows( array $stats ): array {
		$approval_counts = isset( $stats['rsvp_approval_counts'] ) && is_array( $stats['rsvp_approval_counts'] )
			? $stats['rsvp_approval_counts']
			: array();
		$status_counts = isset( $stats['ticket_status_counts'] ) && is_array( $stats['ticket_status_counts'] )
			? $stats['ticket_status_counts']
			: array();

		return array(
			__( 'Unified Attendee Rows', 'oras-tickets' ) => (string) absint( $stats['total_attendee_rows'] ?? 0 ),
			__( 'Ticket Quantity', 'oras-tickets' ) => (string) absint( $stats['ticket_quantity'] ?? 0 ),
			__( 'Ticket Orders', 'oras-tickets' ) => (string) absint( $stats['ticket_order_count'] ?? 0 ),
			__( 'Ticket Status Counts', 'oras-tickets' ) => self::format_count_map( $status_counts ),
			__( 'RSVP Yes', 'oras-tickets' ) => (string) absint( $stats['rsvp_yes_count'] ?? 0 ),
			__( 'RSVP Waitlist', 'oras-tickets' ) => (string) absint( $stats['rsvp_waitlist_count'] ?? 0 ),
			__( 'RSVP Approval Counts', 'oras-tickets' ) => self::format_count_map( $approval_counts ),
			__( 'On-site Attendance', 'oras-tickets' ) => (string) absint( $stats['onsite_attendance_count'] ?? 0 ),
			__( 'Virtual Attendance', 'oras-tickets' ) => (string) absint( $stats['virtual_attendance_count'] ?? 0 ),
			__( 'On-site RSVPs', 'oras-tickets' ) => (string) absint( $stats['rsvp_onsite_count'] ?? 0 ),
			__( 'Virtual RSVPs', 'oras-tickets' ) => (string) absint( $stats['rsvp_virtual_count'] ?? 0 ),
			__( 'On-site Tickets', 'oras-tickets' ) => (string) absint( $stats['ticket_onsite_count'] ?? 0 ),
			__( 'Virtual Tickets', 'oras-tickets' ) => (string) absint( $stats['ticket_virtual_count'] ?? 0 ),
		);
	}

	/**
	 * @param array<string,int> $counts
	 */
	private static function format_count_map( array $counts ): string {
		if ( empty( $counts ) ) {
			return '0';
		}

		$parts = array();
		foreach ( $counts as $key => $count ) {
			$parts[] = sanitize_key( (string) $key ) . ': ' . absint( $count );
		}

		return implode( ', ', $parts );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function format_report_cell( array $row, string $key ): string {
		if ( 'question_answers' === $key ) {
			return self::format_question_answer_count( $row['question_answers'] ?? array() );
		}

		return self::row_scalar( $row, $key );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_question_answers_details( array $row ): void {
		$answers = isset( $row['question_answers'] ) && is_array( $row['question_answers'] ) ? $row['question_answers'] : array();
		if ( empty( $answers ) ) {
			echo esc_html__( 'No event question answers.', 'oras-tickets' );
			return;
		}

		echo esc_html( self::format_question_answer_count( $answers ) );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_question_answers_table_row( array $row, int $colspan ): void {
		$answers = isset( $row['question_answers'] ) && is_array( $row['question_answers'] ) ? $row['question_answers'] : array();
		if ( empty( $answers ) ) {
			return;
		}

		echo '<tr class="oras-board-reports__answer-row"><td colspan="' . esc_attr( (string) max( 1, $colspan ) ) . '">';
		self::render_question_answers_summary( $row );
		echo '</td></tr>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_question_answers_summary( array $row ): void {
		$answers = isset( $row['question_answers'] ) && is_array( $row['question_answers'] ) ? $row['question_answers'] : array();
		if ( empty( $answers ) ) {
			return;
		}

		echo '<details class="oras-board-reports__question-answers">';
		echo '<summary class="oras-board-reports__question-answers-summary">' . esc_html( self::format_question_answer_count( $answers ) ) . '</summary>';
		echo '<div class="oras-board-reports__question-answers-title">' . esc_html__( 'Event question answers', 'oras-tickets' ) . '</div>';
		echo '<dl>';
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}

			$label = isset( $answer['label'] ) && is_scalar( $answer['label'] ) ? sanitize_text_field( (string) $answer['label'] ) : '';
			$value = isset( $answer['display_value'] ) && is_scalar( $answer['display_value'] ) ? sanitize_text_field( (string) $answer['display_value'] ) : '';
			if ( '' === $label || '' === $value ) {
				continue;
			}

			echo '<dt>' . esc_html( $label ) . '</dt>';
			echo '<dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
		echo '</details>';
	}

	/**
	 * @param mixed $answers
	 */
	private static function format_question_answer_count( $answers ): string {
		if ( ! is_array( $answers ) ) {
			return '';
		}

		$count = 0;
		foreach ( $answers as $answer ) {
			if ( is_array( $answer ) ) {
				++$count;
			}
		}

		if ( 0 === $count ) {
			return '';
		}

		return sprintf(
			/* translators: %d: event question answer count */
			_n( '%d event question answer', '%d event question answers', $count, 'oras-tickets' ),
			$count
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function row_scalar( array $row, string $key ): string {
		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}

	private static function get_form_action_url(): string {
		$permalink = get_permalink( get_queried_object_id() );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			return $permalink;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';
		$path = strtok( $request_uri, '?' );
		if ( false === $path ) {
			$path = '/';
		}

		return home_url( $path );
	}

	private static function get_current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';

		if ( '' === $request_uri ) {
			return self::get_form_action_url();
		}

		return home_url( esc_url_raw( $request_uri ) );
	}

	private static function get_context_page_id(): int {
		$page_id = get_queried_object_id();
		if ( $page_id > 0 ) {
			return (int) $page_id;
		}

		global $post;
		if ( $post instanceof \WP_Post && $post->ID > 0 ) {
			return (int) $post->ID;
		}

		return 0;
	}
}
