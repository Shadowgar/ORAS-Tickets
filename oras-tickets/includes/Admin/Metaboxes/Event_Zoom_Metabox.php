<?php

namespace ORAS\Tickets\Admin\Metaboxes;

use ORAS\Tickets\Integrations\Zoom\Meeting_Service;
use ORAS\Tickets\Integrations\Zoom\Registration_Service;
use ORAS\Tickets\Integrations\Zoom\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Event_Zoom_Metabox {

	private const META_KEY     = '_oras_zoom_integration_v1';
	private const NONCE_ACTION = 'oras_event_zoom_save';
	private const NONCE_NAME   = 'oras_event_zoom_nonce';

	public static function register(): void {
		add_action( 'save_post_tribe_events', array( self::class, 'save' ), 25, 1 );
	}

	public static function render( \WP_Post $post ): void {
		$config   = get_post_meta( $post->ID, self::META_KEY, true );
		$defaults = Settings::get();
		if ( ! is_array( $config ) ) {
			$config = array(
				'enabled'    => ! empty( $defaults['default_managed_registration'] ),
				'meeting_id' => '',
			);
		}

		$enabled     = ! empty( $config['enabled'] );
		$meeting_id  = sanitize_text_field( (string) ( $config['meeting_id'] ?? '' ) );
		$resolved_id = Meeting_Service::resolve_meeting_id( $post->ID );
		?>
		<div class="oras-zoom-event-settings">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<h3><?php echo esc_html__( 'Zoom attendee automation', 'oras-tickets' ); ?></h3>
			<p class="description">
				<?php echo esc_html__( 'Create and configure the Zoom meeting in The Events Calendar Virtual Event panel. ORAS uses that meeting for invitations and attendee-specific registration.', 'oras-tickets' ); ?>
			</p>

			<p>
				<label>
					<input type="checkbox" name="oras_event_zoom[enabled]" value="1" <?php checked( $enabled ); ?> />
					<strong><?php echo esc_html__( 'Manage virtual attendees through Zoom registration', 'oras-tickets' ); ?></strong>
				</label>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Paid virtual ticket buyers are registered automatically. Virtual RSVP attendees are registered only after board approval.', 'oras-tickets' ); ?>
			</p>

			<p>
				<label for="oras-event-zoom-meeting-id">
					<strong><?php echo esc_html__( 'Zoom Meeting ID override', 'oras-tickets' ); ?></strong>
				</label><br />
				<input
					id="oras-event-zoom-meeting-id"
					name="oras_event_zoom[meeting_id]"
					type="text"
					inputmode="numeric"
					class="regular-text"
					value="<?php echo esc_attr( $meeting_id ); ?>"
					placeholder="<?php echo esc_attr__( 'Automatically detected from the TEC Zoom link', 'oras-tickets' ); ?>"
				/>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Leave blank unless ORAS cannot detect the meeting created by The Events Calendar.', 'oras-tickets' ); ?>
			</p>

			<div class="notice inline <?php echo '' !== $resolved_id ? 'notice-success' : 'notice-warning'; ?>">
				<p>
					<?php
					echo '' !== $resolved_id
						? esc_html(
							sprintf(
								/* translators: %s: Zoom meeting ID */
								__( 'Resolved Zoom Meeting ID: %s', 'oras-tickets' ),
								$resolved_id
							)
						)
						: esc_html__( 'No Zoom Meeting ID is currently detectable. Configure the virtual meeting in The Events Calendar or enter an override.', 'oras-tickets' );
					?>
				</p>
			</div>
		</div>
		<?php
	}

	public static function save( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
		) {
			return;
		}

		$input = isset( $_POST['oras_event_zoom'] ) && is_array( $_POST['oras_event_zoom'] )
			? wp_unslash( $_POST['oras_event_zoom'] )
			: array();
		$meeting_id = preg_replace( '/\D+/', '', (string) ( $input['meeting_id'] ?? '' ) );
		$meeting_id = is_string( $meeting_id ) && preg_match( '/^\d{9,12}$/', $meeting_id )
			? $meeting_id
			: '';

		update_post_meta(
			$post_id,
			Registration_Service::EVENT_CONFIG_META,
			array(
				'version'    => 1,
				'enabled'    => ! empty( $input['enabled'] ),
				'meeting_id' => $meeting_id,
			)
		);
	}
}
