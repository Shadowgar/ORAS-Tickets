<?php

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'The QBO fixture registry requires WordPress.' );
}

/** @var int[] */
$GLOBALS['oras_qbo_fixture_order_ids'] = array();
/** @var int[] */
$GLOBALS['oras_qbo_fixture_product_ids'] = array();
/** @var int[] */
$GLOBALS['oras_qbo_fixture_post_ids'] = array();

/**
 * Prove that fixture creation and cleanup are confined to the pinned disposable
 * wp-env database before any synthetic commerce record is created or deleted.
 */
function oras_qbo_fixture_assert_disposable_identity(): void {
	global $wpdb;

	$expected_home   = 'http://localhost:8895';
	$expected_marker = 'oras-tickets-qbo-tests-v1-3c882350e7b5f140';
	$home            = (string) get_option( 'home' );
	$site_url        = (string) get_option( 'siteurl' );
	$marker          = (string) get_option( 'oras_qbo_disposable_fixture_id' );

	if (
		! defined( 'DB_NAME' )
		|| DB_NAME !== 'tests-wordpress'
		|| ! defined( 'DB_HOST' )
		|| DB_HOST !== 'tests-mysql'
		|| ! isset( $wpdb->prefix )
		|| $wpdb->prefix !== 'wp_'
		|| $home !== $expected_home
		|| $site_url !== $expected_home
		|| $marker !== $expected_marker
		|| ! defined( 'ORAS_QBO_HTTP_BLOCK_ACTIVE' )
		|| ORAS_QBO_HTTP_BLOCK_ACTIVE !== true
	) {
		throw new RuntimeException( 'Refusing QBO fixture work outside the pinned disposable wp-env database.' );
	}
}

/**
 * Register a newly created order immediately after WooCommerce returns its ID.
 *
 * @param WC_Order $order
 * @return WC_Order
 */
function oras_qbo_fixture_track_order( $order ) {
	if ( is_wp_error( $order ) ) {
		throw new RuntimeException( 'Cannot track a QBO fixture order from a WordPress creation error.' );
	}
	if ( ! $order instanceof WC_Order ) {
		throw new RuntimeException( 'Cannot track a QBO fixture order from an invalid creation result.' );
	}

	$order_id = (int) $order->get_id();
	$persisted = $order_id > 0 ? wc_get_order( $order_id ) : false;
	if ( ! $persisted instanceof WC_Order || (int) $persisted->get_id() !== $order_id ) {
		throw new RuntimeException( 'Cannot track a QBO fixture order without a validated persisted ID.' );
	}

	$GLOBALS['oras_qbo_fixture_order_ids'][] = $order_id;
	return $order;
}

/**
 * Register a newly created product immediately after WooCommerce returns its ID.
 *
 * @param WC_Product|int $product
 * @return WC_Product|int
 */
function oras_qbo_fixture_track_product( $product ) {
	if ( is_wp_error( $product ) ) {
		throw new RuntimeException( 'Cannot track a QBO fixture product from a WordPress creation error.' );
	}
	if ( $product instanceof WC_Product ) {
		$product_id = (int) $product->get_id();
	} elseif ( is_int( $product ) ) {
		$product_id = $product;
	} else {
		throw new RuntimeException( 'Cannot track a QBO fixture product from an invalid creation result.' );
	}

	$persisted = $product_id > 0 ? wc_get_product( $product_id ) : false;
	if ( ! $persisted instanceof WC_Product || (int) $persisted->get_id() !== $product_id ) {
		throw new RuntimeException( 'Cannot track a QBO fixture product without a validated persisted ID.' );
	}

	$GLOBALS['oras_qbo_fixture_product_ids'][] = $product_id;
	return $product;
}

/**
 * Register a newly created non-commerce post immediately after WordPress
 * returns its ID.
 *
 * @param mixed $post_id
 * @return int
 */
function oras_qbo_fixture_track_post( $post_id ): int {
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( 'Cannot track a QBO fixture post from a WordPress creation error.' );
	}
	if ( ! is_int( $post_id ) || $post_id <= 0 || ! get_post( $post_id ) ) {
		throw new RuntimeException( 'Cannot track a QBO fixture post without a validated persisted ID.' );
	}

	$GLOBALS['oras_qbo_fixture_post_ids'][] = $post_id;
	return $post_id;
}

/**
 * Run one fixture-backed suite, always clean exact run-owned IDs, restore the
 * caller's settings, and report every failure without replacing the original.
 */
function oras_qbo_fixture_run_with_cleanup( callable $operation, callable $restore, ?callable $prepare_cleanup = null ): void {
	$original_error = null;
	$cleanup_prepare_error = null;
	$cleanup_error = null;
	$restore_error = null;

	try {
		$operation();
	} catch ( Throwable $throwable ) {
		$original_error = $throwable;
	}

	if ( $prepare_cleanup !== null ) {
		try {
			$prepare_cleanup();
		} catch ( Throwable $throwable ) {
			$cleanup_prepare_error = $throwable;
		}
	}

	try {
		oras_qbo_fixture_cleanup_exact_ids();
	} catch ( Throwable $throwable ) {
		$cleanup_error = $throwable;
	}

	try {
		$restore();
	} catch ( Throwable $throwable ) {
		$restore_error = $throwable;
	}

	if ( $original_error instanceof Throwable ) {
		if ( $cleanup_prepare_error instanceof Throwable || $cleanup_error instanceof Throwable || $restore_error instanceof Throwable ) {
			$details = array( 'Original failure: ' . $original_error->getMessage() );
			if ( $cleanup_prepare_error instanceof Throwable ) {
				$details[] = 'cleanup preparation failed: ' . $cleanup_prepare_error->getMessage();
			}
			if ( $cleanup_error instanceof Throwable ) {
				$details[] = 'cleanup failed: ' . $cleanup_error->getMessage();
			}
			if ( $restore_error instanceof Throwable ) {
				$details[] = 'restoration failed: ' . $restore_error->getMessage();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only aggregation of caught exception messages.
			throw new RuntimeException( implode( '; ', $details ), 0, $original_error );
		}
		throw $original_error;
	}

	if ( $cleanup_prepare_error instanceof Throwable ) {
		$details = array( 'QBO fixture cleanup preparation failed: ' . $cleanup_prepare_error->getMessage() );
		if ( $cleanup_error instanceof Throwable ) {
			$details[] = 'cleanup failed: ' . $cleanup_error->getMessage();
		}
		if ( $restore_error instanceof Throwable ) {
			$details[] = 'restoration failed: ' . $restore_error->getMessage();
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only aggregation of caught exception messages.
		throw new RuntimeException( implode( '; ', $details ), 0, $cleanup_prepare_error );
	}

	if ( $cleanup_error instanceof Throwable && $restore_error instanceof Throwable ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only aggregation of caught exception messages.
		throw new RuntimeException(
			'QBO fixture cleanup failed: ' . $cleanup_error->getMessage() . '; restoration failed: ' . $restore_error->getMessage(),
			0,
			$cleanup_error
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
	if ( $cleanup_error instanceof Throwable ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Re-throwing a caught cleanup failure.
		throw $cleanup_error;
	}
	if ( $restore_error instanceof Throwable ) {
		throw $restore_error;
	}
}

/**
 * Delete only the exact IDs registered by the current process and verify every
 * deletion. Historical fixtures are deliberately out of scope.
 */
function oras_qbo_fixture_cleanup_exact_ids(): void {
	oras_qbo_fixture_assert_disposable_identity();

	$errors = array();
	$order_ids = array_reverse(
		array_values( array_unique( array_map( 'intval', $GLOBALS['oras_qbo_fixture_order_ids'] ) ) )
	);
	$product_ids = array_reverse(
		array_values( array_unique( array_map( 'intval', $GLOBALS['oras_qbo_fixture_product_ids'] ) ) )
	);
	$post_ids = array_reverse(
		array_values( array_unique( array_map( 'intval', $GLOBALS['oras_qbo_fixture_post_ids'] ) ) )
	);

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
		clean_post_cache( $order_id );
		if ( wc_get_order( $order_id ) ) {
			$errors[] = 'order:' . (string) $order_id;
		}
	}

	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->delete( true );
		}
		clean_post_cache( $product_id );
		if ( wc_get_product( $product_id ) ) {
			$errors[] = 'product:' . (string) $product_id;
		}
	}

	foreach ( $post_ids as $post_id ) {
		if ( get_post( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
		clean_post_cache( $post_id );
		if ( get_post( $post_id ) ) {
			$errors[] = 'post:' . (string) $post_id;
		}
	}

	$GLOBALS['oras_qbo_fixture_order_ids'] = array();
	$GLOBALS['oras_qbo_fixture_product_ids'] = array();
	$GLOBALS['oras_qbo_fixture_post_ids'] = array();

	if ( ! empty( $errors ) ) {
		throw new RuntimeException( esc_html( 'QBO fixture cleanup failed for exact IDs: ' . implode( ', ', $errors ) ) );
	}
}
