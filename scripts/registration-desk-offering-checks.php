<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed -- Standalone CLI test doubles intentionally share this script.

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['oras_offering_meta']     = array();
$GLOBALS['oras_offering_products'] = array();
$GLOBALS['oras_offering_events']   = array();

function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' );
}
function sanitize_text_field( mixed $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone WordPress-function test double.
	return trim( strip_tags( (string) $value ) );
}
function absint( mixed $value ): int {
	return abs( (int) $value );
}
function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone WordPress-function test double.
	return json_encode( $value );
}
function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
	return $GLOBALS['oras_offering_meta'][ $post_id ][ $key ] ?? '';
}
function wc_get_product( int $product_id ): mixed {
	return $GLOBALS['oras_offering_products'][ $product_id ] ?? null;
}
function get_the_title( int $post_id ): string {
	return (string) ( $GLOBALS['oras_offering_events'][ $post_id ]['title'] ?? '' );
}
function tribe_get_start_date( int $post_id, bool $display_time = false, string $format = '' ): string {
	unset( $display_time, $format );

	return (string) ( $GLOBALS['oras_offering_events'][ $post_id ]['date'] ?? '' );
}
function tribe_get_end_date( int $post_id, bool $display_time = false, string $format = '' ): string {
	unset( $display_time, $format );

	return (string) ( $GLOBALS['oras_offering_events'][ $post_id ]['end_date'] ?? '' );
}

final class Oras_Offering_Test_Product {
	public function __construct(
		private int $id,
		private string $name,
		private bool $managing_stock,
		private int $stock,
		private bool $purchasable = true,
		private bool $in_stock = true
	) {}
	public function get_id(): int {
		return $this->id; }
	public function get_name(): string {
		return $this->name; }
	public function managing_stock(): bool {
		return $this->managing_stock; }
	public function get_stock_quantity(): int {
		return $this->stock; }
	public function is_purchasable(): bool {
		return $this->purchasable; }
	public function is_in_stock(): bool {
		return $this->in_stock; }
	public function set_stock( int $stock ): void {
		$this->stock = $stock;
		$this->in_stock = $stock > 0; }
}

function oras_offering_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		exit( 1 );
	}
	fwrite( STDOUT, "PASS: {$message}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
}

$plugin_root = dirname( __DIR__ ) . '/oras-tickets/includes/';
require_once $plugin_root . 'Domain/Meta.php';
require_once $plugin_root . 'Domain/Ticket.php';
require_once $plugin_root . 'Domain/Ticket_Collection.php';
require_once $plugin_root . 'Domain/Pricing/Price_Resolver.php';

$included_access_file = $plugin_root . 'Domain/Included_Event_Access.php';
oras_offering_assert( file_exists( $included_access_file ), 'Canonical included-event access value object exists' );
require_once $included_access_file;

$resolver_file = $plugin_root . 'Domain/Event_Offering_Resolver.php';
oras_offering_assert( file_exists( $resolver_file ), 'Shared event offering resolver exists' );
require_once $resolver_file;

$capacity_file = $plugin_root . 'Registration_Desk/RSVP_Capacity.php';
oras_offering_assert( file_exists( $capacity_file ), 'Shared RSVP capacity policy exists' );
require_once $capacity_file;

use ORAS\Tickets\Domain\Event_Offering_Resolver;
use ORAS\Tickets\Domain\Included_Event_Access;
use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Registration_Desk\RSVP_Capacity;

$now = strtotime( '2026-09-19 12:00:00 UTC' );
$GLOBALS['oras_offering_products'][101] = new Oras_Offering_Test_Product( 101, 'Woo fallback', true, 8 );
$GLOBALS['oras_offering_products'][102] = new Oras_Offering_Test_Product( 102, 'Other fallback', false, 0 );
$GLOBALS['oras_offering_products'][201] = new Oras_Offering_Test_Product( 201, 'Unrelated fallback', false, 0 );
$GLOBALS['oras_offering_events'][22] = array(
	'title' => 'Synthetic Event B',
	'date'  => 'October 8, 2026',
);
$GLOBALS['oras_offering_events'][33] = array(
	'title' => 'Synthetic Event C',
	'date'  => 'October 9, 2026',
);

$default_ticket = new Ticket( array() );
oras_offering_assert( array() === $default_ticket->included_event_ids, 'New ticket defaults to no included events' );
oras_offering_assert( array( 22, 33 ) === Included_Event_Access::normalize_ids( array( '22', 22, 11, 0, 33 ), 11 ), 'Included event IDs reject the primary event and normalize duplicates' );

$ticket = static function ( string $key, string $name, string $price, array $extra = array() ): array {
	return array_merge(
		array(
			'ticket_key'         => $key,
			'name'               => $name,
			'price'              => $price,
			'price_phases'       => array(),
			'capacity'           => 0,
			'sale_start'         => '',
			'sale_end'           => '',
			'description'        => 'Canonical description',
			'attendance_mode'    => 'onsite',
			'hide_sold_out'      => false,
			'included_event_ids' => array(),
		),
		$extra
	);
};

$GLOBALS['oras_offering_meta'][11][ Meta::META_KEY_TICKETS ] = array(
	'schema'  => 1,
	'tickets' => array(
		'alpha-ticket' => $ticket(
			'alpha-ticket',
			'Canonical Individual',
			'50.00',
			array(
				'included_event_ids' => array( 22, 33, 22, 11 ),
				'price_phases'       => array(
					array(
						'key'   => 'early',
						'label' => 'Early',
						'price' => '35.00',
						'start' => '2026-09-01 00:00',
						'end'   => '2026-09-30 23:59',
					),
				),
			)
		),
	),
);
$GLOBALS['oras_offering_meta'][11]['_oras_tickets_woo_map_v1'] = array( '0' => 101 );
$GLOBALS['oras_offering_meta'][22][ Meta::META_KEY_TICKETS ] = array(
	'schema'  => 1,
	'tickets' => array( 'unrelated' => $ticket( 'unrelated', 'Unrelated Event Ticket', '99.00' ) ),
);
$GLOBALS['oras_offering_meta'][22]['_oras_tickets_woo_map_v1'] = array( '0' => 201 );

$resolved = Event_Offering_Resolver::resolve_for_event( 11, $now );
oras_offering_assert( 1 === count( $resolved ), 'Resolver returns only selected-event tickets' );
oras_offering_assert( 'alpha-ticket' === $resolved[0]['ticket_key'] && 101 === $resolved[0]['product_id'], 'Canonical ticket and product identities are retained' );
oras_offering_assert( 'Canonical Individual' === $resolved[0]['name'] && 'Canonical description' === $resolved[0]['description'], 'Canonical display fields are retained' );
oras_offering_assert( '35.00' === $resolved[0]['price'] && 'early' === $resolved[0]['phase_key'], 'Current effective pricing phase is resolved' );
oras_offering_assert( 'onsite' === $resolved[0]['attendance_mode'], 'Canonical attendance mode is retained' );
oras_offering_assert( array( 22, 33 ) === $resolved[0]['included_event_ids'], 'Canonical offering retains normalized included-event identity and excludes its primary event' );
oras_offering_assert( array( 'Synthetic Event B', 'Synthetic Event C' ) === array_column( $resolved[0]['included_events'], 'title' ), 'Canonical offering resolves current included-event display context without raw IDs' );
oras_offering_assert( true === $resolved[0]['visible'] && true === $resolved[0]['selectable'], 'On-sale in-stock ticket is available' );

$config = array(
	'ticket_rules' => array(
		array(
			'ticket_key'       => 'alpha-ticket',
			'classification'   => 'family',
			'max_attendees'    => 4,
			'validity_type'    => 'full_event',
			'valid_local_date' => '',
		),
	),
	'entitlements' => array(
		array(
			'source_event_id'   => 22,
			'source_product_id' => 201,
			'classification'    => 'individual',
			'max_attendees'     => 1,
			'validity_type'     => 'full_event',
		),
	),
);
$desk = Event_Offering_Resolver::desk_offerings( 11, $config, $now );
oras_offering_assert( 1 === count( $desk ) && 'family' === $desk[0]['classification'] && 4 === $desk[0]['max_attendees'], 'Desk merges only supplemental coverage metadata' );
oras_offering_assert( 'Canonical Individual' === $desk[0]['label'] && '35.00' === $desk[0]['price'], 'Desk label and price remain canonical' );
oras_offering_assert( ! in_array( 201, array_column( $desk, 'product_id' ), true ), 'Cross-event entitlement never becomes a walk-in offering' );

$original_uuid = $desk[0]['option_uuid'];
$GLOBALS['oras_offering_meta'][11][ Meta::META_KEY_TICKETS ]['tickets']['alpha-ticket']['name'] = 'Renamed Canonically';
$renamed = Event_Offering_Resolver::desk_offerings( 11, $config, $now );
oras_offering_assert( 'Renamed Canonically' === $renamed[0]['label'] && $original_uuid === $renamed[0]['option_uuid'], 'Rename flows through without changing stable identity' );

$GLOBALS['oras_offering_meta'][11][ Meta::META_KEY_TICKETS ]['tickets']['beta-ticket'] = $ticket( 'beta-ticket', 'New Canonical Ticket', '20.00' );
$GLOBALS['oras_offering_meta'][11]['_oras_tickets_woo_map_v1']['1'] = 102;
$added = Event_Offering_Resolver::desk_offerings( 11, $config, $now );
oras_offering_assert( 2 === count( $added ) && in_array( 'New Canonical Ticket', array_column( $added, 'label' ), true ), 'Added canonical ticket flows to desk choices' );

$before_unrelated_change = $added;
$GLOBALS['oras_offering_meta'][22][ Meta::META_KEY_TICKETS ]['tickets']['unrelated']['name'] = 'Changed Elsewhere';
oras_offering_assert( $before_unrelated_change === Event_Offering_Resolver::desk_offerings( 11, $config, $now ), 'Unrelated event changes do not affect selected-event choices' );

$GLOBALS['oras_offering_products'][101]->set_stock( 0 );
$sold_out = Event_Offering_Resolver::resolve_for_event( 11, $now );
oras_offering_assert( 'sold_out' === $sold_out[0]['availability'] && false === $sold_out[0]['selectable'], 'Stock change makes the canonical offering unavailable' );

$GLOBALS['oras_offering_meta'][11][ Meta::META_KEY_TICKETS ]['tickets']['alpha-ticket']['sale_end'] = '2026-09-18 23:59';
$ended = Event_Offering_Resolver::resolve_for_event( 11, $now );
oras_offering_assert( 'ended' === $ended[0]['sale_state'] && false === $ended[0]['visible'], 'Closed sale window removes the ticket from current choices' );

oras_offering_assert( 'admit' === RSVP_Capacity::decision( 2, 1, 0, true ), 'RSVP admits when effective capacity remains' );
oras_offering_assert( 'waitlist' === RSVP_Capacity::decision( 2, 1, 1, true ), 'RSVP waitlists when shared capacity is full and waitlist is enabled' );
oras_offering_assert( 'refuse' === RSVP_Capacity::decision( 2, 2, 0, false ), 'RSVP refuses when full without waitlist' );
oras_offering_assert( 'admit' === RSVP_Capacity::decision( 0, 500, 500, false ), 'Zero RSVP capacity remains unlimited' );

echo "Registration Desk canonical offering checks passed.\n";
