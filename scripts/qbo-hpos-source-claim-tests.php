<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use ORAS\Tickets\Integrations\QuickBooks\Journal_Entry_Creator;
use ORAS\Tickets\Integrations\QuickBooks\Settings;
use ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is unavailable in this CLI misuse branch.
	fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-hpos-source-claim-tests.php\n" );
	return;
}

require_once __DIR__ . '/fixtures/qbo-test-fixture-registry.php';
oras_qbo_fixture_assert_disposable_identity();

add_filter( 'pre_wp_mail', static fn() => true, 10, 2 );

if ( ! class_exists( OrderUtil::class ) || ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
	throw new RuntimeException( 'HPOS must be authoritatively active for the QBO source-claim production-path test.' );
}

if ( get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) !== 'no' ) {
	throw new RuntimeException( 'HPOS CPT compatibility synchronization must be disabled for this test.' );
}

function oras_qbo_hpos_assert( string $label, bool $condition ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label . ' failed.' ) );
	}
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_hpos_assert_same( string $label, $actual, $expected ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException(
			esc_html(
				sprintf(
					'%s failed. Expected %s, got %s',
					$label,
					(string) wp_json_encode( $expected ),
					(string) wp_json_encode( $actual )
				)
			)
		);
	}
}

/**
 * @return array<string,mixed>
 */
function oras_qbo_hpos_settings(): array {
	return array(
		'enabled'                    => true,
		'sandbox'                    => true,
		'dry_run_mode'               => false,
		'require_manual_approval'    => false,
		'strict_mapping_mode'        => true,
		'allow_unmapped_fallback'    => false,
		'client_id'                  => 'hpos-test-client',
		'client_secret'              => 'hpos-test-secret',
		'realm_id'                   => 'hpos-test-realm',
		'access_token'               => 'hpos-test-access',
		'refresh_token'              => 'hpos-test-refresh',
		'token_expires_at'           => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
		'refresh_token_expires_at'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		'sync_cutoff_date'           => '2020-01-01',
		'posting_mode'               => 'reclass',
		'reclass_source_account_id'  => '4000',
		'tickets_default_account_id' => '4100',
		'merchandise_account_id'     => '4200',
		'donations_account_id'       => '4300',
		'source_match_max_wait_days' => 180,
		'excluded_payment_methods'   => '',
	);
}

/**
 * @return WC_Order
 */
function oras_qbo_hpos_order( int $product_id, string $marker, string $transaction_id ) {
	$current_settings = Settings::get_quickbooks_settings();
	Settings::update_quickbooks_settings( array( 'enabled' => false ) );
	try {
		$order = oras_qbo_fixture_track_order( wc_create_order() );
		$order->add_product(
			wc_get_product( $product_id ),
			1,
			array(
				'subtotal' => 71.25,
				'total'    => 71.25,
			)
		);
		$order->set_created_via( 'oras-qbo-hpos-test-' . $marker );
		$order->set_status( 'completed' );
		$order->set_currency( 'USD' );
		$order->set_payment_method( 'stripe' );
		$order->set_transaction_id( $transaction_id );
		$order->set_date_created( '2026-03-22 12:00:00' );
		$order->set_date_paid( '2026-03-22 12:00:00' );
		$order->set_total( 71.25 );
		$order->save();
		return $order;
	} finally {
		Settings::update_quickbooks_settings( $current_settings );
	}
}

/**
 * @return array<string,mixed>
 */
function oras_qbo_hpos_receipt( $order, string $source_id ): array {
	return array(
		'Id'            => $source_id,
		'DocNumber'     => 'SR-' . $source_id,
		'TxnDate'       => '2026-03-22',
		'TotalAmt'      => 71.25,
		'CurrencyRef'   => array( 'value' => 'USD' ),
		'PaymentRefNum' => $order->get_transaction_id(),
		'CustomerRef'   => array(
			'value' => 'hpos-customer',
			'name'  => 'HPOS Fixture',
		),
		'Line'          => array(
			array(
				'Id'          => '1',
				'Description' => 'Order ' . $order->get_order_number() . ' ' . $order->get_transaction_id(),
				'Amount'      => 71.25,
				'DetailType'  => 'SalesItemLineDetail',
			),
		),
	);
}

/**
 * Run the production orchestrator with every external HTTP request mocked.
 *
 * @param array<string,mixed>|null $existing_entry
 * @param callable(array<string,mixed>):void|null $on_post
 * @return array<string,mixed>|WP_Error
 */
function oras_qbo_hpos_sync( $order, array $receipt, ?array $existing_entry, array &$calls, ?callable $on_post = null ) {
	$http = static function ( $preempt, array $args, string $url ) use ( $order, $receipt, $existing_entry, &$calls, $on_post ) {
		$query_args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query_args );
		$query = (string) ( $query_args['query'] ?? '' );
		$method = (string) ( $args['method'] ?? 'GET' );
		$calls[] = array(
			'method' => $method,
			'url'    => $url,
			'query'  => $query,
		);

		if ( strpos( $url, '/preferences' ) !== false ) {
			$body = array(
				'Preferences' => array(
					'CurrencyPrefs' => array(
						'MultiCurrencyEnabled' => false,
						'HomeCurrency'         => array( 'value' => 'USD' ),
					),
				),
			);
		} elseif ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
			$body = array( 'QueryResponse' => array( 'SalesReceipt' => array( $receipt ) ) );
		} elseif ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
			$body = $existing_entry === null
				? array( 'QueryResponse' => array() )
				: array( 'QueryResponse' => array( 'JournalEntry' => array( $existing_entry ) ) );
		} elseif ( $method === 'POST' && strpos( $url, '/journalentry' ) !== false ) {
			if ( $on_post !== null ) {
				$on_post(
					array(
						'url'  => $url,
						'args' => $args,
					)
				);
			}
			$posted_payload = json_decode( (string) ( $args['body'] ?? '' ), true );
			if ( ! is_array( $posted_payload ) || empty( $posted_payload ) ) {
				return new WP_Error( 'invalid_test_fixture', 'HPOS JournalEntry success requires the exact posted payload.' );
			}
			$body = array(
				'JournalEntry' => array_merge(
					$posted_payload,
					array( 'Id' => 'JE-HPOS-' . $order->get_id() )
				),
			);
		} else {
			return new WP_Error( 'unexpected_http', 'Unexpected HPOS test HTTP request: ' . $url );
		}

		return array(
			'headers'  => array( 'intuit_tid' => 'hpos-test-tid' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'mock',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};

	add_filter( 'pre_http_request', $http, 10, 3 );
	try {
		return ( new Sync_Orchestrator() )->sync_order( (int) $order->get_id() );
	} finally {
		remove_filter( 'pre_http_request', $http, 10 );
	}
}

$original_settings = Settings::get_quickbooks_settings();

oras_qbo_fixture_run_with_cleanup(
	static function (): void {
		Settings::update_quickbooks_settings(
			array(
				'enabled'      => false,
				'dry_run_mode' => true,
			)
		);

		$product = new WC_Product_Simple();
		$product->set_name( 'QBO HPOS Production Path Fixture' );
		$product->set_regular_price( '71.25' );
		$product->set_price( '71.25' );
		$product->save();
		oras_qbo_fixture_track_product( $product );
		$product_id = (int) $product->get_id();
		update_post_meta( $product_id, '_oras_qbo_bucket', 'merchandise' );

		$storage_probe = oras_qbo_hpos_order( $product_id, 'storage', 'pi_hpos_storage' );
		global $wpdb;
		$hpos_row_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $storage_probe->get_id() )
		);
		$legacy_post = get_post( $storage_probe->get_id() );
		oras_qbo_hpos_assert_same( 'HPOS order row exists', $hpos_row_count, 1 );
		oras_qbo_hpos_assert( 'CPT compatibility row is absent', ! $legacy_post || $legacy_post->post_type !== 'shop_order' );

		Settings::update_quickbooks_settings( oras_qbo_hpos_settings() );

		// Pending and completed source claims both block a production sync.
		foreach ( array( 'pending', 'completed' ) as $claim_state ) {
			$transaction_id = 'pi_hpos_' . $claim_state . '_' . wp_generate_password( 8, false, false );
			$holder = oras_qbo_hpos_order( $product_id, $claim_state . '-holder', $transaction_id );
			$contender = oras_qbo_hpos_order( $product_id, $claim_state . '-contender', $transaction_id );
			$source_id = 'hpos-' . $claim_state . '-' . (string) $contender->get_id();
			$source_key = 'salesreceipt:' . $source_id;
			$holder->update_meta_data( '_oras_qbo_reclass_source_txn_key', $source_key );
			$holder->update_meta_data( '_oras_qbo_source_claim_state', $claim_state );
			$holder->save();
			$calls = array();
			$result = oras_qbo_hpos_sync( $contender, oras_qbo_hpos_receipt( $contender, $source_id ), null, $calls );
			oras_qbo_hpos_assert( $claim_state . ' claim returns WP_Error', is_wp_error( $result ) );
			oras_qbo_hpos_assert_same( $claim_state . ' claim error code', $result->get_error_code(), 'oras_qbo_reclass_source_claimed' );
			oras_qbo_hpos_assert_same(
				$claim_state . ' claim sends no POST',
				count( array_filter( $calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ),
				0
			);
			$holder = wc_get_order( $holder->get_id() );
			oras_qbo_hpos_assert_same( $claim_state . ' holder key remains owned', (string) $holder->get_meta( '_oras_qbo_reclass_source_txn_key', true ), $source_key );
			oras_qbo_hpos_assert_same( $claim_state . ' holder state remains owned', (string) $holder->get_meta( '_oras_qbo_source_claim_state', true ), $claim_state );
		}

		// Inject a competing claim in the actual source-lock acquisition window.
		$race_transaction_id = 'pi_hpos_race_' . wp_generate_password( 8, false, false );
		$race_holder = oras_qbo_hpos_order( $product_id, 'race-holder', $race_transaction_id );
		$race_contender = oras_qbo_hpos_order( $product_id, 'race-contender', $race_transaction_id );
		$race_source_id = 'hpos-race-' . (string) $race_contender->get_id();
		$race_source_key = 'salesreceipt:' . $race_source_id;
		$order_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $race_contender->get_id() ), 0, 40 );
		$source_lock_name = 'oras_tickets:' . substr( md5( 'qbo-source:' . $race_source_key ), 0, 40 );
		$lock_order = array();
		$claim_injected = false;
		$race_lock_spy = static function ( string $query ) use ( $order_lock_name, $source_lock_name, $race_holder, $race_source_key, &$lock_order, &$claim_injected ): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $order_lock_name ) !== false ) {
				$lock_order[] = 'order';
			}
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $source_lock_name ) !== false ) {
				$lock_order[] = 'source';
				if ( ! $claim_injected ) {
					$claim_injected = true;
					$race_holder->update_meta_data( '_oras_qbo_reclass_source_txn_key', $race_source_key );
					$race_holder->update_meta_data( '_oras_qbo_source_claim_state', 'pending' );
					$race_holder->save();
				}
			}
			return $query;
		};
		add_filter( 'query', $race_lock_spy, 10, 1 );
		$race_calls = array();
		try {
			$race_result = oras_qbo_hpos_sync(
				$race_contender,
				oras_qbo_hpos_receipt( $race_contender, $race_source_id ),
				null,
				$race_calls
			);
		} finally {
			remove_filter( 'query', $race_lock_spy, 10 );
		}
		oras_qbo_hpos_assert( 'production race injects competing claim', $claim_injected );
		oras_qbo_hpos_assert_same( 'production race lock order', array_slice( $lock_order, 0, 2 ), array( 'order', 'source' ) );
		oras_qbo_hpos_assert( 'production race returns WP_Error', is_wp_error( $race_result ) );
		oras_qbo_hpos_assert_same( 'production race error code', $race_result->get_error_code(), 'oras_qbo_reclass_source_claimed' );
		oras_qbo_hpos_assert_same(
			'production race makes no HTTP request after competing claim wins',
			count( array_filter( $race_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ),
			0
		);
		$race_holder = wc_get_order( $race_holder->get_id() );
		oras_qbo_hpos_assert_same( 'losing attempt cannot remove holder key', (string) $race_holder->get_meta( '_oras_qbo_reclass_source_txn_key', true ), $race_source_key );
		oras_qbo_hpos_assert_same( 'losing attempt cannot remove holder claim', (string) $race_holder->get_meta( '_oras_qbo_source_claim_state', true ), 'pending' );

		// Production dispatch persists and verifies pending intent before the POST,
		// then clears only transient ownership after a confirmed response.
		$dispatch_order = oras_qbo_hpos_order( $product_id, 'dispatch', 'pi_hpos_dispatch_' . wp_generate_password( 8, false, false ) );
		$dispatch_source_id = 'hpos-dispatch-' . (string) $dispatch_order->get_id();
		$dispatch_source_key = 'salesreceipt:' . $dispatch_source_id;
		$dispatch_calls = array();
		$dispatch_result = oras_qbo_hpos_sync(
			$dispatch_order,
			oras_qbo_hpos_receipt( $dispatch_order, $dispatch_source_id ),
			null,
			$dispatch_calls,
			static function () use ( $dispatch_order, $dispatch_source_key ): void {
				$reloaded = wc_get_order( $dispatch_order->get_id() );
				$pending = json_decode( (string) $reloaded->get_meta( '_oras_qbo_pending_write', true ), true );
				oras_qbo_hpos_assert( 'HPOS POST sees durable pending intent', is_array( $pending ) );
				oras_qbo_hpos_assert( 'HPOS POST sees dispatch marker', trim( (string) ( $pending['dispatch_started_at'] ?? '' ) ) !== '' );
				oras_qbo_hpos_assert_same( 'HPOS POST sees pending source claim', (string) $reloaded->get_meta( '_oras_qbo_source_claim_state', true ), 'pending' );
				oras_qbo_hpos_assert_same( 'HPOS POST sees exact source key', (string) $reloaded->get_meta( '_oras_qbo_reclass_source_txn_key', true ), $dispatch_source_key );
			}
		);
		oras_qbo_hpos_assert( 'HPOS production dispatch succeeds', is_array( $dispatch_result ) );
		$dispatch_order = wc_get_order( $dispatch_order->get_id() );
		oras_qbo_hpos_assert_same( 'HPOS confirmed JE stored', (string) $dispatch_order->get_meta( '_oras_qbo_je_id', true ), 'JE-HPOS-' . $dispatch_order->get_id() );
		oras_qbo_hpos_assert_same( 'HPOS pending intent cleared after success', (string) $dispatch_order->get_meta( '_oras_qbo_pending_write', true ), '' );
		oras_qbo_hpos_assert_same( 'HPOS source key remains historical evidence', (string) $dispatch_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), $dispatch_source_key );

		// Existing-JE adoption executes through the same production order/source lock sequence.
		$adopt_order = oras_qbo_hpos_order( $product_id, 'adopt', 'pi_hpos_adopt_' . wp_generate_password( 8, false, false ) );
		$adopt_source_id = 'hpos-adopt-' . (string) $adopt_order->get_id();
		$adopt_receipt = oras_qbo_hpos_receipt( $adopt_order, $adopt_source_id );
		$prepare_calls = array();
		$prepare_http = static function ( $preempt, array $args, string $url ) use ( $adopt_receipt, &$prepare_calls ) {
			$query_args = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query_args );
			$query = (string) ( $query_args['query'] ?? '' );
			$prepare_calls[] = $url;
			$body = strpos( $url, '/preferences' ) !== false
			? array(
				'Preferences' => array(
					'CurrencyPrefs' => array(
						'MultiCurrencyEnabled' => false,
						'HomeCurrency'         => array( 'value' => 'USD' ),
					),
				),
			)
			: array( 'QueryResponse' => array( 'SalesReceipt' => array( $adopt_receipt ) ) );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => 200,
					'message' => 'mock',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $prepare_http, 10, 3 );
		try {
			$adopt_prepared = ( new Journal_Entry_Creator() )->build_payload_for_order(
				$adopt_order,
				array(
					'lines'                 => array(
						array(
							'bucket_key'   => 'merchandise',
							'bucket_label' => 'Merchandise Income',
							'account_id'   => '4200',
							'amount'       => 71.25,
						),
					),
					'split_total'           => 71.25,
					'discount_mode'         => 'proportional',
					'warnings'              => array(),
					'unmapped_lines'        => 0,
					'missing_account_lines' => 0,
				),
				Settings::get_quickbooks_settings()
			);
		} finally {
			remove_filter( 'pre_http_request', $prepare_http, 10 );
		}
		oras_qbo_hpos_assert( 'HPOS adoption fixture prepares', is_array( $adopt_prepared ) );
		$adopt_entry = array_merge( $adopt_prepared['payload'], array( 'Id' => 'JE-HPOS-ADOPT-' . $adopt_order->get_id() ) );
		$adopt_calls = array();
		$adopt_result = oras_qbo_hpos_sync( $adopt_order, $adopt_receipt, $adopt_entry, $adopt_calls );
		oras_qbo_hpos_assert( 'HPOS production adoption succeeds', is_array( $adopt_result ) );
		oras_qbo_hpos_assert_same( 'HPOS adoption sends no POST', count( array_filter( $adopt_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );
		$adopt_order = wc_get_order( $adopt_order->get_id() );
		oras_qbo_hpos_assert_same( 'HPOS adopted JE stored', (string) $adopt_order->get_meta( '_oras_qbo_je_id', true ), 'JE-HPOS-ADOPT-' . $adopt_order->get_id() );

		echo "QBO HPOS production-path source-claim tests passed.\n";
	},
	static function () use ( $original_settings ): void {
		Settings::update_quickbooks_settings( $original_settings );
	}
);
