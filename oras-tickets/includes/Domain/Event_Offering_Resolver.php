<?php

namespace ORAS\Tickets\Domain;

use ORAS\Tickets\Domain\Pricing\Price_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolve the current event-scoped ticket choices consumed by every sales surface. */
final class Event_Offering_Resolver {
	/** @return array<int,array<string,mixed>> */
	public static function resolve_for_event( int $event_id, ?int $now = null ): array {
		if ( $event_id <= 0 ) {
			return array();
		}
		$now        = null === $now ? time() : $now;
		$collection = Ticket_Collection::load_for_event( $event_id );
		$map        = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
		$map        = is_array( $map ) ? $map : array();
		$offerings  = array();

		foreach ( $collection->all() as $index => $ticket_object ) {
			$ticket = $ticket_object->to_array();
			$key    = trim( (string) ( $ticket['ticket_key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$product_id = self::mapped_product_id( $map, $index, $ticket );
			$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			$sale_state = self::sale_state( $ticket, $now );
			$stock      = self::stock_state( $product );
			$resolved   = Price_Resolver::resolve_ticket_price( $ticket, $now );
			$hide_sold  = ! empty( $ticket['hide_sold_out'] );
			$included_event_ids = Included_Event_Access::normalize_ids( $ticket['included_event_ids'] ?? array(), $event_id );
			$visible    = $stock['product_exists'] && 'on_sale' === $sale_state && ( 'sold_out' !== $stock['availability'] || ! $hide_sold );
			$selectable = $visible && 'available' === $stock['availability'];

			$offering = array(
				'kind'               => 'ticket',
				'event_id'           => $event_id,
				'ticket_index'       => (int) $index,
				'ticket_key'         => $key,
				'product_id'         => $product_id,
				'option_uuid'        => self::option_uuid( $event_id, 'ticket:' . $key ),
				'name'               => (string) ( $ticket['name'] ?? ( is_object( $product ) && method_exists( $product, 'get_name' ) ? $product->get_name() : '' ) ),
				'label'              => (string) ( $ticket['name'] ?? ( is_object( $product ) && method_exists( $product, 'get_name' ) ? $product->get_name() : '' ) ),
				'description'        => (string) ( $ticket['description'] ?? '' ),
				'price'              => (string) $resolved['price'],
				'phase_key'          => $resolved['phase_key'],
				'phase_label'        => $resolved['phase_label'],
				'phase_end_ts'       => $resolved['phase_end_ts'],
				'attendance_mode'    => Ticket::normalizeAttendanceMode( (string) ( $ticket['attendance_mode'] ?? '' ), Ticket::ATTENDANCE_MODE_VIRTUAL ),
				'sale_state'         => $sale_state,
				'availability'       => (string) $stock['availability'],
				'availability_label' => self::availability_label( $sale_state, (string) $stock['availability'] ),
				'product_exists'     => (bool) $stock['product_exists'],
				'managing_stock'     => (bool) $stock['managing_stock'],
				'stock_quantity'     => $stock['stock_quantity'],
				'max_quantity'       => (int) $stock['max_quantity'],
				'hide_when_sold_out' => $hide_sold,
				'visible'            => $visible,
				'selectable'         => $selectable,
				'available_for_new'  => $selectable,
				'included_event_ids' => $included_event_ids,
				'included_events'    => Included_Event_Access::describe_events( $included_event_ids, $event_id ),
				'canonical_ticket'   => $ticket,
			);
			$offering['offering_fingerprint'] = self::fingerprint( $offering );
			$offerings[] = $offering;
		}

		return $offerings;
	}

	/** @param array<string,mixed> $config @return array<int,array<string,mixed>> */
	public static function desk_offerings( int $event_id, array $config, ?int $now = null ): array {
		$rules = array();
		foreach ( is_array( $config['ticket_rules'] ?? null ) ? $config['ticket_rules'] : array() as $rule ) {
			if ( is_array( $rule ) && '' !== (string) ( $rule['ticket_key'] ?? '' ) ) {
				$rules[ (string) $rule['ticket_key'] ] = $rule;
			}
		}
		$offerings = array();
		foreach ( self::resolve_for_event( $event_id, $now ) as $offering ) {
			if ( empty( $offering['visible'] ) ) {
				continue;
			}
			$offerings[] = self::merge_rule( $offering, $rules[ (string) $offering['ticket_key'] ] ?? array() );
		}

		return $offerings;
	}

	/** @param array<string,mixed> $config @return array<string,mixed>|null */
	public static function find_desk_offering( int $event_id, array $config, string $option_uuid, ?int $now = null ): ?array {
		foreach ( self::desk_offerings( $event_id, $config, $now ) as $offering ) {
			if ( hash_equals( (string) $offering['option_uuid'], strtolower( trim( $option_uuid ) ) ) ) {
				return $offering;
			}
		}

		return null;
	}

	/** @param array<string,mixed> $config @return array<string,mixed>|null */
	public static function access_option_for_product( int $event_id, array $config, int $product_id ): ?array {
		$rules = array();
		foreach ( is_array( $config['ticket_rules'] ?? null ) ? $config['ticket_rules'] : array() as $rule ) {
			if ( is_array( $rule ) ) {
				$rules[ (string) ( $rule['ticket_key'] ?? '' ) ] = $rule;
			}
		}
		foreach ( self::resolve_for_event( $event_id ) as $offering ) {
			if ( $product_id === (int) $offering['product_id'] ) {
				return self::merge_rule( $offering, $rules[ (string) $offering['ticket_key'] ] ?? array() );
			}
		}

		return null;
	}

	/** @param array<string,mixed> $config @return array<string,mixed>|null */
	public static function find_access_option( int $event_id, array $config, string $option_uuid ): ?array {
		$rules = array();
		foreach ( is_array( $config['ticket_rules'] ?? null ) ? $config['ticket_rules'] : array() as $rule ) {
			if ( is_array( $rule ) ) {
				$rules[ (string) ( $rule['ticket_key'] ?? '' ) ] = $rule;
			}
		}
		foreach ( self::resolve_for_event( $event_id ) as $offering ) {
			if ( hash_equals( (string) $offering['option_uuid'], strtolower( trim( $option_uuid ) ) ) ) {
				return self::merge_rule( $offering, $rules[ (string) $offering['ticket_key'] ] ?? array() );
			}
		}

		return null;
	}

	public static function option_uuid( int $event_id, string $identity ): string {
		$hex       = substr( hash( 'sha256', 'oras-registration-desk|' . $event_id . '|' . $identity ), 0, 32 );
		$hex[12]   = '5';
		$variants  = array( '8', '9', 'a', 'b' );
		$hex[16]   = $variants[ hexdec( $hex[16] ) % 4 ];

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	public static function has_canonical_tickets( int $event_id ): bool {
		foreach ( self::resolve_for_event( $event_id ) as $offering ) {
			if ( ! empty( $offering['product_exists'] ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $offering @param array<string,mixed> $rule @return array<string,mixed> */
	private static function merge_rule( array $offering, array $rule ): array {
		$classification = sanitize_key( (string) ( $rule['classification'] ?? 'individual' ) );
		$validity       = sanitize_key( (string) ( $rule['validity_type'] ?? 'full_event' ) );
		$offering['classification']        = in_array( $classification, array( 'individual', 'family' ), true ) ? $classification : 'individual';
		$offering['validity_type']         = in_array( $validity, array( 'full_event', 'one_day' ), true ) ? $validity : 'full_event';
		$offering['valid_local_date']      = 'one_day' === $offering['validity_type'] ? sanitize_text_field( (string) ( $rule['valid_local_date'] ?? '' ) ) : '';
		$offering['max_attendees']         = 'family' === $offering['classification'] ? max( 1, min( 20, absint( $rule['max_attendees'] ?? 1 ) ) ) : 1;
		$offering['existing_access_valid'] = true;

		return $offering;
	}

	/** @param array<string,mixed> $offering */
	private static function fingerprint( array $offering ): string {
		$fields = array(
			'ticket_key'         => (string) $offering['ticket_key'],
			'product_id'         => (int) $offering['product_id'],
			'name'               => (string) $offering['name'],
			'description'        => (string) $offering['description'],
			'price'              => (string) $offering['price'],
			'phase_key'          => (string) ( $offering['phase_key'] ?? '' ),
			'attendance_mode'    => (string) $offering['attendance_mode'],
			'included_event_ids' => array_map( 'intval', (array) ( $offering['included_event_ids'] ?? array() ) ),
			'sale_state'         => (string) $offering['sale_state'],
			'availability'       => (string) $offering['availability'],
		);

		return hash( 'sha256', (string) wp_json_encode( $fields ) );
	}

	/** @param array<int|string,mixed> $map @param array<string,mixed> $ticket */
	private static function mapped_product_id( array $map, int $index, array $ticket ): int {
		if ( isset( $map[ (string) $index ] ) ) {
			return absint( $map[ (string) $index ] );
		}
		if ( isset( $map[ $index ] ) ) {
			return absint( $map[ $index ] );
		}

		return absint( $ticket['product_id'] ?? 0 );
	}

	/** @param array<string,mixed> $ticket */
	private static function sale_state( array $ticket, int $now ): string {
		$start = '' !== (string) ( $ticket['sale_start'] ?? '' ) ? strtotime( (string) $ticket['sale_start'] . ' UTC' ) : false;
		$end   = '' !== (string) ( $ticket['sale_end'] ?? '' ) ? strtotime( (string) $ticket['sale_end'] . ' UTC' ) : false;
		if ( false !== $start && $start > $now ) {
			return 'upcoming';
		}
		if ( false !== $end && $end < $now ) {
			return 'ended';
		}

		return 'on_sale';
	}

	/** @return array{availability:string,product_exists:bool,managing_stock:bool,stock_quantity:int|null,max_quantity:int} */
	private static function stock_state( mixed $product ): array {
		if ( ! is_object( $product ) ) {
			return array(
				'availability'   => 'unavailable',
				'product_exists' => false,
				'managing_stock' => false,
				'stock_quantity' => null,
				'max_quantity'   => 0,
			);
		}
		if ( method_exists( $product, 'is_purchasable' ) && ! $product->is_purchasable() ) {
			return array(
				'availability'   => 'unavailable',
				'product_exists' => true,
				'managing_stock' => false,
				'stock_quantity' => null,
				'max_quantity'   => 0,
			);
		}
		$managing = method_exists( $product, 'managing_stock' ) && $product->managing_stock();
		$quantity = $managing && method_exists( $product, 'get_stock_quantity' ) ? max( 0, (int) $product->get_stock_quantity() ) : null;
		$in_stock = ! method_exists( $product, 'is_in_stock' ) || $product->is_in_stock();
		if ( ! $in_stock || ( $managing && 0 === $quantity ) ) {
			return array(
				'availability'   => 'sold_out',
				'product_exists' => true,
				'managing_stock' => $managing,
				'stock_quantity' => $quantity,
				'max_quantity'   => 0,
			);
		}

		return array(
			'availability'   => 'available',
			'product_exists' => true,
			'managing_stock' => $managing,
			'stock_quantity' => $quantity,
			'max_quantity'   => $managing ? (int) $quantity : 10,
		);
	}

	private static function availability_label( string $sale_state, string $stock_state ): string {
		if ( 'upcoming' === $sale_state ) {
			return 'Not on sale yet';
		}
		if ( 'ended' === $sale_state ) {
			return 'Sales ended';
		}
		if ( 'sold_out' === $stock_state ) {
			return 'Sold out';
		}

		return 'available' === $stock_state ? 'On sale' : 'Unavailable';
	}
}
