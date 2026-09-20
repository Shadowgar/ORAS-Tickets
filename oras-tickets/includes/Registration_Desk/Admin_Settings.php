<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Settings {
	private const ACTION = 'oras_registration_desk_save_settings';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'oras-tickets', 'Registration Desk', 'Registration Desk', 'oras_tickets_manage_registration_desk', 'oras-registration-desk-settings', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'oras_tickets_manage_registration_desk' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'oras-tickets' ) );
		}
		$active_event_id = Config::get_active_event_id();
		$requested_event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$event_id = $requested_event_id > 0 && 'tribe_events' === get_post_type( $requested_event_id ) ? $requested_event_id : $active_event_id;
		$config   = Config::get_event_config( $event_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Registration Desk Settings', 'oras-tickets' ) . '</h1>';
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Registration Desk settings saved and activated.', 'oras-tickets' ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Manager PIN', 'oras-tickets' ) . '</h2><p>' . esc_html__( 'Set a four-digit PIN for manager tools inside the kiosk. The PIN is stored as a password hash.', 'oras-tickets' ) . '</p>';
		echo '<p>' . esc_html__( 'Choose an event, review its canonical offerings, add only supplemental desk rules, then save to make it the active desk event.', 'oras-tickets' ) . '</p>';
		$events = get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		echo '<form method="get"><input type="hidden" name="page" value="oras-registration-desk-settings"><label for="oras-desk-event"><strong>' . esc_html__( 'Event to configure', 'oras-tickets' ) . '</strong></label> <select id="oras-desk-event" name="event_id">';
		foreach ( $events as $event ) {
			if ( ! $event instanceof \WP_Post ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $event->ID ) . '" ' . selected( $event_id, $event->ID, false ) . '>' . esc_html( get_the_title( $event ) . ' (#' . $event->ID . ')' ) . '</option>';
		}
		echo '</select> <button class="button" type="submit">' . esc_html__( 'Load event', 'oras-tickets' ) . '</button></form>';
		if ( $event_id <= 0 ) {
			echo '<p>' . esc_html__( 'Create an event before configuring Registration Desk.', 'oras-tickets' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="expected_active_event_id" value="' . esc_attr( (string) $active_event_id ) . '">';
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		echo '<input type="hidden" name="expected_revision" value="' . esc_attr( (string) $config['revision'] ) . '">';
		echo '<p><label><strong>' . esc_html__( 'New manager PIN', 'oras-tickets' ) . '</strong><br><input type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" name="manager_pin" autocomplete="new-password"> <span class="description">' . esc_html__( 'Leave blank to keep the current PIN.', 'oras-tickets' ) . '</span></label></p>';
		echo '<h2>' . esc_html__( 'Event-sale memberships', 'oras-tickets' ) . '</h2><p class="description">' . esc_html__( 'Choose which current PMPro levels volunteers may record at the event. Names, prices, renewal terms, and checkout links always come from PMPro.', 'oras-tickets' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Offer at the desk', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Canonical PMPro level', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Current price and term', 'oras-tickets' ) . '</th></tr></thead><tbody>';
		$enabled_ids = array_map( static fn( array $mapping ): int => (int) $mapping['level_id'], Config::get_membership_mappings() );
		$membership_offerings = Membership_Offering_Resolver::all();
		$available_ids = array_map( static fn( array $offering ): int => (int) $offering['level_id'], $membership_offerings );
		foreach ( $enabled_ids as $missing_id ) {
			if ( ! in_array( $missing_id, $available_ids, true ) ) {
				/* translators: %d: missing PMPro membership level ID. */
				$missing_name = sprintf( __( 'Unavailable PMPro level #%d', 'oras-tickets' ), $missing_id );
				$membership_offerings[] = array(
					'level_id'     => $missing_id,
					'display_name' => $missing_name,
					'price'        => '',
					'period_label' => __( 'Not selectable at the desk', 'oras-tickets' ),
				);
			}
		}
		foreach ( $membership_offerings as $mapping_index => $offering ) {
			$name = 'membership_levels[' . (int) $mapping_index . ']';
			$level_id = (int) $offering['level_id'];
			echo '<tr><td><input type="hidden" name="' . esc_attr( $name . '[level_id]' ) . '" value="' . esc_attr( (string) $level_id ) . '"><input type="hidden" name="' . esc_attr( $name . '[event_sale_enabled]' ) . '" value="0"><label><input type="checkbox" name="' . esc_attr( $name . '[event_sale_enabled]' ) . '" value="1" ' . checked( in_array( $level_id, $enabled_ids, true ), true, false ) . '> ' . esc_html__( 'Enabled', 'oras-tickets' ) . '</label></td><td><strong>' . esc_html( (string) $offering['display_name'] ) . '</strong><br><code>#' . esc_html( (string) $level_id ) . '</code></td><td>';
			if ( '' !== (string) $offering['price'] ) {
				echo esc_html( '$' . number_format( (float) $offering['price'], 2 ) . ' · ' . (string) $offering['period_label'] );
			} else {
				echo esc_html( (string) $offering['period_label'] );
			}
			echo '</td></tr>';
		}
		if ( empty( $membership_offerings ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No PMPro membership levels are currently available.', 'oras-tickets' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html( get_the_title( $event_id ) ) . '</h2><p><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $config['enabled'] ), true, false ) . '> ' . esc_html__( 'Enable the volunteer desk for this event', 'oras-tickets' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Names, descriptions, prices, attendance modes, sale windows, and stock come directly from the event ticket configuration and cannot be overridden here.', 'oras-tickets' ) . '</p>';
		$rules = array();
		foreach ( $config['ticket_rules'] as $rule ) {
			$rules[ (string) $rule['ticket_key'] ] = $rule;
		}
		$offerings = Event_Offering_Resolver::resolve_for_event( $event_id );
		if ( ! empty( $offerings ) ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Canonical ticket', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Current sale state', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Supplemental coverage', 'oras-tickets' ) . '</th></tr></thead><tbody>';
			foreach ( $offerings as $index => $offering ) {
				self::render_ticket_rule( (int) $index, $offering, $rules[ (string) $offering['ticket_key'] ] ?? array() );
			}
			echo '</tbody></table>';
		} else {
			$rsvp = get_post_meta( $event_id, '_oras_rsvp_v1', true );
			echo '<p>' . esc_html( is_array( $rsvp ) && ! empty( $rsvp['enabled'] ) ? __( 'This RSVP-only event will use its canonical RSVP capacity and waitlist settings.', 'oras-tickets' ) : __( 'This event has no canonical ticket or RSVP offerings.', 'oras-tickets' ) ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Cross-event entitlements', 'oras-tickets' ) . '</h3><p class="description">' . esc_html__( 'These mappings admit existing purchasers only. They never create walk-in products for this event.', 'oras-tickets' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source event and product', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Coverage at this event', 'oras-tickets' ) . '</th></tr></thead><tbody>';
		$entitlements   = $config['entitlements'];
		$entitlements[] = array();
		foreach ( $entitlements as $index => $entitlement ) {
			self::render_entitlement( (int) $index, is_array( $entitlement ) ? $entitlement : array() );
		}
		echo '</tbody></table>';
		submit_button( esc_html__( 'Save and activate this event', 'oras-tickets' ) );
		echo '</form></div>';
	}

	public static function save(): void {
		if ( ! current_user_can( 'oras_tickets_manage_registration_desk' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'oras-tickets' ) );
		}
		check_admin_referer( self::ACTION );
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$revision = isset( $_POST['expected_revision'] ) ? absint( wp_unslash( $_POST['expected_revision'] ) ) : 0;
		$expected_active_event_id = isset( $_POST['expected_active_event_id'] ) ? absint( wp_unslash( $_POST['expected_active_event_id'] ) ) : 0;
		$posted_rules = isset( $_POST['ticket_rules'] ) && is_array( $_POST['ticket_rules'] ) ? wp_unslash( $_POST['ticket_rules'] ) : array();
		$posted_entitlements = isset( $_POST['entitlements'] ) && is_array( $_POST['entitlements'] ) ? wp_unslash( $_POST['entitlements'] ) : array();
		$manager_pin = isset( $_POST['manager_pin'] ) ? sanitize_text_field( wp_unslash( $_POST['manager_pin'] ) ) : '';
		if ( '' !== $manager_pin ) {
			$pin_saved = Manager_Access::set_pin( $manager_pin );
			if ( $pin_saved instanceof \WP_Error ) {
				wp_die( esc_html( $pin_saved->get_error_message() ) );
			}
		}
		$posted_mappings = isset( $_POST['membership_levels'] ) && is_array( $_POST['membership_levels'] ) ? wp_unslash( $_POST['membership_levels'] ) : array();
		update_option( Config::MEMBERSHIP_MAPPINGS_OPTION, Config::normalize_membership_mappings( $posted_mappings ), false );
		$ticket_rules = array();
		foreach ( $posted_rules as $posted ) {
			if ( ! is_array( $posted ) ) {
				continue;
			}
			$ticket_key = sanitize_text_field( (string) ( $posted['ticket_key'] ?? '' ) );
			if ( '' === $ticket_key ) {
				continue;
			}
			$ticket_rules[] = array(
				'ticket_key'       => $ticket_key,
				'classification'   => sanitize_key( (string) ( $posted['classification'] ?? '' ) ),
				'validity_type'    => sanitize_key( (string) ( $posted['validity_type'] ?? '' ) ),
				'valid_local_date' => sanitize_text_field( (string) ( $posted['valid_local_date'] ?? '' ) ),
				'max_attendees'    => absint( $posted['max_attendees'] ?? 1 ),
			);
		}
		$entitlements = array();
		foreach ( $posted_entitlements as $posted ) {
			if ( ! is_array( $posted ) || absint( $posted['source_event_id'] ?? 0 ) <= 0 || absint( $posted['source_product_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$entitlements[] = array(
				'entitlement_uuid'  => sanitize_text_field( (string) ( $posted['entitlement_uuid'] ?? '' ) ),
				'source_event_id'   => absint( $posted['source_event_id'] ),
				'source_product_id' => absint( $posted['source_product_id'] ),
				'classification'    => sanitize_key( (string) ( $posted['classification'] ?? '' ) ),
				'validity_type'     => sanitize_key( (string) ( $posted['validity_type'] ?? '' ) ),
				'valid_local_date'  => sanitize_text_field( (string) ( $posted['valid_local_date'] ?? '' ) ),
				'max_attendees'     => absint( $posted['max_attendees'] ?? 1 ),
			);
		}
		$saved = Config::save_and_activate(
			$event_id,
			array(
				'enabled'      => ! empty( $_POST['enabled'] ),
				'ticket_rules' => $ticket_rules,
				'entitlements' => $entitlements,
				// Hidden compatibility data remains available to historical records but is never selectable.
				'options'      => Config::get_event_config( $event_id )['options'],
			),
			$revision,
			$expected_active_event_id
		);
		if ( $saved instanceof \WP_Error ) {
			wp_die( esc_html( $saved->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=oras-registration-desk-settings&updated=1' ) );
		exit;
	}

	/** @param array<string,mixed> $offering @param array<string,mixed> $rule */
	private static function render_ticket_rule( int $index, array $offering, array $rule ): void {
		$name = 'ticket_rules[' . $index . ']';
		echo '<tr><td><input type="hidden" name="' . esc_attr( $name . '[ticket_key]' ) . '" value="' . esc_attr( (string) $offering['ticket_key'] ) . '"><strong>' . esc_html( (string) $offering['name'] ) . '</strong><br><code>' . esc_html( (string) $offering['ticket_key'] ) . '</code><br>' . esc_html( '$' . number_format( (float) $offering['price'], 2 ) ) . ' · ' . esc_html( (string) $offering['attendance_mode'] ) . '</td>';
		echo '<td>' . esc_html( (string) $offering['availability_label'] ) . '<br>' . esc_html( (string) ( $offering['phase_label'] ?? '' ) ) . '</td><td>';
		self::render_coverage_fields( $name, $rule );
		echo '</td></tr>';
	}

	/** @param array<string,mixed> $entitlement */
	private static function render_entitlement( int $index, array $entitlement ): void {
		$name = 'entitlements[' . $index . ']';
		echo '<tr><td><input type="hidden" name="' . esc_attr( $name . '[entitlement_uuid]' ) . '" value="' . esc_attr( (string) ( $entitlement['entitlement_uuid'] ?? '' ) ) . '"><label>' . esc_html__( 'Source event ID', 'oras-tickets' ) . ' <input type="number" min="1" name="' . esc_attr( $name . '[source_event_id]' ) . '" value="' . esc_attr( (string) ( $entitlement['source_event_id'] ?? '' ) ) . '"></label><br><label>' . esc_html__( 'Source product ID', 'oras-tickets' ) . ' <input type="number" min="1" name="' . esc_attr( $name . '[source_product_id]' ) . '" value="' . esc_attr( (string) ( $entitlement['source_product_id'] ?? '' ) ) . '"></label></td><td>';
		self::render_coverage_fields( $name, $entitlement );
		echo '</td></tr>';
	}

	/** @param array<string,mixed> $values */
	private static function render_coverage_fields( string $name, array $values ): void {
		$classification = (string) ( $values['classification'] ?? 'individual' );
		$validity       = (string) ( $values['validity_type'] ?? 'full_event' );
		echo '<select name="' . esc_attr( $name . '[classification]' ) . '"><option value="individual" ' . selected( $classification, 'individual', false ) . '>' . esc_html__( 'Individual', 'oras-tickets' ) . '</option><option value="family" ' . selected( $classification, 'family', false ) . '>' . esc_html__( 'Family', 'oras-tickets' ) . '</option></select> <label>' . esc_html__( 'Maximum attendees', 'oras-tickets' ) . ' <input type="number" min="1" max="20" name="' . esc_attr( $name . '[max_attendees]' ) . '" value="' . esc_attr( (string) ( $values['max_attendees'] ?? 1 ) ) . '" style="width:5em"></label><br><select name="' . esc_attr( $name . '[validity_type]' ) . '"><option value="full_event" ' . selected( $validity, 'full_event', false ) . '>' . esc_html__( 'Full event', 'oras-tickets' ) . '</option><option value="one_day" ' . selected( $validity, 'one_day', false ) . '>' . esc_html__( 'One day', 'oras-tickets' ) . '</option></select> <input type="date" name="' . esc_attr( $name . '[valid_local_date]' ) . '" value="' . esc_attr( (string) ( $values['valid_local_date'] ?? '' ) ) . '">';
	}
}
