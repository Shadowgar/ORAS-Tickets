<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Reporting\Board_Report_Exporter;
use ORAS\Tickets\Reporting\Board_Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Board_Reports {

	private const NONCE_ACTION = 'oras_board_reports_export';

	public static function register(): void {
		add_shortcode( 'oras_board_reports', array( self::class, 'render_shortcode' ) );
		add_action( 'admin_post_oras_board_reports_export_csv', array( self::class, 'handle_export_csv' ) );
		add_action( 'admin_post_oras_board_reports_export_spreadsheet', array( self::class, 'handle_export_spreadsheet' ) );
		add_action( 'admin_post_oras_board_reports_export_pdf', array( self::class, 'handle_export_pdf' ) );
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
		$page_id = self::get_context_page_id();

		$rows = $service->get_rows( $filters['type'], $filters );
		$spreadsheet_export_url = self::build_export_url( $filters, 'spreadsheet' );
		$pdf_export_url = self::build_export_url( $filters, 'pdf' );

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
				html.oras-dark-on .oras-board-reports label,
				html[data-wp-dark-mode-active] .oras-board-reports label,
				body.wp-dark-mode-active .oras-board-reports label {
					color: #e6edf7;
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
				}
			</style>

			<h2><?php echo esc_html__( 'Board Reports', 'oras-tickets' ); ?></h2>
			<p class="oras-board-reports__notice"><?php echo esc_html__( 'This report excludes payment method, transaction, card, and accounting details.', 'oras-tickets' ); ?></p>

			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
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
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<?php foreach ( Board_Report_Exporter::COLUMNS as $key => $label ) : ?>
										<td><?php echo esc_html( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '' ); ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
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

	/**
	 * @return array{type:string,event_id:int,after:string,before:string,search:string,status:string}
	 */
	private static function get_filters_from_request(): array {
		$type = isset( $_GET['oras_board_report_type'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_report_type'] ) ) : Board_Report_Service::TYPE_TICKETS;
		$after = isset( $_GET['oras_board_after'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_after'] ) ) : '';
		$before = isset( $_GET['oras_board_before'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_before'] ) ) : '';

		return array(
			'type'     => $type,
			'event_id' => isset( $_GET['oras_board_event_id'] ) ? absint( $_GET['oras_board_event_id'] ) : 0,
			'after'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ? $after : '',
			'before'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ? $before : '',
			'search'   => isset( $_GET['oras_board_search'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_search'] ) ) : '',
			'status'   => isset( $_GET['oras_board_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_status'] ) ) : 'all',
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
