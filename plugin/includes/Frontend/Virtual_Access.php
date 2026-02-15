<?php

namespace ORAS\Tickets\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Virtual_Access {

	private const META_KEY           = '_oras_virtual_access_v1';
	private const NONCE_ACTION       = 'oras_virtual_access_save';
	private const NONCE_NAME         = 'oras_virtual_access_nonce';
	private const SHOW_TO_EVERYONE   = 'everyone';
	private const SHOW_TO_LOGGED_IN  = 'logged_in';
	private const SHOW_TO_RSVP       = 'rsvp';
	private const SHOW_TO_TICKET     = 'ticket';
	private const SHOW_TO_FREE_TICKET = 'free_ticket';

	public static function register(): void {
		add_filter( 'tribe_template_pre_html:events-pro/admin-views/virtual-metabox/container/show-to', array( self::class, 'filter_admin_show_to_html' ), 20, 4 );
		add_filter( 'tribe_template_pre_html:events-pro/admin-views/virtual-metabox/container/compatibility/event-tickets/show-to', array( self::class, 'filter_admin_show_to_html' ), 20, 4 );

		add_action( 'save_post_tribe_events', array( self::class, 'save_post' ), 20, 1 );

		add_filter( 'tribe_events_virtual_show_virtual_content', array( self::class, 'filter_show_virtual_content' ), 20, 2 );
		add_filter( 'tribe_template_pre_html:events-pro/zoom/zoom-details', array( self::class, 'filter_virtual_details_html' ), 20, 5 );
		add_filter( 'tribe_template_pre_html:events-pro/google/single/google-details', array( self::class, 'filter_virtual_details_html' ), 20, 5 );
		add_filter( 'tribe_template_pre_html:events-pro/webex/single/webex-details', array( self::class, 'filter_virtual_details_html' ), 20, 5 );
		add_filter( 'tribe_template_pre_html:events-pro/microsoft/single/microsoft-details', array( self::class, 'filter_virtual_details_html' ), 20, 5 );
	}

	public static function filter_admin_show_to_html( $html, $file, $name, $template ) {
		unset( $file, $name );

		if ( ! is_object( $template ) || ! method_exists( $template, 'get' ) ) {
			return $html;
		}

		$post = $template->get( 'post' );
		if ( ! $post instanceof \WP_Post ) {
			return $html;
		}

		$event_id      = (int) $post->ID;
		$metabox_id    = (string) $template->get( 'metabox_id' );
		if ( '' === $metabox_id ) {
			$metabox_id = 'tribe-events-virtual';
		}
		$show_to       = self::get_show_to_for_event( $event_id );
		$has_rsvp      = self::event_has_rsvp_enabled( $event_id );
		$has_tickets   = self::event_has_oras_tickets( $event_id );
		$available     = self::available_show_to_values( $event_id );

		if ( ! in_array( $show_to, $available, true ) ) {
			$show_to = self::SHOW_TO_EVERYONE;
		}

		ob_start();
		?>
		<tr class="tribe-events-virtual-show">
			<td class='tribe-table-field-label'><?php esc_html_e( 'Show to:', 'tribe-events-calendar-pro' ); ?></td>
			<td>
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<ul>
					<li>
						<label for="<?php echo esc_attr( $metabox_id . '-oras-show-to-everyone' ); ?>">
							<input
								id="<?php echo esc_attr( $metabox_id . '-oras-show-to-everyone' ); ?>"
								name="oras_virtual_access[show_to]"
								type="radio"
								value="<?php echo esc_attr( self::SHOW_TO_EVERYONE ); ?>"
								<?php checked( self::SHOW_TO_EVERYONE, $show_to ); ?>
							/>
							<?php echo esc_html_x( 'Everyone', 'Show virtual content to all users.', 'tribe-events-calendar-pro' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Visible to anyone — no login or RSVP required.', 'oras-tickets' ); ?></p>
					</li>
					<li>
						<label for="<?php echo esc_attr( $metabox_id . '-oras-show-to-logged-in' ); ?>">
							<input
								id="<?php echo esc_attr( $metabox_id . '-oras-show-to-logged-in' ); ?>"
								name="oras_virtual_access[show_to]"
								type="radio"
								value="<?php echo esc_attr( self::SHOW_TO_LOGGED_IN ); ?>"
								<?php checked( self::SHOW_TO_LOGGED_IN, $show_to ); ?>
							/>
							<?php echo esc_html_x( 'Logged in users', 'Show virtual content to logged-in users only.', 'tribe-events-calendar-pro' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Visible to registered site users only.', 'oras-tickets' ); ?></p>
					</li>
					<?php if ( $has_rsvp ) : ?>
						<li>
							<label for="<?php echo esc_attr( $metabox_id . '-oras-show-to-rsvp' ); ?>">
								<input
									id="<?php echo esc_attr( $metabox_id . '-oras-show-to-rsvp' ); ?>"
									name="oras_virtual_access[show_to]"
									type="radio"
									value="<?php echo esc_attr( self::SHOW_TO_RSVP ); ?>"
									<?php checked( self::SHOW_TO_RSVP, $show_to ); ?>
								/>
								<?php echo esc_html__( 'People who RSVP’d', 'oras-tickets' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Visible only to people who RSVP via Event Tickets for this event.', 'oras-tickets' ); ?></p>
						</li>
					<?php endif; ?>
					<?php if ( $has_tickets ) : ?>
						<li>
							<label for="<?php echo esc_attr( $metabox_id . '-oras-show-to-ticket' ); ?>">
								<input
									id="<?php echo esc_attr( $metabox_id . '-oras-show-to-ticket' ); ?>"
									name="oras_virtual_access[show_to]"
									type="radio"
									value="<?php echo esc_attr( self::SHOW_TO_TICKET ); ?>"
									<?php checked( self::SHOW_TO_TICKET, $show_to ); ?>
								/>
								<?php echo esc_html__( 'People who purchased tickets', 'oras-tickets' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Visible to users who have purchased tickets for this event.', 'oras-tickets' ); ?></p>
						</li>
						<li>
							<label for="<?php echo esc_attr( $metabox_id . '-oras-show-to-free-ticket' ); ?>">
								<input
									id="<?php echo esc_attr( $metabox_id . '-oras-show-to-free-ticket' ); ?>"
									name="oras_virtual_access[show_to]"
									type="radio"
									value="<?php echo esc_attr( self::SHOW_TO_FREE_TICKET ); ?>"
									<?php checked( self::SHOW_TO_FREE_TICKET, $show_to ); ?>
								/>
								<?php echo esc_html__( 'People with free tickets', 'oras-tickets' ); ?>
							</label>
								<p class="description"><?php echo esc_html__( 'Visible only to users with free (zero-cost) ticket purchases for this event.', 'oras-tickets' ); ?></p>
						</li>
					<?php endif; ?>
				</ul>
			</td>
		</tr>
		<?php

		return (string) ob_get_clean();
	}

	public static function save_post( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input         = isset( $_POST['oras_virtual_access'] ) && is_array( $_POST['oras_virtual_access'] ) ? $_POST['oras_virtual_access'] : array();
		$requested     = sanitize_key( $input['show_to'] ?? '' );
		$available     = self::available_show_to_values( $post_id );
		$normalized    = in_array( $requested, $available, true ) ? $requested : self::SHOW_TO_EVERYONE;
		$existing_meta = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $existing_meta ) ) {
			$existing_meta = array();
		}

		$envelope = array_merge(
			$existing_meta,
			array(
				'version' => 1,
				'show_to' => $normalized,
			)
		);

		update_post_meta( $post_id, self::META_KEY, $envelope );
	}

	public static function filter_show_virtual_content( bool $show, $event ): bool {
		$event_id = self::normalize_event_id( $event );
		if ( $event_id <= 0 ) {
			return $show;
		}

		$meta = get_post_meta( $event_id, self::META_KEY, true );
		if ( ! is_array( $meta ) || empty( $meta['show_to'] ) ) {
			return $show;
		}

		return self::user_can_access_event( $event_id, get_current_user_id() );
	}

	public static function filter_virtual_details_html( $html, $file, $name, $template, $context ) {
		unset( $file, $name );

		if ( ! is_object( $template ) || ! method_exists( $template, 'get' ) ) {
			return $html;
		}

		$event    = $context['event'] ?? $template->get( 'event' ) ?? $template->get( 'post' );
		$event_id = self::normalize_event_id( $event );

		if ( $event_id <= 0 ) {
			return $html;
		}

		$meta = get_post_meta( $event_id, self::META_KEY, true );
		if ( ! is_array( $meta ) || empty( $meta['show_to'] ) ) {
			return $html;
		}

		if ( self::user_can_access_event( $event_id, get_current_user_id() ) ) {
			return $html;
		}

		$message = apply_filters(
			'oras_tickets_virtual_access_denied_message',
			__( 'This link is available to attendees. Please RSVP or purchase a ticket.', 'oras-tickets' ),
			$event_id,
			self::get_show_to_for_event( $event_id )
		);

		return '<div class="tribe-events-virtual-access-message">' . esc_html( (string) $message ) . '</div>';
	}

	public static function current_user_can_access_virtual_link( int $event_id ): bool {
		if ( $event_id <= 0 ) {
			return false;
		}

		return self::user_can_access_event( $event_id, get_current_user_id() );
	}

	private static function user_can_access_event( int $event_id, int $user_id ): bool {
		$show_to = self::get_show_to_for_event( $event_id );

		switch ( $show_to ) {
			case self::SHOW_TO_EVERYONE:
				return true;

			case self::SHOW_TO_LOGGED_IN:
				return is_user_logged_in();

			case self::SHOW_TO_RSVP:
				if ( $user_id <= 0 || ! is_user_logged_in() ) {
					return false;
				}
				return 'yes' === (string) get_user_meta( $user_id, '_oras_rsvp_event_' . $event_id, true );

			case self::SHOW_TO_TICKET:
				if ( $user_id <= 0 || ! is_user_logged_in() ) {
					return false;
				}
				return self::user_has_event_ticket_purchase( $event_id, $user_id, false );

			case self::SHOW_TO_FREE_TICKET:
				if ( $user_id <= 0 || ! is_user_logged_in() ) {
					return false;
				}
				return self::user_has_event_ticket_purchase( $event_id, $user_id, true );

			default:
				return true;
		}
	}

	private static function user_has_event_ticket_purchase( int $event_id, int $user_id, bool $free_only ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$product_ids = self::event_ticket_product_ids( $event_id );
		if ( empty( $product_ids ) ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-completed', 'wc-processing' ),
				'limit'       => -1,
			)
		);

		if ( empty( $orders ) ) {
			return false;
		}

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
					continue;
				}

				$product_id = absint( $item->get_product_id() );
				if ( $product_id <= 0 || ! in_array( $product_id, $product_ids, true ) ) {
					continue;
				}

				if ( ! $free_only ) {
					return true;
				}

				$item_total = (float) wc_get_order_item_meta( $item->get_id(), '_line_total', true );
				if ( 0.0 === $item_total ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function event_ticket_product_ids( int $event_id ): array {
		$map         = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
		$product_ids = array();

		if ( is_array( $map ) ) {
			foreach ( $map as $mapped_id ) {
				$product_id = absint( $mapped_id );
				if ( $product_id > 0 ) {
					$product_ids[] = $product_id;
				}
			}
		}

		$product_ids = array_values( array_unique( $product_ids ) );

		if ( ! empty( $product_ids ) ) {
			return $product_ids;
		}

		$fallback_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_oras_ticket_event_id',
				'meta_value'     => (string) $event_id,
			)
		);

		if ( ! is_array( $fallback_ids ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'absint', $fallback_ids ) ) );
	}

	private static function event_has_rsvp_enabled( int $event_id ): bool {
		$rsvp = get_post_meta( $event_id, '_oras_rsvp_v1', true );
		if ( ! is_array( $rsvp ) ) {
			return false;
		}

		return ! empty( $rsvp['enabled'] );
	}

	private static function event_has_oras_tickets( int $event_id ): bool {
		return ! empty( self::event_ticket_product_ids( $event_id ) );
	}

	private static function available_show_to_values( int $event_id ): array {
		$values = array(
			self::SHOW_TO_EVERYONE,
			self::SHOW_TO_LOGGED_IN,
		);

		if ( self::event_has_rsvp_enabled( $event_id ) ) {
			$values[] = self::SHOW_TO_RSVP;
		}

		if ( self::event_has_oras_tickets( $event_id ) ) {
			$values[] = self::SHOW_TO_TICKET;
			$values[] = self::SHOW_TO_FREE_TICKET;
		}

		return $values;
	}

	private static function get_show_to_for_event( int $event_id ): string {
		$meta = get_post_meta( $event_id, self::META_KEY, true );
		if ( ! is_array( $meta ) || empty( $meta['show_to'] ) ) {
			return self::SHOW_TO_EVERYONE;
		}

		$show_to = sanitize_key( (string) $meta['show_to'] );

		if ( ! in_array( $show_to, self::available_show_to_values( $event_id ), true ) ) {
			return self::SHOW_TO_EVERYONE;
		}

		return $show_to;
	}

	private static function normalize_event_id( $event ): int {
		if ( $event instanceof \WP_Post ) {
			return (int) $event->ID;
		}

		if ( is_numeric( $event ) ) {
			return (int) $event;
		}

		return 0;
	}
}
