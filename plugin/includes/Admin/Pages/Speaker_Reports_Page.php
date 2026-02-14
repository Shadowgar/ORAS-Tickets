<?php

namespace ORAS\Tickets\Admin\Pages;

use ORAS\Tickets\Domain\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Speaker_Reports_Page {

	private const META_KEY           = '_oras_speakers_v1';
	private const META_EVENT_START   = '_EventStartDate';
	private const META_SPEAKER_EMAIL = '_oras_speaker_email';
	private const NONCE_ACTION       = 'oras_speaker_reports_export_csv';
	private const NONCE_NAME         = 'oras_speaker_reports_export_nonce';

	public function register(): void {
		add_action( 'admin_post_oras_speaker_reports_export_csv', array( $this, 'handle_export_csv' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$filters = $this->get_filters( $_GET );
		$rows    = $this->get_report_rows( $filters );

		?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Speaker Reports', 'oras-tickets' ); ?></h1>

		<form method="get" class="oras-speaker-reports-filters">
		<input type="hidden" name="page" value="oras-tickets-speaker-reports" />
		<label>
			<?php echo esc_html__( 'Date from', 'oras-tickets' ); ?>
			<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
		</label>
		<label>
			<?php echo esc_html__( 'Date to', 'oras-tickets' ); ?>
			<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
		</label>
		<label>
			<?php echo esc_html__( 'Compensation', 'oras-tickets' ); ?>
			<select name="compensation_type">
			<option value="all" <?php selected( $filters['compensation_type'], 'all' ); ?>><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
			<option value="fee" <?php selected( $filters['compensation_type'], 'fee' ); ?>><?php echo esc_html__( 'Fee', 'oras-tickets' ); ?></option>
			<option value="membership" <?php selected( $filters['compensation_type'], 'membership' ); ?>><?php echo esc_html__( 'Membership', 'oras-tickets' ); ?></option>
			</select>
		</label>
		<label>
			<?php echo esc_html__( 'Status', 'oras-tickets' ); ?>
			<select name="status">
			<option value="all" <?php selected( $filters['status'], 'all' ); ?>><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
			<option value="unfulfilled" <?php selected( $filters['status'], 'unfulfilled' ); ?>><?php echo esc_html__( 'Unfulfilled', 'oras-tickets' ); ?></option>
			<option value="fulfilled" <?php selected( $filters['status'], 'fulfilled' ); ?>><?php echo esc_html__( 'Fulfilled', 'oras-tickets' ); ?></option>
			</select>
		</label>
		<?php submit_button( __( 'Filter', 'oras-tickets' ), 'secondary', '', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
		<input type="hidden" name="action" value="oras_speaker_reports_export_csv" />
		<input type="hidden" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
		<input type="hidden" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
		<input type="hidden" name="compensation_type" value="<?php echo esc_attr( $filters['compensation_type'] ); ?>" />
		<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
		<?php submit_button( __( 'Export CSV', 'oras-tickets' ), 'secondary', 'export', false ); ?>
		</form>

		<table class="widefat striped">
		<thead>
			<tr>
			<th><?php echo esc_html__( 'Event', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Event start', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Speaker', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Role', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Primary', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Compensation', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Compensation value', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Fulfilled', 'oras-tickets' ); ?></th>
			<th><?php echo esc_html__( 'Fulfilled date', 'oras-tickets' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
			<tr>
				<td colspan="9"><?php echo esc_html__( 'No records found.', 'oras-tickets' ); ?></td>
			</tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
				<tr>
				<td>
					<?php if ( ! empty( $row['event_edit_link'] ) ) : ?>
					<a href="<?php echo esc_url( $row['event_edit_link'] ); ?>"><?php echo esc_html( $row['event_title'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $row['event_title'] ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $row['event_start'] ); ?></td>
				<td>
					<?php if ( ! empty( $row['speaker_edit_link'] ) ) : ?>
					<a href="<?php echo esc_url( $row['speaker_edit_link'] ); ?>"><?php echo esc_html( $row['speaker_name'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $row['speaker_name'] ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $row['role'] ); ?></td>
				<td><?php echo esc_html( $row['is_primary'] ? __( 'Yes', 'oras-tickets' ) : __( 'No', 'oras-tickets' ) ); ?></td>
				<td><?php echo esc_html( $row['compensation_type'] ); ?></td>
				<td><?php echo esc_html( $row['compensation_value'] ); ?></td>
				<td><?php echo esc_html( $row['fulfilled'] ? __( 'Yes', 'oras-tickets' ) : __( 'No', 'oras-tickets' ) ); ?></td>
				<td><?php echo esc_html( $row['fulfilled_date'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
		</table>
	</div>
		<?php
	}

	public function handle_export_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ) );
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ) );
		}

		$filters = $this->get_filters( $_POST );
		$rows    = $this->get_report_rows( $filters );

		$filename = 'oras-speaker-reports-' . wp_date( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		if ( $output === false ) {
			exit;
		}

		fputcsv(
			$output,
			array(
				'event_id',
				'event_title',
				'event_start',
				'speaker_id',
				'speaker_name',
				'speaker_email',
				'role',
				'is_primary',
				'compensation_type',
				'fee_amount',
				'pmpro_level_id',
				'pmpro_level_name',
				'fulfilled',
				'fulfilled_date',
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					$row['event_id'],
					$row['event_title'],
					$row['event_start_raw'],
					$row['speaker_id'],
					$row['speaker_name'],
					$row['speaker_email'],
					$row['role'],
					$row['is_primary'] ? '1' : '0',
					$row['compensation_type'],
					$row['fee_amount'],
					$row['pmpro_level_id'],
					$row['pmpro_level_name'],
					$row['fulfilled'] ? '1' : '0',
					$row['fulfilled_date'],
				)
			);
		}

		fclose( $output );
		exit;
	}

	private function get_filters( array $request ): array {
		$default_from = wp_date( 'Y-m-d', strtotime( '-12 months' ) );
		$default_to   = wp_date( 'Y-m-d', strtotime( '+12 months' ) );

		$date_from         = isset( $request['date_from'] ) ? sanitize_text_field( wp_unslash( $request['date_from'] ) ) : '';
		$date_to           = isset( $request['date_to'] ) ? sanitize_text_field( wp_unslash( $request['date_to'] ) ) : '';
		$compensation_type = isset( $request['compensation_type'] ) ? sanitize_key( wp_unslash( $request['compensation_type'] ) ) : 'all';
		$status            = isset( $request['status'] ) ? sanitize_key( wp_unslash( $request['status'] ) ) : 'all';

		if ( $date_from === '' ) {
			$date_from = $default_from;
		}

		if ( $date_to === '' ) {
			$date_to = $default_to;
		}

		if ( ! in_array( $compensation_type, array( 'all', 'fee', 'membership' ), true ) ) {
			$compensation_type = 'all';
		}

		if ( ! in_array( $status, array( 'all', 'unfulfilled', 'fulfilled' ), true ) ) {
			$status = 'all';
		}

		return array(
			'date_from'         => $date_from,
			'date_to'           => $date_to,
			'compensation_type' => $compensation_type,
			'status'            => $status,
		);
	}

	private function get_report_rows( array $filters ): array {
		$date_from = $filters['date_from'];
		$date_to   = $filters['date_to'];

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => self::META_KEY,
				'compare' => 'EXISTS',
			),
		);

		if ( $date_from !== '' ) {
			$meta_query[] = array(
				'key'     => self::META_EVENT_START,
				'value'   => $date_from . ' 00:00:00',
				'compare' => '>=',
				'type'    => 'DATETIME',
			);
		}

		if ( $date_to !== '' ) {
			$meta_query[] = array(
				'key'     => self::META_EVENT_START,
				'value'   => $date_to . ' 23:59:59',
				'compare' => '<=',
				'type'    => 'DATETIME',
			);
		}

		$events = get_posts(
			array(
				'post_type'      => Meta::EVENT_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query,
				'orderby'        => 'meta_value',
				'meta_key'       => self::META_EVENT_START,
				'order'          => 'ASC',
			)
		);

		$rows = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof \WP_Post ) {
				continue;
			}

			$event_id    = (int) $event->ID;
			$assignments = get_post_meta( $event_id, self::META_KEY, true );
			if ( ! is_array( $assignments ) ) {
				continue;
			}

			$event_start_display = $this->get_event_start_display( $event_id );
			$event_start_raw     = (string) get_post_meta( $event_id, self::META_EVENT_START, true );
			$event_title         = $event->post_title !== '' ? $event->post_title : __( '(Untitled)', 'oras-tickets' );
			$event_edit_link     = get_edit_post_link( $event_id );

			foreach ( $assignments as $assignment ) {
				if ( ! is_array( $assignment ) ) {
					continue;
				}

				$speaker_id = isset( $assignment['speaker_id'] ) ? (int) $assignment['speaker_id'] : 0;
				if ( $speaker_id <= 0 ) {
					continue;
				}

				$fulfilled      = ! empty( $assignment['fulfilled'] );
				$fulfilled_date = isset( $assignment['fulfilled_date'] ) ? (string) $assignment['fulfilled_date'] : '';

				if ( $filters['status'] === 'fulfilled' && ! $fulfilled ) {
					continue;
				}

				if ( $filters['status'] === 'unfulfilled' && $fulfilled ) {
					continue;
				}

				$compensation_type = isset( $assignment['compensation_type'] ) ? (string) $assignment['compensation_type'] : 'none';
				$fee_amount        = isset( $assignment['fee_amount'] ) ? (float) $assignment['fee_amount'] : 0.0;
				$pmpro_level_id    = isset( $assignment['pmpro_level_id'] ) ? (int) $assignment['pmpro_level_id'] : 0;

				if ( $filters['compensation_type'] !== 'all' && $filters['compensation_type'] !== $compensation_type ) {
					continue;
				}

				$compensation_value = $this->format_compensation_value( $compensation_type, $fee_amount, $pmpro_level_id );
				$pmpro_level_name   = $this->resolve_level_name( $pmpro_level_id );

				$speaker           = get_post( $speaker_id );
				$speaker_name      = $speaker instanceof \WP_Post && $speaker->post_title !== ''
				? $speaker->post_title
				: __( '(Unknown)', 'oras-tickets' );
				$speaker_edit_link = get_edit_post_link( $speaker_id );
				$speaker_email     = (string) get_post_meta( $speaker_id, self::META_SPEAKER_EMAIL, true );

				$rows[] = array(
					'event_id'           => $event_id,
					'event_title'        => $event_title,
					'event_edit_link'    => $event_edit_link,
					'event_start'        => $event_start_display,
					'event_start_raw'    => $event_start_raw,
					'speaker_id'         => $speaker_id,
					'speaker_name'       => $speaker_name,
					'speaker_edit_link'  => $speaker_edit_link,
					'speaker_email'      => $speaker_email,
					'role'               => isset( $assignment['role'] ) ? (string) $assignment['role'] : '',
					'is_primary'         => ! empty( $assignment['is_primary'] ),
					'compensation_type'  => $compensation_type,
					'compensation_value' => $compensation_value,
					'fee_amount'         => $fee_amount,
					'pmpro_level_id'     => $pmpro_level_id,
					'pmpro_level_name'   => $pmpro_level_name,
					'fulfilled'          => $fulfilled,
					'fulfilled_date'     => $fulfilled_date,
				);
			}
		}

		return $rows;
	}

	private function format_compensation_value( string $compensation_type, float $fee_amount, int $pmpro_level_id ): string {
		if ( $compensation_type === 'fee' ) {
			return $this->format_fee( $fee_amount );
		}

		if ( $compensation_type === 'membership' ) {
			if ( $pmpro_level_id <= 0 ) {
				return '—';
			}

			$level_name = $this->resolve_level_name( $pmpro_level_id );
			if ( $level_name !== '' ) {
				return $level_name . ' (#' . $pmpro_level_id . ')';
			}

			return (string) $pmpro_level_id;
		}

		return '-';
	}

	private function resolve_level_name( int $pmpro_level_id ): string {
		if ( $pmpro_level_id <= 0 ) {
			return '';
		}

		if ( ! function_exists( 'pmpro_getLevel' ) ) {
			return '';
		}

		$level = pmpro_getLevel( $pmpro_level_id );
		if ( ! $level || ! isset( $level->name ) ) {
			return '';
		}

		return (string) $level->name;
	}

	private function get_event_start_display( int $event_id ): string {
		if ( function_exists( 'tribe_get_start_date' ) ) {
			return (string) tribe_get_start_date( $event_id, false, 'M j, Y g:i a' );
		}

		$start_raw = (string) get_post_meta( $event_id, self::META_EVENT_START, true );
		if ( $start_raw === '' ) {
			return '';
		}

		$timestamp = strtotime( $start_raw );
		if ( ! $timestamp ) {
			return $start_raw;
		}

		return wp_date( 'M j, Y g:i a', $timestamp );
	}

	private function format_fee( float $amount ): string {
		$formatted = number_format( $amount, 2 );

		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$currency_symbol = (string) get_woocommerce_currency_symbol();
			if ( $currency_symbol !== '' ) {
				return $currency_symbol . $formatted;
			}
		}

		return '$' . $formatted;
	}
}
