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
		add_action( 'save_post_tribe_events', array( self::class, 'save' ), 100, 1 );
	}

	public static function render( \WP_Post $post ): void {
		$stored   = get_post_meta( $post->ID, self::META_KEY, true );
		$defaults = Settings::get();
		if ( ! is_array( $stored ) ) {
			$config = array(
				'enabled'           => ! empty( $defaults['default_managed_registration'] ),
				'meeting_id'        => '',
				'unattended_access' => true,
			);
		} else {
			$config = array_merge(
				array(
					'enabled'           => false,
					'meeting_id'        => '',
					'unattended_access' => false,
					'sync_status'       => '',
					'sync_message'      => '',
					'synced_at'         => '',
				),
				$stored
			);
		}

		$enabled           = ! empty( $config['enabled'] );
		$unattended_access = ! empty( $config['unattended_access'] );
		$meeting_id        = sanitize_text_field( (string) ( $config['meeting_id'] ?? '' ) );
		$resolved_id       = Meeting_Service::resolve_meeting_id( $post->ID );
		$sync_status       = sanitize_key( (string) ( $config['sync_status'] ?? '' ) );
		$sync_message      = sanitize_text_field( (string) ( $config['sync_message'] ?? '' ) );
		$synced_at         = sanitize_text_field( (string) ( $config['synced_at'] ?? '' ) );
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
				<label>
					<input
						type="checkbox"
						name="oras_event_zoom[unattended_access]"
						value="1"
						<?php checked( $unattended_access ); ?>
					/>
					<strong><?php echo esc_html__( 'Allow approved attendees to join anytime without a host', 'oras-tickets' ); ?></strong>
				</label>
			</p>
			<p class="description">
				<?php
				echo esc_html__(
					'ORAS will enable join-before-host for any time and disable the Zoom Waiting Room. Host controls remain unavailable until an authorized host joins.',
					'oras-tickets'
				);
				?>
			</p>
			<p class="description">
				<?php
				echo esc_html__(
					'Turning this option off stops ORAS from managing unattended access but does not reverse settings already applied in Zoom.',
					'oras-tickets'
				);
				?>
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

			<?php if ( $enabled && $unattended_access && '' !== $resolved_id ) : ?>
				<p>
					<a
						class="button button-secondary"
						href="<?php echo esc_url( self::sync_url( $post->ID ) ); ?>"
					>
						<?php echo esc_html__( 'Sync Zoom Settings', 'oras-tickets' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $sync_status || '' !== $sync_message ) : ?>
				<div class="notice inline <?php echo 'success' === $sync_status ? 'notice-success' : 'notice-error'; ?>">
					<p>
						<strong>
							<?php
							echo esc_html(
								'success' === $sync_status
									? __( 'Unattended Zoom access synchronized.', 'oras-tickets' )
									: __( 'Unattended Zoom access is not synchronized.', 'oras-tickets' )
							);
							?>
						</strong>
						<?php if ( '' !== $sync_message ) : ?>
							<?php echo esc_html( ' ' . $sync_message ); ?>
						<?php endif; ?>
						<?php if ( '' !== $synced_at ) : ?>
							<?php
							echo esc_html(
								' ' . sprintf(
									/* translators: %s: UTC synchronization timestamp */
									__( 'Last attempt: %s UTC.', 'oras-tickets' ),
									$synced_at
								)
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>
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
		$existing = get_post_meta( $post_id, self::META_KEY, true );
		$existing = is_array( $existing ) ? $existing : array();

		$config = array_merge(
			$existing,
			array(
				'version'           => 1,
				'enabled'           => ! empty( $input['enabled'] ),
				'meeting_id'        => $meeting_id,
				'unattended_access' => ! empty( $input['unattended_access'] ),
			)
		);

		if ( empty( $config['enabled'] ) || empty( $config['unattended_access'] ) ) {
			$config['sync_status']  = '';
			$config['sync_message'] = '';
			$config['synced_at']    = '';
			update_post_meta( $post_id, Registration_Service::EVENT_CONFIG_META, $config );
			return;
		}

		$config['sync_status']  = 'pending';
		$config['sync_message'] = __(
			'Zoom synchronization is queued and will run after the event save completes.',
			'oras-tickets'
		);
		$config['synced_at']    = '';
		$config['sync_revision'] = wp_generate_uuid4();
		update_post_meta( $post_id, Registration_Service::EVENT_CONFIG_META, $config );
		self::queue_unattended_access_sync( $post_id, (string) $config['sync_revision'] );
	}

	/**
	 * Synchronize and persist the event's unattended Zoom access status.
	 *
	 * @return true|\WP_Error
	 */
	public static function synchronize_unattended_access(
		int $event_id,
		?Meeting_Service $meeting_service = null,
		string $expected_revision = ''
	) {
		$config = get_post_meta( $event_id, self::META_KEY, true );
		$config = is_array( $config ) ? $config : array();

		if ( '' !== $expected_revision
			&& ! hash_equals( $expected_revision, (string) ( $config['sync_revision'] ?? '' ) )
		) {
			return self::stale_sync_error();
		}

		if ( empty( $config['enabled'] ) || empty( $config['unattended_access'] ) ) {
			return self::store_sync_error(
				$event_id,
				$config,
				new \WP_Error(
					'oras_zoom_unattended_not_enabled',
					__( 'Enable Zoom attendee automation and unattended access before synchronizing.', 'oras-tickets' )
				)
			);
		}

		if ( ! Settings::is_enabled() || ! Settings::has_credentials() ) {
			return self::store_sync_error(
				$event_id,
				$config,
				new \WP_Error(
					'oras_zoom_integration_not_configured',
					__( 'Configure and enable the ORAS Zoom integration before synchronizing this event.', 'oras-tickets' )
				)
			);
		}

		$meeting_id = Meeting_Service::resolve_meeting_id( $event_id );
		if ( '' === $meeting_id ) {
			return self::store_sync_error(
				$event_id,
				$config,
				new \WP_Error(
					'oras_zoom_missing_meeting_id',
					__( 'This event is not mapped to a Zoom meeting.', 'oras-tickets' )
				)
			);
		}

		$meeting_service = $meeting_service ?? new Meeting_Service();
		$result          = $meeting_service->configure_unattended_access_for_meeting( $meeting_id );
		$latest          = get_post_meta( $event_id, self::META_KEY, true );
		$latest          = is_array( $latest ) ? $latest : array();
		if ( empty( $latest['enabled'] )
			|| empty( $latest['unattended_access'] )
			|| $meeting_id !== Meeting_Service::resolve_meeting_id( $event_id )
			|| ( '' !== $expected_revision
				&& ! hash_equals( $expected_revision, (string) ( $latest['sync_revision'] ?? '' ) )
			)
		) {
			return self::stale_sync_error();
		}

		if ( is_wp_error( $result ) ) {
			return self::store_sync_error( $event_id, $latest, $result );
		}

		$latest['sync_status']  = 'success';
		$latest['sync_message'] = __(
			'Approved attendees can enter at any time without waiting for a host.',
			'oras-tickets'
		);
		$latest['synced_at']    = current_time( 'mysql', true );
		update_post_meta( $event_id, self::META_KEY, $latest );

		return true;
	}

	public static function queue_unattended_access_sync(
		int $event_id,
		string $sync_revision,
		int $attempt = 0,
		int $delay_seconds = 0
	): void {
		$hook = 'oras_tickets_zoom_sync_event_async';
		$args = array( $event_id, $sync_revision, $attempt );

		if ( $delay_seconds <= 0 && function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' )
				|| ! as_has_scheduled_action( $hook, $args, 'oras-tickets' )
			) {
				as_enqueue_async_action( $hook, $args, 'oras-tickets' );
			}
			return;
		}

		if ( $delay_seconds > 0 && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' )
				|| ! as_has_scheduled_action( $hook, $args, 'oras-tickets' )
			) {
				as_schedule_single_action(
					time() + $delay_seconds,
					$hook,
					$args,
					'oras-tickets'
				);
			}
			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + max( 1, $delay_seconds ), $hook, $args );
		}
	}

	public static function mark_retry_scheduled(
		int $event_id,
		string $sync_revision,
		int $attempt,
		int $delay_seconds
	): void {
		$config = get_post_meta( $event_id, self::META_KEY, true );
		$config = is_array( $config ) ? $config : array();
		if ( '' === $sync_revision
			|| ! hash_equals( $sync_revision, (string) ( $config['sync_revision'] ?? '' ) )
		) {
			return;
		}

		$config['sync_status']  = 'pending';
		$config['sync_message'] = sprintf(
			/* translators: 1: retry attempt number, 2: delay in seconds */
			__( 'Zoom synchronization retry %1$d is scheduled in %2$d seconds.', 'oras-tickets' ),
			$attempt,
			$delay_seconds
		);
		update_post_meta( $event_id, self::META_KEY, $config );
	}

	private static function sync_url( int $event_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'oras_tickets_zoom_sync_event',
					'event_id' => $event_id,
				),
				admin_url( 'admin-post.php' )
			),
			'oras_tickets_zoom_sync_event_' . $event_id
		);
	}

	/**
	 * @param array<string,mixed> $config
	 * @return \WP_Error
	 */
	private static function store_sync_error( int $event_id, array $config, \WP_Error $error ): \WP_Error {
		$config['sync_status']  = 'error';
		$config['sync_message'] = sanitize_text_field( $error->get_error_message() );
		$config['synced_at']    = current_time( 'mysql', true );
		update_post_meta( $event_id, self::META_KEY, $config );

		return $error;
	}

	private static function stale_sync_error(): \WP_Error {
		return new \WP_Error(
			'oras_zoom_stale_sync',
			__( 'A newer Zoom event configuration replaced this synchronization request.', 'oras-tickets' )
		);
	}
}
