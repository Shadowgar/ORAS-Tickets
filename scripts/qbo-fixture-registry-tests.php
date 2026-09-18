<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone in-memory WordPress and WooCommerce test doubles.

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'tests-wordpress' );
define( 'DB_HOST', 'tests-mysql' );
define( 'ORAS_QBO_HTTP_BLOCK_ACTIVE', true );
define( 'ORAS_QBO_DISPOSABLE_MARKER_EXPECTED', 'oras-tickets-qbo-tests-v1-3c882350e7b5f140' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

class WC_Order {
	public function __construct( private int $id, private bool $deletable = true ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function delete( bool $force ): void {
		$GLOBALS['fixture_deleted'][] = 'order:' . (string) $this->id;
		if ( $force && $this->deletable ) {
			unset( $GLOBALS['fixture_orders'][ $this->id ] );
		}
	}
}

class WC_Product {
	public function __construct( private int $id, private bool $deletable = true ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function delete( bool $force ): void {
		$GLOBALS['fixture_deleted'][] = 'product:' . (string) $this->id;
		if ( $force && $this->deletable ) {
			unset( $GLOBALS['fixture_products'][ $this->id ] );
		}
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function get_option( string $name ) {
	return array(
		'home'                           => 'http://localhost:8895',
		'siteurl'                        => 'http://localhost:8895',
		'oras_qbo_disposable_fixture_id' => 'oras-tickets-qbo-tests-v1-3c882350e7b5f140',
	)[ $name ] ?? null;
}

function wc_get_order( int $id ) {
	return $GLOBALS['fixture_orders'][ $id ] ?? false;
}

function wc_get_product( int $id ) {
	return $GLOBALS['fixture_products'][ $id ] ?? false;
}

function get_post( int $id ) {
	return $GLOBALS['fixture_posts'][ $id ] ?? null;
}

function wp_delete_post( int $id, bool $force ): void {
	$GLOBALS['fixture_deleted'][] = 'post:' . (string) $id;
	if ( $force ) {
		unset( $GLOBALS['fixture_posts'][ $id ] );
	}
}

function clean_post_cache( int $id ): void {}

function esc_html( string $value ): string {
	return $value;
}

function fixture_assert( string $label, bool $condition ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label . ' failed.' ) );
	}
}

function fixture_assert_rejected_without_registration( string $label, callable $operation, string $registry_key ): void {
	$GLOBALS[ $registry_key ] = array();
	$rejected = false;
	try {
		$operation();
	} catch ( RuntimeException $exception ) {
		$rejected = true;
	}
	fixture_assert( $label . ' is rejected explicitly', $rejected );
	fixture_assert( $label . ' registers no cleanup ID', $GLOBALS[ $registry_key ] === array() );
}

$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$GLOBALS['fixture_orders'] = array();
$GLOBALS['fixture_products'] = array();
$GLOBALS['fixture_posts'] = array();
$GLOBALS['fixture_deleted'] = array();

require __DIR__ . '/fixtures/qbo-test-fixture-registry.php';

fixture_assert_rejected_without_registration(
	'WP_Error order result',
	static fn () => oras_qbo_fixture_track_order( new WP_Error() ),
	'oras_qbo_fixture_order_ids'
);
fixture_assert_rejected_without_registration(
	'null order result',
	static fn () => oras_qbo_fixture_track_order( null ),
	'oras_qbo_fixture_order_ids'
);
fixture_assert_rejected_without_registration(
	'arbitrary order object',
	static fn () => oras_qbo_fixture_track_order( (object) array( 'id' => 41 ) ),
	'oras_qbo_fixture_order_ids'
);
fixture_assert_rejected_without_registration(
	'invalid product result',
	static fn () => oras_qbo_fixture_track_product( new stdClass() ),
	'oras_qbo_fixture_product_ids'
);
fixture_assert_rejected_without_registration(
	'invalid post result',
	static fn () => oras_qbo_fixture_track_post( null ),
	'oras_qbo_fixture_post_ids'
);

$owned_order = new WC_Order( 101 );
$unrelated_order = new WC_Order( 999 );
$GLOBALS['fixture_orders'][101] = $owned_order;
$GLOBALS['fixture_orders'][999] = $unrelated_order;
oras_qbo_fixture_track_order( $owned_order );
$later_creation_rejected = false;
try {
	oras_qbo_fixture_track_order( new WP_Error( 'later_failure', 'later setup failed' ) );
} catch ( RuntimeException $exception ) {
	$later_creation_rejected = true;
}
fixture_assert( 'later fixture creation failure is rejected', $later_creation_rejected );
oras_qbo_fixture_cleanup_exact_ids();
fixture_assert( 'previous fixture is cleaned after a later creation failure', ! isset( $GLOBALS['fixture_orders'][101] ) );
fixture_assert( 'unrelated disposable record survives exact cleanup', isset( $GLOBALS['fixture_orders'][999] ) );
fixture_assert( 'cleanup deletes no broad or unrelated IDs', $GLOBALS['fixture_deleted'] === array( 'order:101' ) );

$GLOBALS['fixture_deleted'] = array();
$cleanup_failure_order = new WC_Order( 202, false );
$GLOBALS['fixture_orders'][202] = $cleanup_failure_order;
oras_qbo_fixture_track_order( $cleanup_failure_order );
$restored = false;
$combined = null;
try {
	oras_qbo_fixture_run_with_cleanup(
		static function (): void {
			throw new RuntimeException( 'original setup failure' );
		},
		static function () use ( &$restored ): void {
			$restored = true;
		}
	);
} catch ( RuntimeException $exception ) {
	$combined = $exception;
}
fixture_assert( 'cleanup failure propagates', $combined instanceof RuntimeException );
fixture_assert( 'original failure remains visible', strpos( $combined?->getMessage() ?? '', 'original setup failure' ) !== false );
fixture_assert( 'cleanup failure remains visible', strpos( $combined?->getMessage() ?? '', 'cleanup failed' ) !== false );
fixture_assert( 'original failure is retained as previous exception', $combined?->getPrevious()?->getMessage() === 'original setup failure' );
fixture_assert( 'settings restoration runs after partial failure', $restored );

echo "QBO fixture registry tests passed.\n";
