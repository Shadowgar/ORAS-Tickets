<?php

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-safety-controls-tests.php\n" );
	return;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_filter(
		'pre_wp_mail',
		static function ( $short_circuit, $atts ) {
			return true;
		},
		10,
		2
	);
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Settings' ) ) {
	throw new RuntimeException( 'QuickBooks Settings class not loaded.' );
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Sync_Orchestrator' ) ) {
	throw new RuntimeException( 'QuickBooks Sync_Orchestrator class not loaded.' );
}

if ( ! function_exists( 'wc_create_order' ) ) {
	throw new RuntimeException( 'WooCommerce is required for safety controls tests.' );
}

require_once __DIR__ . '/fixtures/qbo-test-fixture-registry.php';
oras_qbo_fixture_assert_disposable_identity();

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_safety_assert_same( string $label, $actual, $expected ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException(
			sprintf(
				'%s failed. Expected %s, got %s',
				$label,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}
}

function oras_qbo_safety_assert_true( string $label, bool $condition ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $label . ' failed.' );
	}
}

$original_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();

oras_qbo_fixture_run_with_cleanup(
	static function (): void {
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			array(
				'enabled'                    => true,
				'sandbox'                    => true,
				'dry_run_mode'               => true,
				'require_manual_approval'    => true,
				'posting_mode'               => 'clearing',
				'strict_mapping_mode'        => true,
				'allow_unmapped_fallback'    => false,
				'sync_cutoff_date'           => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
				'clearing_account_id'        => '1150040001',
				'tickets_default_account_id' => '1150040000',
				'merchandise_account_id'     => '1150040003',
				'unmapped_account_id'        => '',
			)
		);

		$mapped_product = new \WC_Product_Simple();
		$mapped_product->set_name( 'QBO Safety Fixture (Mapped)' );
		$mapped_product->set_regular_price( '10.00' );
		$mapped_product->set_price( '10.00' );
		$mapped_product_id = (int) $mapped_product->save();
		if ( $mapped_product_id <= 0 ) {
			throw new RuntimeException( 'Failed to create mapped fixture product.' );
		}
		oras_qbo_fixture_track_product( $mapped_product_id );
		update_post_meta( $mapped_product_id, '_oras_qbo_bucket', 'merchandise' );

		$orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();

		// Scenario 1: Dry-run queue, approval, and sync are local and read-only.
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Local CLI fixture variable, not the WordPress global.
		$order = oras_qbo_fixture_track_order( wc_create_order() );
		$order->add_product( wc_get_product( $mapped_product_id ), 1 );
		$order->calculate_totals();
		$order->update_status( 'completed', 'fixture', true );
		$order_id = (int) $order->get_id();

		$orchestrator->enqueue_order_sync( $order_id );
		$order = wc_get_order( $order_id );
		oras_qbo_safety_assert_same(
			'dry-run enqueue leaves status untouched',
			(string) $order->get_meta( '_oras_qbo_sync_status', true ),
			''
		);

		$approved = $orchestrator->approve_order_sync( $order_id, false );
		oras_qbo_safety_assert_true( 'dry-run approval returns preview status', is_array( $approved ) );
		$reloaded_order = wc_get_order( $order_id );
		oras_qbo_safety_assert_same(
			'dry-run approval does not persist approval',
			(string) $reloaded_order->get_meta( '_oras_qbo_manual_approved_at', true ),
			''
		);

		$dry_run = $orchestrator->sync_order( $order_id );
		oras_qbo_safety_assert_true( 'dry run sync result is array', is_array( $dry_run ) );
		if ( is_array( $dry_run ) ) {
			oras_qbo_safety_assert_same( 'dry run status', (string) ( $dry_run['status'] ?? '' ), 'dry_run' );
		}

		$order = wc_get_order( $order_id );
		oras_qbo_safety_assert_same(
			'dry run does not mark synced meta',
			(string) $order->get_meta( '_oras_qbo_synced', true ),
			''
		);

		// Scenario 2: Strict mapping still validates locally without persisting failure state.
		$unmapped_product = new \WC_Product_Simple();
		$unmapped_product->set_name( 'QBO Safety Fixture (Unmapped)' );
		$unmapped_product->set_regular_price( '12.00' );
		$unmapped_product->set_price( '12.00' );
		$unmapped_product_id = (int) $unmapped_product->save();
		oras_qbo_fixture_track_product( $unmapped_product_id );

		$strict_order = oras_qbo_fixture_track_order( wc_create_order() );
		$strict_order->add_product( wc_get_product( $unmapped_product_id ), 1 );
		$strict_order->calculate_totals();
		$strict_order->update_status( 'completed', 'fixture', true );
		$strict_order_id = (int) $strict_order->get_id();

		$orchestrator->approve_order_sync( $strict_order_id, false );
		$strict_result = $orchestrator->sync_order( $strict_order_id );
		oras_qbo_safety_assert_true( 'strict mapping returns WP_Error', is_wp_error( $strict_result ) );
		if ( is_wp_error( $strict_result ) ) {
			oras_qbo_safety_assert_same( 'strict mapping error code', $strict_result->get_error_code(), 'oras_qbo_strict_mapping_failed' );
		}
		$strict_order = wc_get_order( $strict_order_id );
		oras_qbo_safety_assert_same(
			'strict dry-run failure does not persist status',
			(string) $strict_order->get_meta( '_oras_qbo_sync_status', true ),
			''
		);

		// Scenario 3: Reversal in dry-run mode validates payload without write.
		$order = wc_get_order( $order_id );
		$order->update_meta_data( '_oras_qbo_je_id', '12345' );
		$order->update_meta_data(
			'_oras_qbo_split_snapshot',
			wp_json_encode(
				array(
					'lines'       => array(
						array(
							'bucket_key'   => 'merchandise',
							'bucket_label' => 'Merchandise Income',
							'account_id'   => '1150040003',
							'amount'       => 10.00,
						),
					),
					'split_total' => 10.00,
				)
			)
		);
		$order->save();

		$reversal = $orchestrator->reverse_order( $order_id, true );
		oras_qbo_safety_assert_true( 'reversal dry-run response is array', is_array( $reversal ) );
		if ( is_array( $reversal ) ) {
			oras_qbo_safety_assert_same( 'reversal dry-run status', (string) ( $reversal['status'] ?? '' ), 'reversal_dry_run' );
		}

		$audit_entries = get_post_meta( $order_id, '_oras_qbo_audit_entry', false );
		oras_qbo_safety_assert_same( 'dry-run audit entries are not written', is_array( $audit_entries ) ? count( $audit_entries ) : 0, 0 );

		$dry_log_writes = 0;
		$dry_log_spy = static function ( string $message ) use ( &$dry_log_writes ): string {
			++$dry_log_writes;
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $dry_log_spy, 1, 3 );
		try {
			( new \ORAS\Tickets\Integrations\QuickBooks\QuickBooks_Logger() )->error(
				'Dry-run logger boundary probe',
				array( 'order_id' => $order_id )
			);
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $dry_log_spy, 1 );
		}
		oras_qbo_safety_assert_same( 'dry-run logger boundary makes no persistent write', $dry_log_writes, 0 );

		echo "QBO safety controls tests passed.\n";
	},
	static function () use ( $original_settings ): void {
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $original_settings );
	},
	static function (): void {
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			array(
				'enabled'      => false,
				'dry_run_mode' => true,
			)
		);
	}
);
