<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ticket_Print_Controller {





	private static ?Ticket_Print_Controller $instance = null;

	public static function instance(): Ticket_Print_Controller {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function init(): void {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_print_page' ), 1 );

		if ( did_action( 'init' ) ) {
			$this->register_rewrite();
		} else {
			add_action( 'init', array( $this, 'register_rewrite' ) );
		}
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'oras_ticket_print';
		$vars[] = 'order_id';
		$vars[] = 'event_id';

		return $vars;
	}

	public function register_rewrite(): void {
		add_rewrite_rule( '^oras-ticket/print/?$', 'index.php?oras_ticket_print=1', 'top' );
	}

	public function maybe_render_print_page(): void {
		if ( ! $this->is_print_request() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			$this->deny();
		}

		$order_id = $this->get_request_int( 'order_id' );
		$event_id = $this->get_request_int( 'event_id' );

		if ( $order_id <= 0 || $event_id <= 0 ) {
			$this->deny();
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			$this->deny();
		}

		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof \WC_Order ) ) {
			$this->deny();
		}

		/** @var \WC_Order $order */

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || (int) $order->get_user_id() !== $user_id ) {
			$this->deny();
		}

		$event = get_post( $event_id );
		if ( ! $event || ! isset( $event->post_type ) || $event->post_type !== Meta::EVENT_POST_TYPE ) {
			$this->deny();
		}

		$items = $this->get_oras_items_for_event( $order, $event_id );
		if ( empty( $items ) ) {
			$this->deny();
		}

		$event_title = (string) get_the_title( $event_id );
		$event_start = $this->get_event_start( $event_id );

		$this->render_page(
			array(
				'event_title' => $event_title,
				'event_start' => $event_start,
				'event_id'    => $event_id,
				'order_id'    => $order_id,
				'items'       => $items,
			)
		);
	}

	private function is_print_request(): bool {
		$flag = get_query_var( 'oras_ticket_print' );
		if ( $flag !== '' && $flag !== null ) {
			return true;
		}

		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH )
			: '';

		return rtrim( $path, '/' ) === '/oras-ticket/print';
	}

	private function get_request_int( string $key ): int {
		$value = get_query_var( $key );
		if ( $value === '' || $value === null ) {
			$raw   = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
			$value = is_scalar( $raw ) ? (string) $raw : '';
		}

		return absint( $value );
	}

	private function deny(): void {
		wp_die( '', '', array( 'response' => 403 ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_oras_items_for_event( \WC_Order $order, int $event_id ): array {
		$items      = array();
		$line_items = $order->get_items( 'line_item' );

		foreach ( $line_items as $item ) {
			if ( ! $item || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			$context = $this->get_item_ticket_context( $item );
			if ( $context['event_id'] !== $event_id ) {
				continue;
			}

			if ( $context['ticket_name'] === '' ) {
				$context['ticket_name'] = $this->get_ticket_name_from_collection( $event_id, $context['ticket_index'] );
			}

			$items[] = $context;
		}

		return $items;
	}

	/**
	 * @param \WC_Order_Item_Product|\WC_Order_Item $item
	 * @return array{event_id:int,ticket_index:int,ticket_name:string,quantity:int,unit_price:float,currency:string,phase_label:string}
	 */
	private function get_item_ticket_context( $item ): array {
		$event_id     = $item->get_meta( '_oras_ticket_event_id', true );
		$ticket_index = $item->get_meta( '_oras_ticket_index', true );

		if ( $event_id === '' || $event_id === null || (int) $event_id <= 0 ) {
			$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			if ( $product_id > 0 ) {
				$event_id     = get_post_meta( $product_id, '_oras_ticket_event_id', true );
				$ticket_index = get_post_meta( $product_id, '_oras_ticket_index', true );
			}
		}

		$ticket_name = (string) $item->get_meta( '_oras_ticket_name', true );
		if ( $ticket_name === '' ) {
			$ticket_name = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
		}

		$quantity = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;

		$unit_price = (float) $item->get_meta( '_oras_ticket_unit_price', true );
		if ( $unit_price <= 0 && method_exists( $item, 'get_subtotal' ) ) {
			$subtotal   = (float) $item->get_subtotal();
			$unit_price = $quantity > 0 ? $subtotal / $quantity : 0.0;
		}

		$currency = (string) $item->get_meta( '_oras_ticket_currency', true );
		if ( $currency === '' && method_exists( $item, 'get_order' ) ) {
			$order    = $item->get_order();
			$currency = $order ? (string) $order->get_currency() : '';
		}

		$phase_label = (string) $item->get_meta( '_oras_ticket_price_phase_label', true );
		if ( $phase_label === '' ) {
			$phase_label = (string) $item->get_meta( '_oras_ticket_price_phase_key', true );
		}
		if ( $phase_label === '' ) {
			$phase_label = __( 'Standard', 'oras-tickets' );
		}

		return array(
			'event_id'     => (int) $event_id,
			'ticket_index' => (int) $ticket_index,
			'ticket_name'  => $ticket_name,
			'quantity'     => $quantity,
			'unit_price'   => $unit_price,
			'currency'     => $currency,
			'phase_label'  => $phase_label,
		);
	}

	private function get_ticket_name_from_collection( int $event_id, int $index ): string {
		if ( $event_id <= 0 || $index < 0 ) {
			return '';
		}

		$collection = Ticket_Collection::load_for_event( $event_id );
		$tickets    = $collection->all();

		if ( ! array_key_exists( $index, $tickets ) ) {
			return '';
		}

		$ticket_obj = $tickets[ $index ];
		$ticket     = $ticket_obj->to_array();

		if ( isset( $ticket['name'] ) && $ticket['name'] !== '' ) {
			return (string) $ticket['name'];
		}

		return '';
	}

	private function get_event_start( int $event_id ): ?string {
		if ( $event_id <= 0 ) {
			return null;
		}

		if ( function_exists( 'tribe_get_start_date' ) ) {
			$start = tribe_get_start_date( $event_id, false, 'c' );
			if ( is_string( $start ) && $start !== '' ) {
				return $start;
			}
		}

		$raw = get_post_meta( $event_id, '_EventStartDateUTC', true );
		if ( $raw === '' ) {
			$raw = get_post_meta( $event_id, '_EventStartDate', true );
		}

		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}

		$timestamp = strtotime( $raw );
		if ( ! $timestamp ) {
			return null;
		}

		return wp_date( 'c', $timestamp );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function render_page( array $data ): void {
		status_header( 200 );
		nocache_headers();

		$css_url   = ORAS_TICKETS_URL . 'assets/frontend/print-ticket.css';
		$view_path = ORAS_TICKETS_DIR . 'includes/Frontend/Print_Ticket_View.php';

		echo '<!doctype html>';
		echo '<html lang="en">';
		echo '<head>';
		echo '<meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html__( 'Ticket Print', 'oras-tickets' ) . '</title>';
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '?ver=' . esc_attr( ORAS_TICKETS_VERSION ) . '">';
		echo '</head>';
		echo '<body class="oras-ticket-print-body">';
		echo '<main class="oras-ticket-print-main">';

		if ( file_exists( $view_path ) ) {
			include $view_path;
		} else {
			echo '<p>' . esc_html__( 'Print view unavailable.', 'oras-tickets' ) . '</p>';
		}

		echo '</main>';
		echo '</body>';
		echo '</html>';

		exit;
	}
}
