<?php

namespace ORAS\Tickets\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {


	private const OPTION_KEY            = 'oras_tickets_settings';
	private const OPTION_SPEAKER_NOTIFY = 'oras_tickets_speaker_notify_emails';

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$updated = false;
		if ( isset( $_POST['oras_tickets_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['oras_tickets_settings_nonce'] ), 'oras_tickets_settings' ) ) {
			$restore       = isset( $_POST['oras_tickets_restore_on_cancel_refund'] ) ? 1 : 0;
			$notify_emails = isset( $_POST['oras_tickets_speaker_notify_emails'] )
			? sanitize_text_field( wp_unslash( $_POST['oras_tickets_speaker_notify_emails'] ) )
			: '';
			update_option( self::OPTION_KEY, array( 'restore_on_cancel_refund' => $restore ) );
			update_option( self::OPTION_SPEAKER_NOTIFY, $notify_emails );
			$updated = true;
		}

		$settings        = get_option( self::OPTION_KEY, array( 'restore_on_cancel_refund' => 1 ) );
		$restore_enabled = isset( $settings['restore_on_cancel_refund'] ) ? (bool) $settings['restore_on_cancel_refund'] : true;
		$notify_emails   = (string) get_option( self::OPTION_SPEAKER_NOTIFY, '' );

		?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'ORAS Tickets Settings', 'oras-tickets' ); ?></h1>

		<?php if ( $updated ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'Settings saved.', 'oras-tickets' ); ?></p>
		</div>
		<?php endif; ?>

		<form method="post">
		<?php wp_nonce_field( 'oras_tickets_settings', 'oras_tickets_settings_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Restore stock on cancellation/refund', 'oras-tickets' ); ?></th>
				<td>
				<label>
					<input type="checkbox" name="oras_tickets_restore_on_cancel_refund" value="1" <?php checked( $restore_enabled ); ?> />
					<?php echo esc_html__( 'Enable automatic stock restoration for cancelled or refunded orders.', 'oras-tickets' ); ?>
				</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Speaker fulfillment notification emails', 'oras-tickets' ); ?></th>
				<td>
				<input type="text" class="regular-text" name="oras_tickets_speaker_notify_emails" value="<?php echo esc_attr( $notify_emails ); ?>" />
				<p class="description"><?php echo esc_html__( 'Comma-separated list. Leave blank to disable notifications.', 'oras-tickets' ); ?></p>
				</td>
			</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Save Settings', 'oras-tickets' ) ); ?>
		</form>
	</div>
		<?php
	}
}
