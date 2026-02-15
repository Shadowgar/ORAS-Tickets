<?php

namespace ORAS\Tickets\Admin;

use ORAS\Tickets\Admin\Metaboxes\Event_Agenda_Metabox;
use ORAS\Tickets\Admin\Metaboxes\Event_RSVP_Metabox;
use ORAS\Tickets\Domain\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Event_Addon_Metabox {

	private const META_BOX_ID = 'oras-events-addon';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 40 );
		add_action( 'add_meta_boxes', array( $this, 'remove_legacy_metaboxes' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_metabox(): void {
		add_meta_box(
			self::META_BOX_ID,
			'ORAS EVENTS ADDON',
			array( $this, 'render_metabox' ),
			Meta::EVENT_POST_TYPE,
			'normal',
			'default'
		);
	}

	public function remove_legacy_metaboxes(): void {
		remove_meta_box( 'oras_tickets_metabox', Meta::EVENT_POST_TYPE, 'normal' );
		remove_meta_box( 'oras_event_agenda_metabox', Meta::EVENT_POST_TYPE, 'normal' );
		remove_meta_box( 'oras_event_rsvp_metabox', Meta::EVENT_POST_TYPE, 'normal' );
		remove_meta_box( 'oras_event_speakers_metabox', Meta::EVENT_POST_TYPE, 'normal' );
		remove_meta_box( 'oras_event_rsvp_attendees_metabox', Meta::EVENT_POST_TYPE, 'normal' );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || Meta::EVENT_POST_TYPE !== $screen->post_type ) {
			return;
		}

		$is_editor = ( 'post' === $screen->base ) || in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
		if ( ! $is_editor ) {
			return;
		}

		wp_enqueue_style(
			'oras-event-addon-metabox',
			ORAS_TICKETS_URL . 'assets/admin/event-addon-metabox.css',
			array(),
			ORAS_TICKETS_VERSION
		);

		wp_enqueue_script(
			'oras-event-addon-metabox',
			ORAS_TICKETS_URL . 'assets/admin/event-addon-metabox.js',
			array(),
			ORAS_TICKETS_VERSION,
			true
		);
	}

	public function render_metabox( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		?>
		<div id="oras-events-addon" class="oras-events-addon" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<div class="oras-events-addon__layout">
				<div class="oras-events-addon__tabs" role="tablist" aria-orientation="vertical">
					<button type="button" class="button-link oras-events-addon__tab is-active" data-tab="tickets" role="tab" aria-selected="true"><?php echo esc_html__( 'Tickets', 'oras-tickets' ); ?></button>
					<button type="button" class="button-link oras-events-addon__tab" data-tab="agenda" role="tab" aria-selected="false"><?php echo esc_html__( 'Agenda', 'oras-tickets' ); ?></button>
					<button type="button" class="button-link oras-events-addon__tab" data-tab="rsvp" role="tab" aria-selected="false"><?php echo esc_html__( 'RSVP', 'oras-tickets' ); ?></button>
					<button type="button" class="button-link oras-events-addon__tab" data-tab="speakers" role="tab" aria-selected="false"><?php echo esc_html__( 'Speakers', 'oras-tickets' ); ?></button>
				</div>
				<div class="oras-events-addon__panels">
					<div class="oras-events-addon__panel is-active" data-panel="tickets" role="tabpanel">
						<?php Tickets_Metabox::instance()->render_metabox( $post ); ?>
					</div>
					<div class="oras-events-addon__panel" data-panel="agenda" role="tabpanel" hidden>
						<?php Event_Agenda_Metabox::render( $post ); ?>
					</div>
					<div class="oras-events-addon__panel" data-panel="rsvp" role="tabpanel" hidden>
						<?php Event_RSVP_Metabox::render( $post ); ?>
					</div>
					<div class="oras-events-addon__panel" data-panel="speakers" role="tabpanel" hidden>
						<?php ( new Event_Speakers_Metabox() )->render_metabox( $post ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
