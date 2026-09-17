<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Source_Change_Listener {
	public function __construct(
		private ?Projection_Service $projection = null,
		private ?Coverage_Store $coverage = null,
		private ?Source_Adapter $adapter = null
	) {
		$this->projection = $projection ?? new Projection_Service();
		$this->coverage   = $coverage ?? new Coverage_Store();
		$this->adapter    = $adapter ?? new Source_Adapter();
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'changed' ), 20, 4 );
	}

	/** @param mixed $order */
	public function changed( int $order_id, string $from_status = '', string $to_status = '', $order = null ): void {
		$event_id = Config::get_active_event_id();
		$config   = Config::get_event_config( $event_id );
		if ( $event_id <= 0 || empty( $config['enabled'] ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( (int) $item->get_meta( '_oras_ticket_event_id', true ) !== $event_id ) {
				continue;
			}
			$item_id  = (int) $item->get_id();
			$identity = self::identity( $order_id, $item_id );
			try {
				$result = $this->projection->reconcile_source( $event_id, $order_id, $item_id, $config );
				if ( $result instanceof \WP_Error ) {
					$this->coverage->fail( $event_id, (int) $config['revision'], $identity, $result->get_error_code() );
					continue;
				}
				$this->coverage->clear_failure( $event_id, (int) $config['revision'], $identity );
				$snapshot = $this->adapter->snapshot();
				if ( ! $snapshot instanceof \WP_Error ) {
					$this->coverage->refresh_listener_snapshot( $event_id, (int) $config['revision'], $snapshot );
				}
			} catch ( \Throwable $error ) {
				$this->coverage->fail( $event_id, (int) $config['revision'], $identity, 'oras_desk_listener_failed' );
			}
		}
	}

	public static function identity( int $order_id, int $order_item_id ): string {
		return 'woo:' . $order_id . ':' . $order_item_id;
	}
}
