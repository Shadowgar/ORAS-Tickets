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
		$event_id = Config::get_active_event_id();
		$config   = Config::get_event_config( $event_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Registration Desk Settings', 'oras-tickets' ) . '</h1>';
		echo '<p>' . esc_html__( 'Backend configuration only. This is not the final volunteer interface.', 'oras-tickets' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<p><label>Active event ID <input name="event_id" type="number" min="1" value="' . esc_attr( (string) $event_id ) . '"></label></p>';
		echo '<input type="hidden" name="expected_revision" value="' . esc_attr( (string) $config['revision'] ) . '">';
		echo '<p><label>Versioned configuration JSON<br><textarea name="config_json" rows="18" cols="100">' . esc_textarea( (string) wp_json_encode( $config, JSON_PRETTY_PRINT ) ) . '</textarea></label></p>';
		submit_button( esc_html__( 'Save Registration Desk Settings', 'oras-tickets' ) );
		echo '</form></div>';
	}

	public static function save(): void {
		if ( ! current_user_can( 'oras_tickets_manage_registration_desk' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'oras-tickets' ) );
		}
		check_admin_referer( self::ACTION );
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$revision = isset( $_POST['expected_revision'] ) ? absint( wp_unslash( $_POST['expected_revision'] ) ) : 0;
		$json     = isset( $_POST['config_json'] ) ? (string) wp_unslash( $_POST['config_json'] ) : '';
		$decoded  = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			wp_die( esc_html__( 'Configuration must be valid JSON.', 'oras-tickets' ) );
		}
		$saved = Config::save_and_activate( $event_id, $decoded, $revision );
		if ( $saved instanceof \WP_Error ) {
			wp_die( esc_html( $saved->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=oras-registration-desk-settings&updated=1' ) );
		exit;
	}
}
