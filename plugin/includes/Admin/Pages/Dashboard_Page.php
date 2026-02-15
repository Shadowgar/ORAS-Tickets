<?php

namespace ORAS\Tickets\Admin\Pages;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dashboard_Page {

	public function render(): void {

		$events = $this->get_events_with_tickets();
		$rsvp_events = $this->get_events_with_rsvp();
		$all_events = $this->get_all_events();

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

		?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'ORAS Tickets Dashboard', 'oras-tickets' ); ?></h1>

		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=oras-tickets&tab=overview' ) ); ?>" class="nav-tab <?php echo $current_tab === 'overview' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Overview', 'oras-tickets' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=oras-tickets&tab=rsvp' ) ); ?>" class="nav-tab <?php echo $current_tab === 'rsvp' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'RSVP Management', 'oras-tickets' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=oras-tickets&tab=attendees' ) ); ?>" class="nav-tab <?php echo $current_tab === 'attendees' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Attendees', 'oras-tickets' ); ?></a>
		</nav>

		<?php if ( $current_tab === 'overview' ) : ?>
			<table class="widefat striped">
			<thead>
				<tr>
				<th><?php echo esc_html__( 'Event', 'oras-tickets' ); ?></th>
				<th><?php echo esc_html__( 'Ticket Count', 'oras-tickets' ); ?></th>
				<th><?php echo esc_html__( 'Any Sold Out', 'oras-tickets' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $events ) ) : ?>
				<tr>
					<td colspan="3"><?php echo esc_html__( 'No events found.', 'oras-tickets' ); ?></td>
				</tr>
				<?php else : ?>
					<?php
					foreach ( $events as $event_id ) :
						$title     = get_the_title( $event_id );
						$edit_link = get_edit_post_link( $event_id );
						$envelope  = Ticket_Collection::load_envelope_for_event( $event_id );
						$tickets   = isset( $envelope['tickets'] ) && is_array( $envelope['tickets'] ) ? $envelope['tickets'] : array();
						$count     = count( $tickets );
						$sold_out  = $this->has_sold_out_limited_ticket( $event_id );
						?>
					<tr>
					<td>
						<?php if ( $edit_link ) : ?>
						<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $title ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) $count ); ?></td>
					<td><?php echo esc_html( $sold_out ? __( 'Yes', 'oras-tickets' ) : __( 'No', 'oras-tickets' ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			</table>
		<?php elseif ( $current_tab === 'rsvp' ) : ?>
			<h2><?php echo esc_html__( 'RSVP Management', 'oras-tickets' ); ?></h2>
			<p><?php echo esc_html__( 'Select an event to manage RSVPs.', 'oras-tickets' ); ?></p>
			<select id="oras-rsvp-event-selector">
				<option value=""><?php echo esc_html__( 'Select Event', 'oras-tickets' ); ?></option>
				<?php foreach ( $rsvp_events as $event_id ) : ?>
					<option value="<?php echo esc_attr( (string) $event_id ); ?>"><?php echo esc_html( get_the_title( $event_id ) ); ?></option>
				<?php endforeach; ?>
			</select>

			<div id="oras-rsvp-stats" style="display:none;">
				<h3><?php echo esc_html__( 'RSVP Stats', 'oras-tickets' ); ?></h3>
				<p><strong><?php echo esc_html__( 'Capacity:', 'oras-tickets' ); ?></strong> <span id="oras-rsvp-capacity"></span></p>
				<p><strong><?php echo esc_html__( 'Yes Count:', 'oras-tickets' ); ?></strong> <span id="oras-rsvp-yes-count"></span></p>
				<p><strong><?php echo esc_html__( 'Waitlist Count:', 'oras-tickets' ); ?></strong> <span id="oras-rsvp-waitlist-count"></span></p>
				<p><strong><?php echo esc_html__( 'Is Full:', 'oras-tickets' ); ?></strong> <span id="oras-rsvp-is-full"></span></p>
			</div>

			<div id="oras-rsvp-actions" style="display:none;">
				<h3><?php echo esc_html__( 'Actions', 'oras-tickets' ); ?></h3>
				<button id="oras-rsvp-export-yes" class="button"><?php echo esc_html__( 'Export YES CSV', 'oras-tickets' ); ?></button>
				<button id="oras-rsvp-export-waitlist" class="button"><?php echo esc_html__( 'Export WAITLIST CSV', 'oras-tickets' ); ?></button>
				<button id="oras-rsvp-promote" class="button"><?php echo esc_html__( 'Promote from Waitlist', 'oras-tickets' ); ?></button>
			</div>

			<div id="oras-rsvp-list" style="display:none;">
				<h3><?php echo esc_html__( 'Attendee List', 'oras-tickets' ); ?></h3>
				<table class="widefat striped" id="oras-rsvp-attendees-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Name', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Email', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'oras-tickets' ); ?></th>
						</tr>
					</thead>
					<tbody id="oras-rsvp-attendees-body">
					</tbody>
				</table>
			</div>
		<?php elseif ( $current_tab === 'attendees' ) : ?>
			<h2><?php echo esc_html__( 'Attendee List', 'oras-tickets' ); ?></h2>
			<p><?php echo esc_html__( 'Select an event to view attendees.', 'oras-tickets' ); ?></p>

			<div style="margin-bottom: 20px;">
				<label for="oras-attendees-event-selector"><?php echo esc_html__( 'Event:', 'oras-tickets' ); ?></label>
				<select id="oras-attendees-event-selector" style="margin-left: 10px;">
					<option value=""><?php echo esc_html__( 'Select Event', 'oras-tickets' ); ?></option>
					<?php foreach ( $all_events as $event_id ) : ?>
						<option value="<?php echo esc_attr( (string) $event_id ); ?>"><?php echo esc_html( get_the_title( $event_id ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div id="oras-attendees-filters" style="display:none; margin-bottom: 20px;">
				<label for="oras-attendees-source-filter"><?php echo esc_html__( 'Source:', 'oras-tickets' ); ?></label>
				<select id="oras-attendees-source-filter" style="margin-left: 10px; margin-right: 20px;">
					<option value="all"><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
					<option value="tickets"><?php echo esc_html__( 'Tickets', 'oras-tickets' ); ?></option>
					<option value="rsvp"><?php echo esc_html__( 'RSVP', 'oras-tickets' ); ?></option>
					<option value="both"><?php echo esc_html__( 'Both', 'oras-tickets' ); ?></option>
				</select>

				<label for="oras-attendees-ticket-status-filter"><?php echo esc_html__( 'Ticket Status:', 'oras-tickets' ); ?></label>
				<select id="oras-attendees-ticket-status-filter" style="margin-left: 10px; margin-right: 20px;">
					<option value="all"><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
					<option value="completed"><?php echo esc_html__( 'Completed', 'oras-tickets' ); ?></option>
					<option value="processing"><?php echo esc_html__( 'Processing', 'oras-tickets' ); ?></option>
					<option value="on-hold"><?php echo esc_html__( 'On Hold', 'oras-tickets' ); ?></option>
					<option value="refunded"><?php echo esc_html__( 'Refunded', 'oras-tickets' ); ?></option>
					<option value="cancelled"><?php echo esc_html__( 'Cancelled', 'oras-tickets' ); ?></option>
					<option value="failed"><?php echo esc_html__( 'Failed', 'oras-tickets' ); ?></option>
				</select>

				<label for="oras-attendees-guests-only">
					<input type="checkbox" id="oras-attendees-guests-only" style="margin-left: 10px; margin-right: 5px;" />
					<?php echo esc_html__( 'Guests only', 'oras-tickets' ); ?>
				</label>

				<label for="oras-attendees-has-note-only">
					<input type="checkbox" id="oras-attendees-has-note-only" style="margin-left: 10px; margin-right: 5px;" />
					<?php echo esc_html__( 'Has note only', 'oras-tickets' ); ?>
				</label>

				<label for="oras-attendees-search"><?php echo esc_html__( 'Search:', 'oras-tickets' ); ?></label>
				<input type="text" id="oras-attendees-search" placeholder="<?php echo esc_attr__( 'Name or email...', 'oras-tickets' ); ?>" style="margin-left: 10px;" />

				<button id="oras-attendees-export-csv" class="button" style="margin-left: 20px;"><?php echo esc_html__( 'Export CSV', 'oras-tickets' ); ?></button>
			</div>

			<div id="oras-attendees-message-panel" style="display:none; margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
				<h3><?php echo esc_html__( 'Message Attendees', 'oras-tickets' ); ?></h3>
				<p><label for="oras-attendees-message-subject"><?php echo esc_html__( 'Subject:', 'oras-tickets' ); ?></label></p>
				<input type="text" id="oras-attendees-message-subject" style="width: 100%;" required />

				<p><label for="oras-attendees-message-body"><?php echo esc_html__( 'Message:', 'oras-tickets' ); ?></label></p>
				<textarea id="oras-attendees-message-body" rows="6" style="width: 100%;" required></textarea>

				<p>
					<label><input type="checkbox" id="oras-attendees-message-bcc" checked /> <?php echo esc_html__( 'BCC recipients', 'oras-tickets' ); ?></label>
					<label style="margin-left: 20px;"><input type="checkbox" id="oras-attendees-message-cc-me" /> <?php echo esc_html__( 'CC me', 'oras-tickets' ); ?></label>
				</p>

				<button id="oras-attendees-send-email" class="button button-primary"><?php echo esc_html__( 'Send Email', 'oras-tickets' ); ?></button>
			</div>

			<div id="oras-attendees-table-container" style="display:none;">
				<table class="widefat striped" id="oras-attendees-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Name', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Email', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Source', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'User ID', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Order ID', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Order Status', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Note', 'oras-tickets' ); ?></th>
							<th><?php echo esc_html__( 'Actions', 'oras-tickets' ); ?></th>
						</tr>
					</thead>
					<tbody id="oras-attendees-body">
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
		<?php
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
			)
		);

		return is_array( $query ) ? $query : array();
	}

	/**
	 * @return int[]
	 */
	private function get_all_events(): array {
		$query = get_posts(
			array(
				'post_type'      => Meta::EVENT_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return is_array( $query ) ? $query : array();
	}

	/**
	 * @return int[]
	 */
	private function get_events_with_rsvp(): array {
		$query = get_posts(
			array(
				'post_type'      => Meta::EVENT_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => '_oras_rsvp_v1',
				'meta_compare'   => 'EXISTS',
			)
		);

		return is_array( $query ) ? $query : array();
	}

	private function has_sold_out_limited_ticket( int $event_id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$map = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
		if ( ! is_array( $map ) ) {
			return false;
		}

		foreach ( $map as $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id <= 0 ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$managing_stock = method_exists( $product, 'managing_stock' )
			? (bool) $product->managing_stock()
			: ( method_exists( $product, 'get_manage_stock' ) ? (bool) $product->get_manage_stock() : false );

			if ( ! $managing_stock ) {
				continue;
			}

			$stock_qty    = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : null;
			$stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';

			if ( ( null !== $stock_qty && $stock_qty <= 0 ) || $stock_status === 'outofstock' ) {
				return true;
			}
		}

		return false;
	}
}
