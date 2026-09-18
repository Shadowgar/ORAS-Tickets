<?php

namespace ORAS\Tickets\Registration_Desk;

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
		echo '<p>' . esc_html__( 'Choose an event, configure its registration options, then save to make it the active desk event.', 'oras-tickets' ) . '</p>';
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
		echo '<h2>' . esc_html( get_the_title( $event_id ) ) . '</h2><p><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $config['enabled'] ), true, false ) . '> ' . esc_html__( 'Enable the volunteer desk for this event', 'oras-tickets' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Product and source event IDs are explicit mappings. Leave them blank for walk-in-only options. No event-specific values are built into the plugin.', 'oras-tickets' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Option', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Access', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Type and validity', 'oras-tickets' ) . '</th><th>' . esc_html__( 'Explicit source mappings', 'oras-tickets' ) . '</th></tr></thead><tbody>';
		$options = $config['options'];
		$options[] = array();
		foreach ( $options as $index => $option ) {
			self::render_option( (int) $index, is_array( $option ) ? $option : array() );
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
		$posted_options = isset( $_POST['options'] ) && is_array( $_POST['options'] ) ? wp_unslash( $_POST['options'] ) : array();
		$options = array();
		foreach ( $posted_options as $posted ) {
			if ( ! is_array( $posted ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $posted['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$uuid = strtolower( sanitize_text_field( (string) ( $posted['option_uuid'] ?? '' ) ) );
			if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid ) ) {
				$uuid = wp_generate_uuid4();
			}
			$options[] = array(
				'option_uuid'           => $uuid,
				'label'                 => $label,
				'available_for_new'     => ! empty( $posted['available_for_new'] ),
				'existing_access_valid' => ! empty( $posted['existing_access_valid'] ),
				'classification'        => sanitize_key( (string) ( $posted['classification'] ?? '' ) ),
				'validity_type'         => sanitize_key( (string) ( $posted['validity_type'] ?? '' ) ),
				'valid_local_date'      => sanitize_text_field( (string) ( $posted['valid_local_date'] ?? '' ) ),
				'max_attendees'         => absint( $posted['max_attendees'] ?? 1 ),
				'source_product_ids'    => self::id_list( (string) ( $posted['source_product_ids'] ?? '' ) ),
				'source_event_ids'      => self::id_list( (string) ( $posted['source_event_ids'] ?? '' ) ),
			);
		}
		$saved = Config::save_and_activate(
			$event_id,
			array(
				'enabled' => ! empty( $_POST['enabled'] ),
				'options' => $options,
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

	/** @param array<string,mixed> $option */
	private static function render_option( int $index, array $option ): void {
		$name = 'options[' . $index . ']';
		$classification = (string) ( $option['classification'] ?? 'individual' );
		$validity = (string) ( $option['validity_type'] ?? 'full_event' );
		echo '<tr><td><input type="hidden" name="' . esc_attr( $name . '[option_uuid]' ) . '" value="' . esc_attr( (string) ( $option['option_uuid'] ?? '' ) ) . '"><label>' . esc_html__( 'Label', 'oras-tickets' ) . '<br><input name="' . esc_attr( $name . '[label]' ) . '" value="' . esc_attr( (string) ( $option['label'] ?? '' ) ) . '"></label></td>';
		echo '<td><label><input type="checkbox" name="' . esc_attr( $name . '[available_for_new]' ) . '" value="1" ' . checked( ! empty( $option['available_for_new'] ), true, false ) . '> ' . esc_html__( 'New desk registrations', 'oras-tickets' ) . '</label><br><label><input type="checkbox" name="' . esc_attr( $name . '[existing_access_valid]' ) . '" value="1" ' . checked( ! empty( $option['existing_access_valid'] ), true, false ) . '> ' . esc_html__( 'Existing access valid', 'oras-tickets' ) . '</label></td>';
		echo '<td><select name="' . esc_attr( $name . '[classification]' ) . '"><option value="individual" ' . selected( $classification, 'individual', false ) . '>' . esc_html__( 'Individual', 'oras-tickets' ) . '</option><option value="family" ' . selected( $classification, 'family', false ) . '>' . esc_html__( 'Family', 'oras-tickets' ) . '</option></select> <label>' . esc_html__( 'Maximum attendees', 'oras-tickets' ) . ' <input type="number" min="1" max="20" name="' . esc_attr( $name . '[max_attendees]' ) . '" value="' . esc_attr( (string) ( $option['max_attendees'] ?? 1 ) ) . '" style="width:5em"></label><br><select name="' . esc_attr( $name . '[validity_type]' ) . '"><option value="full_event" ' . selected( $validity, 'full_event', false ) . '>' . esc_html__( 'Full event', 'oras-tickets' ) . '</option><option value="one_day" ' . selected( $validity, 'one_day', false ) . '>' . esc_html__( 'One day', 'oras-tickets' ) . '</option></select> <input type="date" name="' . esc_attr( $name . '[valid_local_date]' ) . '" value="' . esc_attr( (string) ( $option['valid_local_date'] ?? '' ) ) . '"></td>';
		echo '<td><label>' . esc_html__( 'Woo product IDs', 'oras-tickets' ) . '<br><input name="' . esc_attr( $name . '[source_product_ids]' ) . '" value="' . esc_attr( implode( ', ', $option['source_product_ids'] ?? array() ) ) . '"></label><br><label>' . esc_html__( 'Source event IDs', 'oras-tickets' ) . '<br><input name="' . esc_attr( $name . '[source_event_ids]' ) . '" value="' . esc_attr( implode( ', ', $option['source_event_ids'] ?? array() ) ) . '"></label></td></tr>';
	}

	/** @return array<int,int> */
	private static function id_list( string $value ): array {
		$parts = preg_split( '/[^0-9]+/', $value );

		return array_values( array_unique( array_filter( array_map( 'absint', false !== $parts ? $parts : array() ) ) ) );
	}
}
