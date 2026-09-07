<?php

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is unavailable in this CLI misuse branch.
	fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-reclass-safety-tests.php\n" );
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

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Journal_Entry_Creator' ) ) {
	throw new RuntimeException( 'QuickBooks Journal_Entry_Creator class not loaded.' );
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Sync_Orchestrator' ) ) {
	throw new RuntimeException( 'QuickBooks Sync_Orchestrator class not loaded.' );
}

if ( ! function_exists( 'wc_create_order' ) ) {
	throw new RuntimeException( 'WooCommerce is required for reclass safety tests.' );
}

require_once __DIR__ . '/fixtures/qbo-test-fixture-registry.php';
oras_qbo_fixture_assert_disposable_identity();

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_reclass_assert_same( string $label, $actual, $expected ): void {
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

function oras_qbo_reclass_assert_true( string $label, bool $condition ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label . ' failed.' ) );
	}
}

/**
 * Return a stable order-meta snapshot that works with CPT and HPOS storage.
 */
function oras_qbo_reclass_order_meta_snapshot( $order ): string {
	$snapshot = array();
	foreach ( $order->get_meta_data() as $meta ) {
		$data = $meta->get_data();
		$key = (string) ( $data['key'] ?? '' );
		if ( strpos( $key, '_oras_qbo_' ) !== 0 ) {
			continue;
		}
		if ( ! isset( $snapshot[ $key ] ) ) {
			$snapshot[ $key ] = array();
		}
		$snapshot[ $key ][] = $data['value'] ?? null;
	}
	ksort( $snapshot, SORT_STRING );
	return (string) wp_json_encode( $snapshot );
}

/**
 * Run an operation in dry-run mode and prove it did not cross any write boundary.
 *
 * @return mixed
 */
function oras_qbo_reclass_assert_dry_operation( string $label, $order, callable $operation ) {
	$order_id = (int) $order->get_id();
	$meta_before = get_post_meta( $order_id );
	$settings_before = get_option( \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY );
	$http_calls = 0;
	$lock_calls = 0;
	$option_write_attempts = 0;
	$order_save_calls = 0;
	$audit_writes = 0;
	$schedule_writes = 0;
	$metadata_writes = 0;
	$transient_writes = 0;
	$redirects = 0;
	$log_writes = 0;

	$http_spy = static function () use ( &$http_calls ): \WP_Error {
		++$http_calls;
		return new \WP_Error( 'unexpected_http', 'Dry runs must not make HTTP requests.' );
	};
	$query_spy = static function ( string $query ) use ( &$lock_calls, &$schedule_writes ): string {
		if ( strpos( $query, 'GET_LOCK' ) !== false || strpos( $query, 'RELEASE_LOCK' ) !== false ) {
			++$lock_calls;
		}
		if ( stripos( $query, 'actionscheduler_' ) !== false && stripos( $query, 'INSERT' ) !== false ) {
			++$schedule_writes;
		}
		return $query;
	};
	$option_spy = static function ( $value, string $option ) use ( &$option_write_attempts, &$schedule_writes ) {
		++$option_write_attempts;
		if ( $option === 'cron' ) {
			++$schedule_writes;
		}
		return $value;
	};
	$order_save_spy = static function ( int $saved_order_id ) use ( $order_id, &$order_save_calls ): void {
		if ( $saved_order_id === $order_id ) {
			++$order_save_calls;
		}
	};
	$metadata_spy = static function ( int $meta_id, int $object_id, string $meta_key ) use ( $order_id, &$audit_writes, &$metadata_writes ): void {
		if ( $object_id === $order_id && $meta_key === '_oras_qbo_audit_entry' ) {
			++$audit_writes;
		}
		if ( $object_id === $order_id ) {
			++$metadata_writes;
		}
	};
	$transient_spy = static function () use ( &$transient_writes ): void {
		++$transient_writes;
	};
	$redirect_spy = static function () use ( &$redirects ): void {
		++$redirects;
	};
	$log_spy = static function ( string $message ) use ( &$log_writes ): string {
		++$log_writes;
		return $message;
	};

	add_filter( 'pre_http_request', $http_spy, 1, 3 );
	add_filter( 'query', $query_spy, 1, 1 );
	add_filter( 'pre_update_option', $option_spy, 1, 3 );
	add_action( 'woocommerce_update_order', $order_save_spy, 1, 1 );
	add_action( 'added_post_meta', $metadata_spy, 1, 4 );
	add_action( 'updated_post_meta', $metadata_spy, 1, 4 );
	add_action( 'deleted_post_meta', $metadata_spy, 1, 4 );
	add_action( 'setted_transient', $transient_spy, 1, 3 );
	add_action( 'deleted_transient', $transient_spy, 1, 1 );
	add_action( 'oras_tickets_qbo_redirecting', $redirect_spy, 1, 2 );
	add_filter( 'woocommerce_logger_log_message', $log_spy, 1, 3 );
	try {
		$result = $operation();
	} finally {
		remove_filter( 'pre_http_request', $http_spy, 1 );
		remove_filter( 'query', $query_spy, 1 );
		remove_filter( 'pre_update_option', $option_spy, 1 );
		remove_action( 'woocommerce_update_order', $order_save_spy, 1 );
		remove_action( 'added_post_meta', $metadata_spy, 1 );
		remove_action( 'updated_post_meta', $metadata_spy, 1 );
		remove_action( 'deleted_post_meta', $metadata_spy, 1 );
		remove_action( 'setted_transient', $transient_spy, 1 );
		remove_action( 'deleted_transient', $transient_spy, 1 );
		remove_action( 'oras_tickets_qbo_redirecting', $redirect_spy, 1 );
		remove_filter( 'woocommerce_logger_log_message', $log_spy, 1 );
	}

	clean_post_cache( $order_id );
	oras_qbo_reclass_assert_same( $label . ' HTTP requests', $http_calls, 0 );
	oras_qbo_reclass_assert_same( $label . ' lock queries', $lock_calls, 0 );
	oras_qbo_reclass_assert_same( $label . ' option write attempts', $option_write_attempts, 0 );
	oras_qbo_reclass_assert_same( $label . ' order saves', $order_save_calls, 0 );
	oras_qbo_reclass_assert_same( $label . ' audit writes', $audit_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' metadata writes', $metadata_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' transient writes', $transient_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' scheduling writes', $schedule_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' redirects', $redirects, 0 );
	oras_qbo_reclass_assert_same( $label . ' WooCommerce logs', $log_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' order metadata', get_post_meta( $order_id ), $meta_before );
	oras_qbo_reclass_assert_same(
		$label . ' settings option',
		get_option( \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY ),
		$settings_before
	);

	return $result;
}

/**
 * Run a lookup-only reconciliation and prove that its permitted HTTP read is
 * the only observable side effect.
 *
 * @return mixed
 */
function oras_qbo_reclass_assert_lookup_read_only( string $label, $order, callable $operation ) {
	$order_id = (int) $order->get_id();
	$meta_before = get_post_meta( $order_id );
	$settings_before = get_option( \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY );
	$lock_calls = 0;
	$option_write_attempts = 0;
	$order_save_calls = 0;
	$audit_writes = 0;
	$schedule_writes = 0;
	$metadata_writes = 0;
	$transient_writes = 0;
	$log_writes = 0;

	$query_spy = static function ( string $query ) use ( &$lock_calls, &$schedule_writes ): string {
		if ( strpos( $query, 'GET_LOCK' ) !== false || strpos( $query, 'RELEASE_LOCK' ) !== false ) {
			++$lock_calls;
		}
		if ( stripos( $query, 'actionscheduler_' ) !== false && stripos( $query, 'INSERT' ) !== false ) {
			++$schedule_writes;
		}
		return $query;
	};
	$option_spy = static function ( $value, string $option ) use ( &$option_write_attempts, &$schedule_writes ) {
		++$option_write_attempts;
		if ( $option === 'cron' ) {
			++$schedule_writes;
		}
		return $value;
	};
	$order_save_spy = static function ( int $saved_order_id ) use ( $order_id, &$order_save_calls ): void {
		if ( $saved_order_id === $order_id ) {
			++$order_save_calls;
		}
	};
	$metadata_spy = static function ( int $meta_id, int $object_id, string $meta_key ) use ( $order_id, &$audit_writes, &$metadata_writes ): void {
		if ( $object_id === $order_id && $meta_key === '_oras_qbo_audit_entry' ) {
			++$audit_writes;
		}
		if ( $object_id === $order_id ) {
			++$metadata_writes;
		}
	};
	$transient_spy = static function () use ( &$transient_writes ): void {
		++$transient_writes;
	};
	$log_spy = static function ( string $message ) use ( &$log_writes ): string {
		++$log_writes;
		return $message;
	};

	add_filter( 'query', $query_spy, 1, 1 );
	add_filter( 'pre_update_option', $option_spy, 1, 3 );
	add_action( 'woocommerce_update_order', $order_save_spy, 1, 1 );
	add_action( 'added_post_meta', $metadata_spy, 1, 4 );
	add_action( 'updated_post_meta', $metadata_spy, 1, 4 );
	add_action( 'deleted_post_meta', $metadata_spy, 1, 4 );
	add_action( 'setted_transient', $transient_spy, 1, 3 );
	add_action( 'deleted_transient', $transient_spy, 1, 1 );
	add_filter( 'woocommerce_logger_log_message', $log_spy, 1, 3 );
	try {
		$result = $operation();
	} finally {
		remove_filter( 'query', $query_spy, 1 );
		remove_filter( 'pre_update_option', $option_spy, 1 );
		remove_action( 'woocommerce_update_order', $order_save_spy, 1 );
		remove_action( 'added_post_meta', $metadata_spy, 1 );
		remove_action( 'updated_post_meta', $metadata_spy, 1 );
		remove_action( 'deleted_post_meta', $metadata_spy, 1 );
		remove_action( 'setted_transient', $transient_spy, 1 );
		remove_action( 'deleted_transient', $transient_spy, 1 );
		remove_filter( 'woocommerce_logger_log_message', $log_spy, 1 );
	}

	clean_post_cache( $order_id );
	oras_qbo_reclass_assert_same( $label . ' lock queries', $lock_calls, 0 );
	oras_qbo_reclass_assert_same( $label . ' option write attempts', $option_write_attempts, 0 );
	oras_qbo_reclass_assert_same( $label . ' order saves', $order_save_calls, 0 );
	oras_qbo_reclass_assert_same( $label . ' audit writes', $audit_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' metadata writes', $metadata_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' transient writes', $transient_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' scheduling writes', $schedule_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' WooCommerce logs', $log_writes, 0 );
	oras_qbo_reclass_assert_same( $label . ' order metadata', get_post_meta( $order_id ), $meta_before );
	oras_qbo_reclass_assert_same(
		$label . ' settings option',
		get_option( \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY ),
		$settings_before
	);

	return $result;
}

/**
 * @param array<string,mixed>|string $body
 * @return array<string,mixed>
 */
function oras_qbo_reclass_mock_response( int $status, $body ): array {
	return array(
		'headers'  => array( 'intuit_tid' => 'reclass-test-tid' ),
		'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => $status,
			'message' => 'mock',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * @param mixed $body
 */
function oras_qbo_reclass_request_body( $body ): string {
	if ( is_string( $body ) ) {
		return $body;
	}
	$encoded = wp_json_encode( $body );
	return is_string( $encoded ) ? $encoded : '';
}

/**
 * Convert a test responder value into the WordPress HTTP response shape.
 * Company preferences default to USD unless a scenario explicitly supplies
 * or rejects that context.
 *
 * @param array<string,mixed>|string|\WP_Error $response
 * @param array<string,mixed> $call
 * @return array<string,mixed>|\WP_Error
 */
function oras_qbo_reclass_finalize_mock_response( $response, array $call ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( is_array( $response ) && isset( $response['__oras_http_status'] ) ) {
		$mock = oras_qbo_reclass_mock_response(
			is_numeric( $response['__oras_http_status'] ) ? (int) $response['__oras_http_status'] : 0,
			$response['__oras_http_body'] ?? array()
		);
		if ( ! is_numeric( $response['__oras_http_status'] ) ) {
			$mock['response']['code'] = $response['__oras_http_status'];
		}
		if ( ! empty( $response['__oras_missing_http_status'] ) ) {
			unset( $mock['response']['code'] );
		}
		return $mock;
	}

	if (
		strpos( (string) ( $call['url'] ?? '' ), '/preferences' ) !== false
		&& ( ! is_array( $response ) || ! array_key_exists( 'Preferences', $response ) )
	) {
		$response = array(
			'Preferences' => array(
				'CurrencyPrefs' => array(
					'MultiCurrencyEnabled' => false,
					'HomeCurrency'         => array( 'value' => 'USD' ),
				),
			),
		);
	}

	return oras_qbo_reclass_mock_response( 200, $response );
}

/**
 * @param array<string,mixed> $call
 */
function oras_qbo_reclass_request_id( array $call ): string {
	$query_args = array();
	wp_parse_str( (string) wp_parse_url( (string) ( $call['url'] ?? '' ), PHP_URL_QUERY ), $query_args );
	return trim( (string) ( $query_args['requestid'] ?? '' ) );
}

/**
 * Return the realistic material JournalEntry representation required before
 * production code may mark a dispatched write complete.
 *
 * @param array<string,mixed> $call
 * @return array<string,mixed>
 */
function oras_qbo_reclass_complete_journal_entry_response( array $call, string $je_id ): array {
	$payload = json_decode( (string) ( $call['body'] ?? '' ), true );
	if ( ! is_array( $payload ) || empty( $payload ) ) {
		throw new RuntimeException( 'A successful JournalEntry mock requires the exact posted payload.' );
	}

	return array(
		'JournalEntry' => array_merge( $payload, array( 'Id' => $je_id ) ),
	);
}

/**
 * Execute any production entry point with only its HTTP boundary replaced.
 *
 * @return mixed
 */
function oras_qbo_reclass_entrypoint_with_responder( callable $operation, callable $responder, array &$calls ) {
	$callback = static function ( $preempt, $args, $url ) use ( $responder, &$calls ) {
		$query_args = array();
		wp_parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query_args );
		$query = isset( $query_args['query'] ) ? (string) $query_args['query'] : '';
		$method = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$call = array(
			'method' => $method,
			'url'    => (string) $url,
			'query'  => $query,
			'body'   => isset( $args['body'] ) ? oras_qbo_reclass_request_body( $args['body'] ) : '',
		);
		$calls[] = $call;
		$response = $responder( $method, $query, $call, count( $calls ) );
		return oras_qbo_reclass_finalize_mock_response( $response, $call );
	};

	add_filter( 'pre_http_request', $callback, 10, 3 );
	try {
		return $operation();
	} finally {
		remove_filter( 'pre_http_request', $callback, 10 );
	}
}

/**
 * @return array<string,mixed>
 */
function oras_qbo_reclass_settings( array $overrides = array() ): array {
	return array_merge(
		array(
			'enabled'                    => true,
			'sandbox'                    => true,
			'dry_run_mode'               => false,
			'require_manual_approval'    => false,
			'strict_mapping_mode'        => true,
			'allow_unmapped_fallback'    => false,
			'client_id'                  => 'reclass-test-client',
			'client_secret'              => 'reclass-test-secret',
			'realm_id'                   => 'reclass-test-realm',
			'access_token'               => 'reclass-test-access-token',
			'refresh_token'              => 'reclass-test-refresh-token',
			'token_expires_at'           => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			'refresh_token_expires_at'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'connected_at'               => gmdate( 'Y-m-d H:i:s' ),
			'sync_cutoff_date'           => '2020-01-01',
			'posting_mode'               => 'reclass',
			'reclass_source_account_id'  => '4000',
			'tickets_default_account_id' => '4100',
			'merchandise_account_id'     => '4200',
			'donations_account_id'       => '4300',
			'source_match_max_wait_days' => 180,
			'excluded_payment_methods'   => '',
		),
		$overrides
	);
}

/**
 * @return array<string,mixed>
 */
function oras_qbo_reclass_split( float $amount ): array {
	return array(
		'lines'                 => array(
			array(
				'bucket_key'   => 'merchandise',
				'bucket_label' => 'Merchandise Income',
				'account_id'   => '4200',
				'amount'       => $amount,
			),
		),
		'split_total'           => $amount,
		'discount_mode'         => 'proportional',
		'warnings'              => array(),
		'unmapped_lines'        => 0,
		'missing_account_lines' => 0,
	);
}

/**
 * @return \WC_Order
 */
function oras_qbo_reclass_create_order( int $product_id, float $line_total, float $gross_total, string $paid_date, string $transaction_id = '' ) {
	$current_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
	\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );

	try {
		$order = oras_qbo_fixture_track_order( wc_create_order() );
		$order->add_product(
			wc_get_product( $product_id ),
			1,
			array(
				'subtotal' => $line_total,
				'total'    => $line_total,
			)
		);
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_currency( 'USD' );
		$order->set_payment_method( 'stripe' );
		$order->set_transaction_id( $transaction_id );
		$order->set_date_created( $paid_date . ' 12:00:00' );
		$order->set_date_paid( $paid_date . ' 12:00:00' );
		$order->set_total( $gross_total );
		$order->set_status( 'completed' );
		$order->save();

		return $order;
	} finally {
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $current_settings );
	}
}

/**
 * @return array<string,mixed>
 */
function oras_qbo_reclass_sales_receipt(
	string $id,
	float $total,
	string $txn_date,
	string $description = '',
	string $currency = 'USD',
	string $payment_ref = ''
): array {
	return array(
		'Id'            => $id,
		'DocNumber'     => 'SR-' . $id,
		'TxnDate'       => $txn_date,
		'TotalAmt'      => $total,
		'CurrencyRef'   => array( 'value' => $currency ),
		'PrivateNote'   => '',
		'CustomerMemo'  => array( 'value' => '' ),
		'CustomerRef'   => array(
			'value' => 'customer-1',
			'name'  => 'Ada Lovelace',
		),
		'PaymentRefNum' => $payment_ref,
		'CustomField'   => array(),
		'Line'          => array(
			array(
				'Id'          => '1',
				'Description' => $description,
				'Amount'      => $total,
				'DetailType'  => 'SalesItemLineDetail',
			),
		),
	);
}

/**
 * Run a payload build with only the HTTP boundary replaced.
 *
 * @param callable(string,int,array<string,mixed>):array<string,mixed>|\WP_Error $responder
 * @param array<int,array<string,mixed>> $calls
 * @return array<string,mixed>|\WP_Error
 */
function oras_qbo_reclass_build_with_responder( $order, array $split, callable $responder, array &$calls ) {
	$callback = static function ( $preempt, $args, $url ) use ( $responder, &$calls ) {
		$query_args = array();
		wp_parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query_args );
		$query = isset( $query_args['query'] ) ? (string) $query_args['query'] : '';
		$call  = array(
			'method' => isset( $args['method'] ) ? (string) $args['method'] : 'GET',
			'url'    => (string) $url,
			'query'  => $query,
			'body'   => isset( $args['body'] ) ? oras_qbo_reclass_request_body( $args['body'] ) : '',
		);
		$calls[] = $call;
		$response = $responder( $query, count( $calls ), $call );

		return oras_qbo_reclass_finalize_mock_response( $response, $call );
	};

	add_filter( 'pre_http_request', $callback, 10, 3 );
	try {
		$creator = new \ORAS\Tickets\Integrations\QuickBooks\Journal_Entry_Creator();
		return $creator->build_payload_for_order(
			$order,
			$split,
			\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings(),
			false
		);
	} finally {
		remove_filter( 'pre_http_request', $callback, 10 );
	}
}

/**
 * Run a full sync with only the HTTP boundary replaced.
 *
 * @param callable(string,string,array<string,mixed>,int):array<string,mixed>|\WP_Error $responder
 * @param array<int,array<string,mixed>> $calls
 * @return array<string,mixed>|\WP_Error
 */
function oras_qbo_reclass_sync_with_responder( int $order_id, callable $responder, array &$calls ) {
	$callback = static function ( $preempt, $args, $url ) use ( $responder, &$calls ) {
		$query_args = array();
		wp_parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query_args );
		$query  = isset( $query_args['query'] ) ? (string) $query_args['query'] : '';
		$method = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$call   = array(
			'method' => $method,
			'url'    => (string) $url,
			'query'  => $query,
			'body'   => isset( $args['body'] ) ? oras_qbo_reclass_request_body( $args['body'] ) : '',
		);
		$calls[] = $call;
		$response = $responder( $method, $query, $call, count( $calls ) );

		return oras_qbo_reclass_finalize_mock_response( $response, $call );
	};

	add_filter( 'pre_http_request', $callback, 10, 3 );
	try {
		$orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();
		return $orchestrator->sync_order( $order_id );
	} finally {
		remove_filter( 'pre_http_request', $callback, 10 );
	}
}

/**
 * Run a reversal with only the HTTP boundary replaced.
 *
 * @param callable(string,string,array<string,mixed>,int):array<string,mixed>|\WP_Error $responder
 * @param array<int,array<string,mixed>> $calls
 * @return array<string,mixed>|\WP_Error
 */
function oras_qbo_reclass_reverse_with_responder( int $order_id, callable $responder, array &$calls ) {
	$callback = static function ( $preempt, $args, $url ) use ( $responder, &$calls ) {
		$query_args = array();
		wp_parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query_args );
		$query  = isset( $query_args['query'] ) ? (string) $query_args['query'] : '';
		$method = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$call   = array(
			'method' => $method,
			'url'    => (string) $url,
			'query'  => $query,
			'body'   => isset( $args['body'] ) ? oras_qbo_reclass_request_body( $args['body'] ) : '',
		);
		$calls[] = $call;
		$response = $responder( $method, $query, $call, count( $calls ) );

		return oras_qbo_reclass_finalize_mock_response( $response, $call );
	};

	add_filter( 'pre_http_request', $callback, 10, 3 );
	try {
		$orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();
		return $orchestrator->reverse_order( $order_id );
	} finally {
		remove_filter( 'pre_http_request', $callback, 10 );
	}
}

/**
 * Run the explicit pending-write reconciliation path with only HTTP mocked.
 *
 * @param callable(string,string,array<string,mixed>,int):array<string,mixed>|\WP_Error $responder
 * @param array<int,array<string,mixed>> $calls
 * @return array<string,mixed>|\WP_Error
 */
function oras_qbo_reclass_reconcile_with_responder( int $order_id, bool $retry_if_absent, callable $responder, array &$calls ) {
	$callback = static function ( $preempt, $args, $url ) use ( $responder, &$calls ) {
		$query_args = array();
		wp_parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query_args );
		$query  = isset( $query_args['query'] ) ? (string) $query_args['query'] : '';
		$method = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$call   = array(
			'method' => $method,
			'url'    => (string) $url,
			'query'  => $query,
			'body'   => isset( $args['body'] ) ? oras_qbo_reclass_request_body( $args['body'] ) : '',
		);
		$calls[] = $call;
		$response = $responder( $method, $query, $call, count( $calls ) );

		return oras_qbo_reclass_finalize_mock_response( $response, $call );
	};

	add_filter( 'pre_http_request', $callback, 10, 3 );
	try {
		$orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();
		return $orchestrator->reconcile_unknown_write( $order_id, $retry_if_absent );
	} finally {
		remove_filter( 'pre_http_request', $callback, 10 );
	}
}

/**
 * @return array<string,mixed>
 */
function oras_qbo_reclass_seed_unknown_write( $order, float $amount, int $age_seconds = 600 ): array {
	$doc_number = 'ORAS-RC-' . (string) $order->get_id();
	$payload = array(
		'DocNumber'   => $doc_number,
		'TxnDate'     => $order->get_date_paid()->date_i18n( 'Y-m-d' ),
		'PrivateNote' => 'Seeded pending write for order ' . $order->get_order_number(),
		'CurrencyRef' => array( 'value' => 'USD' ),
		'Line'        => array(
			array(
				'Amount'                 => $amount,
				'Description'            => 'Seed debit',
				'DetailType'             => 'JournalEntryLineDetail',
				'JournalEntryLineDetail' => array(
					'PostingType' => 'Debit',
					'AccountRef'  => array( 'value' => '4000' ),
				),
			),
			array(
				'Amount'                 => $amount,
				'Description'            => 'Seed credit',
				'DetailType'             => 'JournalEntryLineDetail',
				'JournalEntryLineDetail' => array(
					'PostingType' => 'Credit',
					'AccountRef'  => array( 'value' => '4200' ),
				),
			),
		),
	);
	$source_match = array(
		'entity'               => 'SalesReceipt',
		'id'                   => 'seed-source-' . (string) $order->get_id(),
		'key'                  => 'salesreceipt:seed-source-' . (string) $order->get_id(),
		'txn_date'             => $order->get_date_paid()->date_i18n( 'Y-m-d' ),
		'total'                => $amount,
		'transaction_currency' => 'USD',
		'home_currency'        => 'USD',
	);
	$fingerprint = ( new \ORAS\Tickets\Integrations\QuickBooks\Journal_Entry_Creator() )
		->get_accounting_fingerprint( $payload, 'USD' );
	$encoded_payload = wp_json_encode( $payload );
	$request_id = 'oras-' . substr( hash( 'sha256', is_string( $encoded_payload ) ? $encoded_payload : '' ), 0, 45 );
	$attempt_id = wp_generate_uuid4();
	$pending = array(
		'order_id'               => (int) $order->get_id(),
		'doc_number'             => $doc_number,
		'request_id'             => $request_id,
		'order_hash'             => 'seed-order-hash-' . (string) $order->get_id(),
		'payload_hash'           => hash( 'sha256', is_string( $encoded_payload ) ? $encoded_payload : '' ),
		'accounting_fingerprint' => $fingerprint,
		'home_currency'          => 'USD',
		'transaction_currency'   => 'USD',
		'multicurrency_enabled'  => false,
		'exchange_rate'          => '1',
		'attempt_id'             => $attempt_id,
		'provisional_doc_number' => true,
		'operation'              => 'sync',
		'payload'                => $payload,
		'split'                  => oras_qbo_reclass_split( $amount ),
		'source_match'           => $source_match,
		'started_at'             => gmdate( 'c', time() - $age_seconds ),
		'dispatch_started_at'    => gmdate( 'c', time() - $age_seconds ),
	);

	$order->update_meta_data( '_oras_qbo_pending_write', wp_json_encode( $pending ) );
	$order->update_meta_data( '_oras_qbo_write_state', 'unknown_outcome' );
	$order->update_meta_data( '_oras_qbo_sync_status', 'qbo_write_unknown' );
	$order->update_meta_data( '_oras_qbo_doc_number', $doc_number );
	$order->update_meta_data( '_oras_qbo_reclass_source_txn_key', $source_match['key'] );
	$order->update_meta_data( '_oras_qbo_reclass_source_txn_id', $source_match['id'] );
	$order->update_meta_data( '_oras_qbo_reclass_source_txn_type', $source_match['entity'] );
	$order->update_meta_data( '_oras_qbo_reclass_source_txn_date', $source_match['txn_date'] );
	$order->update_meta_data( '_oras_qbo_source_claim_state', 'pending' );
	$order->update_meta_data( '_oras_qbo_source_claim_attempt_id', $attempt_id );
	$order->update_meta_data( '_oras_qbo_source_claim_request_id', $request_id );
	$order->update_meta_data( '_oras_qbo_source_claim_operation', 'sync' );
	$order->update_meta_data( '_oras_qbo_doc_number_attempt_id', $attempt_id );
	$order->save();

	return $pending;
}

function oras_qbo_reclass_assert_error_code( string $label, $result, string $expected_code ): void {
	oras_qbo_reclass_assert_true( $label . ' returns WP_Error', is_wp_error( $result ) );
	if ( is_wp_error( $result ) ) {
		oras_qbo_reclass_assert_same( $label . ' error code', $result->get_error_code(), $expected_code );
	}
}

/**
 * Invoke a public QuickBooks admin handler and stop at its first redirect so
 * tests can inspect the complete pre-redirect side effects.
 *
 * @param array<string,string> $post
 */
function oras_qbo_reclass_invoke_admin_handler( string $method, string $nonce_action, array $post, bool $expect_redirect = true ) {
	$previous_user_id = get_current_user_id();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- This helper constructs a nonce-authenticated synthetic admin request.
	$previous_post = $_POST;
	$previous_request = $_REQUEST;
	$redirected = false;
	$stop_at_redirect = static function () use ( &$redirected ): void {
		$redirected = true;
		throw new UnexpectedValueException( 'oras-qbo-test-redirect' );
	};

	wp_set_current_user( 1 );
	$_POST = array_merge( $post, array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
	$_REQUEST = $_POST;
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	add_action( 'oras_tickets_qbo_redirecting', $stop_at_redirect, 1, 2 );
	try {
		try {
			$result = ( new \ORAS\Tickets\Integrations\QuickBooks\Module() )->{$method}();
		} catch ( UnexpectedValueException $exception ) {
			if ( $exception->getMessage() !== 'oras-qbo-test-redirect' ) {
				throw $exception;
			}
		}
	} finally {
		remove_action( 'oras_tickets_qbo_redirecting', $stop_at_redirect, 1 );
		$_POST = $previous_post;
		$_REQUEST = $previous_request;
		wp_set_current_user( $previous_user_id );
	}

	oras_qbo_reclass_assert_same( 'admin handler ' . $method . ' redirect state', $redirected, $expect_redirect );
	return $result ?? null;
}

$original_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();

oras_qbo_fixture_run_with_cleanup(
	static function (): void {
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );

		$product = new \WC_Product_Simple();
		$product->set_name( 'QBO Reclass Safety Fixture' );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );
		$product_id = (int) $product->save();
		if ( $product_id <= 0 ) {
			throw new RuntimeException( 'Failed to create QBO reclass fixture product.' );
		}
		oras_qbo_fixture_track_product( $product_id );
		update_post_meta( $product_id, '_oras_qbo_bucket', 'merchandise' );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );

		// 1) Source matching uses gross order total while JE lines retain split_total.
		$gross_order = oras_qbo_reclass_create_order( $product_id, 90.00, 100.00, '2026-01-15', 'pi_gross_total' );
		$gross_receipt = oras_qbo_reclass_sales_receipt(
			'gross-source',
			100.00,
			'2026-01-15',
			'ORAS Order: fixture - Order ' . $gross_order->get_order_number()
		);
		$gross_calls = array();
		$gross_result = oras_qbo_reclass_build_with_responder(
			$gross_order,
			oras_qbo_reclass_split( 90.00 ),
			static function ( string $query ) use ( $gross_receipt ): array {
				return array( 'QueryResponse' => array( 'SalesReceipt' => array( $gross_receipt ) ) );
			},
			$gross_calls
		);
		oras_qbo_reclass_assert_true( 'gross total source match succeeds', is_array( $gross_result ) );
		if ( is_array( $gross_result ) ) {
			oras_qbo_reclass_assert_same( 'gross source ID', (string) ( $gross_result['source_match']['id'] ?? '' ), 'gross-source' );
			oras_qbo_reclass_assert_same( 'JE debit retains split total', (float) ( $gross_result['payload']['Line'][0]['Amount'] ?? 0.0 ), 90.00 );
		}
		oras_qbo_reclass_assert_same(
			'single source page requested',
			count( array_filter( $gross_calls, static fn ( array $call ): bool => strpos( (string) $call['query'], 'FROM SalesReceipt' ) !== false ) ),
			1
		);
		oras_qbo_reclass_assert_same(
			'gross source build verifies company home currency',
			count( array_filter( $gross_calls, static fn ( array $call ): bool => strpos( (string) $call['url'], '/preferences' ) !== false ) ),
			1
		);
		oras_qbo_reclass_assert_true( 'source query only selects SalesReceipt', strpos( $gross_calls[0]['query'], 'FROM SalesReceipt' ) !== false );
		oras_qbo_reclass_assert_true( 'source query excludes Payment', strpos( $gross_calls[0]['query'], 'FROM Payment' ) === false );
		oras_qbo_reclass_assert_true( 'source query excludes Deposit', strpos( $gross_calls[0]['query'], 'FROM Deposit' ) === false );
		oras_qbo_reclass_assert_true( 'fixed lower date boundary', strpos( $gross_calls[0]['query'], "TxnDate >= '2026-01-08'" ) !== false );
		oras_qbo_reclass_assert_true( 'fixed upper date boundary', strpos( $gross_calls[0]['query'], "TxnDate <= '2026-01-22'" ) !== false );
		oras_qbo_reclass_assert_true( 'first source page is explicit', strpos( $gross_calls[0]['query'], 'STARTPOSITION 1 MAXRESULTS 100' ) !== false );

		// The production calculator excludes shipping/tax but retains discounts and fees;
		// source matching must still use the complete Woo gross total.
		$complex_order = oras_qbo_reclass_create_order( $product_id, 90.00, 110.00, '2026-01-16', 'pi_complex_gross' );
		$complex_items = $complex_order->get_items( 'line_item' );
		$complex_item = reset( $complex_items );
		if ( ! $complex_item instanceof \WC_Order_Item_Product ) {
			throw new RuntimeException( 'Complex gross fixture line item missing.' );
		}
		$complex_item->set_subtotal( 100.00 );
		$complex_item->set_total( 90.00 );
		$complex_item->save();
		$complex_fee = new \WC_Order_Item_Fee();
		$complex_fee->set_name( 'Donation' );
		$complex_fee->set_amount( 5.00 );
		$complex_fee->set_total( 5.00 );
		$complex_fee->add_meta_data( '_oras_qbo_bucket', 'donation', true );
		$complex_order->add_item( $complex_fee );
		$complex_order->set_shipping_total( 10.00 );
		$complex_order->set_cart_tax( 4.00 );
		$complex_order->set_shipping_tax( 1.00 );
		$complex_order->set_total( 110.00 );
		$complex_order->save();
		$complex_split = ( new \ORAS\Tickets\Integrations\QuickBooks\Split_Calculator() )->calculate(
			$complex_order,
			\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings()
		);
		oras_qbo_reclass_assert_true( 'complex production split succeeds', is_array( $complex_split ) );
		if ( is_array( $complex_split ) ) {
			oras_qbo_reclass_assert_same( 'complex split excludes shipping and tax', (float) $complex_split['split_total'], 95.00 );
			oras_qbo_reclass_assert_same( 'complex split retains product discount', (float) $complex_split['discount_total'], 10.00 );
			$complex_calls = array();
			$complex_result = oras_qbo_reclass_build_with_responder(
				$complex_order,
				$complex_split,
				static function () use ( $complex_order ): array {
					return array(
						'QueryResponse' => array(
							'SalesReceipt' => array(
								oras_qbo_reclass_sales_receipt(
									'complex-gross',
									110.00,
									'2026-01-16',
									'ORAS Order: fixture - Order ' . $complex_order->get_order_number()
								),
							),
						),
					);
				},
				$complex_calls
			);
			oras_qbo_reclass_assert_true( 'complex gross source match succeeds', is_array( $complex_result ) );
			if ( is_array( $complex_result ) ) {
				oras_qbo_reclass_assert_same( 'complex JE debit uses calculated classification total', (float) $complex_result['payload']['Line'][0]['Amount'], 95.00 );
			}
		}

		$outside_window_calls = array();
		$outside_window_result = oras_qbo_reclass_build_with_responder(
			$gross_order,
			oras_qbo_reclass_split( 90.00 ),
			static function () use ( $gross_order ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt(
								'outside-window',
								100.00,
								'2026-01-23',
								'ORAS Order: fixture - Order ' . $gross_order->get_order_number()
							),
						),
					),
				);
			},
			$outside_window_calls
		);
		oras_qbo_reclass_assert_error_code( 'source outside fixed date window', $outside_window_result, 'oras_qbo_reclass_source_not_found' );

		// 2) Pagination reaches a unique high-confidence receipt after the first 100 rows.
		$paged_order = oras_qbo_reclass_create_order( $product_id, 75.00, 75.00, '2026-02-10' );
		$noise_rows = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$noise_rows[] = oras_qbo_reclass_sales_receipt( 'noise-' . (string) $index, 75.00, '2026-02-10', 'Unrelated Stripe sale' );
		}
		$paged_target = oras_qbo_reclass_sales_receipt(
			'paged-target',
			75.00,
			'2026-02-10',
			'ORAS Order: fixture - Order #' . $paged_order->get_order_number()
		);
		$paged_calls = array();
		$paged_result = oras_qbo_reclass_build_with_responder(
			$paged_order,
			oras_qbo_reclass_split( 75.00 ),
			static function ( string $query ) use ( $noise_rows, $paged_target ): array {
				$rows = strpos( $query, 'STARTPOSITION 101' ) !== false ? array( $paged_target ) : $noise_rows;
				return array( 'QueryResponse' => array( 'SalesReceipt' => $rows ) );
			},
			$paged_calls
		);
		oras_qbo_reclass_assert_true( 'paginated source match succeeds', is_array( $paged_result ) );
		if ( is_array( $paged_result ) ) {
			oras_qbo_reclass_assert_same( 'second-page source selected', (string) ( $paged_result['source_match']['id'] ?? '' ), 'paged-target' );
		}
		oras_qbo_reclass_assert_same(
			'two source pages requested',
			count( array_filter( $paged_calls, static fn ( array $call ): bool => strpos( (string) $call['query'], 'FROM SalesReceipt' ) !== false ) ),
			2
		);
		oras_qbo_reclass_assert_true( 'second source page is explicit', strpos( $paged_calls[1]['query'], 'STARTPOSITION 101 MAXRESULTS 100' ) !== false );

		// 3) Pagination is bounded and fails closed when the cap is exhausted.
		$cap_order = oras_qbo_reclass_create_order( $product_id, 50.00, 50.00, '2026-02-11' );
		$cap_rows = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$cap_rows[] = oras_qbo_reclass_sales_receipt( 'cap-' . (string) $index, 50.00, '2026-02-11', 'Unrelated transaction' );
		}
		$cap_calls = array();
		$cap_result = oras_qbo_reclass_build_with_responder(
			$cap_order,
			oras_qbo_reclass_split( 50.00 ),
			static function () use ( $cap_rows ): array {
				return array( 'QueryResponse' => array( 'SalesReceipt' => $cap_rows ) );
			},
			$cap_calls
		);
		oras_qbo_reclass_assert_error_code( 'source pagination cap', $cap_result, 'oras_qbo_reclass_source_query_limit' );
		oras_qbo_reclass_assert_same( 'source pagination cap page count', count( $cap_calls ), 10 );

		// 4) Customer/amount/date evidence without order or Stripe identity is rejected.
		$zero_order = oras_qbo_reclass_create_order( $product_id, 64.46, 64.46, '2026-03-01', 'pi_expected_score_zero' );
		$zero_calls = array();
		$zero_result = oras_qbo_reclass_build_with_responder(
			$zero_order,
			oras_qbo_reclass_split( 64.46 ),
			static function (): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt( 'zero-score', 64.46, '2026-03-01', 'Customer purchase pi_unrelated_score_zero' ),
						),
					),
				);
			},
			$zero_calls
		);
		oras_qbo_reclass_assert_error_code( 'score-zero source', $zero_result, 'oras_qbo_reclass_source_not_found' );

		// 5) Numeric order substrings do not count as exact order evidence.
		$substring_order = oras_qbo_reclass_create_order( $product_id, 44.00, 44.00, '2026-03-02' );
		$substring_number = (string) $substring_order->get_order_number();
		$substring_calls = array();
		$substring_result = oras_qbo_reclass_build_with_responder(
			$substring_order,
			oras_qbo_reclass_split( 44.00 ),
			static function () use ( $substring_number ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt( 'substring', 44.00, '2026-03-02', 'Order 1' . $substring_number ),
						),
					),
				);
			},
			$substring_calls
		);
		oras_qbo_reclass_assert_error_code( 'order-number substring collision', $substring_result, 'oras_qbo_reclass_source_not_found' );

		// 6) Stripe identifiers are exact, regex-safe high-confidence evidence.
		$stripe_identifier = 'pi_test.+[abc]';
		$stripe_order = oras_qbo_reclass_create_order( $product_id, 45.00, 45.00, '2026-03-03', $stripe_identifier );
		$stripe_calls = array();
		$stripe_result = oras_qbo_reclass_build_with_responder(
			$stripe_order,
			oras_qbo_reclass_split( 45.00 ),
			static function () use ( $stripe_identifier ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt( 'stripe-id', 45.00, '2026-03-03', 'Stripe sale', 'USD', $stripe_identifier ),
						),
					),
				);
			},
			$stripe_calls
		);
		oras_qbo_reclass_assert_true( 'Stripe identifier source match succeeds', is_array( $stripe_result ) );
		if ( is_array( $stripe_result ) ) {
			$evidence = isset( $stripe_result['source_match']['match_evidence'] ) && is_array( $stripe_result['source_match']['match_evidence'] )
			? $stripe_result['source_match']['match_evidence']
			: array();
			oras_qbo_reclass_assert_true( 'Stripe identifier evidence recorded', in_array( 'stripe_transaction_id', $evidence, true ) );
		}

		$conflict_order = oras_qbo_reclass_create_order( $product_id, 45.50, 45.50, '2026-03-03', 'pi_expected_identifier' );
		$conflict_calls = array();
		$conflict_result = oras_qbo_reclass_build_with_responder(
			$conflict_order,
			oras_qbo_reclass_split( 45.50 ),
			static function () use ( $conflict_order ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt(
								'conflicting-stripe-id',
								45.50,
								'2026-03-03',
								'ORAS Order: fixture - Order ' . $conflict_order->get_order_number(),
								'USD',
								'pi_different_identifier'
							),
						),
					),
				);
			},
			$conflict_calls
		);
		oras_qbo_reclass_assert_error_code( 'conflicting Stripe identity', $conflict_result, 'oras_qbo_reclass_source_identity_conflict' );

		$mixed_conflict_order = oras_qbo_reclass_create_order( $product_id, 45.60, 45.60, '2026-03-03', 'pi_expected_and_present' );
		$mixed_conflict_calls = array();
		$mixed_conflict_result = oras_qbo_reclass_build_with_responder(
			$mixed_conflict_order,
			oras_qbo_reclass_split( 45.60 ),
			static function (): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt(
								'mixed-conflicting-stripe-id',
								45.60,
								'2026-03-03',
								'Stripe pi_conflicting_identifier',
								'USD',
								'pi_expected_and_present'
							),
						),
					),
				);
			},
			$mixed_conflict_calls
		);
		oras_qbo_reclass_assert_error_code(
			'expected plus conflicting Stripe identity',
			$mixed_conflict_result,
			'oras_qbo_reclass_source_identity_conflict'
		);

		$order_conflict_order = oras_qbo_reclass_create_order( $product_id, 45.70, 45.70, '2026-03-03' );
		$order_conflict_calls = array();
		$order_conflict_result = oras_qbo_reclass_build_with_responder(
			$order_conflict_order,
			oras_qbo_reclass_split( 45.70 ),
			static function () use ( $order_conflict_order ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt(
								'conflicting-order-number',
								45.70,
								'2026-03-03',
								'Order ' . $order_conflict_order->get_order_number() . ' imported from Order 999999'
							),
						),
					),
				);
			},
			$order_conflict_calls
		);
		oras_qbo_reclass_assert_error_code(
			'expected plus conflicting order identity',
			$order_conflict_result,
			'oras_qbo_reclass_source_identity_conflict'
		);

		// 7) Multiple high-confidence SalesReceipts are ambiguous and fail closed.
		$ambiguous_order = oras_qbo_reclass_create_order( $product_id, 46.00, 46.00, '2026-03-04' );
		$ambiguous_description = 'ORAS Order: fixture - Order ' . $ambiguous_order->get_order_number();
		$ambiguous_calls = array();
		$ambiguous_result = oras_qbo_reclass_build_with_responder(
			$ambiguous_order,
			oras_qbo_reclass_split( 46.00 ),
			static function () use ( $ambiguous_description ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt( 'ambiguous-a', 46.00, '2026-03-04', $ambiguous_description ),
							oras_qbo_reclass_sales_receipt( 'ambiguous-b', 46.00, '2026-03-04', $ambiguous_description ),
						),
					),
				);
			},
			$ambiguous_calls
		);
		oras_qbo_reclass_assert_error_code( 'ambiguous source', $ambiguous_result, 'oras_qbo_reclass_source_ambiguous' );

		// 8) A high-confidence source already claimed by another order is rejected explicitly.
		$claimed_order = oras_qbo_reclass_create_order( $product_id, 47.00, 47.00, '2026-03-05' );
		$claim_holder = oras_qbo_reclass_create_order( $product_id, 1.00, 1.00, '2026-03-05' );
		$claim_holder->update_meta_data( '_oras_qbo_reclass_source_txn_key', 'salesreceipt:claimed-source' );
		$claim_holder->update_meta_data( '_oras_qbo_source_claim_state', 'pending' );
		$claim_holder->save();
		$claimed_calls = array();
		$claimed_result = oras_qbo_reclass_build_with_responder(
			$claimed_order,
			oras_qbo_reclass_split( 47.00 ),
			static function () use ( $claimed_order ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt(
								'claimed-source',
								47.00,
								'2026-03-05',
								'ORAS Order: fixture - Order ' . $claimed_order->get_order_number()
							),
						),
					),
				);
			},
			$claimed_calls
		);
		oras_qbo_reclass_assert_error_code( 'already-claimed source', $claimed_result, 'oras_qbo_reclass_source_claimed' );

		// 9) Payment and Deposit responses can never be selected as reclass sources.
		$wrong_type_order = oras_qbo_reclass_create_order( $product_id, 48.00, 48.00, '2026-03-06' );
		$wrong_type_calls = array();
		$wrong_type_result = oras_qbo_reclass_build_with_responder(
			$wrong_type_order,
			oras_qbo_reclass_split( 48.00 ),
			static function ( string $query ) use ( $wrong_type_order ): array {
				$payment = oras_qbo_reclass_sales_receipt(
					'payment-source',
					48.00,
					'2026-03-06',
					'ORAS Order: fixture - Order ' . $wrong_type_order->get_order_number()
				);
				if ( strpos( $query, 'FROM Payment' ) !== false ) {
					return array( 'QueryResponse' => array( 'Payment' => array( $payment ) ) );
				}
				if ( strpos( $query, 'FROM Deposit' ) !== false ) {
					return array( 'QueryResponse' => array( 'Deposit' => array( $payment ) ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$wrong_type_calls
		);
		oras_qbo_reclass_assert_error_code( 'Payment and Deposit source', $wrong_type_result, 'oras_qbo_reclass_source_not_found' );
		oras_qbo_reclass_assert_same( 'only SalesReceipt queried for wrong-type source', count( $wrong_type_calls ), 1 );

		// 10) Exact-cent and currency mismatches are rejected.
		$precision_order = oras_qbo_reclass_create_order( $product_id, 49.00, 49.00, '2026-03-07' );
		$precision_description = 'ORAS Order: fixture - Order ' . $precision_order->get_order_number();
		$precision_calls = array();
		$precision_result = oras_qbo_reclass_build_with_responder(
			$precision_order,
			oras_qbo_reclass_split( 49.00 ),
			static function () use ( $precision_description ): array {
				return array(
					'QueryResponse' => array(
						'SalesReceipt' => array(
							oras_qbo_reclass_sales_receipt( 'wrong-cent', 49.01, '2026-03-07', $precision_description ),
							oras_qbo_reclass_sales_receipt( 'wrong-currency', 49.00, '2026-03-07', $precision_description, 'EUR' ),
						),
					),
				);
			},
			$precision_calls
		);
		oras_qbo_reclass_assert_error_code( 'precision and currency mismatch', $precision_result, 'oras_qbo_reclass_source_not_found' );

		$missing_currency_order = oras_qbo_reclass_create_order( $product_id, 49.10, 49.10, '2026-03-07' );
		$missing_currency_receipt = oras_qbo_reclass_sales_receipt(
			'missing-currency',
			49.10,
			'2026-03-07',
			'Order ' . $missing_currency_order->get_order_number()
		);
		unset( $missing_currency_receipt['CurrencyRef'] );
		$missing_currency_calls = array();
		$missing_currency_result = oras_qbo_reclass_build_with_responder(
			$missing_currency_order,
			oras_qbo_reclass_split( 49.10 ),
			static function ( string $query, int $call_number, array $call ) use ( $missing_currency_receipt ): array {
				if ( strpos( (string) $call['url'], '/preferences' ) !== false ) {
					return array( 'Preferences' => array() );
				}
				return array( 'QueryResponse' => array( 'SalesReceipt' => array( $missing_currency_receipt ) ) );
			},
			$missing_currency_calls
		);
		oras_qbo_reclass_assert_error_code(
			'missing currency without home-currency proof',
			$missing_currency_result,
			'oras_qbo_reclass_source_currency_unverified'
		);

		$home_currency_order = oras_qbo_reclass_create_order( $product_id, 49.20, 49.20, '2026-03-07' );
		$home_currency_receipt = oras_qbo_reclass_sales_receipt(
			'home-currency-normalized',
			49.20,
			'2026-03-07',
			'Order ' . $home_currency_order->get_order_number()
		);
		unset( $home_currency_receipt['CurrencyRef'] );
		$home_currency_calls = array();
		$home_currency_result = oras_qbo_reclass_build_with_responder(
			$home_currency_order,
			oras_qbo_reclass_split( 49.20 ),
			static function ( string $query, int $call_number, array $call ) use ( $home_currency_receipt ): array {
				if ( strpos( (string) $call['url'], '/preferences' ) !== false ) {
					return array(
						'Preferences' => array(
							'CurrencyPrefs' => array(
								'MultiCurrencyEnabled' => false,
								'HomeCurrency'         => array( 'value' => 'USD' ),
							),
						),
					);
				}
				return array( 'QueryResponse' => array( 'SalesReceipt' => array( $home_currency_receipt ) ) );
			},
			$home_currency_calls
		);
		oras_qbo_reclass_assert_true( 'documented home-currency omission is accepted', is_array( $home_currency_result ) );
		if ( is_array( $home_currency_result ) ) {
			oras_qbo_reclass_assert_same(
				'home currency context retained for fingerprinting',
				(string) ( $home_currency_result['home_currency'] ?? '' ),
				'USD'
			);
		}

		// 10b) Fingerprints normalize harmless response formatting and retain all material fields.
		$fingerprint_creator = new \ORAS\Tickets\Integrations\QuickBooks\Journal_Entry_Creator();
		$fingerprint_base = array(
			'DocNumber'     => 'ORAS-RC-123',
			'TxnDate'       => '2026-03-07',
			'PrivateNote'   => 'ORAS fingerprint fixture',
			'CurrencyRef'   => array( 'value' => 'USD' ),
			'DepartmentRef' => array( 'value' => 'department-1' ),
			'Line'          => array(
				array(
					'Amount'                 => 49.20,
					'Description'            => 'Debit description',
					'DetailType'             => 'JournalEntryLineDetail',
					'JournalEntryLineDetail' => array(
						'PostingType' => 'Debit',
						'AccountRef'  => array( 'value' => '4000' ),
						'ClassRef'    => array( 'value' => 'class-1' ),
						'Entity'      => array(
							'Type'      => 'Customer',
							'EntityRef' => array( 'value' => 'customer-1' ),
						),
					),
				),
				array(
					'Amount'                 => 49.20,
					'Description'            => 'Credit description',
					'DetailType'             => 'JournalEntryLineDetail',
					'JournalEntryLineDetail' => array(
						'PostingType' => 'Credit',
						'AccountRef'  => array( 'value' => '4200' ),
						'ClassRef'    => array( 'value' => 'class-2' ),
						'Entity'      => array(
							'Type'      => 'Customer',
							'EntityRef' => array( 'value' => 'customer-1' ),
						),
					),
				),
			),
		);
		$fingerprint_normalized = $fingerprint_base;
		unset( $fingerprint_normalized['CurrencyRef'] );
		$fingerprint_normalized['ExchangeRate'] = '1.000000';
		$fingerprint_normalized['PrivateNote'] = '  ORAS fingerprint fixture  ';
		$fingerprint_normalized['Line'] = array_reverse( $fingerprint_normalized['Line'] );
		$fingerprint_normalized['Line'][0]['Amount'] = '49.200';
		$fingerprint_normalized['Line'][0]['JournalEntryLineDetail']['AccountRef']['name'] = 'Ignored name';
		oras_qbo_reclass_assert_same(
			'harmless QBO fingerprint normalization',
			$fingerprint_creator->get_accounting_fingerprint( $fingerprint_normalized, 'USD' ),
			$fingerprint_creator->get_accounting_fingerprint( $fingerprint_base, 'USD' )
		);
		$fingerprint_currency_case = $fingerprint_base;
		$fingerprint_currency_case['CurrencyRef']['value'] = 'usd';
		oras_qbo_reclass_assert_same(
			'only CurrencyRef.value case normalizes harmlessly',
			$fingerprint_creator->get_accounting_fingerprint( $fingerprint_currency_case, 'USD' ),
			$fingerprint_creator->get_accounting_fingerprint( $fingerprint_base, 'USD' )
		);

		$material_mutations = array(
			'currency'               => static function ( array $entry ): array {
				$entry['CurrencyRef']['value'] = 'EUR';
				return $entry;
			},
			'exchange rate'          => static function ( array $entry ): array {
				$entry['ExchangeRate'] = 1.25;
				return $entry;
			},
			'department'             => static function ( array $entry ): array {
				$entry['DepartmentRef']['value'] = 'department-2';
				return $entry;
			},
			'class'                  => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['ClassRef']['value'] = 'class-9';
				return $entry;
			},
			'description'            => static function ( array $entry ): array {
				$entry['Line'][0]['Description'] = 'Materially different';
				return $entry;
			},
			'date'                   => static function ( array $entry ): array {
				$entry['TxnDate'] = '2026-03-08';
				return $entry;
			},
			'note'                   => static function ( array $entry ): array {
				$entry['PrivateNote'] = 'Different note';
				return $entry;
			},
			'account'                => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['AccountRef']['value'] = '4999';
				return $entry;
			},
			'amount'                 => static function ( array $entry ): array {
				$entry['Line'][0]['Amount'] = 49.21;
				return $entry;
			},
			'entity'                 => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['Entity']['EntityRef']['value'] = 'customer-2';
				return $entry;
			},
			'detail type'            => static function ( array $entry ): array {
				$entry['Line'][0]['DetailType'] = 'DifferentLineDetail';
				return $entry;
			},
			'posting type case'      => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['PostingType'] = 'debit';
				return $entry;
			},
			'detail type case'       => static function ( array $entry ): array {
				$entry['Line'][0]['DetailType'] = 'journalentrylinedetail';
				return $entry;
			},
			'entity type case'       => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['Entity']['Type'] = 'customer';
				return $entry;
			},
			'account reference case' => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['AccountRef']['value'] = 'AbCd';
				return $entry;
			},
		);
		$base_fingerprint = $fingerprint_creator->get_accounting_fingerprint( $fingerprint_base, 'USD' );
		foreach ( $material_mutations as $mutation_label => $mutation ) {
			oras_qbo_reclass_assert_true(
				'material fingerprint difference: ' . $mutation_label,
				$fingerprint_creator->get_accounting_fingerprint( $mutation( $fingerprint_base ), 'USD' ) !== $base_fingerprint
			);
		}

		// 11) Full sync prepares once, persists intent before POST, and records the exact posted source/payload.
		$single_order = oras_qbo_reclass_create_order( $product_id, 51.00, 51.00, '2026-03-08', 'pi_single_prepare' );
		$single_source_id = 'single-source-' . (string) $single_order->get_id();
		$single_receipt = oras_qbo_reclass_sales_receipt(
			$single_source_id,
			51.00,
			'2026-03-08',
			'ORAS Order: fixture - Order ' . $single_order->get_order_number()
		);
		$single_calls = array();
		$single_source_queries = 0;
		$single_posts = 0;
		$single_post_hash = '';
		$single_request_id = '';
		$single_result = oras_qbo_reclass_sync_with_responder(
			(int) $single_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $single_receipt, $single_order, $single_source_id, &$single_source_queries, &$single_posts, &$single_post_hash, &$single_request_id ) {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					$single_source_queries++;
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $single_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					$single_posts++;
					$posted = json_decode( (string) $call['body'], true );
					$pending_order = wc_get_order( (int) $single_order->get_id() );
					$pending = json_decode( (string) $pending_order->get_meta( '_oras_qbo_pending_write', true ), true );
					oras_qbo_reclass_assert_true( 'pending write exists before POST', is_array( $pending ) );
					$encoded_post = wp_json_encode( $posted );
					$single_post_hash = hash( 'sha256', is_string( $encoded_post ) ? $encoded_post : '' );
					$single_request_id = oras_qbo_reclass_request_id( $call );
					oras_qbo_reclass_assert_same( 'pending payload hash matches POST', (string) ( $pending['payload_hash'] ?? '' ), $single_post_hash );
					oras_qbo_reclass_assert_same( 'pending request ID matches POST', (string) ( $pending['request_id'] ?? '' ), $single_request_id );
					oras_qbo_reclass_assert_same( 'request ID is derived from exact payload', $single_request_id, 'oras-' . substr( $single_post_hash, 0, 45 ) );
					oras_qbo_reclass_assert_same( 'pending source matches POST source', (string) ( $pending['source_match']['id'] ?? '' ), $single_source_id );
					oras_qbo_reclass_assert_same( 'source is reserved before POST', (string) $pending_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), 'salesreceipt:' . $single_source_id );
					oras_qbo_reclass_assert_same( 'source reservation is pending before POST', (string) $pending_order->get_meta( '_oras_qbo_source_claim_state', true ), 'pending' );
					return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-single' );
				}
				return array( 'QueryResponse' => array() );
			},
			$single_calls
		);
		oras_qbo_reclass_assert_true( 'single preparation sync succeeds', is_array( $single_result ) );
		oras_qbo_reclass_assert_same( 'single preparation source query count', $single_source_queries, 1 );
		oras_qbo_reclass_assert_same( 'single preparation POST count', $single_posts, 1 );
		oras_qbo_reclass_assert_true( 'single preparation request ID is present', $single_request_id !== '' && strlen( $single_request_id ) <= 50 );
		$single_order = wc_get_order( (int) $single_order->get_id() );
		oras_qbo_reclass_assert_same( 'posted payload hash persisted', (string) $single_order->get_meta( '_oras_qbo_last_payload_hash', true ), $single_post_hash );
		oras_qbo_reclass_assert_same( 'posted source ID persisted', (string) $single_order->get_meta( '_oras_qbo_reclass_source_txn_id', true ), $single_source_id );
		oras_qbo_reclass_assert_same( 'pending write cleared after success', (string) $single_order->get_meta( '_oras_qbo_pending_write', true ), '' );
		oras_qbo_reclass_assert_same( 'write state cleared after success', (string) $single_order->get_meta( '_oras_qbo_write_state', true ), '' );
		oras_qbo_reclass_assert_same( 'source reservation finalized after success', (string) $single_order->get_meta( '_oras_qbo_source_claim_state', true ), '' );

		// 11b) Conclusively pre-dispatch failures clear intent and never become unknown outcomes.
		$pre_dispatch_order = oras_qbo_reclass_create_order( $product_id, 51.25, 51.25, '2026-03-08' );
		$pre_dispatch_receipt = oras_qbo_reclass_sales_receipt(
			'pre-dispatch-source',
			51.25,
			'2026-03-08',
			'Order ' . $pre_dispatch_order->get_order_number()
		);
		$pre_dispatch_calls = array();
		$pre_dispatch_je_posts = 0;
		$pre_dispatch_result = oras_qbo_reclass_sync_with_responder(
			(int) $pre_dispatch_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $pre_dispatch_receipt, &$pre_dispatch_je_posts ): array|\WP_Error {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $pre_dispatch_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
						array( 'realm_id' => '' )
					);
					return array( 'QueryResponse' => array() );
				}
				if ( strpos( (string) $call['url'], '/journalentry' ) !== false && $method === 'POST' ) {
					$pre_dispatch_je_posts++;
				}
				return array( 'QueryResponse' => array() );
			},
			$pre_dispatch_calls
		);
		oras_qbo_reclass_assert_error_code( 'missing realm before dispatch', $pre_dispatch_result, 'oras_qbo_missing_realm' );
		$pre_dispatch_order = wc_get_order( (int) $pre_dispatch_order->get_id() );
		oras_qbo_reclass_assert_same( 'pre-dispatch failure sends no JE POST', $pre_dispatch_je_posts, 0 );
		oras_qbo_reclass_assert_same( 'pre-dispatch failure clears pending intent', (string) $pre_dispatch_order->get_meta( '_oras_qbo_pending_write', true ), '' );
		oras_qbo_reclass_assert_same( 'pre-dispatch failure clears source claim', (string) $pre_dispatch_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), '' );
		oras_qbo_reclass_assert_true( 'pre-dispatch failure is not unknown', (string) $pre_dispatch_order->get_meta( '_oras_qbo_write_state', true ) !== 'unknown_outcome' );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$refresh_failure_order = oras_qbo_reclass_create_order( $product_id, 51.50, 51.50, '2026-03-08' );
		$refresh_failure_receipt = oras_qbo_reclass_sales_receipt(
			'refresh-failure-source-' . (string) $refresh_failure_order->get_id(),
			51.50,
			'2026-03-08',
			'Order ' . $refresh_failure_order->get_order_number()
		);
		$refresh_failure_calls = array();
		$refresh_failure_je_posts = 0;
		$refresh_failure_result = oras_qbo_reclass_sync_with_responder(
			(int) $refresh_failure_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $refresh_failure_receipt, &$refresh_failure_je_posts ): array|\WP_Error {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $refresh_failure_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
						array( 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) )
					);
					return array( 'QueryResponse' => array() );
				}
				if ( strpos( (string) $call['url'], 'oauth.platform.intuit.com' ) !== false ) {
					return new \WP_Error( 'http_request_failed', 'Token refresh timed out before JournalEntry dispatch.' );
				}
				if ( strpos( (string) $call['url'], '/journalentry' ) !== false && $method === 'POST' ) {
					$refresh_failure_je_posts++;
				}
				return array( 'QueryResponse' => array() );
			},
			$refresh_failure_calls
		);
		oras_qbo_reclass_assert_error_code( 'token refresh timeout before dispatch', $refresh_failure_result, 'http_request_failed' );
		$refresh_failure_order = wc_get_order( (int) $refresh_failure_order->get_id() );
		oras_qbo_reclass_assert_same( 'token refresh failure sends no JE POST', $refresh_failure_je_posts, 0 );
		oras_qbo_reclass_assert_same( 'token refresh failure clears pending intent', (string) $refresh_failure_order->get_meta( '_oras_qbo_pending_write', true ), '' );
		oras_qbo_reclass_assert_same( 'token refresh failure clears source claim', (string) $refresh_failure_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), '' );
		oras_qbo_reclass_assert_true( 'token refresh failure is not unknown', (string) $refresh_failure_order->get_meta( '_oras_qbo_write_state', true ) !== 'unknown_outcome' );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );

		$before_dispatch_http_calls = 0;
		$before_dispatch_http_spy = static function () use ( &$before_dispatch_http_calls ): \WP_Error {
			++$before_dispatch_http_calls;
			return new \WP_Error( 'unexpected_http', 'Pre-dispatch callback failure must prevent HTTP.' );
		};
		add_filter( 'pre_http_request', $before_dispatch_http_spy, 10, 3 );
		try {
			$before_dispatch_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Api_Client() )->create_journal_entry(
				array( 'DocNumber' => 'PRE-DISPATCH-TEST' ),
				static function (): \WP_Error {
					return new \WP_Error( 'oras_qbo_write_reservation_failed', 'Injected reservation failure.' );
				}
			);
		} finally {
			remove_filter( 'pre_http_request', $before_dispatch_http_spy, 10 );
		}
		oras_qbo_reclass_assert_error_code( 'direct API callback cannot authorize dispatch', $before_dispatch_result, 'oras_qbo_orchestrator_required' );
		oras_qbo_reclass_assert_same( 'failure immediately before dispatch HTTP count', $before_dispatch_http_calls, 0 );
		if ( is_wp_error( $before_dispatch_result ) ) {
			$before_dispatch_data = $before_dispatch_result->get_error_data();
			oras_qbo_reclass_assert_same(
				'failure immediately before dispatch is tagged as not dispatched',
				(bool) ( is_array( $before_dispatch_data ) ? ( $before_dispatch_data['qbo_request_dispatched'] ?? true ) : true ),
				false
			);
		}

		$escaped_lookup_calls = array();
		$escaped_lookup_result = oras_qbo_reclass_entrypoint_with_responder(
			static function () {
				return ( new \ORAS\Tickets\Integrations\QuickBooks\Api_Client() )
				->find_journal_entry_by_doc_number( "ORAS-RC-O'Brien" );
			},
			static function (): array {
				return array( 'QueryResponse' => array() );
			},
			$escaped_lookup_calls
		);
		oras_qbo_reclass_assert_true( 'escaped DocNumber lookup succeeds', is_array( $escaped_lookup_result ) );
		oras_qbo_reclass_assert_true(
			'DocNumber apostrophe is escaped for QuickBooks query',
			isset( $escaped_lookup_calls[0]['query'] )
			&& strpos( (string) $escaped_lookup_calls[0]['query'], "ORAS-RC-O\\'Brien" ) !== false
		);

		// 11c) Explicit unknown-write reconciliation fails closed and can reuse only the persisted payload.
		$reconcile_absent_order = oras_qbo_reclass_create_order( $product_id, 51.60, 51.60, '2026-03-08' );
		oras_qbo_reclass_seed_unknown_write( $reconcile_absent_order, 51.60 );
		$reconcile_absent_meta_before = oras_qbo_reclass_order_meta_snapshot( $reconcile_absent_order );
		$reconcile_absent_calls = array();
		$reconcile_absent_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_absent_order->get_id(),
			false,
			static function (): array {
				return array( 'QueryResponse' => array() );
			},
			$reconcile_absent_calls
		);
		oras_qbo_reclass_assert_same( 'reconciliation absent without retry is inventory-only', (string) ( $reconcile_absent_result['status'] ?? '' ), 'remote_absent_read_only' );
		$reconcile_absent_order = wc_get_order( (int) $reconcile_absent_order->get_id() );
		oras_qbo_reclass_assert_same( 'absent reconciliation retains unknown state', (string) $reconcile_absent_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_same( 'absent reconciliation verifies currency and performs one JE GET', count( $reconcile_absent_calls ), 2 );
		oras_qbo_reclass_assert_same(
			'absent reconciliation verifies company home currency',
			count( array_filter( $reconcile_absent_calls, static fn ( array $call ): bool => strpos( (string) $call['url'], '/preferences' ) !== false ) ),
			1
		);
		oras_qbo_reclass_assert_same( 'absent reconciliation writes no metadata or audit', oras_qbo_reclass_order_meta_snapshot( $reconcile_absent_order ), $reconcile_absent_meta_before );

		$reconcile_exact_order = oras_qbo_reclass_create_order( $product_id, 51.70, 51.70, '2026-03-08' );
		$reconcile_exact_pending = oras_qbo_reclass_seed_unknown_write( $reconcile_exact_order, 51.70 );
		$reconcile_exact_meta_before = oras_qbo_reclass_order_meta_snapshot( $reconcile_exact_order );
		$reconcile_exact_entry = $reconcile_exact_pending['payload'];
		$reconcile_exact_entry['Id'] = 'JE-reconciled-exact';
		$reconcile_exact_calls = array();
		$reconcile_exact_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_exact_order->get_id(),
			false,
			static function () use ( $reconcile_exact_entry ): array {
				return array( 'QueryResponse' => array( 'JournalEntry' => array( $reconcile_exact_entry ) ) );
			},
			$reconcile_exact_calls
		);
		oras_qbo_reclass_assert_true( 'exact reconciliation succeeds', is_array( $reconcile_exact_result ) );
		oras_qbo_reclass_assert_same( 'exact lookup reports existing JE without adoption', (string) ( $reconcile_exact_result['status'] ?? '' ), 'remote_found_read_only' );
		$reconcile_exact_order = wc_get_order( (int) $reconcile_exact_order->get_id() );
		oras_qbo_reclass_assert_same( 'exact lookup does not persist JE ID', (string) $reconcile_exact_order->get_meta( '_oras_qbo_je_id', true ), '' );
		oras_qbo_reclass_assert_same( 'exact lookup preserves unknown state', (string) $reconcile_exact_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_same( 'exact lookup writes no metadata or audit', oras_qbo_reclass_order_meta_snapshot( $reconcile_exact_order ), $reconcile_exact_meta_before );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
		$disabled_read_calls = array();
		$disabled_read_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_exact_order->get_id(),
			false,
			static function ( string $method, string $query ) use ( $reconcile_exact_entry ): array {
				return strpos( $query, 'FROM JournalEntry' ) !== false
				? array( 'QueryResponse' => array( 'JournalEntry' => array( $reconcile_exact_entry ) ) )
				: array( 'QueryResponse' => array() );
			},
			$disabled_read_calls
		);
		oras_qbo_reclass_assert_same( 'disabled current-format lookup remains reachable', (string) ( $disabled_read_result['status'] ?? '' ), 'remote_found_read_only' );
		oras_qbo_reclass_assert_same( 'disabled current-format lookup sends no POST', count( array_filter( $disabled_read_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );
		$disabled_retry_calls = array();
		$disabled_retry_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_exact_order->get_id(),
			true,
			static function (): \WP_Error {
				return new \WP_Error( 'unexpected_http', 'Disabled reconciliation retry must not contact QuickBooks.' );
			},
			$disabled_retry_calls
		);
		oras_qbo_reclass_assert_error_code( 'disabled current-format retry is rejected', $disabled_retry_result, 'oras_qbo_disabled' );
		oras_qbo_reclass_assert_same( 'disabled current-format retry makes no request', count( $disabled_retry_calls ), 0 );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );

		$reconcile_mismatch_order = oras_qbo_reclass_create_order( $product_id, 51.80, 51.80, '2026-03-08' );
		$reconcile_mismatch_pending = oras_qbo_reclass_seed_unknown_write( $reconcile_mismatch_order, 51.80 );
		$reconcile_mismatch_meta_before = oras_qbo_reclass_order_meta_snapshot( $reconcile_mismatch_order );
		$reconcile_mismatch_entry = $reconcile_mismatch_pending['payload'];
		$reconcile_mismatch_entry['Id'] = 'JE-reconciled-mismatch';
		$reconcile_mismatch_entry['Line'][0]['Amount'] = 999.99;
		$reconcile_mismatch_calls = array();
		$reconcile_mismatch_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_mismatch_order->get_id(),
			false,
			static function () use ( $reconcile_mismatch_entry ): array {
				return array( 'QueryResponse' => array( 'JournalEntry' => array( $reconcile_mismatch_entry ) ) );
			},
			$reconcile_mismatch_calls
		);
		oras_qbo_reclass_assert_error_code( 'mismatched reconciliation', $reconcile_mismatch_result, 'oras_qbo_existing_je_mismatch' );
		$reconcile_mismatch_order = wc_get_order( (int) $reconcile_mismatch_order->get_id() );
		oras_qbo_reclass_assert_same( 'mismatched reconciliation retains unknown state', (string) $reconcile_mismatch_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_same( 'mismatched reconciliation does not adopt JE', (string) $reconcile_mismatch_order->get_meta( '_oras_qbo_je_id', true ), '' );
		oras_qbo_reclass_assert_same( 'mismatched read-only reconciliation writes no metadata', oras_qbo_reclass_order_meta_snapshot( $reconcile_mismatch_order ), $reconcile_mismatch_meta_before );

		$reconcile_duplicate_order = oras_qbo_reclass_create_order( $product_id, 51.90, 51.90, '2026-03-08' );
		$reconcile_duplicate_pending = oras_qbo_reclass_seed_unknown_write( $reconcile_duplicate_order, 51.90 );
		$reconcile_duplicate_meta_before = oras_qbo_reclass_order_meta_snapshot( $reconcile_duplicate_order );
		$reconcile_duplicate_one = $reconcile_duplicate_pending['payload'];
		$reconcile_duplicate_one['Id'] = 'JE-reconciled-duplicate-1';
		$reconcile_duplicate_two = $reconcile_duplicate_pending['payload'];
		$reconcile_duplicate_two['Id'] = 'JE-reconciled-duplicate-2';
		$reconcile_duplicate_calls = array();
		$reconcile_duplicate_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_duplicate_order->get_id(),
			false,
			static function () use ( $reconcile_duplicate_one, $reconcile_duplicate_two ): array {
				return array( 'QueryResponse' => array( 'JournalEntry' => array( $reconcile_duplicate_one, $reconcile_duplicate_two ) ) );
			},
			$reconcile_duplicate_calls
		);
		oras_qbo_reclass_assert_error_code( 'duplicate reconciliation', $reconcile_duplicate_result, 'oras_qbo_duplicate_lookup_failed' );
		$reconcile_duplicate_order = wc_get_order( (int) $reconcile_duplicate_order->get_id() );
		oras_qbo_reclass_assert_same( 'duplicate reconciliation retains unknown state', (string) $reconcile_duplicate_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_same( 'duplicate read-only reconciliation writes no metadata', oras_qbo_reclass_order_meta_snapshot( $reconcile_duplicate_order ), $reconcile_duplicate_meta_before );

		$reconcile_lookup_order = oras_qbo_reclass_create_order( $product_id, 52.10, 52.10, '2026-03-08' );
		oras_qbo_reclass_seed_unknown_write( $reconcile_lookup_order, 52.10 );
		$reconcile_lookup_calls = array();
		$reconcile_lookup_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_lookup_order->get_id(),
			true,
			static function ( string $method, string $query, array $call ): array|\WP_Error {
				if ( strpos( (string) $call['url'], '/preferences' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				return new \WP_Error( 'http_request_failed', 'Reconciliation lookup timed out.' );
			},
			$reconcile_lookup_calls
		);
		oras_qbo_reclass_assert_error_code( 'failed reconciliation lookup', $reconcile_lookup_result, 'oras_qbo_duplicate_lookup_failed' );
		$reconcile_lookup_order = wc_get_order( (int) $reconcile_lookup_order->get_id() );
		oras_qbo_reclass_assert_same( 'failed lookup retains unknown state', (string) $reconcile_lookup_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );

		$reconcile_corrupt_order = oras_qbo_reclass_create_order( $product_id, 52.15, 52.15, '2026-03-08' );
		$reconcile_corrupt_pending = oras_qbo_reclass_seed_unknown_write( $reconcile_corrupt_order, 52.15 );
		$reconcile_corrupt_pending['payload']['Line'][0]['Amount'] = 999.99;
		$reconcile_corrupt_order->update_meta_data( '_oras_qbo_pending_write', wp_json_encode( $reconcile_corrupt_pending ) );
		$reconcile_corrupt_order->save();
		$reconcile_corrupt_calls = array();
		$reconcile_corrupt_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_corrupt_order->get_id(),
			true,
			static function (): \WP_Error {
				return new \WP_Error( 'unexpected_http', 'Corrupt pending payload must fail before lookup or POST.' );
			},
			$reconcile_corrupt_calls
		);
		oras_qbo_reclass_assert_error_code( 'corrupt pending payload reconciliation', $reconcile_corrupt_result, 'oras_qbo_pending_write_invalid' );
		oras_qbo_reclass_assert_same( 'corrupt pending payload makes no request', count( $reconcile_corrupt_calls ), 0 );
		$reconcile_corrupt_order = wc_get_order( (int) $reconcile_corrupt_order->get_id() );
		oras_qbo_reclass_assert_same( 'corrupt pending payload retains unknown state', (string) $reconcile_corrupt_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );

		$reconcile_grace_order = oras_qbo_reclass_create_order( $product_id, 52.20, 52.20, '2026-03-08' );
		oras_qbo_reclass_seed_unknown_write( $reconcile_grace_order, 52.20, 10 );
		$reconcile_grace_calls = array();
		$reconcile_grace_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_grace_order->get_id(),
			true,
			static function (): array {
				return array( 'QueryResponse' => array() );
			},
			$reconcile_grace_calls
		);
		oras_qbo_reclass_assert_error_code( 'reconciliation retry grace', $reconcile_grace_result, 'oras_qbo_pending_write_grace_period' );
		oras_qbo_reclass_assert_same( 'grace block sends no POST', count( array_filter( $reconcile_grace_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );

		$reconcile_retry_order = oras_qbo_reclass_create_order( $product_id, 52.30, 52.30, '2026-03-08' );
		$reconcile_retry_pending = oras_qbo_reclass_seed_unknown_write( $reconcile_retry_order, 52.30, 600 );
		$reconcile_retry_calls = array();
		$reconcile_retry_body = '';
		$reconcile_retry_request_id = '';
		$reconcile_retry_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $reconcile_retry_order->get_id(),
			true,
			static function ( string $method, string $query, array $call ) use ( &$reconcile_retry_body, &$reconcile_retry_request_id ): array {
				if ( $method === 'POST' ) {
					$reconcile_retry_body = (string) $call['body'];
					$reconcile_retry_request_id = oras_qbo_reclass_request_id( $call );
					return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-reconciliation-retry' );
				}
				return array( 'QueryResponse' => array() );
			},
			$reconcile_retry_calls
		);
		oras_qbo_reclass_assert_true( 'explicit reconciliation retry succeeds', is_array( $reconcile_retry_result ) );
		oras_qbo_reclass_assert_same( 'explicit reconciliation retry status', (string) ( $reconcile_retry_result['status'] ?? '' ), 'synced' );
		oras_qbo_reclass_assert_same( 'explicit reconciliation retry uses exact persisted payload', $reconcile_retry_body, (string) wp_json_encode( $reconcile_retry_pending['payload'] ) );
		oras_qbo_reclass_assert_same( 'explicit reconciliation retry reuses persisted request ID', $reconcile_retry_request_id, (string) $reconcile_retry_pending['request_id'] );
		oras_qbo_reclass_assert_same( 'explicit reconciliation retry sends one POST', count( array_filter( $reconcile_retry_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

		// 11d) All dry-run production entry points remain local, read-only previews.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			oras_qbo_reclass_settings(
				array(
					'dry_run_mode'        => true,
					'posting_mode'        => 'clearing',
					'clearing_account_id' => '4000',
				)
			)
		);
		$dry_normal_order = oras_qbo_reclass_create_order( $product_id, 52.40, 52.40, '2026-03-08' );
		$dry_normal_result = oras_qbo_reclass_assert_dry_operation(
			'normal dry sync',
			$dry_normal_order,
			static function () use ( $dry_normal_order ) {
				return ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->sync_order( (int) $dry_normal_order->get_id() );
			}
		);
		oras_qbo_reclass_assert_same( 'normal dry sync status', (string) ( $dry_normal_result['status'] ?? '' ), 'dry_run' );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			oras_qbo_reclass_settings( array( 'dry_run_mode' => true ) )
		);
		$dry_reclass_order = oras_qbo_reclass_create_order( $product_id, 52.50, 52.50, '2026-03-08' );
		$dry_reclass_result = oras_qbo_reclass_assert_dry_operation(
			'reclass dry sync',
			$dry_reclass_order,
			static function () use ( $dry_reclass_order ) {
				return ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->sync_order( (int) $dry_reclass_order->get_id() );
			}
		);
		oras_qbo_reclass_assert_same( 'reclass dry sync status', (string) ( $dry_reclass_result['status'] ?? '' ), 'dry_run' );
		oras_qbo_reclass_assert_same( 'reclass dry source is explicitly unvalidated', (bool) ( $dry_reclass_result['remote_source_validated'] ?? true ), false );

		$dry_reversal_order = oras_qbo_reclass_create_order( $product_id, 52.60, 52.60, '2026-03-08' );
		$dry_reversal_order->update_meta_data( '_oras_qbo_je_id', 'JE-dry-primary' );
		$dry_reversal_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 52.60 ) ) );
		$dry_reversal_order->save();
		$dry_reversal_result = oras_qbo_reclass_assert_dry_operation(
			'reversal dry sync',
			$dry_reversal_order,
			static function () use ( $dry_reversal_order ) {
				return ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->reverse_order( (int) $dry_reversal_order->get_id() );
			}
		);
		oras_qbo_reclass_assert_same( 'reversal dry sync status', (string) ( $dry_reversal_result['status'] ?? '' ), 'reversal_dry_run' );

		$dry_resync_order = oras_qbo_reclass_create_order( $product_id, 52.70, 52.70, '2026-03-08' );
		$dry_resync_order->update_meta_data( '_oras_qbo_je_id', 'JE-dry-resync-primary' );
		$dry_resync_order->update_meta_data( '_oras_qbo_je_hash', 'old-hash' );
		$dry_resync_order->save();
		$dry_resync_result = oras_qbo_reclass_assert_dry_operation(
			'orchestrator resync dry run',
			$dry_resync_order,
			static function () use ( $dry_resync_order ) {
				return ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->resync_order( (int) $dry_resync_order->get_id() );
			}
		);
		oras_qbo_reclass_assert_same( 'orchestrator dry resync status', (string) ( $dry_resync_result['status'] ?? '' ), 'dry_run' );

		$dry_admin_order = oras_qbo_reclass_create_order( $product_id, 52.80, 52.80, '2026-03-08' );
		oras_qbo_reclass_assert_dry_operation(
			'admin resync dry run',
			$dry_admin_order,
			static function () use ( $dry_admin_order ): void {
				oras_qbo_reclass_invoke_admin_handler(
					'handle_resync_order',
					'oras_tickets_qbo_resync_order',
					array( 'order_id' => (string) $dry_admin_order->get_id() ),
					false
				);
			}
		);

		$dry_error_product = new \WC_Product_Simple();
		$dry_error_product->set_name( 'QBO Dry Error Fixture' );
		$dry_error_product->set_regular_price( '12.00' );
		$dry_error_product->set_price( '12.00' );
		oras_qbo_fixture_track_product( (int) $dry_error_product->save() );
		$dry_error_order = oras_qbo_reclass_create_order( (int) $dry_error_product->get_id(), 12.00, 12.00, '2026-03-08' );

		$dry_admin_error_paths = array(
			'admin sync error dry run'     => array(
				'method' => 'handle_sync_order_now',
				'nonce'  => 'oras_tickets_qbo_sync_order_now',
				'post'   => array( 'order_id' => (string) $dry_error_order->get_id() ),
			),
			'admin approval error dry run' => array(
				'method' => 'handle_approve_order',
				'nonce'  => 'oras_tickets_qbo_approve_order',
				'post'   => array(
					'order_id' => (string) $dry_error_order->get_id(),
					'sync_now' => '1',
				),
			),
			'admin resync error dry run'   => array(
				'method' => 'handle_resync_order',
				'nonce'  => 'oras_tickets_qbo_resync_order',
				'post'   => array( 'order_id' => (string) $dry_error_order->get_id() ),
			),
		);
		foreach ( $dry_admin_error_paths as $dry_error_label => $dry_error_path ) {
			oras_qbo_reclass_assert_dry_operation(
				$dry_error_label,
				$dry_error_order,
				static function () use ( $dry_error_path ): void {
					oras_qbo_reclass_invoke_admin_handler(
						(string) $dry_error_path['method'],
						(string) $dry_error_path['nonce'],
						(array) $dry_error_path['post'],
						false
					);
				}
			);
		}

		$dry_reverse_error_order = oras_qbo_reclass_create_order( $product_id, 12.50, 12.50, '2026-03-08' );
		oras_qbo_reclass_assert_dry_operation(
			'admin reversal error dry run',
			$dry_reverse_error_order,
			static function () use ( $dry_reverse_error_order ): void {
				oras_qbo_reclass_invoke_admin_handler(
					'handle_reverse_order',
					'oras_tickets_qbo_reverse_order',
					array( 'order_id' => (string) $dry_reverse_error_order->get_id() ),
					false
				);
			}
		);

		oras_qbo_reclass_assert_dry_operation(
			'admin test JournalEntry dry run',
			$dry_error_order,
			static function (): void {
				oras_qbo_reclass_invoke_admin_handler(
					'handle_test_journal_entry',
					'oras_tickets_qbo_test_journal_entry',
					array(),
					false
				);
			}
		);

		oras_qbo_reclass_assert_dry_operation(
			'admin waiting queue dry run',
			$dry_error_order,
			static function (): void {
				oras_qbo_reclass_invoke_admin_handler(
					'handle_process_waiting_queue',
					'oras_tickets_qbo_process_waiting_queue',
					array( 'limit' => '10' ),
					false
				);
			}
		);

		$dry_cli_order = oras_qbo_reclass_create_order( $product_id, 52.90, 52.90, '2026-03-08' );
		$dry_cli_order->update_meta_data( '_oras_qbo_je_id', 'JE-dry-cli-primary' );
		$dry_cli_order->save();
		oras_qbo_reclass_assert_dry_operation(
			'CLI resync dry run',
			$dry_cli_order,
			static function () use ( $dry_cli_order ): void {
				$command = new \ORAS\Tickets\Integrations\QuickBooks\Cli_Command(
					new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(),
					new \ORAS\Tickets\Integrations\QuickBooks\Api_Client()
				);
				$command->resync_order( array( (string) $dry_cli_order->get_id() ), array() );
			}
		);

		$direct_order = oras_qbo_reclass_create_order( $product_id, 53.00, 53.00, '2026-03-08' );
		$direct_creator = new \ORAS\Tickets\Integrations\QuickBooks\Journal_Entry_Creator();
		$direct_prepared = $direct_creator->build_payload_for_order(
			$direct_order,
			oras_qbo_reclass_split( 53.00 ),
			\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings(),
			false,
			'',
			false
		);
		oras_qbo_reclass_assert_true( 'local direct-write fixture prepares', is_array( $direct_prepared ) );
		$direct_http_calls = 0;
		$direct_http_spy = static function () use ( &$direct_http_calls ): \WP_Error {
			++$direct_http_calls;
			return new \WP_Error( 'unexpected_http', 'Direct write bypass must not reach HTTP.' );
		};
		add_filter( 'pre_http_request', $direct_http_spy, 10, 3 );
		try {
			$direct_prepared_result = $direct_creator->create_prepared_for_order( $direct_order, $direct_prepared, false );
			$direct_build_result = $direct_creator->create_for_order(
				$direct_order,
				oras_qbo_reclass_split( 53.00 ),
				\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings()
			);
			$direct_reversal_result = $direct_creator->create_reversal_for_order(
				$direct_order,
				oras_qbo_reclass_split( 53.00 ),
				\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings(),
				'JE-direct-primary'
			);
		} finally {
			remove_filter( 'pre_http_request', $direct_http_spy, 10 );
		}
		oras_qbo_reclass_assert_same( 'direct write bypass HTTP count', $direct_http_calls, 0 );
		oras_qbo_reclass_assert_error_code( 'prepared direct write bypass', $direct_prepared_result, 'oras_qbo_orchestrator_required' );
		oras_qbo_reclass_assert_error_code( 'build-and-write direct bypass', $direct_build_result, 'oras_qbo_orchestrator_required' );
		oras_qbo_reclass_assert_error_code( 'reversal direct write bypass', $direct_reversal_result, 'oras_qbo_orchestrator_required' );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );

		// 11e) Admin and CLI resync entry points retain one order lock across reset and sync.
		$atomic_admin_order = oras_qbo_reclass_create_order( $product_id, 53.10, 53.10, '2026-03-08' );
		$atomic_admin_order->update_meta_data( '_oras_qbo_je_id', 'JE-admin-old' );
		$atomic_admin_order->update_meta_data( '_oras_qbo_je_hash', 'admin-old-hash' );
		$atomic_admin_order->save();
		$atomic_admin_receipt = oras_qbo_reclass_sales_receipt(
			'atomic-admin-source-' . (string) $atomic_admin_order->get_id(),
			53.10,
			'2026-03-08',
			'Order ' . $atomic_admin_order->get_order_number()
		);
		$atomic_admin_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $atomic_admin_order->get_id() ), 0, 40 );
		$atomic_admin_lock_acquires = 0;
		$atomic_admin_lock_releases = 0;
		$atomic_admin_lock_spy = static function ( string $query ) use ( $atomic_admin_lock_name, &$atomic_admin_lock_acquires, &$atomic_admin_lock_releases ): string {
			if ( strpos( $query, $atomic_admin_lock_name ) !== false && strpos( $query, 'GET_LOCK' ) !== false ) {
				++$atomic_admin_lock_acquires;
			}
			if ( strpos( $query, $atomic_admin_lock_name ) !== false && strpos( $query, 'RELEASE_LOCK' ) !== false ) {
				++$atomic_admin_lock_releases;
			}
			return $query;
		};
		$atomic_admin_calls = array();
		add_filter( 'query', $atomic_admin_lock_spy, 10, 1 );
		try {
			oras_qbo_reclass_entrypoint_with_responder(
				static function () use ( $atomic_admin_order ): void {
					oras_qbo_reclass_invoke_admin_handler(
						'handle_resync_order',
						'oras_tickets_qbo_resync_order',
						array( 'order_id' => (string) $atomic_admin_order->get_id() )
					);
				},
				static function ( string $method, string $query, array $call ) use ( $atomic_admin_receipt ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $atomic_admin_receipt ) ) );
					}
					if ( $method === 'POST' ) {
						return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-admin-atomic-new' );
					}
					return array( 'QueryResponse' => array() );
				},
				$atomic_admin_calls
			);
		} finally {
			remove_filter( 'query', $atomic_admin_lock_spy, 10 );
		}
		$atomic_admin_order = wc_get_order( (int) $atomic_admin_order->get_id() );
		oras_qbo_reclass_assert_same( 'admin resync acquires one order lock', $atomic_admin_lock_acquires, 1 );
		oras_qbo_reclass_assert_same( 'admin resync releases one order lock', $atomic_admin_lock_releases, 1 );
		oras_qbo_reclass_assert_same( 'admin resync stores replacement JE', (string) $atomic_admin_order->get_meta( '_oras_qbo_je_id', true ), 'JE-admin-atomic-new' );
		oras_qbo_reclass_assert_same( 'admin resync sends one POST', count( array_filter( $atomic_admin_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

		$atomic_cli_order = oras_qbo_reclass_create_order( $product_id, 53.20, 53.20, '2026-03-08' );
		$atomic_cli_order->update_meta_data( '_oras_qbo_je_id', 'JE-cli-old' );
		$atomic_cli_order->update_meta_data( '_oras_qbo_je_hash', 'cli-old-hash' );
		$atomic_cli_order->save();
		$atomic_cli_receipt = oras_qbo_reclass_sales_receipt(
			'atomic-cli-source-' . (string) $atomic_cli_order->get_id(),
			53.20,
			'2026-03-08',
			'Order ' . $atomic_cli_order->get_order_number()
		);
		$atomic_cli_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $atomic_cli_order->get_id() ), 0, 40 );
		$atomic_cli_lock_acquires = 0;
		$atomic_cli_lock_releases = 0;
		$atomic_cli_lock_spy = static function ( string $query ) use ( $atomic_cli_lock_name, &$atomic_cli_lock_acquires, &$atomic_cli_lock_releases ): string {
			if ( strpos( $query, $atomic_cli_lock_name ) !== false && strpos( $query, 'GET_LOCK' ) !== false ) {
				++$atomic_cli_lock_acquires;
			}
			if ( strpos( $query, $atomic_cli_lock_name ) !== false && strpos( $query, 'RELEASE_LOCK' ) !== false ) {
				++$atomic_cli_lock_releases;
			}
			return $query;
		};
		$atomic_cli_calls = array();
		add_filter( 'query', $atomic_cli_lock_spy, 10, 1 );
		try {
			oras_qbo_reclass_entrypoint_with_responder(
				static function () use ( $atomic_cli_order ): void {
					$command = new \ORAS\Tickets\Integrations\QuickBooks\Cli_Command(
						new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(),
						new \ORAS\Tickets\Integrations\QuickBooks\Api_Client()
					);
					$command->resync_order( array( (string) $atomic_cli_order->get_id() ), array() );
				},
				static function ( string $method, string $query, array $call ) use ( $atomic_cli_receipt ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $atomic_cli_receipt ) ) );
					}
					if ( $method === 'POST' ) {
						return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-cli-atomic-new' );
					}
					return array( 'QueryResponse' => array() );
				},
				$atomic_cli_calls
			);
		} finally {
			remove_filter( 'query', $atomic_cli_lock_spy, 10 );
		}
		$atomic_cli_order = wc_get_order( (int) $atomic_cli_order->get_id() );
		oras_qbo_reclass_assert_same( 'CLI resync acquires one order lock', $atomic_cli_lock_acquires, 1 );
		oras_qbo_reclass_assert_same( 'CLI resync releases one order lock', $atomic_cli_lock_releases, 1 );
		oras_qbo_reclass_assert_same( 'CLI resync stores replacement JE', (string) $atomic_cli_order->get_meta( '_oras_qbo_je_id', true ), 'JE-cli-atomic-new' );
		oras_qbo_reclass_assert_same( 'CLI resync sends one POST', count( array_filter( $atomic_cli_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

		// 11f) Order lock is always acquired before source lock, then claims are rechecked.
		$race_transaction_id = 'pi_source_race_' . wp_generate_password( 8, false, false );
		$race_claim_order = oras_qbo_reclass_create_order( $product_id, 53.30, 53.30, '2026-03-08', $race_transaction_id );
		$race_sync_order = oras_qbo_reclass_create_order( $product_id, 53.30, 53.30, '2026-03-08', $race_transaction_id );
		$race_source_id = 'race-source-' . (string) $race_sync_order->get_id();
		$race_source_key = 'salesreceipt:' . $race_source_id;
		$race_receipt = oras_qbo_reclass_sales_receipt( $race_source_id, 53.30, '2026-03-08', $race_transaction_id );
		$race_order_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $race_sync_order->get_id() ), 0, 40 );
		$race_source_lock_name = 'oras_tickets:' . substr( md5( 'qbo-source:' . $race_source_key ), 0, 40 );
		$race_lock_order = array();
		$race_claim_injected = false;
		$race_lock_spy = static function ( string $query ) use (
			$race_order_lock_name,
			$race_source_lock_name,
			$race_claim_order,
			$race_source_key,
			&$race_lock_order,
			&$race_claim_injected
		): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $race_order_lock_name ) !== false ) {
				$race_lock_order[] = 'order';
			}
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $race_source_lock_name ) !== false ) {
				$race_lock_order[] = 'source';
				if ( ! $race_claim_injected ) {
					$race_claim_injected = true;
					$race_claim_order->update_meta_data( '_oras_qbo_reclass_source_txn_key', $race_source_key );
					$race_claim_order->save();
				}
			}
			return $query;
		};
		$race_calls = array();
		add_filter( 'query', $race_lock_spy, 10, 1 );
		try {
			$race_result = oras_qbo_reclass_sync_with_responder(
				(int) $race_sync_order->get_id(),
				static function ( string $method, string $query ) use ( $race_receipt ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $race_receipt ) ) );
					}
					return array( 'QueryResponse' => array() );
				},
				$race_calls
			);
		} finally {
			remove_filter( 'query', $race_lock_spy, 10 );
		}
		oras_qbo_reclass_assert_error_code( 'source claim race', $race_result, 'oras_qbo_reclass_source_claimed' );
		oras_qbo_reclass_assert_same( 'source claim lock ordering', array_slice( $race_lock_order, 0, 2 ), array( 'order', 'source' ) );
		oras_qbo_reclass_assert_same( 'source claim race sends no POST', count( array_filter( $race_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );
		$race_sync_order = wc_get_order( (int) $race_sync_order->get_id() );
		oras_qbo_reclass_assert_same( 'losing order does not reserve source', (string) $race_sync_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), '' );

		// Scheduling is not committed until the exact newly created action survives
		// a post-create controls check and its required order state is persisted.
		if ( function_exists( 'as_schedule_single_action' ) && class_exists( '\ActionScheduler' ) ) {
			\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
			$schedule_race_order = oras_qbo_reclass_create_order( $product_id, 53.35, 53.35, '2026-03-08' );
			$schedule_race_order->update_meta_data( '_oras_qbo_sync_status', 'preexisting_status' );
			$schedule_race_order->update_meta_data( '_oras_qbo_sync_error_code', 'preexisting_error' );
			$schedule_race_order->save();
			$schedule_race_before = oras_qbo_reclass_order_meta_snapshot( $schedule_race_order );
			$preexisting_schedule_order = oras_qbo_reclass_create_order( $product_id, 53.36, 53.36, '2026-03-08' );
			$preexisting_action_id = as_schedule_single_action(
				time() + HOUR_IN_SECONDS,
				\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
				array( (int) $preexisting_schedule_order->get_id(), 0 ),
				'oras-tickets',
				true
			);
			oras_qbo_reclass_assert_true( 'pre-existing scheduler evidence prepares', (int) $preexisting_action_id > 0 );
			$race_created_action_id = 0;
			$flip_controls_after_store = static function ( int $action_id ) use ( $schedule_race_order, &$race_created_action_id ): void {
				$action = \ActionScheduler::store()->fetch_action( $action_id );
				if (
				method_exists( $action, 'get_hook' )
				&& $action->get_hook() === \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK
				&& (int) ( $action->get_args()[0] ?? 0 ) === (int) $schedule_race_order->get_id()
				) {
					$race_created_action_id = $action_id;
					\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'dry_run_mode' => true ) );
				}
			};
			add_action( 'action_scheduler_stored_action', $flip_controls_after_store, 1, 1 );
			try {
				$schedule_race_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->enqueue_order_sync(
					(int) $schedule_race_order->get_id()
				);
				oras_qbo_reclass_assert_error_code( 'post-create controls change', $schedule_race_result, 'oras_qbo_schedule_controls_changed' );
				oras_qbo_reclass_assert_true( 'post-create controls change reached a real stored action', $race_created_action_id > 0 );
				oras_qbo_reclass_assert_true(
					'post-create controls change compensates the exact new action',
					! as_has_scheduled_action(
						\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
						array( (int) $schedule_race_order->get_id(), 0 ),
						'oras-tickets'
					)
				);
				oras_qbo_reclass_assert_true(
					'post-create compensation preserves pre-existing action evidence',
					(bool) as_has_scheduled_action(
						\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
						array( (int) $preexisting_schedule_order->get_id(), 0 ),
						'oras-tickets'
					)
				);
				$schedule_race_order = wc_get_order( (int) $schedule_race_order->get_id() );
				oras_qbo_reclass_assert_same( 'post-create compensation preserves order evidence', oras_qbo_reclass_order_meta_snapshot( $schedule_race_order ), $schedule_race_before );
			} finally {
				remove_action( 'action_scheduler_stored_action', $flip_controls_after_store, 1 );
				\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
				as_unschedule_all_actions( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK, array( (int) $schedule_race_order->get_id(), 0 ), 'oras-tickets' );
				as_unschedule_all_actions( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK, array( (int) $preexisting_schedule_order->get_id(), 0 ), 'oras-tickets' );
			}

			$approval_schedule_order = oras_qbo_reclass_create_order( $product_id, 53.37, 53.37, '2026-03-08' );
			$approval_schedule_before = oras_qbo_reclass_order_meta_snapshot( $approval_schedule_order );
			$reject_approval_schedule = static function ( $pre, int $timestamp, string $hook, array $args ) use ( $approval_schedule_order ) {
				if (
				$hook === \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK
				&& (int) ( $args[0] ?? 0 ) === (int) $approval_schedule_order->get_id()
				) {
					return 0;
				}
				return $pre;
			};
			$reject_approval_async = static function ( $pre, string $hook, array $args ) use ( $approval_schedule_order ) {
				if (
				$hook === \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK
				&& (int) ( $args[0] ?? 0 ) === (int) $approval_schedule_order->get_id()
				) {
					return 0;
				}
				return $pre;
			};
			add_filter( 'pre_as_schedule_single_action', $reject_approval_schedule, 1, 7 );
			add_filter( 'pre_as_enqueue_async_action', $reject_approval_async, 1, 6 );
			try {
				$approval_schedule_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->approve_order_sync(
					(int) $approval_schedule_order->get_id(),
					false
				);
			} finally {
				remove_filter( 'pre_as_schedule_single_action', $reject_approval_schedule, 1 );
				remove_filter( 'pre_as_enqueue_async_action', $reject_approval_async, 1 );
			}
			oras_qbo_reclass_assert_error_code( 'approval scheduler refusal', $approval_schedule_result, 'oras_qbo_schedule_failed' );
			$approval_schedule_order = wc_get_order( (int) $approval_schedule_order->get_id() );
			oras_qbo_reclass_assert_same( 'approval scheduler refusal preserves order evidence', oras_qbo_reclass_order_meta_snapshot( $approval_schedule_order ), $approval_schedule_before );

			$persist_failure_order = oras_qbo_reclass_create_order( $product_id, 53.38, 53.38, '2026-03-08' );
			$persist_failure_before = oras_qbo_reclass_order_meta_snapshot( $persist_failure_order );
			$persist_action_created = false;
			$notice_persist_action = static function ( int $action_id ) use ( $persist_failure_order, &$persist_action_created ): void {
				$action = \ActionScheduler::store()->fetch_action( $action_id );
				if (
				method_exists( $action, 'get_hook' )
				&& $action->get_hook() === \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK
				&& (int) ( $action->get_args()[0] ?? 0 ) === (int) $persist_failure_order->get_id()
				) {
					$persist_action_created = true;
				}
			};
			$reject_scheduled_persistence = static function ( $check, int $object_id, string $meta_key ) use ( $persist_failure_order, &$persist_action_created ) {
				if (
				$persist_action_created
				&& $object_id === (int) $persist_failure_order->get_id()
				&& $meta_key === '_oras_qbo_audit_entry'
				) {
					throw new RuntimeException( 'Injected persistence failure after action creation.' );
				}
				return $check;
			};
			add_action( 'action_scheduler_stored_action', $notice_persist_action, 1, 1 );
			add_filter( 'add_post_metadata', $reject_scheduled_persistence, 1, 5 );
			add_filter( 'update_post_metadata', $reject_scheduled_persistence, 1, 5 );
			try {
				try {
					$persist_failure_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->approve_order_sync(
						(int) $persist_failure_order->get_id(),
						false
					);
				} catch ( Throwable $throwable ) {
					$persist_failure_result = $throwable;
				}
			} finally {
				remove_action( 'action_scheduler_stored_action', $notice_persist_action, 1 );
				remove_filter( 'add_post_metadata', $reject_scheduled_persistence, 1 );
				remove_filter( 'update_post_metadata', $reject_scheduled_persistence, 1 );
			}
			oras_qbo_reclass_assert_true( 'post-schedule persistence probe observes action creation', $persist_action_created );
			oras_qbo_reclass_assert_error_code( 'post-schedule persistence failure', $persist_failure_result, 'oras_qbo_schedule_persistence_failed' );
			oras_qbo_reclass_assert_true(
				'post-schedule persistence failure compensates the exact action',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
					array( (int) $persist_failure_order->get_id(), 0 ),
					'oras-tickets'
				)
			);
			$persist_failure_order = wc_get_order( (int) $persist_failure_order->get_id() );
			oras_qbo_reclass_assert_same( 'post-schedule persistence failure preserves order evidence', oras_qbo_reclass_order_meta_snapshot( $persist_failure_order ), $persist_failure_before );

			$retry_controls_order = oras_qbo_reclass_create_order( $product_id, 53.39, 53.39, '2026-03-08' );
			$retry_controls_order->update_meta_data( '_oras_qbo_sync_status', 'preexisting_retry_status' );
			$retry_controls_order->save();
			$retry_controls_before = oras_qbo_reclass_order_meta_snapshot( $retry_controls_order );
			$retry_controls_reads = 0;
			$retry_controls_action_id = 0;
			$retry_controls_compensated = false;
			$flip_retry_controls_before_persistence = static function ( $settings ) use ( &$retry_controls_reads ) {
				++$retry_controls_reads;
				if ( $retry_controls_reads >= 4 && isset( $settings['quickbooks'] ) && is_array( $settings['quickbooks'] ) ) {
					$settings['quickbooks']['enabled'] = false;
				}
				return $settings;
			};
			add_filter(
				'option_' . \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY,
				$flip_retry_controls_before_persistence,
				1,
				1
			);
			try {
				$retry_controls_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Retry_Handler() )->record_failure(
					$retry_controls_order,
					'Injected retryable failure.',
					'http_request_failed',
					true,
					static function ( int $order_id, int $delay_minutes ) use ( &$retry_controls_action_id ): array {
						$retry_controls_action_id = (int) as_schedule_single_action(
							time() + ( $delay_minutes * MINUTE_IN_SECONDS ),
							'oras_qbo_retry_controls_probe',
							array( $order_id ),
							'oras-tickets',
							true
						);
						return array(
							'scheduled' => $retry_controls_action_id > 0,
							'created'   => $retry_controls_action_id > 0,
							'backend'   => 'action_scheduler',
							'action_id' => $retry_controls_action_id,
							'hook'      => 'oras_qbo_retry_controls_probe',
							'args'      => array( $order_id ),
						);
					},
					'sync',
					static function ( array $schedule ) use ( &$retry_controls_compensated ): bool {
						\ActionScheduler::store()->cancel_action( (int) ( $schedule['action_id'] ?? 0 ) );
						$retry_controls_compensated = ! as_has_scheduled_action(
							(string) ( $schedule['hook'] ?? '' ),
							(array) ( $schedule['args'] ?? array() ),
							'oras-tickets'
						);
						return $retry_controls_compensated;
					}
				);
			} finally {
				remove_filter(
					'option_' . \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY,
					$flip_retry_controls_before_persistence,
					1
				);
				as_unschedule_all_actions(
					'oras_qbo_retry_controls_probe',
					array( (int) $retry_controls_order->get_id() ),
					'oras-tickets'
				);
			}
			oras_qbo_reclass_assert_true( 'retry controls race reaches exact action creation', $retry_controls_action_id > 0 );
			oras_qbo_reclass_assert_same( 'retry controls race reports blocked work', (string) ( $retry_controls_result['status'] ?? '' ), 'blocked' );
			oras_qbo_reclass_assert_true( 'retry controls race compensates exact action', $retry_controls_compensated );
			$retry_controls_order = wc_get_order( (int) $retry_controls_order->get_id() );
			oras_qbo_reclass_assert_same( 'retry controls race preserves order evidence', oras_qbo_reclass_order_meta_snapshot( $retry_controls_order ), $retry_controls_before );
		}

		// 11g) Async order-lock contention schedules only three delayed retries.
		$async_lock_order = oras_qbo_reclass_create_order( $product_id, 53.40, 53.40, '2026-03-08' );
		$async_meta_before = get_post_meta( (int) $async_lock_order->get_id() );
		$async_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $async_lock_order->get_id() ), 0, 40 );
		$force_async_lock_timeout = static function ( string $query ) use ( $async_lock_name ): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $async_lock_name ) !== false ) {
				return 'SELECT 0';
			}
			return $query;
		};
		add_filter( 'query', $force_async_lock_timeout, 10, 1 );
		try {
			$async_orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();
			for ( $lock_attempt = 0; $lock_attempt <= 3; $lock_attempt++ ) {
				$async_orchestrator->sync_order_async( (int) $async_lock_order->get_id(), $lock_attempt );
			}
		} finally {
			remove_filter( 'query', $force_async_lock_timeout, 10 );
		}
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			for ( $scheduled_attempt = 1; $scheduled_attempt <= 3; $scheduled_attempt++ ) {
				oras_qbo_reclass_assert_true(
					'async lock retry attempt ' . (string) $scheduled_attempt . ' scheduled',
					(bool) as_has_scheduled_action(
						\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
						array( (int) $async_lock_order->get_id(), $scheduled_attempt ),
						'oras-tickets'
					)
				);
			}
			oras_qbo_reclass_assert_true(
				'async lock retry attempt four not scheduled',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
					array( (int) $async_lock_order->get_id(), 4 ),
					'oras-tickets'
				)
			);
			for ( $scheduled_attempt = 1; $scheduled_attempt <= 3; $scheduled_attempt++ ) {
				as_unschedule_all_actions(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
					array( (int) $async_lock_order->get_id(), $scheduled_attempt ),
					'oras-tickets'
				);
			}
		}

		// Scheduler refusal is a hard, explicit local failure. Neither the order
		// status nor queue counters may claim that retry work exists.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
			$schedule_failure_order = oras_qbo_reclass_create_order( $product_id, 66.07, 66.07, '2026-03-20' );
			$schedule_failure_order->update_meta_data( '_oras_qbo_je_id', 'JE-schedule-failure-primary' );
			$schedule_failure_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 66.07 ) ) );
			$schedule_failure_order->save();
			$reject_reversal_schedule = static function ( $pre, int $timestamp, string $hook, array $args ) use ( $schedule_failure_order ) {
				if (
				$hook === \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK
				&& (int) ( $args[0] ?? 0 ) === (int) $schedule_failure_order->get_id()
				) {
					return 0;
				}
				return $pre;
			};
			add_filter( 'pre_as_schedule_single_action', $reject_reversal_schedule, 1, 7 );
			try {
				$schedule_failure_calls = array();
				$schedule_failure_result = oras_qbo_reclass_reverse_with_responder(
					(int) $schedule_failure_order->get_id(),
					static function ( string $method ): array {
						if ( $method === 'POST' ) {
							return array(
								'__oras_http_status' => 429,
								'__oras_http_body'   => array(
									'Fault' => array( 'Error' => array( array( 'Message' => 'Rate limited.' ) ) ),
								),
							);
						}
						return array( 'QueryResponse' => array() );
					},
					$schedule_failure_calls
				);
			} finally {
				remove_filter( 'pre_as_schedule_single_action', $reject_reversal_schedule, 1 );
			}
			oras_qbo_reclass_assert_error_code( 'scheduler refusal preserves original operation error', $schedule_failure_result, 'oras_qbo_api_http_429' );
			$schedule_failure_order = wc_get_order( (int) $schedule_failure_order->get_id() );
			oras_qbo_reclass_assert_same( 'scheduler refusal does not report retrying', (string) $schedule_failure_order->get_meta( '_oras_qbo_sync_status', true ), 'reversal_failed' );
			oras_qbo_reclass_assert_same( 'scheduler refusal stores explicit error code', (string) $schedule_failure_order->get_meta( '_oras_qbo_sync_error_code', true ), 'oras_qbo_retry_schedule_failed' );
			oras_qbo_reclass_assert_same( 'scheduler refusal stores explicit audit', (string) $schedule_failure_order->get_meta( '_oras_qbo_last_audit_event', true ), 'reversal_retry_schedule_failed' );

			$primary_schedule_failure_order = oras_qbo_reclass_create_order( $product_id, 66.08, 66.08, '2026-03-20' );
			$primary_schedule_failure_before = get_post_meta( (int) $primary_schedule_failure_order->get_id() );
			$primary_schedule_failure_lock = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $primary_schedule_failure_order->get_id() ), 0, 40 );
			$force_primary_schedule_failure_lock = static function ( string $query ) use ( $primary_schedule_failure_lock ): string {
				return strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $primary_schedule_failure_lock ) !== false
				? 'SELECT 0'
				: $query;
			};
			$reject_primary_lock_retry = static function ( $pre, int $timestamp, string $hook, array $args ) use ( $primary_schedule_failure_order ) {
				if (
				$hook === \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK
				&& (int) ( $args[0] ?? 0 ) === (int) $primary_schedule_failure_order->get_id()
				) {
					return 0;
				}
				return $pre;
			};
			add_filter( 'query', $force_primary_schedule_failure_lock, 10, 1 );
			add_filter( 'pre_as_schedule_single_action', $reject_primary_lock_retry, 1, 7 );
			try {
				$primary_schedule_failure_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->sync_order_async(
					(int) $primary_schedule_failure_order->get_id(),
					0
				);
			} finally {
				remove_filter( 'query', $force_primary_schedule_failure_lock, 10 );
				remove_filter( 'pre_as_schedule_single_action', $reject_primary_lock_retry, 1 );
			}
			oras_qbo_reclass_assert_error_code( 'primary lock retry scheduler refusal', $primary_schedule_failure_result, 'oras_qbo_schedule_failed' );
			clean_post_cache( (int) $primary_schedule_failure_order->get_id() );
			oras_qbo_reclass_assert_same(
				'primary lock retry scheduler refusal preserves running-attempt metadata',
				get_post_meta( (int) $primary_schedule_failure_order->get_id() ),
				$primary_schedule_failure_before
			);
		}
		clean_post_cache( (int) $async_lock_order->get_id() );
		oras_qbo_reclass_assert_same( 'async lock contention does not change order metadata', get_post_meta( (int) $async_lock_order->get_id() ), $async_meta_before );

		// 12) Duplicate lookup failure always stops before POST, including non-strict mode.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			oras_qbo_reclass_settings( array( 'strict_mapping_mode' => false ) )
		);
		$duplicate_order = oras_qbo_reclass_create_order( $product_id, 52.00, 52.00, '2026-03-09' );
		$duplicate_receipt = oras_qbo_reclass_sales_receipt(
			'duplicate-source',
			52.00,
			'2026-03-09',
			'ORAS Order: fixture - Order ' . $duplicate_order->get_order_number()
		);
		$duplicate_calls = array();
		$duplicate_posts = 0;
		$duplicate_result = oras_qbo_reclass_sync_with_responder(
			(int) $duplicate_order->get_id(),
			static function ( string $method, string $query ) use ( $duplicate_receipt, &$duplicate_posts ) {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $duplicate_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return new \WP_Error(
						'oras_qbo_api_http_503',
						'Duplicate lookup service unavailable.',
						array( 'retriable' => true )
					);
				}
				if ( $method === 'POST' ) {
					$duplicate_posts++;
					return array( 'JournalEntry' => array( 'Id' => 'JE-unsafe' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$duplicate_calls
		);
		oras_qbo_reclass_assert_error_code( 'duplicate lookup hard stop', $duplicate_result, 'oras_qbo_duplicate_lookup_failed' );
		oras_qbo_reclass_assert_same( 'duplicate lookup failure POST count', $duplicate_posts, 0 );
		if ( is_wp_error( $duplicate_result ) ) {
			$duplicate_error_data = $duplicate_result->get_error_data();
			oras_qbo_reclass_assert_same(
				'duplicate lookup hard stop is non-retriable',
				(bool) ( is_array( $duplicate_error_data ) ? ( $duplicate_error_data['retriable'] ?? true ) : true ),
				false
			);
		}
		$duplicate_order = wc_get_order( (int) $duplicate_order->get_id() );
		oras_qbo_reclass_assert_same( 'duplicate lookup does not schedule retry state', (string) $duplicate_order->get_meta( '_oras_qbo_sync_status', true ), 'failed' );

		// 13) Multiple remote JournalEntries with the deterministic DocNumber fail closed.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$remote_duplicate_order = oras_qbo_reclass_create_order( $product_id, 52.50, 52.50, '2026-03-09' );
		$remote_duplicate_source_id = 'remote-duplicate-source-' . (string) $remote_duplicate_order->get_id();
		$remote_duplicate_receipt = oras_qbo_reclass_sales_receipt(
			$remote_duplicate_source_id,
			52.50,
			'2026-03-09',
			'ORAS Order: fixture - Order ' . $remote_duplicate_order->get_order_number()
		);
		$remote_duplicate_calls = array();
		$remote_duplicate_posts = 0;
		$remote_duplicate_result = oras_qbo_reclass_sync_with_responder(
			(int) $remote_duplicate_order->get_id(),
			static function ( string $method, string $query ) use ( $remote_duplicate_receipt, &$remote_duplicate_posts ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $remote_duplicate_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array(
						'QueryResponse' => array(
							'JournalEntry' => array(
								array( 'Id' => 'JE-duplicate-a' ),
								array( 'Id' => 'JE-duplicate-b' ),
							),
						),
					);
				}
				if ( $method === 'POST' ) {
					$remote_duplicate_posts++;
				}
				return array( 'QueryResponse' => array() );
			},
			$remote_duplicate_calls
		);
		oras_qbo_reclass_assert_error_code( 'multiple remote JournalEntries', $remote_duplicate_result, 'oras_qbo_duplicate_lookup_failed' );
		oras_qbo_reclass_assert_same( 'multiple remote JournalEntries POST count', $remote_duplicate_posts, 0 );
		$remote_duplicate_query = '';
		foreach ( $remote_duplicate_calls as $remote_duplicate_call ) {
			if ( strpos( (string) $remote_duplicate_call['query'], 'FROM JournalEntry' ) !== false ) {
				$remote_duplicate_query = (string) $remote_duplicate_call['query'];
			}
		}
		oras_qbo_reclass_assert_true( 'duplicate query requests full JournalEntry content', strpos( $remote_duplicate_query, 'SELECT * FROM JournalEntry' ) !== false );
		oras_qbo_reclass_assert_true( 'duplicate query requests two JournalEntries', strpos( $remote_duplicate_query, 'MAXRESULTS 2' ) !== false );

		// 14) A same-DocNumber JournalEntry with different accounting lines is never adopted.
		$mismatch_order = oras_qbo_reclass_create_order( $product_id, 52.75, 52.75, '2026-03-09' );
		$mismatch_source_id = 'mismatch-source-' . (string) $mismatch_order->get_id();
		$mismatch_receipt = oras_qbo_reclass_sales_receipt(
			$mismatch_source_id,
			52.75,
			'2026-03-09',
			'ORAS Order: fixture - Order ' . $mismatch_order->get_order_number()
		);
		$mismatch_calls = array();
		$mismatch_posts = 0;
		$mismatch_result = oras_qbo_reclass_sync_with_responder(
			(int) $mismatch_order->get_id(),
			static function ( string $method, string $query ) use ( $mismatch_receipt, $mismatch_order, &$mismatch_posts ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $mismatch_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array(
						'QueryResponse' => array(
							'JournalEntry' => array(
								array(
									'Id'        => 'JE-mismatch',
									'DocNumber' => 'ORAS-RC-' . $mismatch_order->get_id(),
									'TxnDate'   => '2026-03-09',
									'Line'      => array(
										array(
											'Amount' => 52.75,
											'JournalEntryLineDetail' => array(
												'PostingType' => 'Debit',
												'AccountRef'  => array( 'value' => 'wrong-account' ),
											),
										),
										array(
											'Amount' => 52.75,
											'JournalEntryLineDetail' => array(
												'PostingType' => 'Credit',
												'AccountRef'  => array( 'value' => '4200' ),
											),
										),
									),
								),
							),
						),
					);
				}
				if ( $method === 'POST' ) {
					$mismatch_posts++;
				}
				return array( 'QueryResponse' => array() );
			},
			$mismatch_calls
		);
		oras_qbo_reclass_assert_error_code( 'remote accounting mismatch', $mismatch_result, 'oras_qbo_existing_je_mismatch' );
		oras_qbo_reclass_assert_same( 'remote accounting mismatch POST count', $mismatch_posts, 0 );

		// 15) A timed-out POST becomes unknown; a later verified remote JE is adopted without another POST/source query.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$timeout_order = oras_qbo_reclass_create_order( $product_id, 53.00, 53.00, '2026-03-10' );
		$timeout_source_id = 'timeout-source-' . (string) $timeout_order->get_id();
		$timeout_receipt = oras_qbo_reclass_sales_receipt(
			$timeout_source_id,
			53.00,
			'2026-03-10',
			'ORAS Order: fixture - Order ' . $timeout_order->get_order_number()
		);
		$timeout_calls = array();
		$timeout_posted_payload = array();
		$timeout_result = oras_qbo_reclass_sync_with_responder(
			(int) $timeout_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $timeout_receipt, &$timeout_posted_payload ) {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $timeout_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					$decoded = json_decode( (string) $call['body'], true );
					$timeout_posted_payload = is_array( $decoded ) ? $decoded : array();
					return new \WP_Error( 'http_request_failed', 'Operation timed out after 30001 milliseconds with 0 bytes received.' );
				}
				return array( 'QueryResponse' => array() );
			},
			$timeout_calls
		);
		oras_qbo_reclass_assert_error_code( 'unknown POST outcome', $timeout_result, 'oras_qbo_write_outcome_unknown' );
		$timeout_order = wc_get_order( (int) $timeout_order->get_id() );
		oras_qbo_reclass_assert_same( 'unknown write state persisted', (string) $timeout_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_true( 'unknown pending write retained', (string) $timeout_order->get_meta( '_oras_qbo_pending_write', true ) !== '' );
		oras_qbo_reclass_assert_same( 'unknown source reservation retained', (string) $timeout_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), 'salesreceipt:' . $timeout_source_id );

		$recovery_calls = array();
		$recovery_source_queries = 0;
		$recovery_posts = 0;
		$recovery_result = oras_qbo_reclass_sync_with_responder(
			(int) $timeout_order->get_id(),
			static function ( string $method, string $query ) use ( $timeout_posted_payload, &$recovery_source_queries, &$recovery_posts ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					$recovery_source_queries++;
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array(
						'QueryResponse' => array(
							'JournalEntry' => array(
								array_merge( $timeout_posted_payload, array( 'Id' => 'JE-timeout-accepted' ) ),
							),
						),
					);
				}
				if ( $method === 'POST' ) {
					$recovery_posts++;
					return array( 'JournalEntry' => array( 'Id' => 'JE-duplicate' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$recovery_calls
		);
		oras_qbo_reclass_assert_true( 'unknown write recovery succeeds', is_array( $recovery_result ) );
		if ( is_array( $recovery_result ) ) {
			oras_qbo_reclass_assert_same( 'unknown write recovery status', (string) ( $recovery_result['status'] ?? '' ), 'already_synced_remote' );
		}
		oras_qbo_reclass_assert_same( 'unknown recovery source query count', $recovery_source_queries, 0 );
		oras_qbo_reclass_assert_same( 'unknown recovery POST count', $recovery_posts, 0 );
		$timeout_order = wc_get_order( (int) $timeout_order->get_id() );
		oras_qbo_reclass_assert_same( 'accepted JE ID adopted', (string) $timeout_order->get_meta( '_oras_qbo_je_id', true ), 'JE-timeout-accepted' );
		oras_qbo_reclass_assert_same( 'unknown pending write cleared after adoption', (string) $timeout_order->get_meta( '_oras_qbo_pending_write', true ), '' );

		// 16) A successful not-found lookup after an unknown POST remains a hard stop and never rewrites automatically.
		$unknown_absent_order = oras_qbo_reclass_create_order( $product_id, 54.00, 54.00, '2026-03-11' );
		$unknown_absent_order->update_meta_data( '_oras_qbo_write_state', 'unknown_outcome' );
		$unknown_absent_order->update_meta_data(
			'_oras_qbo_pending_write',
			wp_json_encode(
				array(
					'doc_number'             => 'ORAS-RC-' . $unknown_absent_order->get_id(),
					'order_hash'             => 'pending-hash',
					'payload_hash'           => 'payload-hash',
					'accounting_fingerprint' => 'accounting-fingerprint',
					'operation'              => 'sync',
					'split'                  => oras_qbo_reclass_split( 54.00 ),
					'source_match'           => array(
						'entity'   => 'SalesReceipt',
						'id'       => 'unknown-source',
						'key'      => 'salesreceipt:unknown-source',
						'txn_date' => '2026-03-11',
					),
				)
			)
		);
		$unknown_absent_order->save();
		$unknown_absent_calls = array();
		$unknown_absent_posts = 0;
		$unknown_absent_result = oras_qbo_reclass_sync_with_responder(
			(int) $unknown_absent_order->get_id(),
			static function ( string $method, string $query ) use ( &$unknown_absent_posts ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					$unknown_absent_posts++;
				}
				return array( 'QueryResponse' => array() );
			},
			$unknown_absent_calls
		);
		oras_qbo_reclass_assert_error_code( 'unknown absent legacy hard stop', $unknown_absent_result, 'oras_qbo_legacy_possible_write' );
		oras_qbo_reclass_assert_same( 'unknown absent POST count', $unknown_absent_posts, 0 );

		// 17) Atomic order lock contention prevents all QBO requests.
		$locked_order = oras_qbo_reclass_create_order( $product_id, 55.00, 55.00, '2026-03-12' );
		$lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $locked_order->get_id() ), 0, 40 );
		$force_lock_timeout = static function ( string $query ) use ( $lock_name ): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $lock_name ) !== false ) {
				return 'SELECT 0';
			}
			return $query;
		};
		add_filter( 'query', $force_lock_timeout, 10, 1 );
		$lock_calls = array();
		try {
			$lock_result = oras_qbo_reclass_sync_with_responder(
				(int) $locked_order->get_id(),
				static function (): \WP_Error {
					return new \WP_Error( 'unexpected_http', 'Lock contention must prevent HTTP.' );
				},
				$lock_calls
			);
		} finally {
			remove_filter( 'query', $force_lock_timeout, 10 );
		}
		oras_qbo_reclass_assert_error_code( 'QBO order lock', $lock_result, 'oras_qbo_sync_in_progress' );
		oras_qbo_reclass_assert_same( 'lock contention HTTP count', count( $lock_calls ), 0 );

		// 18) Reversal duplicate checks, POST timeouts, and lock contention use the same safeguards.
		$reversal_order = oras_qbo_reclass_create_order( $product_id, 58.00, 58.00, '2026-03-13' );
		$reversal_order->update_meta_data( '_oras_qbo_je_id', 'JE-original' );
		$reversal_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 58.00 ) ) );
		$reversal_order->save();
		$reversal_duplicate_calls = array();
		$reversal_duplicate_posts = 0;
		$reversal_duplicate_result = oras_qbo_reclass_reverse_with_responder(
			(int) $reversal_order->get_id(),
			static function ( string $method, string $query ) use ( &$reversal_duplicate_posts ) {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return new \WP_Error( 'http_request_failed', 'Reversal duplicate lookup timed out.' );
				}
				if ( $method === 'POST' ) {
					$reversal_duplicate_posts++;
					return array( 'JournalEntry' => array( 'Id' => 'JE-reversal-unsafe' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$reversal_duplicate_calls
		);
		oras_qbo_reclass_assert_error_code( 'reversal duplicate lookup hard stop', $reversal_duplicate_result, 'oras_qbo_duplicate_lookup_failed' );
		oras_qbo_reclass_assert_same( 'reversal duplicate lookup POST count', $reversal_duplicate_posts, 0 );

		$reversal_timeout_order = oras_qbo_reclass_create_order( $product_id, 59.00, 59.00, '2026-03-13' );
		$reversal_timeout_order->update_meta_data( '_oras_qbo_je_id', 'JE-original-timeout' );
		$reversal_primary_doc_number = 'ORAS-RC-' . (string) $reversal_timeout_order->get_id();
		$reversal_timeout_order->update_meta_data( '_oras_qbo_doc_number', $reversal_primary_doc_number );
		$reversal_timeout_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 59.00 ) ) );
		$reversal_timeout_order->save();
		$reversal_timeout_calls = array();
		$reversal_posted_payload = array();
		$reversal_request_id = '';
		$reversal_timeout_result = oras_qbo_reclass_reverse_with_responder(
			(int) $reversal_timeout_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( &$reversal_posted_payload, &$reversal_request_id ) {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					$decoded = json_decode( (string) $call['body'], true );
					$reversal_posted_payload = is_array( $decoded ) ? $decoded : array();
					$reversal_request_id = oras_qbo_reclass_request_id( $call );
					return new \WP_Error( 'http_request_failed', 'Reversal POST timed out.' );
				}
				return array( 'QueryResponse' => array() );
			},
			$reversal_timeout_calls
		);
		oras_qbo_reclass_assert_error_code( 'reversal unknown POST outcome', $reversal_timeout_result, 'oras_qbo_write_outcome_unknown' );
		$reversal_timeout_order = wc_get_order( (int) $reversal_timeout_order->get_id() );
		$reversal_pending = json_decode( (string) $reversal_timeout_order->get_meta( '_oras_qbo_pending_write', true ), true );
		oras_qbo_reclass_assert_same( 'reversal pending operation', (string) ( $reversal_pending['operation'] ?? '' ), 'reversal' );
		oras_qbo_reclass_assert_same( 'reversal timeout persists POST request ID', (string) ( $reversal_pending['request_id'] ?? '' ), $reversal_request_id );
		oras_qbo_reclass_assert_true( 'reversal timeout request ID is present', $reversal_request_id !== '' );
		oras_qbo_reclass_assert_same( 'reversal timeout preserves primary DocNumber', (string) $reversal_timeout_order->get_meta( '_oras_qbo_doc_number', true ), $reversal_primary_doc_number );

		$reversal_recovery_calls = array();
		$reversal_recovery_posts = 0;
		$reversal_recovery_result = oras_qbo_reclass_reverse_with_responder(
			(int) $reversal_timeout_order->get_id(),
			static function ( string $method, string $query ) use ( $reversal_posted_payload, &$reversal_recovery_posts ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array(
						'QueryResponse' => array(
							'JournalEntry' => array(
								array_merge( $reversal_posted_payload, array( 'Id' => 'JE-reversal-accepted' ) ),
							),
						),
					);
				}
				if ( $method === 'POST' ) {
					$reversal_recovery_posts++;
				}
				return array( 'QueryResponse' => array() );
			},
			$reversal_recovery_calls
		);
		oras_qbo_reclass_assert_true( 'reversal unknown recovery succeeds', is_array( $reversal_recovery_result ) );
		if ( is_array( $reversal_recovery_result ) ) {
			oras_qbo_reclass_assert_same( 'reversal unknown recovery status', (string) ( $reversal_recovery_result['status'] ?? '' ), 'already_reversed_remote' );
		}
		oras_qbo_reclass_assert_same( 'reversal unknown recovery POST count', $reversal_recovery_posts, 0 );
		$reversal_timeout_order = wc_get_order( (int) $reversal_timeout_order->get_id() );
		oras_qbo_reclass_assert_same( 'accepted reversal JE ID adopted', (string) $reversal_timeout_order->get_meta( '_oras_qbo_reversal_je_id', true ), 'JE-reversal-accepted' );
		oras_qbo_reclass_assert_same( 'reversal recovery preserves primary DocNumber', (string) $reversal_timeout_order->get_meta( '_oras_qbo_doc_number', true ), $reversal_primary_doc_number );

		$reversal_failure_order = oras_qbo_reclass_create_order( $product_id, 59.25, 59.25, '2026-03-13' );
		$reversal_failure_order->update_meta_data( '_oras_qbo_je_id', 'JE-original-conclusive-failure' );
		$reversal_failure_primary_doc = 'ORAS-RC-' . (string) $reversal_failure_order->get_id();
		$reversal_failure_order->update_meta_data( '_oras_qbo_doc_number', $reversal_failure_primary_doc );
		$reversal_failure_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 59.25 ) ) );
		$reversal_failure_order->save();
		$reversal_failure_calls = array();
		$reversal_failure_result = oras_qbo_reclass_reverse_with_responder(
			(int) $reversal_failure_order->get_id(),
			static function ( string $method, string $query ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					return array(
						'__oras_http_status' => 400,
						'__oras_http_body'   => array(
							'Fault' => array(
								'Error' => array(
									array(
										'Message' => 'Validation fault',
										'Detail'  => 'Conclusive reversal rejection.',
									),
								),
							),
						),
					);
				}
				return array( 'QueryResponse' => array() );
			},
			$reversal_failure_calls
		);
		oras_qbo_reclass_assert_error_code( 'conclusive reversal failure', $reversal_failure_result, 'oras_qbo_api_http_400' );
		$reversal_failure_order = wc_get_order( (int) $reversal_failure_order->get_id() );
		oras_qbo_reclass_assert_same( 'conclusive reversal clears pending payload', (string) $reversal_failure_order->get_meta( '_oras_qbo_pending_write', true ), '' );
		oras_qbo_reclass_assert_same( 'conclusive reversal clears write state', (string) $reversal_failure_order->get_meta( '_oras_qbo_write_state', true ), '' );
		oras_qbo_reclass_assert_same( 'conclusive reversal status agrees with failure', (string) $reversal_failure_order->get_meta( '_oras_qbo_sync_status', true ), 'reversal_failed' );
		oras_qbo_reclass_assert_same( 'conclusive reversal error code persists', (string) $reversal_failure_order->get_meta( '_oras_qbo_sync_error_code', true ), 'oras_qbo_api_http_400' );
		oras_qbo_reclass_assert_same( 'conclusive reversal failure is audited', (string) $reversal_failure_order->get_meta( '_oras_qbo_last_audit_event', true ), 'reversal_failure' );
		oras_qbo_reclass_assert_same( 'conclusive reversal preserves primary DocNumber', (string) $reversal_failure_order->get_meta( '_oras_qbo_doc_number', true ), $reversal_failure_primary_doc );
		oras_qbo_reclass_assert_same( 'conclusive reversal sends one POST', count( array_filter( $reversal_failure_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

		$reversal_missing_id_order = oras_qbo_reclass_create_order( $product_id, 59.50, 59.50, '2026-03-13' );
		$reversal_missing_id_order->update_meta_data( '_oras_qbo_je_id', 'JE-original-missing-id' );
		$reversal_missing_id_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 59.50 ) ) );
		$reversal_missing_id_order->save();
		$reversal_missing_id_calls = array();
		$reversal_missing_id_result = oras_qbo_reclass_reverse_with_responder(
			(int) $reversal_missing_id_order->get_id(),
			static function ( string $method, string $query ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					return array( 'JournalEntry' => array() );
				}
				return array( 'QueryResponse' => array() );
			},
			$reversal_missing_id_calls
		);
		oras_qbo_reclass_assert_error_code( 'reversal missing ID is unknown outcome', $reversal_missing_id_result, 'oras_qbo_write_outcome_unknown' );
		$reversal_missing_id_order = wc_get_order( (int) $reversal_missing_id_order->get_id() );
		oras_qbo_reclass_assert_same( 'reversal missing ID pending intent retained', (string) $reversal_missing_id_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );

		$reversal_locked_order = oras_qbo_reclass_create_order( $product_id, 60.00, 60.00, '2026-03-13' );
		$reversal_locked_order->update_meta_data( '_oras_qbo_je_id', 'JE-original-lock' );
		$reversal_locked_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 60.00 ) ) );
		$reversal_locked_order->save();
		$reversal_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $reversal_locked_order->get_id() ), 0, 40 );
		$force_reversal_lock_timeout = static function ( string $query ) use ( $reversal_lock_name ): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $reversal_lock_name ) !== false ) {
				return 'SELECT 0';
			}
			return $query;
		};
		add_filter( 'query', $force_reversal_lock_timeout, 10, 1 );
		$reversal_lock_calls = array();
		try {
			$reversal_lock_result = oras_qbo_reclass_reverse_with_responder(
				(int) $reversal_locked_order->get_id(),
				static function (): \WP_Error {
					return new \WP_Error( 'unexpected_http', 'Reversal lock contention must prevent HTTP.' );
				},
				$reversal_lock_calls
			);
		} finally {
			remove_filter( 'query', $force_reversal_lock_timeout, 10 );
		}
		oras_qbo_reclass_assert_error_code( 'reversal order lock', $reversal_lock_result, 'oras_qbo_sync_in_progress' );
		oras_qbo_reclass_assert_same( 'reversal lock contention HTTP count', count( $reversal_lock_calls ), 0 );

		$reset_pending_order = oras_qbo_reclass_create_order( $product_id, 60.50, 60.50, '2026-03-13' );
		$reset_pending_order->update_meta_data( '_oras_qbo_write_state', 'unknown_outcome' );
		$reset_pending_order->update_meta_data( '_oras_qbo_pending_write', '{"operation":"sync"}' );
		$reset_pending_order->save();
		$reset_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->reset_order_sync_state(
			(int) $reset_pending_order->get_id()
		);
		oras_qbo_reclass_assert_error_code( 'incomplete pending write cannot be reset', $reset_result, 'oras_qbo_legacy_possible_write' );

		// 19) Generic transport retries remain bounded at three failures.
		$retry_order = oras_qbo_reclass_create_order( $product_id, 56.00, 56.00, '2026-03-13' );
		$scheduled_retries = array();
		$retry_handler = new \ORAS\Tickets\Integrations\QuickBooks\Retry_Handler();
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$retry_handler->record_failure(
				$retry_order,
				'network failure',
				'http_request_failed',
				true,
				static function ( int $order_id, int $delay ) use ( &$scheduled_retries ): bool {
					$scheduled_retries[] = array( $order_id, $delay );
					return true;
				}
			);
		}
		$retry_order = wc_get_order( (int) $retry_order->get_id() );
		oras_qbo_reclass_assert_same( 'generic retry schedules only two follow-ups', count( $scheduled_retries ), 2 );
		oras_qbo_reclass_assert_same( 'generic retry count stops at three', (string) $retry_order->get_meta( '_oras_qbo_retry_count', true ), '3' );
		oras_qbo_reclass_assert_same( 'generic retry ends failed', (string) $retry_order->get_meta( '_oras_qbo_sync_status', true ), 'failed' );

		// 20) Source-wait retries remain time-bounded and end in needs_review.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			oras_qbo_reclass_settings( array( 'source_match_max_wait_days' => 1 ) )
		);
		$wait_order = oras_qbo_reclass_create_order( $product_id, 57.00, 57.00, '2026-03-14' );
		$wait_order->update_meta_data( '_oras_qbo_wait_first_at', gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ) );
		$wait_order->save();
		$source_error = new \WP_Error(
			'oras_qbo_reclass_source_not_found',
			'No source.',
			array( 'retriable' => true )
		);
		$failure_method = new ReflectionMethod( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::class, 'handle_sync_failure' );
		$failure_method->setAccessible( true );
		$failure_method->invoke( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(), $wait_order, $source_error );
		$wait_order = wc_get_order( (int) $wait_order->get_id() );
		oras_qbo_reclass_assert_same( 'expired source wait needs review', (string) $wait_order->get_meta( '_oras_qbo_sync_status', true ), 'needs_review' );
		oras_qbo_reclass_assert_same( 'expired source wait code', (string) $wait_order->get_meta( '_oras_qbo_sync_error_code', true ), 'oras_qbo_wait_expired' );

		// 21) The same persisted deterministic request ID survives an OAuth
		// refresh retry through the production synchronization entry point.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$refresh_order = oras_qbo_reclass_create_order( $product_id, 60.75, 60.75, '2026-03-14' );
		$refresh_receipt = oras_qbo_reclass_sales_receipt(
			'refresh-source-' . (string) $refresh_order->get_id(),
			60.75,
			'2026-03-14',
			'Order ' . $refresh_order->get_order_number()
		);
		$refresh_post_request_ids = array();
		$refresh_calls = array();
		$refresh_result = oras_qbo_reclass_sync_with_responder(
			(int) $refresh_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $refresh_receipt, &$refresh_post_request_ids ): array {
				if ( strpos( (string) $call['url'], 'oauth.platform.intuit.com' ) !== false ) {
					return array(
						'access_token'               => 'refreshed-access-token',
						'refresh_token'              => 'refreshed-refresh-token',
						'expires_in'                 => 3600,
						'x_refresh_token_expires_in' => 86400,
					);
				}
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $refresh_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' && strpos( (string) $call['url'], '/journalentry' ) !== false ) {
					$refresh_post_request_ids[] = oras_qbo_reclass_request_id( $call );
					if ( count( $refresh_post_request_ids ) === 1 ) {
						return array(
							'__oras_http_status' => 401,
							'__oras_http_body'   => array( 'Fault' => array( 'Error' => array() ) ),
						);
					}
					return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-refresh-retry' );
				}
				return array( 'QueryResponse' => array() );
			},
			$refresh_calls
		);
		oras_qbo_reclass_assert_true( 'token refresh retry succeeds', is_array( $refresh_result ) );
		oras_qbo_reclass_assert_same( 'token refresh retry sends two JournalEntry POSTs', count( $refresh_post_request_ids ), 2 );
		oras_qbo_reclass_assert_true( 'token refresh retry request ID is non-empty', trim( (string) ( $refresh_post_request_ids[0] ?? '' ) ) !== '' );
		oras_qbo_reclass_assert_same( 'token refresh retry reuses request ID', $refresh_post_request_ids[1] ?? '', $refresh_post_request_ids[0] ?? '' );
		$refresh_order = wc_get_order( (int) $refresh_order->get_id() );
		oras_qbo_reclass_assert_same( 'token refresh retry records successful JE', (string) $refresh_order->get_meta( '_oras_qbo_je_id', true ), 'JE-refresh-retry' );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );

		// 22) Production primary and reversal adoption use verified home currency.
		$home_primary_order = oras_qbo_reclass_create_order( $product_id, 61.00, 61.00, '2026-03-15' );
		$home_primary_receipt = oras_qbo_reclass_sales_receipt(
			'home-primary-source-' . (string) $home_primary_order->get_id(),
			61.00,
			'2026-03-15',
			'Order ' . $home_primary_order->get_order_number()
		);
		$home_primary_build_calls = array();
		$home_primary_prepared = oras_qbo_reclass_build_with_responder(
			$home_primary_order,
			oras_qbo_reclass_split( 61.00 ),
			static function () use ( $home_primary_receipt ): array {
				return array( 'QueryResponse' => array( 'SalesReceipt' => array( $home_primary_receipt ) ) );
			},
			$home_primary_build_calls
		);
		oras_qbo_reclass_assert_true( 'home currency primary fixture prepares', is_array( $home_primary_prepared ) );
		$home_primary_remote = (array) ( $home_primary_prepared['payload'] ?? array() );
		unset( $home_primary_remote['CurrencyRef'] );
		$home_primary_remote['ExchangeRate'] = '1.000000';
		$home_primary_remote['Id'] = 'JE-home-primary';
		$home_primary_calls = array();
		$home_primary_result = oras_qbo_reclass_sync_with_responder(
			(int) $home_primary_order->get_id(),
			static function ( string $method, string $query ) use ( $home_primary_receipt, $home_primary_remote ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $home_primary_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array( 'JournalEntry' => array( $home_primary_remote ) ) );
				}
				if ( $method === 'POST' ) {
					return array( 'JournalEntry' => array( 'Id' => 'JE-home-primary-unexpected' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$home_primary_calls
		);
		oras_qbo_reclass_assert_true( 'verified-home primary adoption succeeds', is_array( $home_primary_result ) );
		oras_qbo_reclass_assert_same( 'verified-home primary adoption status', (string) ( $home_primary_result['status'] ?? '' ), 'already_synced_remote' );
		oras_qbo_reclass_assert_same(
			'verified-home primary requests Preferences',
			count( array_filter( $home_primary_calls, static fn ( array $call ): bool => strpos( (string) $call['url'], '/preferences' ) !== false ) ),
			1
		);
		oras_qbo_reclass_assert_same( 'verified-home primary sends no POST', count( array_filter( $home_primary_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );

		$home_reversal_order = oras_qbo_reclass_create_order( $product_id, 61.50, 61.50, '2026-03-15' );
		$home_reversal_order->update_meta_data( '_oras_qbo_je_id', 'JE-home-reversal-primary' );
		$home_reversal_order->update_meta_data( '_oras_qbo_doc_number', 'ORAS-RC-' . (string) $home_reversal_order->get_id() );
		$home_reversal_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 61.50 ) ) );
		$home_reversal_order->save();
		$home_reversal_prepared = ( new \ORAS\Tickets\Integrations\QuickBooks\Journal_Entry_Creator() )->build_payload_for_order(
			$home_reversal_order,
			oras_qbo_reclass_split( 61.50 ),
			\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings(),
			true,
			'JE-home-reversal-primary',
			false
		);
		oras_qbo_reclass_assert_true( 'home currency reversal fixture prepares', is_array( $home_reversal_prepared ) );
		$home_reversal_remote = (array) ( $home_reversal_prepared['payload'] ?? array() );
		unset( $home_reversal_remote['CurrencyRef'] );
		$home_reversal_remote['ExchangeRate'] = 1;
		$home_reversal_remote['Id'] = 'JE-home-reversal';
		$home_reversal_calls = array();
		$home_reversal_result = oras_qbo_reclass_reverse_with_responder(
			(int) $home_reversal_order->get_id(),
			static function ( string $method, string $query ) use ( $home_reversal_remote ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array( 'JournalEntry' => array( $home_reversal_remote ) ) );
				}
				if ( $method === 'POST' ) {
					return array( 'JournalEntry' => array( 'Id' => 'JE-home-reversal-unexpected' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$home_reversal_calls
		);
		oras_qbo_reclass_assert_true( 'verified-home reversal adoption succeeds', is_array( $home_reversal_result ) );
		oras_qbo_reclass_assert_same( 'verified-home reversal status', (string) ( $home_reversal_result['status'] ?? '' ), 'already_reversed_remote' );
		oras_qbo_reclass_assert_same(
			'verified-home reversal requests Preferences',
			count( array_filter( $home_reversal_calls, static fn ( array $call ): bool => strpos( (string) $call['url'], '/preferences' ) !== false ) ),
			1
		);
		oras_qbo_reclass_assert_same( 'verified-home reversal sends no POST', count( array_filter( $home_reversal_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );

		// 23) An existing JournalEntry cannot be adopted if the source is claimed while its lock is acquired.
		$adoption_race_order = oras_qbo_reclass_create_order( $product_id, 62.00, 62.00, '2026-03-16' );
		$adoption_claim_order = oras_qbo_reclass_create_order( $product_id, 62.00, 62.00, '2026-03-16' );
		$adoption_source_id = 'adoption-race-source-' . (string) $adoption_race_order->get_id();
		$adoption_source_key = 'salesreceipt:' . $adoption_source_id;
		$adoption_receipt = oras_qbo_reclass_sales_receipt(
			$adoption_source_id,
			62.00,
			'2026-03-16',
			'Order ' . $adoption_race_order->get_order_number()
		);
		$adoption_build_calls = array();
		$adoption_prepared = oras_qbo_reclass_build_with_responder(
			$adoption_race_order,
			oras_qbo_reclass_split( 62.00 ),
			static function () use ( $adoption_receipt ): array {
				return array( 'QueryResponse' => array( 'SalesReceipt' => array( $adoption_receipt ) ) );
			},
			$adoption_build_calls
		);
		oras_qbo_reclass_assert_true( 'existing-JE race fixture prepares', is_array( $adoption_prepared ) );
		$adoption_remote_entry = array_merge( (array) ( $adoption_prepared['payload'] ?? array() ), array( 'Id' => 'JE-adoption-race' ) );
		$adoption_source_lock_name = 'oras_tickets:' . substr( md5( 'qbo-source:' . $adoption_source_key ), 0, 40 );
		$adoption_claim_injected = false;
		$adoption_lock_spy = static function ( string $query ) use ( $adoption_source_lock_name, $adoption_claim_order, $adoption_source_key, &$adoption_claim_injected ): string {
			if ( ! $adoption_claim_injected && strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $adoption_source_lock_name ) !== false ) {
				$adoption_claim_injected = true;
				$adoption_claim_order->update_meta_data( '_oras_qbo_reclass_source_txn_key', $adoption_source_key );
				$adoption_claim_order->save();
			}
			return $query;
		};
		$adoption_calls = array();
		$adoption_je_lookups = 0;
		add_filter( 'query', $adoption_lock_spy, 10, 1 );
		try {
			$adoption_result = oras_qbo_reclass_sync_with_responder(
				(int) $adoption_race_order->get_id(),
				static function ( string $method, string $query ) use ( $adoption_receipt, $adoption_remote_entry, &$adoption_je_lookups ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $adoption_receipt ) ) );
					}
					if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
						$adoption_je_lookups++;
						return array( 'QueryResponse' => array( 'JournalEntry' => array( $adoption_remote_entry ) ) );
					}
					if ( $method === 'POST' ) {
						return array( 'JournalEntry' => array( 'Id' => 'JE-adoption-race-unexpected' ) );
					}
					return array( 'QueryResponse' => array() );
				},
				$adoption_calls
			);
		} finally {
			remove_filter( 'query', $adoption_lock_spy, 10 );
		}
		oras_qbo_reclass_assert_true( 'existing-JE race reaches source lock', $adoption_claim_injected );
		oras_qbo_reclass_assert_error_code( 'existing-JE source-lock race', $adoption_result, 'oras_qbo_reclass_source_claimed' );
		oras_qbo_reclass_assert_same( 'existing-JE race checks claim before JE lookup', $adoption_je_lookups, 0 );
		oras_qbo_reclass_assert_same( 'existing-JE race sends no POST', count( array_filter( $adoption_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );
		$adoption_race_order = wc_get_order( (int) $adoption_race_order->get_id() );
		oras_qbo_reclass_assert_same( 'existing-JE race does not adopt JE', (string) $adoption_race_order->get_meta( '_oras_qbo_je_id', true ), '' );

		// 24) An explicit retry is rejected before locks or HTTP when sync is disabled.
		$disabled_retry_order = oras_qbo_reclass_create_order( $product_id, 62.50, 62.50, '2026-03-16' );
		oras_qbo_reclass_seed_unknown_write( $disabled_retry_order, 62.50 );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			oras_qbo_reclass_settings( array( 'enabled' => false ) )
		);
		$disabled_retry_lock_calls = 0;
		$disabled_retry_lock_spy = static function ( string $query ) use ( &$disabled_retry_lock_calls ): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false || strpos( $query, 'RELEASE_LOCK' ) !== false ) {
				++$disabled_retry_lock_calls;
			}
			return $query;
		};
		$disabled_retry_calls = array();
		add_filter( 'query', $disabled_retry_lock_spy, 10, 1 );
		try {
			$disabled_retry_result = oras_qbo_reclass_reconcile_with_responder(
				(int) $disabled_retry_order->get_id(),
				true,
				static function (): \WP_Error {
					return new \WP_Error( 'unexpected_http', 'Disabled explicit retries must not reach HTTP.' );
				},
				$disabled_retry_calls
			);
		} finally {
			remove_filter( 'query', $disabled_retry_lock_spy, 10 );
		}
		oras_qbo_reclass_assert_error_code( 'disabled explicit retry', $disabled_retry_result, 'oras_qbo_disabled' );
		oras_qbo_reclass_assert_same( 'disabled explicit retry makes no HTTP request', count( $disabled_retry_calls ), 0 );
		oras_qbo_reclass_assert_same( 'disabled explicit retry acquires no lock', $disabled_retry_lock_calls, 0 );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );

		$disabled_during_retry_order = oras_qbo_reclass_create_order( $product_id, 62.75, 62.75, '2026-03-16' );
		oras_qbo_reclass_seed_unknown_write( $disabled_during_retry_order, 62.75 );
		$disabled_during_retry_posts = 0;
		$disabled_during_retry_calls = array();
		$disabled_during_retry_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $disabled_during_retry_order->get_id(),
			true,
			static function ( string $method, string $query ) use ( &$disabled_during_retry_posts ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					$disabled_during_retry_posts++;
					return array( 'JournalEntry' => array( 'Id' => 'JE-disabled-race-unsafe' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$disabled_during_retry_calls
		);
		oras_qbo_reclass_assert_error_code( 'disabled during explicit retry', $disabled_during_retry_result, 'oras_qbo_disabled' );
		oras_qbo_reclass_assert_same( 'disabled during explicit retry sends no POST', $disabled_during_retry_posts, 0 );
		$disabled_during_retry_order = wc_get_order( (int) $disabled_during_retry_order->get_id() );
		oras_qbo_reclass_assert_same( 'disabled during explicit retry retains unknown state', (string) $disabled_during_retry_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );

		// 25) A reservation that did not reach durable order metadata prevents POST.
		$reservation_order = oras_qbo_reclass_create_order( $product_id, 63.00, 63.00, '2026-03-17' );
		$reservation_receipt = oras_qbo_reclass_sales_receipt(
			'reservation-source-' . (string) $reservation_order->get_id(),
			63.00,
			'2026-03-17',
			'Order ' . $reservation_order->get_order_number()
		);
		$reservation_meta_blocks = 0;
		$block_pending_add = static function ( $check, int $object_id, string $meta_key ) use ( $reservation_order, &$reservation_meta_blocks ) {
			if ( $object_id === (int) $reservation_order->get_id() && $meta_key === '_oras_qbo_pending_write' ) {
				++$reservation_meta_blocks;
				return true;
			}
			return $check;
		};
		$reservation_posts = 0;
		$reservation_calls = array();
		add_filter( 'add_post_metadata', $block_pending_add, 10, 5 );
		add_filter( 'update_post_metadata', $block_pending_add, 10, 5 );
		try {
			$reservation_result = oras_qbo_reclass_sync_with_responder(
				(int) $reservation_order->get_id(),
				static function ( string $method, string $query ) use ( $reservation_receipt, &$reservation_posts ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $reservation_receipt ) ) );
					}
					if ( $method === 'POST' ) {
						$reservation_posts++;
						return array( 'JournalEntry' => array( 'Id' => 'JE-reservation-unsafe' ) );
					}
					return array( 'QueryResponse' => array() );
				},
				$reservation_calls
			);
		} finally {
			remove_filter( 'add_post_metadata', $block_pending_add, 10 );
			remove_filter( 'update_post_metadata', $block_pending_add, 10 );
		}
		oras_qbo_reclass_assert_true( 'pending reservation persistence was interrupted', $reservation_meta_blocks > 0 );
		oras_qbo_reclass_assert_error_code( 'unpersisted reservation', $reservation_result, 'oras_qbo_write_reservation_failed' );
		oras_qbo_reclass_assert_same( 'unpersisted reservation sends no POST', $reservation_posts, 0 );
		$reservation_order = wc_get_order( (int) $reservation_order->get_id() );
		oras_qbo_reclass_assert_same( 'failed reservation leaves no pending payload', (string) $reservation_order->get_meta( '_oras_qbo_pending_write', true ), '' );
		oras_qbo_reclass_assert_same( 'failed reservation releases source claim', (string) $reservation_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), '' );

		// 26) Timeout recovery explicitly retries the exact payload with the original request ID.
		$timeout_retry_order = oras_qbo_reclass_create_order( $product_id, 63.50, 63.50, '2026-03-17' );
		$timeout_retry_receipt = oras_qbo_reclass_sales_receipt(
			'timeout-retry-source-' . (string) $timeout_retry_order->get_id(),
			63.50,
			'2026-03-17',
			'Order ' . $timeout_retry_order->get_order_number()
		);
		$timeout_retry_initial_request_id = '';
		$timeout_retry_initial_calls = array();
		$timeout_retry_initial_result = oras_qbo_reclass_sync_with_responder(
			(int) $timeout_retry_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $timeout_retry_receipt, &$timeout_retry_initial_request_id ): array|\WP_Error {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $timeout_retry_receipt ) ) );
				}
				if ( $method === 'POST' ) {
					$timeout_retry_initial_request_id = oras_qbo_reclass_request_id( $call );
					return new \WP_Error( 'http_request_failed', 'Injected timeout after dispatch began.' );
				}
				return array( 'QueryResponse' => array() );
			},
			$timeout_retry_initial_calls
		);
		oras_qbo_reclass_assert_error_code( 'timeout retry fixture enters unknown state', $timeout_retry_initial_result, 'oras_qbo_write_outcome_unknown' );
		$timeout_retry_order = wc_get_order( (int) $timeout_retry_order->get_id() );
		$timeout_retry_pending = json_decode( (string) $timeout_retry_order->get_meta( '_oras_qbo_pending_write', true ), true );
		oras_qbo_reclass_assert_same( 'timeout persisted original request ID', (string) ( $timeout_retry_pending['request_id'] ?? '' ), $timeout_retry_initial_request_id );
		$timeout_retry_pending['started_at'] = gmdate( 'c', time() - 600 );
		$timeout_retry_pending['dispatch_started_at'] = gmdate( 'c', time() - 600 );
		$timeout_retry_order->update_meta_data( '_oras_qbo_pending_write', wp_json_encode( $timeout_retry_pending ) );
		$timeout_retry_order->save();
		$timeout_retry_reused_request_id = '';
		$timeout_retry_body = '';
		$timeout_retry_recovery_calls = array();
		$timeout_retry_recovery_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $timeout_retry_order->get_id(),
			true,
			static function ( string $method, string $query, array $call ) use ( &$timeout_retry_reused_request_id, &$timeout_retry_body ): array {
				if ( $method === 'POST' ) {
					$timeout_retry_reused_request_id = oras_qbo_reclass_request_id( $call );
					$timeout_retry_body = (string) $call['body'];
					return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-timeout-explicit-retry' );
				}
				return array( 'QueryResponse' => array() );
			},
			$timeout_retry_recovery_calls
		);
		oras_qbo_reclass_assert_true( 'timeout explicit recovery succeeds', is_array( $timeout_retry_recovery_result ) );
		oras_qbo_reclass_assert_same( 'timeout explicit recovery reuses request ID', $timeout_retry_reused_request_id, $timeout_retry_initial_request_id );
		oras_qbo_reclass_assert_same( 'timeout explicit recovery reuses exact payload', $timeout_retry_body, (string) wp_json_encode( $timeout_retry_pending['payload'] ?? array() ) );
		oras_qbo_reclass_assert_same( 'timeout explicit recovery sends one POST', count( array_filter( $timeout_retry_recovery_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

		// 27) An ID-only POST response is not enough to complete a write when exact lookup cannot confirm it.
		$id_only_order = oras_qbo_reclass_create_order( $product_id, 63.75, 63.75, '2026-03-17' );
		$id_only_receipt = oras_qbo_reclass_sales_receipt(
			'id-only-source-' . (string) $id_only_order->get_id(),
			63.75,
			'2026-03-17',
			'Order ' . $id_only_order->get_order_number()
		);
		$id_only_post_request_id = '';
		$id_only_lookups = 0;
		$id_only_calls = array();
		$id_only_result = oras_qbo_reclass_sync_with_responder(
			(int) $id_only_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $id_only_receipt, &$id_only_post_request_id, &$id_only_lookups ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $id_only_receipt ) ) );
				}
				if ( $method === 'POST' ) {
					$id_only_post_request_id = oras_qbo_reclass_request_id( $call );
					return array( 'JournalEntry' => array( 'Id' => 'JE-id-only' ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					++$id_only_lookups;
					return array( 'QueryResponse' => array() );
				}
				return array( 'QueryResponse' => array() );
			},
			$id_only_calls
		);
		oras_qbo_reclass_assert_error_code( 'ID-only POST outcome', $id_only_result, 'oras_qbo_write_outcome_unknown' );
		$id_only_order = wc_get_order( (int) $id_only_order->get_id() );
		$id_only_pending = json_decode( (string) $id_only_order->get_meta( '_oras_qbo_pending_write', true ), true );
		oras_qbo_reclass_assert_same( 'ID-only POST retains unknown state', (string) $id_only_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_same( 'ID-only POST retains request ID', (string) ( $id_only_pending['request_id'] ?? '' ), $id_only_post_request_id );
		oras_qbo_reclass_assert_same( 'ID-only POST sends one write', count( array_filter( $id_only_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );
		oras_qbo_reclass_assert_same( 'ID-only POST performs one guarded confirmation lookup', $id_only_lookups, 2 );

		$id_lookup_order = oras_qbo_reclass_create_order( $product_id, 63.76, 63.76, '2026-03-17' );
		$id_lookup_receipt = oras_qbo_reclass_sales_receipt(
			'id-lookup-source-' . (string) $id_lookup_order->get_id(),
			63.76,
			'2026-03-17',
			'Order ' . $id_lookup_order->get_order_number()
		);
		$id_lookup_entry = array();
		$id_lookup_count = 0;
		$id_lookup_calls = array();
		$id_lookup_result = oras_qbo_reclass_sync_with_responder(
			(int) $id_lookup_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $id_lookup_receipt, &$id_lookup_entry, &$id_lookup_count ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $id_lookup_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					++$id_lookup_count;
					return $id_lookup_count === 1
					? array( 'QueryResponse' => array() )
					: array( 'QueryResponse' => array( 'JournalEntry' => array( $id_lookup_entry ) ) );
				}
				if ( $method === 'POST' ) {
					$payload = json_decode( (string) $call['body'], true );
					$id_lookup_entry = is_array( $payload )
					? array_merge( $payload, array( 'Id' => 'JE-id-lookup-confirmed' ) )
					: array();
					return array( 'JournalEntry' => array( 'Id' => 'JE-id-lookup-confirmed' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$id_lookup_calls
		);
		oras_qbo_reclass_assert_true( 'ID-only POST succeeds after exact guarded lookup', is_array( $id_lookup_result ) );
		oras_qbo_reclass_assert_same( 'ID-only exact lookup count', $id_lookup_count, 2 );
		oras_qbo_reclass_assert_same( 'ID-only exact lookup never repeats POST', count( array_filter( $id_lookup_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );
		$id_lookup_order = wc_get_order( (int) $id_lookup_order->get_id() );
		oras_qbo_reclass_assert_same( 'ID-only exact lookup stores confirmed identity', (string) $id_lookup_order->get_meta( '_oras_qbo_je_id', true ), 'JE-id-lookup-confirmed' );

		$valid_reversal_order = oras_qbo_reclass_create_order( $product_id, 63.77, 63.77, '2026-03-17' );
		$valid_reversal_order->update_meta_data( '_oras_qbo_je_id', 'JE-valid-reversal-primary' );
		$valid_reversal_order->update_meta_data( '_oras_qbo_doc_number', 'ORAS-RC-' . (string) $valid_reversal_order->get_id() );
		$valid_reversal_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 63.77 ) ) );
		$valid_reversal_order->save();
		$valid_reversal_calls = array();
		$valid_reversal_result = oras_qbo_reclass_reverse_with_responder(
			(int) $valid_reversal_order->get_id(),
			static function ( string $method, string $query, array $call ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-valid-reversal' );
				}
				return array( 'QueryResponse' => array() );
			},
			$valid_reversal_calls
		);
		oras_qbo_reclass_assert_true( 'complete reversal response succeeds', is_array( $valid_reversal_result ) );
		$valid_reversal_order = wc_get_order( (int) $valid_reversal_order->get_id() );
		oras_qbo_reclass_assert_same( 'complete reversal response stores verified identity', (string) $valid_reversal_order->get_meta( '_oras_qbo_reversal_je_id', true ), 'JE-valid-reversal' );
		oras_qbo_reclass_assert_same( 'complete reversal response sends one write', count( array_filter( $valid_reversal_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

		$verification_mutations = array(
			'wrong DocNumber'           => static function ( array $entry ): array {
				$entry['DocNumber'] = 'ORAS-RC-WRONG';
				return $entry;
			},
			'wrong account'             => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['AccountRef']['value'] = '4999';
				return $entry;
			},
			'wrong amount'              => static function ( array $entry ): array {
				$entry['Line'][0]['Amount'] = '999.99';
				return $entry;
			},
			'wrong currency'            => static function ( array $entry ): array {
				$entry['CurrencyRef']['value'] = 'CAD';
				return $entry;
			},
			'wrong material note'       => static function ( array $entry ): array {
				$entry['PrivateNote'] = 'Different accounting intent';
				return $entry;
			},
			'unexpected material field' => static function ( array $entry ): array {
				$entry['OpaqueMaterial'] = array( 'value' => 'unexpected' );
				return $entry;
			},
		);
		foreach ( $verification_mutations as $verification_label => $verification_mutation ) {
			$verification_order = oras_qbo_reclass_create_order( $product_id, 63.80, 63.80, '2026-03-17' );
			$verification_receipt = oras_qbo_reclass_sales_receipt(
				'verification-' . sanitize_key( $verification_label ) . '-' . (string) $verification_order->get_id(),
				63.80,
				'2026-03-17',
				'Order ' . $verification_order->get_order_number()
			);
			$verification_lookups = 0;
			$verification_calls = array();
			$verification_result = oras_qbo_reclass_sync_with_responder(
				(int) $verification_order->get_id(),
				static function ( string $method, string $query, array $call ) use ( $verification_receipt, $verification_mutation, &$verification_lookups ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $verification_receipt ) ) );
					}
					if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
						++$verification_lookups;
						return array( 'QueryResponse' => array() );
					}
					if ( $method === 'POST' ) {
						$payload = json_decode( (string) $call['body'], true );
						if ( ! is_array( $payload ) ) {
							throw new RuntimeException( 'Verification mismatch mock requires a decoded payload.' );
						}
						$remote = $verification_mutation( array_merge( $payload, array( 'Id' => 'JE-mismatch-response' ) ) );
						return array( 'JournalEntry' => $remote );
					}
					return array( 'QueryResponse' => array() );
				},
				$verification_calls
			);
			oras_qbo_reclass_assert_error_code( $verification_label . ' POST verification', $verification_result, 'oras_qbo_write_outcome_unknown' );
			$verification_order = wc_get_order( (int) $verification_order->get_id() );
			$verification_pending = json_decode( (string) $verification_order->get_meta( '_oras_qbo_pending_write', true ), true );
			oras_qbo_reclass_assert_same( $verification_label . ' retains unknown state', (string) $verification_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
			oras_qbo_reclass_assert_true( $verification_label . ' retains exact pending payload', is_array( $verification_pending ) && ! empty( $verification_pending['payload'] ) );
			oras_qbo_reclass_assert_same( $verification_label . ' does not perform replacement lookup', $verification_lookups, 1 );
			oras_qbo_reclass_assert_same( $verification_label . ' sends one write', count( array_filter( $verification_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );
		}

		$lookup_failure_order = oras_qbo_reclass_create_order( $product_id, 63.90, 63.90, '2026-03-17' );
		$lookup_failure_receipt = oras_qbo_reclass_sales_receipt(
			'lookup-failure-' . (string) $lookup_failure_order->get_id(),
			63.90,
			'2026-03-17',
			'Order ' . $lookup_failure_order->get_order_number()
		);
		$lookup_failure_count = 0;
		$lookup_failure_calls = array();
		$lookup_failure_result = oras_qbo_reclass_sync_with_responder(
			(int) $lookup_failure_order->get_id(),
			static function ( string $method, string $query ) use ( $lookup_failure_receipt, &$lookup_failure_count ): array|\WP_Error {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $lookup_failure_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					++$lookup_failure_count;
					return $lookup_failure_count === 1
					? array( 'QueryResponse' => array() )
					: new \WP_Error( 'http_request_failed', 'Injected confirmation lookup failure.' );
				}
				if ( $method === 'POST' ) {
					return array( 'JournalEntry' => array( 'Id' => 'JE-lookup-failure' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$lookup_failure_calls
		);
		oras_qbo_reclass_assert_error_code( 'failed confirmation lookup', $lookup_failure_result, 'oras_qbo_write_outcome_unknown' );
		$lookup_failure_order = wc_get_order( (int) $lookup_failure_order->get_id() );
		oras_qbo_reclass_assert_same( 'failed confirmation lookup retains unknown state', (string) $lookup_failure_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_same( 'failed confirmation lookup is attempted once after POST', $lookup_failure_count, 2 );
		oras_qbo_reclass_assert_same( 'failed confirmation lookup never repeats POST', count( array_filter( $lookup_failure_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

		// 28) Invalid JSON and 5xx responses retain unknown protection on primary and reversal writes.
		$unknown_response_cases = array(
			'invalid-json' => array(
				'status' => 200,
				'body'   => '{invalid-json',
			),
			'http-503'     => array(
				'status' => 503,
				'body'   => array( 'Fault' => array( 'Error' => array( array( 'Message' => 'Unavailable' ) ) ) ),
			),
		);
		foreach ( $unknown_response_cases as $unknown_label => $unknown_case ) {
			$unknown_primary_order = oras_qbo_reclass_create_order( $product_id, 64.00, 64.00, '2026-03-18' );
			$unknown_primary_receipt = oras_qbo_reclass_sales_receipt(
				'unknown-primary-' . $unknown_label . '-' . (string) $unknown_primary_order->get_id(),
				64.00,
				'2026-03-18',
				'Order ' . $unknown_primary_order->get_order_number()
			);
			$unknown_primary_post_id = '';
			$unknown_primary_calls = array();
			$unknown_primary_result = oras_qbo_reclass_sync_with_responder(
				(int) $unknown_primary_order->get_id(),
				static function ( string $method, string $query, array $call ) use ( $unknown_primary_receipt, $unknown_case, &$unknown_primary_post_id ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $unknown_primary_receipt ) ) );
					}
					if ( $method === 'POST' ) {
						$unknown_primary_post_id = oras_qbo_reclass_request_id( $call );
						return array(
							'__oras_http_status' => (int) $unknown_case['status'],
							'__oras_http_body'   => $unknown_case['body'],
						);
					}
					return array( 'QueryResponse' => array() );
				},
				$unknown_primary_calls
			);
			oras_qbo_reclass_assert_error_code( 'primary ' . $unknown_label . ' outcome', $unknown_primary_result, 'oras_qbo_write_outcome_unknown' );
			$unknown_primary_order = wc_get_order( (int) $unknown_primary_order->get_id() );
			$unknown_primary_pending = json_decode( (string) $unknown_primary_order->get_meta( '_oras_qbo_pending_write', true ), true );
			oras_qbo_reclass_assert_same( 'primary ' . $unknown_label . ' retains unknown state', (string) $unknown_primary_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
			oras_qbo_reclass_assert_same( 'primary ' . $unknown_label . ' retains request ID', (string) ( $unknown_primary_pending['request_id'] ?? '' ), $unknown_primary_post_id );
			oras_qbo_reclass_assert_same( 'primary ' . $unknown_label . ' sends one POST', count( array_filter( $unknown_primary_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );

			$unknown_reversal_order = oras_qbo_reclass_create_order( $product_id, 64.50, 64.50, '2026-03-18' );
			$unknown_reversal_primary_doc = 'ORAS-RC-' . (string) $unknown_reversal_order->get_id();
			$unknown_reversal_order->update_meta_data( '_oras_qbo_je_id', 'JE-unknown-reversal-primary-' . $unknown_label );
			$unknown_reversal_order->update_meta_data( '_oras_qbo_doc_number', $unknown_reversal_primary_doc );
			$unknown_reversal_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 64.50 ) ) );
			$unknown_reversal_order->save();
			$unknown_reversal_post_id = '';
			$unknown_reversal_calls = array();
			$unknown_reversal_result = oras_qbo_reclass_reverse_with_responder(
				(int) $unknown_reversal_order->get_id(),
				static function ( string $method, string $query, array $call ) use ( $unknown_case, &$unknown_reversal_post_id ): array {
					if ( $method === 'POST' ) {
						$unknown_reversal_post_id = oras_qbo_reclass_request_id( $call );
						return array(
							'__oras_http_status' => (int) $unknown_case['status'],
							'__oras_http_body'   => $unknown_case['body'],
						);
					}
					return array( 'QueryResponse' => array() );
				},
				$unknown_reversal_calls
			);
			oras_qbo_reclass_assert_error_code( 'reversal ' . $unknown_label . ' outcome', $unknown_reversal_result, 'oras_qbo_write_outcome_unknown' );
			$unknown_reversal_order = wc_get_order( (int) $unknown_reversal_order->get_id() );
			$unknown_reversal_pending = json_decode( (string) $unknown_reversal_order->get_meta( '_oras_qbo_pending_write', true ), true );
			oras_qbo_reclass_assert_same( 'reversal ' . $unknown_label . ' retains unknown state', (string) $unknown_reversal_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
			oras_qbo_reclass_assert_same( 'reversal ' . $unknown_label . ' retains request ID', (string) ( $unknown_reversal_pending['request_id'] ?? '' ), $unknown_reversal_post_id );
			oras_qbo_reclass_assert_same( 'reversal ' . $unknown_label . ' preserves primary DocNumber', (string) $unknown_reversal_order->get_meta( '_oras_qbo_doc_number', true ), $unknown_reversal_primary_doc );
			oras_qbo_reclass_assert_same( 'reversal ' . $unknown_label . ' sends one POST', count( array_filter( $unknown_reversal_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );
		}

		// A corrupted request ID is rejected before any reconciliation request.
		$request_id_corrupt_order = oras_qbo_reclass_create_order( $product_id, 65.00, 65.00, '2026-03-18' );
		$request_id_corrupt_pending = oras_qbo_reclass_seed_unknown_write( $request_id_corrupt_order, 65.00 );
		$request_id_corrupt_pending['request_id'] = 'oras-corrupt-request-id';
		$request_id_corrupt_order->update_meta_data( '_oras_qbo_pending_write', wp_json_encode( $request_id_corrupt_pending ) );
		$request_id_corrupt_order->save();
		$request_id_corrupt_calls = array();
		$request_id_corrupt_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $request_id_corrupt_order->get_id(),
			true,
			static function (): \WP_Error {
				return new \WP_Error( 'unexpected_http', 'A corrupt request ID must fail before HTTP.' );
			},
			$request_id_corrupt_calls
		);
		oras_qbo_reclass_assert_error_code( 'corrupt pending request ID', $request_id_corrupt_result, 'oras_qbo_pending_write_invalid' );
		oras_qbo_reclass_assert_same( 'corrupt pending request ID makes no request', count( $request_id_corrupt_calls ), 0 );

		// 28) Currency context is fresh per operation and foreign currency fails
		// before reservation when company multicurrency is not explicitly enabled.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			oras_qbo_reclass_settings(
				array(
					'posting_mode'        => 'clearing',
					'clearing_account_id' => '4000',
				)
			)
		);
		$fresh_currency_one = oras_qbo_reclass_create_order( $product_id, 65.10, 65.10, '2026-03-19' );
		$fresh_currency_two = oras_qbo_reclass_create_order( $product_id, 65.20, 65.20, '2026-03-19' );
		$fresh_currency_two->set_currency( 'CAD' );
		$fresh_currency_two->save();
		$fresh_currency_calls = array();
		$fresh_currency_context_index = 0;
		$fresh_currency_results = oras_qbo_reclass_entrypoint_with_responder(
			static function () use ( $fresh_currency_one, $fresh_currency_two ): array {
				$creator = new \ORAS\Tickets\Integrations\QuickBooks\Journal_Entry_Creator();
				return array(
					$creator->build_payload_for_order(
						$fresh_currency_one,
						oras_qbo_reclass_split( 65.10 ),
						\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings()
					),
					$creator->build_payload_for_order(
						$fresh_currency_two,
						oras_qbo_reclass_split( 65.20 ),
						\ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings()
					),
				);
			},
			static function ( string $method, string $query, array $call ) use ( &$fresh_currency_context_index ): array {
				if ( strpos( (string) $call['url'], '/preferences' ) !== false ) {
					$fresh_currency_context_index++;
					return array(
						'Preferences' => array(
							'CurrencyPrefs' => array(
								'MultiCurrencyEnabled' => false,
								'HomeCurrency'         => array(
									'value' => $fresh_currency_context_index === 1 ? 'USD' : 'CAD',
								),
							),
						),
					);
				}
				return array( 'QueryResponse' => array() );
			},
			$fresh_currency_calls
		);
		oras_qbo_reclass_assert_true( 'fresh currency first operation prepares', is_array( $fresh_currency_results[0] ?? null ) );
		oras_qbo_reclass_assert_true( 'fresh currency second operation prepares', is_array( $fresh_currency_results[1] ?? null ) );
		oras_qbo_reclass_assert_same( 'fresh currency first home context', (string) ( $fresh_currency_results[0]['home_currency'] ?? '' ), 'USD' );
		oras_qbo_reclass_assert_same( 'fresh currency second home context', (string) ( $fresh_currency_results[1]['home_currency'] ?? '' ), 'CAD' );
		oras_qbo_reclass_assert_same( 'fresh currency context fetched twice', $fresh_currency_context_index, 2 );

		$foreign_disabled_order = oras_qbo_reclass_create_order( $product_id, 65.30, 65.30, '2026-03-19' );
		$foreign_disabled_order->set_currency( 'EUR' );
		$foreign_disabled_order->save();
		$foreign_disabled_calls = array();
		$foreign_disabled_result = oras_qbo_reclass_sync_with_responder(
			(int) $foreign_disabled_order->get_id(),
			static function ( string $method, string $query, array $call ): array {
				if ( strpos( (string) $call['url'], '/preferences' ) !== false ) {
					return array(
						'Preferences' => array(
							'CurrencyPrefs' => array(
								'MultiCurrencyEnabled' => 'false',
								'HomeCurrency'         => array( 'value' => 'USD' ),
							),
						),
					);
				}
				if ( $method === 'POST' ) {
					return array( 'JournalEntry' => array( 'Id' => 'JE-foreign-unsafe' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$foreign_disabled_calls
		);
		oras_qbo_reclass_assert_error_code( 'foreign currency with multicurrency disabled', $foreign_disabled_result, 'oras_qbo_multicurrency_disabled' );
		oras_qbo_reclass_assert_same( 'foreign disabled sends no POST', count( array_filter( $foreign_disabled_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );
		$foreign_disabled_order = wc_get_order( (int) $foreign_disabled_order->get_id() );
		oras_qbo_reclass_assert_same( 'foreign disabled creates no pending intent', (string) $foreign_disabled_order->get_meta( '_oras_qbo_pending_write', true ), '' );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$foreign_pending_order = oras_qbo_reclass_create_order( $product_id, 65.35, 65.35, '2026-03-19' );
		$foreign_pending_order->set_currency( 'EUR' );
		$foreign_pending_order->save();
		$foreign_pending_receipt = oras_qbo_reclass_sales_receipt(
			'foreign-pending-' . (string) $foreign_pending_order->get_id(),
			65.35,
			'2026-03-19',
			'Order ' . $foreign_pending_order->get_order_number(),
			'EUR'
		);
		$foreign_pending_receipt['ExchangeRate'] = '1.123456789123';
		$foreign_pending_calls = array();
		$foreign_pending_result = oras_qbo_reclass_sync_with_responder(
			(int) $foreign_pending_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $foreign_pending_receipt ): array|\WP_Error {
				if ( strpos( (string) $call['url'], '/preferences' ) !== false ) {
					return array(
						'Preferences' => array(
							'CurrencyPrefs' => array(
								'MultiCurrencyEnabled' => true,
								'HomeCurrency'         => array( 'value' => 'USD' ),
							),
						),
					);
				}
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $foreign_pending_receipt ) ) );
				}
				if ( $method === 'POST' ) {
					return new \WP_Error( 'http_request_failed', 'Injected foreign-currency write timeout.' );
				}
				return array( 'QueryResponse' => array() );
			},
			$foreign_pending_calls
		);
		oras_qbo_reclass_assert_error_code( 'foreign currency pending fixture', $foreign_pending_result, 'oras_qbo_write_outcome_unknown' );
		$foreign_pending_order = wc_get_order( (int) $foreign_pending_order->get_id() );
		$foreign_pending_intent = json_decode( (string) $foreign_pending_order->get_meta( '_oras_qbo_pending_write', true ), true );
		oras_qbo_reclass_assert_true( 'foreign pending attempt ID is persisted', trim( (string) ( $foreign_pending_intent['attempt_id'] ?? '' ) ) !== '' );
		oras_qbo_reclass_assert_same( 'foreign pending home currency', (string) ( $foreign_pending_intent['home_currency'] ?? '' ), 'USD' );
		oras_qbo_reclass_assert_same( 'foreign pending transaction currency', (string) ( $foreign_pending_intent['transaction_currency'] ?? '' ), 'EUR' );
		oras_qbo_reclass_assert_same( 'foreign pending multicurrency status', (bool) ( $foreign_pending_intent['multicurrency_enabled'] ?? false ), true );
		oras_qbo_reclass_assert_same( 'foreign pending exact exchange rate', (string) ( $foreign_pending_intent['exchange_rate'] ?? '' ), '1.123456789123' );
		oras_qbo_reclass_assert_same( 'foreign pending payload exact exchange rate', (string) ( $foreign_pending_intent['payload']['ExchangeRate'] ?? '' ), '1.123456789123' );
		oras_qbo_reclass_assert_same(
			'foreign pending source owner attempt',
			(string) $foreign_pending_order->get_meta( '_oras_qbo_source_claim_attempt_id', true ),
			(string) ( $foreign_pending_intent['attempt_id'] ?? '' )
		);
		$foreign_pending_intent['exchange_rate'] = '1.123456789124';
		$foreign_pending_order->update_meta_data( '_oras_qbo_pending_write', wp_json_encode( $foreign_pending_intent ) );
		$foreign_pending_order->save();
		$foreign_rate_validation_calls = array();
		$foreign_rate_validation_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $foreign_pending_order->get_id(),
			false,
			static function ( string $method, string $query, array $call ): array {
				if ( strpos( (string) $call['url'], '/preferences' ) !== false ) {
					return array(
						'Preferences' => array(
							'CurrencyPrefs' => array(
								'MultiCurrencyEnabled' => true,
								'HomeCurrency'         => array( 'value' => 'USD' ),
							),
						),
					);
				}
				return array( 'QueryResponse' => array() );
			},
			$foreign_rate_validation_calls
		);
		oras_qbo_reclass_assert_error_code( 'materially different persisted exchange rate', $foreign_rate_validation_result, 'oras_qbo_pending_currency_context_mismatch' );
		oras_qbo_reclass_assert_same(
			'exchange-rate mismatch stops before JournalEntry lookup',
			count( array_filter( $foreign_rate_validation_calls, static fn ( array $call ): bool => strpos( (string) $call['query'], 'FROM JournalEntry' ) !== false ) ),
			0
		);

		// 29) Fingerprints retain full writable content and exact decimal precision.
		$full_fingerprint = $fingerprint_base;
		$full_fingerprint['Adjustment'] = false;
		$full_fingerprint['JournalCodeRef'] = array( 'value' => 'JC-1' );
		$full_fingerprint['TxnTaxDetail'] = array(
			'TotalTax'      => '1.2300',
			'TxnTaxCodeRef' => array( 'value' => 'TAX' ),
		);
		$full_fingerprint['ExchangeRate'] = '1.123456789123';
		$full_fingerprint['ClassRef'] = array( 'value' => 'top-class-1' );
		$full_fingerprint['Balance'] = '49.20';
		$full_fingerprint['HomeBalance'] = '49.20';
		$full_fingerprint['Line'][0]['JournalEntryLineDetail']['TaxCodeRef'] = array( 'value' => 'TAX' );
		$full_fingerprint['Line'][0]['JournalEntryLineDetail']['TaxApplicableOn'] = 'Sales';
		$full_fingerprint['Line'][0]['JournalEntryLineDetail']['TaxAmount'] = '1.2300';
		$full_fingerprint['Line'][0]['JournalEntryLineDetail']['BillableStatus'] = 'NotBillable';
		$full_fingerprint['Line'][0]['JournalEntryLineDetail']['DepartmentRef'] = array( 'value' => 'line-department-1' );
		$full_fingerprint_hash = $fingerprint_creator->get_accounting_fingerprint( $full_fingerprint, 'USD' );
		$full_material_mutations = array(
			'adjustment'                               => static function ( array $entry ): array {
				$entry['Adjustment'] = true;
				return $entry; },
			'journal code'                             => static function ( array $entry ): array {
				$entry['JournalCodeRef']['value'] = 'JC-2';
				return $entry; },
			'transaction tax'                          => static function ( array $entry ): array {
				$entry['TxnTaxDetail']['TotalTax'] = '1.24';
				return $entry; },
			'tax code'                                 => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['TaxCodeRef']['value'] = 'NON';
				return $entry; },
			'tax applicability'                        => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['TaxApplicableOn'] = 'Purchase';
				return $entry; },
			'tax amount'                               => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['TaxAmount'] = '1.24';
				return $entry; },
			'billable status'                          => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['BillableStatus'] = 'Billable';
				return $entry; },
			'top-level class'                          => static function ( array $entry ): array {
				$entry['ClassRef']['value'] = 'top-class-2';
				return $entry; },
			'line department'                          => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['DepartmentRef']['value'] = 'line-department-2';
				return $entry; },
			'nested TotalAmt is not response metadata' => static function ( array $entry ): array {
				$entry['AccountingExtension'] = array( 'TotalAmt' => '99.99' );
				return $entry; },
			'unexpected material field'                => static function ( array $entry ): array {
				$entry['AccountingExtension'] = array( 'Mode' => 'Different' );
				return $entry; },
			'balance'                                  => static function ( array $entry ): array {
				$entry['Balance'] = '49.21';
				return $entry; },
			'home balance'                             => static function ( array $entry ): array {
				$entry['HomeBalance'] = '49.21';
				return $entry; },
			'tax reference case'                       => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['TaxCodeRef']['value'] = 'tax';
				return $entry; },
			'department reference case'                => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['DepartmentRef']['value'] = 'LINE-DEPARTMENT-1';
				return $entry; },
			'journal code reference case'              => static function ( array $entry ): array {
				$entry['JournalCodeRef']['value'] = 'jc-1';
				return $entry; },
		);
		foreach ( $full_material_mutations as $label => $mutation ) {
			oras_qbo_reclass_assert_true(
				'full fingerprint material difference: ' . $label,
				$fingerprint_creator->get_accounting_fingerprint( $mutation( $full_fingerprint ), 'USD' ) !== $full_fingerprint_hash
			);
		}
		$ordered_extension = $full_fingerprint;
		$ordered_extension['AccountingExtension'] = array(
			'Steps' => array( 'recognize', 'allocate' ),
		);
		$reordered_extension = $ordered_extension;
		$reordered_extension['AccountingExtension']['Steps'] = array_reverse(
			$reordered_extension['AccountingExtension']['Steps']
		);
		oras_qbo_reclass_assert_true(
			'unexpected material list order remains significant',
			$fingerprint_creator->get_accounting_fingerprint( $ordered_extension, 'USD' )
			!== $fingerprint_creator->get_accounting_fingerprint( $reordered_extension, 'USD' )
		);
		$precision_difference = $full_fingerprint;
		$precision_difference['ExchangeRate'] = '1.123456789124';
		oras_qbo_reclass_assert_true(
			'exchange rate precision is not rounded away',
			$fingerprint_creator->get_accounting_fingerprint( $precision_difference, 'USD' ) !== $full_fingerprint_hash
		);
		$duplicate_root_line = $full_fingerprint;
		$duplicate_root_line['Line'][] = $duplicate_root_line['Line'][0];
		oras_qbo_reclass_assert_true(
			'duplicate root lines remain material',
			$fingerprint_creator->get_accounting_fingerprint( $duplicate_root_line, 'USD' ) !== $full_fingerprint_hash
		);
		$empty_reference_name_a = $full_fingerprint;
		$empty_reference_name_a['Line'][0]['JournalEntryLineDetail']['AccountRef'] = array(
			'value' => '',
			'name'  => 'First empty reference label',
		);
		$empty_reference_name_b = $empty_reference_name_a;
		$empty_reference_name_b['Line'][0]['JournalEntryLineDetail']['AccountRef']['name'] = 'Second empty reference label';
		oras_qbo_reclass_assert_true(
			'reference name remains material when value is empty',
			$fingerprint_creator->get_accounting_fingerprint( $empty_reference_name_a, 'USD' )
			!== $fingerprint_creator->get_accounting_fingerprint( $empty_reference_name_b, 'USD' )
		);
		$missing_reference_value_a = $full_fingerprint;
		$missing_reference_value_a['Line'][0]['JournalEntryLineDetail']['AccountRef'] = array( 'name' => 'First missing-value label' );
		$missing_reference_value_b = $missing_reference_value_a;
		$missing_reference_value_b['Line'][0]['JournalEntryLineDetail']['AccountRef']['name'] = 'Second missing-value label';
		oras_qbo_reclass_assert_true(
			'reference name remains material when value is absent',
			$fingerprint_creator->get_accounting_fingerprint( $missing_reference_value_a, 'USD' )
			!== $fingerprint_creator->get_accounting_fingerprint( $missing_reference_value_b, 'USD' )
		);
		$harmless_full_response = $full_fingerprint;
		$harmless_full_response['Id'] = 'JE-server-id';
		$harmless_full_response['SyncToken'] = '3';
		$harmless_full_response['MetaData'] = array( 'CreateTime' => '2026-03-19T12:00:00Z' );
		$harmless_full_response['domain'] = 'QBO';
		$harmless_full_response['sparse'] = false;
		$harmless_full_response['ExchangeRate'] = '1.1234567891230';
		oras_qbo_reclass_assert_same(
			'documented server fields and trailing zeros normalize harmlessly',
			$fingerprint_creator->get_accounting_fingerprint( $harmless_full_response, 'USD' ),
			$full_fingerprint_hash
		);

		// 30) An arbitrary callback is never an orchestration capability.
		$direct_callback_http = 0;
		$direct_callback_spy = static function () use ( &$direct_callback_http ): \WP_Error {
			++$direct_callback_http;
			return new \WP_Error( 'unexpected_http', 'Arbitrary creator callback reached HTTP.' );
		};
		add_filter( 'pre_http_request', $direct_callback_spy, 1, 3 );
		try {
			$direct_callback_result = $direct_creator->create_prepared_for_order(
				$direct_order,
				$direct_prepared,
				false,
				static function (): bool {
					return true; }
			);
		} finally {
			remove_filter( 'pre_http_request', $direct_callback_spy, 1 );
		}
		oras_qbo_reclass_assert_error_code( 'arbitrary callback creator bypass', $direct_callback_result, 'oras_qbo_orchestrator_required' );
		oras_qbo_reclass_assert_same( 'arbitrary callback creator bypass HTTP count', $direct_callback_http, 0 );

		// 31) Dry-run and disabled settings are rechecked after lock acquisition.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$dry_race_order = oras_qbo_reclass_create_order( $product_id, 65.40, 65.40, '2026-03-19' );
		$dry_race_meta_before = get_post_meta( (int) $dry_race_order->get_id() );
		$dry_race_lock = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $dry_race_order->get_id() ), 0, 40 );
		$dry_race_toggled = false;
		$dry_race_lock_spy = static function ( string $query ) use ( $dry_race_lock, &$dry_race_toggled ): string {
			if ( ! $dry_race_toggled && strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $dry_race_lock ) !== false ) {
				$dry_race_toggled = true;
				\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'dry_run_mode' => true ) );
			}
			return $query;
		};
		$dry_race_http = 0;
		$dry_race_http_spy = static function () use ( &$dry_race_http ): \WP_Error {
			++$dry_race_http;
			return new \WP_Error( 'unexpected_http', 'Dry-run race reached HTTP.' );
		};
		add_filter( 'query', $dry_race_lock_spy, 10, 1 );
		add_filter( 'pre_http_request', $dry_race_http_spy, 1, 3 );
		try {
			$dry_race_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->sync_order( (int) $dry_race_order->get_id() );
		} finally {
			remove_filter( 'query', $dry_race_lock_spy, 10 );
			remove_filter( 'pre_http_request', $dry_race_http_spy, 1 );
		}
		oras_qbo_reclass_assert_true( 'dry-run toggled after order lock', $dry_race_toggled );
		oras_qbo_reclass_assert_same( 'dry-run race HTTP count', $dry_race_http, 0 );
		clean_post_cache( (int) $dry_race_order->get_id() );
		oras_qbo_reclass_assert_same( 'dry-run race preserves order metadata', get_post_meta( (int) $dry_race_order->get_id() ), $dry_race_meta_before );
		oras_qbo_reclass_assert_same( 'dry-run race returns local preview', (string) ( $dry_race_result['status'] ?? '' ), 'dry_run' );

		$connection_probe_order = oras_qbo_reclass_create_order( $product_id, 65.50, 65.50, '2026-03-19' );
		$connection_http = 0;
		$connection_http_spy = static function () use ( &$connection_http ): \WP_Error {
			++$connection_http;
			return new \WP_Error( 'unexpected_http', 'Dry connection test reached HTTP.' );
		};
		$connection_settings_before = get_option( \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY );
		add_filter( 'pre_http_request', $connection_http_spy, 1, 3 );
		try {
			oras_qbo_reclass_invoke_admin_handler( 'handle_test_connection', 'oras_tickets_qbo_test_connection', array(), false );
			$dry_cli_command = new \ORAS\Tickets\Integrations\QuickBooks\Cli_Command(
				new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(),
				new \ORAS\Tickets\Integrations\QuickBooks\Api_Client()
			);
			$dry_cli_command->test_connection( array(), array() );
		} finally {
			remove_filter( 'pre_http_request', $connection_http_spy, 1 );
		}
		oras_qbo_reclass_assert_same( 'dry connection tests HTTP count', $connection_http, 0 );
		oras_qbo_reclass_assert_same( 'dry connection tests preserve settings', get_option( \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY ), $connection_settings_before );
		oras_qbo_reclass_assert_dry_operation(
			'admin auto-map dry-run',
			$connection_probe_order,
			static function (): bool {
				oras_qbo_reclass_invoke_admin_handler(
					'handle_auto_map_event_accounts',
					'oras_tickets_qbo_auto_map_event_accounts',
					array(),
					false
				);
				return true;
			}
		);

		// A refresh response cannot persist tokens or failure state after dry-run
		// becomes enabled while the token HTTP request is in flight.
		foreach ( array( 'success', 'error' ) as $refresh_race_case ) {
			\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
				oras_qbo_reclass_settings(
					array(
						'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
						'last_error'       => 'preserve-refresh-state',
					)
				)
			);
			$refresh_race_before = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
			$refresh_race_writes_after_dry = 0;
			$refresh_race_dry_enabled = false;
			$refresh_race_option_spy = static function ( $value ) use ( &$refresh_race_writes_after_dry, &$refresh_race_dry_enabled ) {
				if ( $refresh_race_dry_enabled ) {
					++$refresh_race_writes_after_dry;
				}
				return $value;
			};
			$refresh_race_calls = array();
			add_filter( 'pre_update_option_' . \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY, $refresh_race_option_spy, 1, 3 );
			try {
				$refresh_race_result = oras_qbo_reclass_entrypoint_with_responder(
					static function () {
						return ( new \ORAS\Tickets\Integrations\QuickBooks\Api_Client() )->test_connection();
					},
					static function ( string $method, string $query, array $call ) use ( $refresh_race_case, &$refresh_race_dry_enabled ): array {
						if ( strpos( (string) $call['url'], 'oauth.platform.intuit.com' ) === false ) {
							return array( 'CompanyInfo' => array( 'CompanyName' => 'Unsafe remote continuation' ) );
						}

						\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'dry_run_mode' => true ) );
						$refresh_race_dry_enabled = true;
						if ( $refresh_race_case === 'success' ) {
							return array(
								'access_token'  => 'must-not-persist-access',
								'refresh_token' => 'must-not-persist-refresh',
								'expires_in'    => 3600,
								'x_refresh_token_expires_in' => 86400,
							);
						}

						return array(
							'__oras_http_status' => 400,
							'__oras_http_body'   => array(
								'error'             => 'invalid_grant',
								'error_description' => 'must not clear tokens after dry-run',
							),
						);
					},
					$refresh_race_calls
				);
			} finally {
				remove_filter( 'pre_update_option_' . \ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY, $refresh_race_option_spy, 1 );
			}
			oras_qbo_reclass_assert_error_code( 'OAuth refresh dry-run race ' . $refresh_race_case, $refresh_race_result, 'oras_qbo_dry_run_read_only' );
			$refresh_race_after = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
			oras_qbo_reclass_assert_same( 'OAuth refresh dry-run race ' . $refresh_race_case . ' performs one token HTTP request', count( $refresh_race_calls ), 1 );
			oras_qbo_reclass_assert_same( 'OAuth refresh dry-run race ' . $refresh_race_case . ' option writes after dry-run', $refresh_race_writes_after_dry, 0 );
			oras_qbo_reclass_assert_same( 'OAuth refresh dry-run race ' . $refresh_race_case . ' access token', (string) $refresh_race_after['access_token'], (string) $refresh_race_before['access_token'] );
			oras_qbo_reclass_assert_same( 'OAuth refresh dry-run race ' . $refresh_race_case . ' refresh token', (string) $refresh_race_after['refresh_token'], (string) $refresh_race_before['refresh_token'] );
			oras_qbo_reclass_assert_same( 'OAuth refresh dry-run race ' . $refresh_race_case . ' error state', (string) $refresh_race_after['last_error'], (string) $refresh_race_before['last_error'] );
		}

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$disabled_resync_order = oras_qbo_reclass_create_order( $product_id, 65.60, 65.60, '2026-03-19' );
		$disabled_resync_order->update_meta_data( '_oras_qbo_je_id', 'JE-preserve-disabled' );
		$disabled_resync_order->update_meta_data( '_oras_qbo_doc_number', 'DOC-preserve-disabled' );
		$disabled_resync_order->update_meta_data( '_oras_qbo_last_audit_event', 'preserve-audit' );
		$disabled_resync_order->save();
		$disabled_resync_meta_before = get_post_meta( (int) $disabled_resync_order->get_id() );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
		$disabled_resync_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->resync_order( (int) $disabled_resync_order->get_id() );
		oras_qbo_reclass_assert_error_code( 'disabled resync rejected', $disabled_resync_result, 'oras_qbo_disabled' );
		clean_post_cache( (int) $disabled_resync_order->get_id() );
		oras_qbo_reclass_assert_same( 'disabled resync preserves all metadata', get_post_meta( (int) $disabled_resync_order->get_id() ), $disabled_resync_meta_before );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$disabled_lock_race_order = oras_qbo_reclass_create_order( $product_id, 65.70, 65.70, '2026-03-19' );
		$disabled_lock_race_order->update_meta_data( '_oras_qbo_je_id', 'JE-preserve-lock-race' );
		$disabled_lock_race_order->update_meta_data( '_oras_qbo_doc_number', 'DOC-preserve-lock-race' );
		$disabled_lock_race_order->save();
		$disabled_lock_meta_before = get_post_meta( (int) $disabled_lock_race_order->get_id() );
		$disabled_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $disabled_lock_race_order->get_id() ), 0, 40 );
		$disable_after_lock = false;
		$disabled_lock_spy = static function ( string $query ) use ( $disabled_lock_name, &$disable_after_lock ): string {
			if ( ! $disable_after_lock && strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $disabled_lock_name ) !== false ) {
				$disable_after_lock = true;
				\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
			}
			return $query;
		};
		add_filter( 'query', $disabled_lock_spy, 10, 1 );
		try {
			$disabled_lock_result = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->resync_order( (int) $disabled_lock_race_order->get_id() );
		} finally {
			remove_filter( 'query', $disabled_lock_spy, 10 );
		}
		oras_qbo_reclass_assert_true( 'disabled state toggled after resync lock', $disable_after_lock );
		oras_qbo_reclass_assert_error_code( 'disabled after resync lock rejected', $disabled_lock_result, 'oras_qbo_disabled' );
		clean_post_cache( (int) $disabled_lock_race_order->get_id() );
		oras_qbo_reclass_assert_same( 'disabled after lock preserves all metadata', get_post_meta( (int) $disabled_lock_race_order->get_id() ), $disabled_lock_meta_before );

		// 32) Cleanup is owned by the exact pending attempt and provisional DocNumber.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$owned_cleanup_order = oras_qbo_reclass_create_order( $product_id, 65.80, 65.80, '2026-03-19' );
		$owned_pending = oras_qbo_reclass_seed_unknown_write( $owned_cleanup_order, 65.80 );
		$clear_pending_method = new ReflectionMethod( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::class, 'clear_pending_write' );
		$clear_pending_method->setAccessible( true );
		$clear_pending_method->invoke( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(), $owned_cleanup_order, $owned_pending );
		$owned_cleanup_order = wc_get_order( (int) $owned_cleanup_order->get_id() );
		oras_qbo_reclass_assert_same( 'owned cleanup clears pending payload', (string) $owned_cleanup_order->get_meta( '_oras_qbo_pending_write', true ), '' );
		oras_qbo_reclass_assert_same( 'owned cleanup clears owned source key', (string) $owned_cleanup_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), '' );
		oras_qbo_reclass_assert_same( 'owned cleanup clears owned provisional DocNumber', (string) $owned_cleanup_order->get_meta( '_oras_qbo_doc_number', true ), '' );

		$foreign_pending_order = oras_qbo_reclass_create_order( $product_id, 65.90, 65.90, '2026-03-19' );
		$expected_pending = oras_qbo_reclass_seed_unknown_write( $foreign_pending_order, 65.90 );
		$other_attempt = wp_generate_uuid4();
		$other_pending = $expected_pending;
		$other_pending['attempt_id'] = $other_attempt;
		$foreign_pending_order->update_meta_data( '_oras_qbo_pending_write', wp_json_encode( $other_pending ) );
		$foreign_pending_order->update_meta_data( '_oras_qbo_source_claim_attempt_id', $other_attempt );
		$foreign_pending_order->update_meta_data( '_oras_qbo_doc_number_attempt_id', $other_attempt );
		$foreign_pending_order->save();
		$clear_pending_method->invoke( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(), $foreign_pending_order, $expected_pending );
		$foreign_pending_order = wc_get_order( (int) $foreign_pending_order->get_id() );
		oras_qbo_reclass_assert_same( 'cleanup preserves another attempt pending payload', (string) $foreign_pending_order->get_meta( '_oras_qbo_pending_write', true ), (string) wp_json_encode( $other_pending ) );
		oras_qbo_reclass_assert_same( 'cleanup preserves another attempt source key', (string) $foreign_pending_order->get_meta( '_oras_qbo_reclass_source_txn_key', true ), (string) $other_pending['source_match']['key'] );
		oras_qbo_reclass_assert_same( 'cleanup preserves another attempt DocNumber', (string) $foreign_pending_order->get_meta( '_oras_qbo_doc_number', true ), (string) $other_pending['doc_number'] );

		// 33) Every uncertain response shape retains the exact pending write.
		$response_matrix = array(
			'http-408'         => array(
				'__oras_http_status' => 408,
				'__oras_http_body'   => array( 'Fault' => array() ),
			),
			'status-zero'      => array(
				'__oras_http_status' => 0,
				'__oras_http_body'   => '',
			),
			'malformed-status' => array(
				'__oras_http_status' => 'not-a-status',
				'__oras_http_body'   => '{}',
			),
			'missing-status'   => array(
				'__oras_http_status'         => 200,
				'__oras_missing_http_status' => true,
				'__oras_http_body'           => '{}',
			),
			'invalid-json'     => array(
				'__oras_http_status' => 200,
				'__oras_http_body'   => '{truncated',
			),
			'missing-id'       => array(
				'__oras_http_status' => 200,
				'__oras_http_body'   => array( 'JournalEntry' => array() ),
			),
			'http-500'         => array(
				'__oras_http_status' => 500,
				'__oras_http_body'   => array( 'Fault' => array() ),
			),
		);
		foreach ( $response_matrix as $matrix_label => $matrix_response ) {
			$matrix_order = oras_qbo_reclass_create_order( $product_id, 66.00, 66.00, '2026-03-20' );
			$matrix_receipt = oras_qbo_reclass_sales_receipt(
				'matrix-' . $matrix_label . '-' . (string) $matrix_order->get_id(),
				66.00,
				'2026-03-20',
				'Order ' . $matrix_order->get_order_number()
			);
			$matrix_calls = array();
			$matrix_result = oras_qbo_reclass_sync_with_responder(
				(int) $matrix_order->get_id(),
				static function ( string $method, string $query ) use ( $matrix_receipt, $matrix_response ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $matrix_receipt ) ) );
					}
					if ( $method === 'POST' ) {
						return $matrix_response;
					}
					return array( 'QueryResponse' => array() );
				},
				$matrix_calls
			);
			oras_qbo_reclass_assert_error_code( 'response matrix ' . $matrix_label, $matrix_result, 'oras_qbo_write_outcome_unknown' );
			$matrix_order = wc_get_order( (int) $matrix_order->get_id() );
			oras_qbo_reclass_assert_same( 'response matrix ' . $matrix_label . ' retains unknown state', (string) $matrix_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
			$matrix_pending = json_decode( (string) $matrix_order->get_meta( '_oras_qbo_pending_write', true ), true );
			oras_qbo_reclass_assert_true( 'response matrix ' . $matrix_label . ' retains pending intent', is_array( $matrix_pending ) );
			oras_qbo_reclass_assert_true( 'response matrix ' . $matrix_label . ' retains attempt ID', trim( (string) ( $matrix_pending['attempt_id'] ?? '' ) ) !== '' );
			oras_qbo_reclass_assert_same( 'response matrix ' . $matrix_label . ' retains home currency', (string) ( $matrix_pending['home_currency'] ?? '' ), 'USD' );
			oras_qbo_reclass_assert_same( 'response matrix ' . $matrix_label . ' retains transaction currency', (string) ( $matrix_pending['transaction_currency'] ?? '' ), 'USD' );
			oras_qbo_reclass_assert_same( 'response matrix ' . $matrix_label . ' retains multicurrency state', (bool) ( $matrix_pending['multicurrency_enabled'] ?? true ), false );
			oras_qbo_reclass_assert_same( 'response matrix ' . $matrix_label . ' retains exchange rate', (string) ( $matrix_pending['exchange_rate'] ?? '' ), '1' );

			$matrix_reversal_order = oras_qbo_reclass_create_order( $product_id, 66.05, 66.05, '2026-03-20' );
			$matrix_primary_doc = 'ORAS-RC-' . (string) $matrix_reversal_order->get_id();
			$matrix_reversal_order->update_meta_data( '_oras_qbo_je_id', 'JE-matrix-primary-' . $matrix_label );
			$matrix_reversal_order->update_meta_data( '_oras_qbo_doc_number', $matrix_primary_doc );
			$matrix_reversal_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 66.05 ) ) );
			$matrix_reversal_order->save();
			$matrix_reversal_calls = array();
			$matrix_reversal_result = oras_qbo_reclass_reverse_with_responder(
				(int) $matrix_reversal_order->get_id(),
				static function ( string $method ) use ( $matrix_response ): array {
					if ( $method === 'POST' ) {
						return $matrix_response;
					}
					return array( 'QueryResponse' => array() );
				},
				$matrix_reversal_calls
			);
			oras_qbo_reclass_assert_error_code( 'reversal response matrix ' . $matrix_label, $matrix_reversal_result, 'oras_qbo_write_outcome_unknown' );
			$matrix_reversal_order = wc_get_order( (int) $matrix_reversal_order->get_id() );
			$matrix_reversal_pending = json_decode( (string) $matrix_reversal_order->get_meta( '_oras_qbo_pending_write', true ), true );
			oras_qbo_reclass_assert_same( 'reversal response matrix ' . $matrix_label . ' retains unknown state', (string) $matrix_reversal_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
			oras_qbo_reclass_assert_same( 'reversal response matrix ' . $matrix_label . ' retains reversal operation', (string) ( $matrix_reversal_pending['operation'] ?? '' ), 'reversal' );
			oras_qbo_reclass_assert_same( 'reversal response matrix ' . $matrix_label . ' preserves primary DocNumber', (string) $matrix_reversal_order->get_meta( '_oras_qbo_doc_number', true ), $matrix_primary_doc );
			oras_qbo_reclass_assert_same( 'reversal response matrix ' . $matrix_label . ' sends one POST', count( array_filter( $matrix_reversal_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );
		}

		// A 401 exists only after the JournalEntry crossed the dispatch boundary.
		// A later settings change may stop refresh but cannot release the intent.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$post_401_order = oras_qbo_reclass_create_order( $product_id, 66.02, 66.02, '2026-03-20' );
		$post_401_receipt = oras_qbo_reclass_sales_receipt(
			'post-401-' . (string) $post_401_order->get_id(),
			66.02,
			'2026-03-20',
			'Order ' . $post_401_order->get_order_number()
		);
		$post_401_calls = array();
		$post_401_request_id = '';
		$post_401_result = oras_qbo_reclass_sync_with_responder(
			(int) $post_401_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $post_401_receipt, &$post_401_request_id ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $post_401_receipt ) ) );
				}
				if ( $method === 'POST' && strpos( (string) $call['url'], '/journalentry' ) !== false ) {
					$post_401_request_id = oras_qbo_reclass_request_id( $call );
					\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
					return array(
						'__oras_http_status' => 401,
						'__oras_http_body'   => array(
							'Fault' => array(
								'Error' => array( array( 'Message' => 'Authentication failed after dispatch.' ) ),
							),
						),
					);
				}
				return array( 'QueryResponse' => array() );
			},
			$post_401_calls
		);
		oras_qbo_reclass_assert_error_code( 'post-401 settings guard preserves uncertainty', $post_401_result, 'oras_qbo_write_outcome_unknown' );
		$post_401_order = wc_get_order( (int) $post_401_order->get_id() );
		$post_401_pending = json_decode( (string) $post_401_order->get_meta( '_oras_qbo_pending_write', true ), true );
		oras_qbo_reclass_assert_true( 'post-401 retains exact pending payload', is_array( $post_401_pending ) && ! empty( $post_401_pending['payload'] ) );
		oras_qbo_reclass_assert_same( 'post-401 retains request ID', (string) ( $post_401_pending['request_id'] ?? '' ), $post_401_request_id );
		oras_qbo_reclass_assert_true( 'post-401 retains dispatch marker', trim( (string) ( $post_401_pending['dispatch_started_at'] ?? '' ) ) !== '' );
		oras_qbo_reclass_assert_same( 'post-401 retains source claim', (string) $post_401_order->get_meta( '_oras_qbo_source_claim_state', true ), 'pending' );
		oras_qbo_reclass_assert_same( 'post-401 sends no refresh request after disable', count( array_filter( $post_401_calls, static fn ( array $call ): bool => strpos( (string) $call['url'], 'oauth.platform.intuit.com' ) !== false ) ), 0 );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$post_401_refresh_order = oras_qbo_reclass_create_order( $product_id, 66.03, 66.03, '2026-03-20' );
		$post_401_refresh_receipt = oras_qbo_reclass_sales_receipt(
			'post-401-refresh-' . (string) $post_401_refresh_order->get_id(),
			66.03,
			'2026-03-20',
			'Order ' . $post_401_refresh_order->get_order_number()
		);
		$post_401_refresh_calls = array();
		$post_401_refresh_result = oras_qbo_reclass_sync_with_responder(
			(int) $post_401_refresh_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $post_401_refresh_receipt ): array|\WP_Error {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $post_401_refresh_receipt ) ) );
				}
				if ( strpos( (string) $call['url'], '/journalentry' ) !== false && $method === 'POST' ) {
					return array(
						'__oras_http_status' => 401,
						'__oras_http_body'   => array(),
					);
				}
				if ( strpos( (string) $call['url'], 'oauth.platform.intuit.com' ) !== false ) {
					return new \WP_Error( 'http_request_failed', 'Injected OAuth refresh timeout after JournalEntry 401.' );
				}
				return array( 'QueryResponse' => array() );
			},
			$post_401_refresh_calls
		);
		oras_qbo_reclass_assert_error_code( 'post-401 refresh failure preserves uncertainty', $post_401_refresh_result, 'oras_qbo_write_outcome_unknown' );
		$post_401_refresh_order = wc_get_order( (int) $post_401_refresh_order->get_id() );
		oras_qbo_reclass_assert_same( 'post-401 refresh failure records unknown outcome', (string) $post_401_refresh_order->get_meta( '_oras_qbo_write_state', true ), 'unknown_outcome' );
		oras_qbo_reclass_assert_true( 'post-401 refresh failure retains pending intent', is_array( json_decode( (string) $post_401_refresh_order->get_meta( '_oras_qbo_pending_write', true ), true ) ) );

		// 34) Final enabled guard prevents a primary POST after duplicate lookup.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$dispatch_disable_order = oras_qbo_reclass_create_order( $product_id, 66.10, 66.10, '2026-03-20' );
		$dispatch_disable_receipt = oras_qbo_reclass_sales_receipt(
			'dispatch-disable-' . (string) $dispatch_disable_order->get_id(),
			66.10,
			'2026-03-20',
			'Order ' . $dispatch_disable_order->get_order_number()
		);
		$dispatch_disable_posts = 0;
		$dispatch_disable_calls = array();
		$dispatch_disable_result = oras_qbo_reclass_sync_with_responder(
			(int) $dispatch_disable_order->get_id(),
			static function ( string $method, string $query ) use ( $dispatch_disable_receipt, &$dispatch_disable_posts ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $dispatch_disable_receipt ) ) );
				}
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					$dispatch_disable_posts++;
					return array( 'JournalEntry' => array( 'Id' => 'JE-disabled-dispatch-unsafe' ) );
				}
				return array( 'QueryResponse' => array() );
			},
			$dispatch_disable_calls
		);
		oras_qbo_reclass_assert_error_code( 'disabled immediately before primary dispatch', $dispatch_disable_result, 'oras_qbo_disabled' );
		oras_qbo_reclass_assert_same( 'disabled immediately before primary dispatch sends no POST', $dispatch_disable_posts, 0 );

		// Disabling after one sync-owned read also blocks every later Preferences,
		// duplicate-lookup, and write request in that operation.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$read_disable_order = oras_qbo_reclass_create_order( $product_id, 66.15, 66.15, '2026-03-20' );
		$read_disable_receipt = oras_qbo_reclass_sales_receipt(
			'read-disable-' . (string) $read_disable_order->get_id(),
			66.15,
			'2026-03-20',
			'Order ' . $read_disable_order->get_order_number()
		);
		$read_disable_posts = 0;
		$read_disable_calls = array();
		$read_disable_result = oras_qbo_reclass_sync_with_responder(
			(int) $read_disable_order->get_id(),
			static function ( string $method, string $query ) use ( $read_disable_receipt, &$read_disable_posts ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $read_disable_receipt ) ) );
				}
				if ( $method === 'POST' ) {
					$read_disable_posts++;
				}
				return array( 'QueryResponse' => array() );
			},
			$read_disable_calls
		);
		oras_qbo_reclass_assert_error_code( 'disabled after sync-owned read', $read_disable_result, 'oras_qbo_disabled' );
		oras_qbo_reclass_assert_same( 'disabled after sync-owned read stops later HTTP', count( $read_disable_calls ), 1 );
		oras_qbo_reclass_assert_same( 'disabled after sync-owned read sends no POST', $read_disable_posts, 0 );
		$read_disable_order = wc_get_order( (int) $read_disable_order->get_id() );
		oras_qbo_reclass_assert_same( 'disabled after sync-owned read creates no pending intent', (string) $read_disable_order->get_meta( '_oras_qbo_pending_write', true ), '' );

		// 35) Retriable reversal failures schedule only the reversal hook; primary
		// failures schedule only the primary hook. Reconciliation is never queued.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$routing_reversal_order = oras_qbo_reclass_create_order( $product_id, 66.20, 66.20, '2026-03-20' );
		$routing_reversal_order->update_meta_data( '_oras_qbo_je_id', 'JE-routing-primary' );
		$routing_reversal_order->update_meta_data( '_oras_qbo_split_snapshot', wp_json_encode( oras_qbo_reclass_split( 66.20 ) ) );
		$routing_reversal_order->save();
		$routing_reversal_calls = array();
		$routing_reversal_result = oras_qbo_reclass_reverse_with_responder(
			(int) $routing_reversal_order->get_id(),
			static function ( string $method, string $query ): array {
				if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
					return array( 'QueryResponse' => array() );
				}
				if ( $method === 'POST' ) {
					return array(
						'__oras_http_status' => 429,
						'__oras_http_body'   => array(
							'Fault' => array(
								'Error' => array(
									array(
										'Message' => 'Rate limited',
										'Detail'  => 'Request was rejected.',
									),
								),
							),
						),
					);
				}
				return array( 'QueryResponse' => array() );
			},
			$routing_reversal_calls
		);
		oras_qbo_reclass_assert_error_code( 'reversal routing fixture rejection', $routing_reversal_result, 'oras_qbo_api_http_429' );
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			oras_qbo_reclass_assert_true(
				'reversal failure schedules reversal hook',
				(bool) as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK,
					array( (int) $routing_reversal_order->get_id(), 0 ),
					'oras-tickets'
				)
			);
			oras_qbo_reclass_assert_true(
				'reversal failure does not schedule primary hook',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
					array( (int) $routing_reversal_order->get_id() ),
					'oras-tickets'
				)
			);
			as_unschedule_all_actions( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK, array( (int) $routing_reversal_order->get_id(), 0 ), 'oras-tickets' );
		}

		$routing_primary_order = oras_qbo_reclass_create_order( $product_id, 66.30, 66.30, '2026-03-20' );
		$routing_primary_receipt = oras_qbo_reclass_sales_receipt(
			'routing-primary-' . (string) $routing_primary_order->get_id(),
			66.30,
			'2026-03-20',
			'Order ' . $routing_primary_order->get_order_number()
		);
		$routing_primary_calls = array();
		$routing_primary_result = oras_qbo_reclass_sync_with_responder(
			(int) $routing_primary_order->get_id(),
			static function ( string $method, string $query ) use ( $routing_primary_receipt ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $routing_primary_receipt ) ) );
				}
				if ( $method === 'POST' ) {
					return array(
						'__oras_http_status' => 429,
						'__oras_http_body'   => array(
							'Fault' => array(
								'Error' => array(
									array(
										'Message' => 'Rate limited',
										'Detail'  => 'Request was rejected.',
									),
								),
							),
						),
					);
				}
				return array( 'QueryResponse' => array() );
			},
			$routing_primary_calls
		);
		oras_qbo_reclass_assert_error_code( 'primary routing fixture rejection', $routing_primary_result, 'oras_qbo_api_http_429' );
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			oras_qbo_reclass_assert_true(
				'primary failure schedules primary hook',
				(bool) as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
					array( (int) $routing_primary_order->get_id(), 0 ),
					'oras-tickets'
				)
			);
			oras_qbo_reclass_assert_true(
				'primary failure does not schedule reversal hook',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK,
					array( (int) $routing_primary_order->get_id(), 0 ),
					'oras-tickets'
				)
			);
			as_unschedule_all_actions( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK, array( (int) $routing_primary_order->get_id(), 0 ), 'oras-tickets' );
		}

		$routing_reconcile_order = oras_qbo_reclass_create_order( $product_id, 66.40, 66.40, '2026-03-20' );
		oras_qbo_reclass_seed_unknown_write( $routing_reconcile_order, 66.40 );
		$routing_reconcile_calls = array();
		$routing_reconcile_result = oras_qbo_reclass_reconcile_with_responder(
			(int) $routing_reconcile_order->get_id(),
			true,
			static function ( string $method ): array {
				if ( $method === 'POST' ) {
					return array(
						'__oras_http_status' => 429,
						'__oras_http_body'   => array(
							'Fault' => array(
								'Error' => array(
									array(
										'Message' => 'Rate limited',
										'Detail'  => 'Explicit retry rejected.',
									),
								),
							),
						),
					);
				}
				return array( 'QueryResponse' => array() );
			},
			$routing_reconcile_calls
		);
		oras_qbo_reclass_assert_error_code( 'reconciliation routing fixture rejection', $routing_reconcile_result, 'oras_qbo_api_http_429' );
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			oras_qbo_reclass_assert_true(
				'reconciliation never schedules primary hook',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
					array( (int) $routing_reconcile_order->get_id(), 0 ),
					'oras-tickets'
				)
			);
			oras_qbo_reclass_assert_true(
				'reconciliation never schedules reversal hook',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK,
					array( (int) $routing_reconcile_order->get_id() ),
					'oras-tickets'
				)
			);
		}

		// 36) Fingerprint normalization is path-specific and fails closed for
		// opaque reference IDs and unexpected balance fields.
		$currency_lowercase = $fingerprint_base;
		$currency_lowercase['CurrencyRef']['value'] = 'usd';
		oras_qbo_reclass_assert_same(
			'CurrencyRef value is case-normalized',
			$fingerprint_creator->get_accounting_fingerprint( $currency_lowercase, 'USD' ),
			$base_fingerprint
		);
		$opaque_reference_mutations = array(
			'three-letter AccountRef' => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['AccountRef']['value'] = 'ABC';
				$other = $entry;
				$other['Line'][0]['JournalEntryLineDetail']['AccountRef']['value'] = 'abc';
				return array( $entry, $other );
			},
			'TaxCodeRef'              => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['TaxCodeRef'] = array( 'value' => 'TAX' );
				$other = $entry;
				$other['Line'][0]['JournalEntryLineDetail']['TaxCodeRef']['value'] = 'tax';
				return array( $entry, $other );
			},
			'ClassRef'                => static function ( array $entry ): array {
				$entry['Line'][0]['JournalEntryLineDetail']['ClassRef']['value'] = 'ABC';
				$other = $entry;
				$other['Line'][0]['JournalEntryLineDetail']['ClassRef']['value'] = 'abc';
				return array( $entry, $other );
			},
		);
		foreach ( $opaque_reference_mutations as $reference_label => $reference_mutation ) {
			$reference_pair = $reference_mutation( $fingerprint_base );
			oras_qbo_reclass_assert_true(
				$reference_label . ' case remains material',
				$fingerprint_creator->get_accounting_fingerprint( $reference_pair[0], 'USD' )
				!== $fingerprint_creator->get_accounting_fingerprint( $reference_pair[1], 'USD' )
			);
		}
		foreach ( array( 'Balance', 'HomeBalance' ) as $balance_field ) {
			$with_balance = $fingerprint_base;
			$with_balance[ $balance_field ] = '49.20';
			oras_qbo_reclass_assert_true(
				$balance_field . ' is not silently ignored',
				$fingerprint_creator->get_accounting_fingerprint( $with_balance, 'USD' ) !== $base_fingerprint
			);
		}
		$duplicate_root_line = $fingerprint_base;
		$duplicate_root_line['Line'][] = $duplicate_root_line['Line'][0];
		oras_qbo_reclass_assert_true(
			'duplicate root line is material',
			$fingerprint_creator->get_accounting_fingerprint( $duplicate_root_line, 'USD' ) !== $base_fingerprint
		);
		$unexpected_nested_field = $fingerprint_base;
		$unexpected_nested_field['Line'][0]['JournalEntryLineDetail']['OpaqueExtension'] = array( 'value' => 'ABC' );
		oras_qbo_reclass_assert_true(
			'unexpected nested material field is retained',
			$fingerprint_creator->get_accounting_fingerprint( $unexpected_nested_field, 'USD' ) !== $base_fingerprint
		);

		// 37) Durable intent is visibly prepared without a dispatch marker. The
		// marker is persisted at the last possible boundary immediately before HTTP.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$boundary_order = oras_qbo_reclass_create_order( $product_id, 66.50, 66.50, '2026-03-21' );
		$boundary_receipt = oras_qbo_reclass_sales_receipt(
			'boundary-' . (string) $boundary_order->get_id(),
			66.50,
			'2026-03-21',
			'Order ' . $boundary_order->get_order_number()
		);
		$boundary_pending_snapshots = array();
		$boundary_save_spy = static function ( $saved_order ) use ( $boundary_order, &$boundary_pending_snapshots ): void {
			if ( ! $saved_order instanceof \WC_Order || (int) $saved_order->get_id() !== (int) $boundary_order->get_id() ) {
				return;
			}
			$pending = json_decode( (string) $saved_order->get_meta( '_oras_qbo_pending_write', true ), true );
			if ( is_array( $pending ) ) {
				$boundary_pending_snapshots[] = (string) ( $pending['dispatch_started_at'] ?? '' );
			}
		};
		add_action( 'woocommerce_after_order_object_save', $boundary_save_spy, 10, 1 );
		$boundary_calls = array();
		try {
			$boundary_result = oras_qbo_reclass_sync_with_responder(
				(int) $boundary_order->get_id(),
				static function ( string $method, string $query, array $call ) use ( $boundary_receipt, $boundary_order ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $boundary_receipt ) ) );
					}
					if ( $method === 'POST' ) {
						$posted_order = wc_get_order( (int) $boundary_order->get_id() );
						$pending = json_decode( (string) $posted_order->get_meta( '_oras_qbo_pending_write', true ), true );
						oras_qbo_reclass_assert_true( 'dispatch marker exists at HTTP boundary', trim( (string) ( $pending['dispatch_started_at'] ?? '' ) ) !== '' );
						return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-boundary' );
					}
					return array( 'QueryResponse' => array() );
				},
				$boundary_calls
			);
		} finally {
			remove_action( 'woocommerce_after_order_object_save', $boundary_save_spy, 10 );
		}
		oras_qbo_reclass_assert_true( 'dispatch boundary fixture succeeds', is_array( $boundary_result ) );
		oras_qbo_reclass_assert_true( 'prepared pending intent is saved without dispatch marker', in_array( '', $boundary_pending_snapshots, true ) );
		oras_qbo_reclass_assert_true(
			'dispatched pending intent is saved with marker',
			count( array_filter( $boundary_pending_snapshots, static fn ( string $marker ): bool => $marker !== '' ) ) >= 1
		);

		$marker_failure_order = oras_qbo_reclass_create_order( $product_id, 66.60, 66.60, '2026-03-21' );
		$marker_failure_receipt = oras_qbo_reclass_sales_receipt(
			'marker-failure-' . (string) $marker_failure_order->get_id(),
			66.60,
			'2026-03-21',
			'Order ' . $marker_failure_order->get_order_number()
		);
		$marker_prepared_seen = false;
		$marker_failure_thrown = false;
		$marker_failure_save_spy = static function ( $saved_order ) use ( $marker_failure_order, &$marker_prepared_seen, &$marker_failure_thrown ): void {
			if ( ! $saved_order instanceof \WC_Order || (int) $saved_order->get_id() !== (int) $marker_failure_order->get_id() ) {
				return;
			}
			$pending = json_decode( (string) $saved_order->get_meta( '_oras_qbo_pending_write', true ), true );
			if ( ! is_array( $pending ) ) {
				return;
			}
			$marker = trim( (string) ( $pending['dispatch_started_at'] ?? '' ) );
			if ( $marker === '' ) {
				$marker_prepared_seen = true;
				return;
			}
			if ( $marker_prepared_seen && ! $marker_failure_thrown ) {
				$marker_failure_thrown = true;
				throw new RuntimeException( 'Injected dispatch-marker persistence failure.' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $marker_failure_save_spy, 10, 1 );
		$marker_failure_calls = array();
		try {
			$marker_failure_result = oras_qbo_reclass_sync_with_responder(
				(int) $marker_failure_order->get_id(),
				static function ( string $method, string $query ) use ( $marker_failure_receipt ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $marker_failure_receipt ) ) );
					}
					if ( $method === 'POST' ) {
						return array( 'JournalEntry' => array( 'Id' => 'JE-marker-failure-unsafe' ) );
					}
					return array( 'QueryResponse' => array() );
				},
				$marker_failure_calls
			);
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $marker_failure_save_spy, 10 );
		}
		oras_qbo_reclass_assert_true( 'marker persistence failure is injected after preparation', $marker_prepared_seen && $marker_failure_thrown );
		oras_qbo_reclass_assert_error_code( 'marker persistence failure', $marker_failure_result, 'oras_qbo_dispatch_marker_failed' );
		oras_qbo_reclass_assert_same( 'marker persistence failure sends no POST', count( array_filter( $marker_failure_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );
		$marker_failure_order = wc_get_order( (int) $marker_failure_order->get_id() );
		oras_qbo_reclass_assert_true( 'marker persistence failure is conclusively pre-dispatch', (string) $marker_failure_order->get_meta( '_oras_qbo_write_state', true ) !== 'unknown_outcome' );

		$guard_before_marker_order = oras_qbo_reclass_create_order( $product_id, 66.70, 66.70, '2026-03-21' );
		$guard_before_marker_receipt = oras_qbo_reclass_sales_receipt(
			'guard-before-marker-' . (string) $guard_before_marker_order->get_id(),
			66.70,
			'2026-03-21',
			'Order ' . $guard_before_marker_order->get_order_number()
		);
		$guard_before_marker_seen = null;
		$guard_before_marker_toggled = false;
		$guard_before_marker_spy = static function ( $saved_order ) use ( $guard_before_marker_order, &$guard_before_marker_seen, &$guard_before_marker_toggled ): void {
			if ( $guard_before_marker_toggled || ! $saved_order instanceof \WC_Order || (int) $saved_order->get_id() !== (int) $guard_before_marker_order->get_id() ) {
				return;
			}
			$pending = json_decode( (string) $saved_order->get_meta( '_oras_qbo_pending_write', true ), true );
			if ( ! is_array( $pending ) ) {
				return;
			}
			$guard_before_marker_seen = trim( (string) ( $pending['dispatch_started_at'] ?? '' ) ) !== '';
			$guard_before_marker_toggled = true;
			\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
		};
		add_action( 'woocommerce_after_order_object_save', $guard_before_marker_spy, 10, 1 );
		$guard_before_marker_calls = array();
		try {
			$guard_before_marker_result = oras_qbo_reclass_sync_with_responder(
				(int) $guard_before_marker_order->get_id(),
				static function ( string $method, string $query ) use ( $guard_before_marker_receipt ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $guard_before_marker_receipt ) ) );
					}
					return $method === 'POST'
					? array( 'JournalEntry' => array( 'Id' => 'JE-guard-before-marker-unsafe' ) )
					: array( 'QueryResponse' => array() );
				},
				$guard_before_marker_calls
			);
		} finally {
			remove_action( 'woocommerce_after_order_object_save', $guard_before_marker_spy, 10 );
		}
		oras_qbo_reclass_assert_error_code( 'final guard before marker', $guard_before_marker_result, 'oras_qbo_disabled' );
		oras_qbo_reclass_assert_same( 'final guard observes prepared-not-dispatched state', $guard_before_marker_seen, false );
		oras_qbo_reclass_assert_same( 'final guard before marker sends no POST', count( array_filter( $guard_before_marker_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$pre_dispatch_recovery_posts = 0;
		$pre_dispatch_recovery_calls = array();
		$pre_dispatch_recovery_result = oras_qbo_reclass_sync_with_responder(
			(int) $guard_before_marker_order->get_id(),
			static function ( string $method, string $query, array $call ) use ( $guard_before_marker_receipt, &$pre_dispatch_recovery_posts ): array {
				if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
					return array( 'QueryResponse' => array( 'SalesReceipt' => array( $guard_before_marker_receipt ) ) );
				}
				if ( $method === 'POST' ) {
					++$pre_dispatch_recovery_posts;
					return oras_qbo_reclass_complete_journal_entry_response( $call, 'JE-pre-dispatch-recovered' );
				}
				return array( 'QueryResponse' => array() );
			},
			$pre_dispatch_recovery_calls
		);
		oras_qbo_reclass_assert_true( 'pre-dispatch reservation recovers after controls are restored', is_array( $pre_dispatch_recovery_result ) );
		oras_qbo_reclass_assert_same( 'pre-dispatch reservation permits exactly one later POST', $pre_dispatch_recovery_posts, 1 );
		$guard_before_marker_order = wc_get_order( (int) $guard_before_marker_order->get_id() );
		oras_qbo_reclass_assert_same( 'pre-dispatch reservation does not become unknown', (string) $guard_before_marker_order->get_meta( '_oras_qbo_write_state', true ), '' );

		$after_marker_order = oras_qbo_reclass_create_order( $product_id, 66.80, 66.80, '2026-03-21' );
		$after_marker_receipt = oras_qbo_reclass_sales_receipt(
			'after-marker-' . (string) $after_marker_order->get_id(),
			66.80,
			'2026-03-21',
			'Order ' . $after_marker_order->get_order_number()
		);
		$after_marker_toggled = false;
		$after_marker_spy = static function ( $saved_order ) use ( $after_marker_order, &$after_marker_toggled ): void {
			if ( $after_marker_toggled || ! $saved_order instanceof \WC_Order || (int) $saved_order->get_id() !== (int) $after_marker_order->get_id() ) {
				return;
			}
			$pending = json_decode( (string) $saved_order->get_meta( '_oras_qbo_pending_write', true ), true );
			if ( is_array( $pending ) && trim( (string) ( $pending['dispatch_started_at'] ?? '' ) ) !== '' ) {
				$after_marker_toggled = true;
				\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
			}
		};
		add_action( 'woocommerce_after_order_object_save', $after_marker_spy, 10, 1 );
		$after_marker_calls = array();
		try {
			$after_marker_result = oras_qbo_reclass_sync_with_responder(
				(int) $after_marker_order->get_id(),
				static function ( string $method, string $query ) use ( $after_marker_receipt ): array {
					if ( strpos( $query, 'FROM SalesReceipt' ) !== false ) {
						return array( 'QueryResponse' => array( 'SalesReceipt' => array( $after_marker_receipt ) ) );
					}
					return $method === 'POST'
					? array( 'JournalEntry' => array( 'Id' => 'JE-after-marker' ) )
					: array( 'QueryResponse' => array() );
				},
				$after_marker_calls
			);
		} finally {
			remove_action( 'woocommerce_after_order_object_save', $after_marker_spy, 10 );
		}
		oras_qbo_reclass_assert_true( 'settings toggle occurs after marker persistence', $after_marker_toggled );
		oras_qbo_reclass_assert_same( 'nothing aborts between marker and HTTP', count( array_filter( $after_marker_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 1 );
		oras_qbo_reclass_assert_error_code( 'post-marker settings change retains unknown completion protection', $after_marker_result, 'oras_qbo_write_outcome_unknown' );
		$after_marker_order = wc_get_order( (int) $after_marker_order->get_id() );
		$after_marker_pending = json_decode( (string) $after_marker_order->get_meta( '_oras_qbo_pending_write', true ), true );
		oras_qbo_reclass_assert_true( 'post-marker settings change retains pending intent', is_array( $after_marker_pending ) );
		oras_qbo_reclass_assert_true( 'post-marker settings change retains dispatch marker', trim( (string) ( $after_marker_pending['dispatch_started_at'] ?? '' ) ) !== '' );
		oras_qbo_reclass_assert_same( 'post-marker settings change does not store an unconfirmed JE ID', (string) $after_marker_order->get_meta( '_oras_qbo_je_id', true ), '' );

		// Request IDs are exactly 50 ASCII letters/digits/hyphens and are the
		// deterministic ID already proven above to survive OAuth and explicit retry.
		oras_qbo_reclass_assert_same( 'Intuit requestid length contract', strlen( $single_request_id ), 50 );
		oras_qbo_reclass_assert_true( 'Intuit requestid character contract', preg_match( '/^[A-Za-z0-9-]{50}$/', $single_request_id ) === 1 );

		// 38) Reversal lock contention retains the reversal operation through the
		// same three bounded retries and records explicit exhaustion.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$reversal_lock_order = oras_qbo_reclass_create_order( $product_id, 66.90, 66.90, '2026-03-21' );
		$reversal_lock_name = 'oras_tickets:' . substr( md5( 'qbo-sync-order:' . (string) $reversal_lock_order->get_id() ), 0, 40 );
		$reversal_lock_timeout = static function ( string $query ) use ( $reversal_lock_name ): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false && strpos( $query, $reversal_lock_name ) !== false ) {
				return 'SELECT 0';
			}
			return $query;
		};
		add_filter( 'query', $reversal_lock_timeout, 10, 1 );
		try {
			$reversal_lock_orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();
			for ( $reversal_lock_attempt = 0; $reversal_lock_attempt <= 3; $reversal_lock_attempt++ ) {
				$reversal_lock_orchestrator->reverse_order_async( (int) $reversal_lock_order->get_id(), $reversal_lock_attempt );
			}
		} finally {
			remove_filter( 'query', $reversal_lock_timeout, 10 );
		}
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			$reversal_expected_delays = array(
				1 => 60,
				2 => 120,
				3 => 240,
			);
			for ( $reversal_scheduled_attempt = 1; $reversal_scheduled_attempt <= 3; $reversal_scheduled_attempt++ ) {
				oras_qbo_reclass_assert_true(
					'reversal lock retry attempt ' . (string) $reversal_scheduled_attempt . ' scheduled',
					(bool) as_has_scheduled_action(
						\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK,
						array( (int) $reversal_lock_order->get_id(), $reversal_scheduled_attempt ),
						'oras-tickets'
					)
				);
				$reversal_scheduled_timestamp = as_next_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK,
					array( (int) $reversal_lock_order->get_id(), $reversal_scheduled_attempt ),
					'oras-tickets'
				);
				oras_qbo_reclass_assert_true(
					'reversal lock retry attempt ' . (string) $reversal_scheduled_attempt . ' uses bounded delay',
					is_int( $reversal_scheduled_timestamp )
					&& abs( $reversal_scheduled_timestamp - time() - $reversal_expected_delays[ $reversal_scheduled_attempt ] ) <= 15
				);
				as_unschedule_all_actions(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK,
					array( (int) $reversal_lock_order->get_id(), $reversal_scheduled_attempt ),
					'oras-tickets'
				);
			}
			oras_qbo_reclass_assert_true(
				'reversal contention never schedules primary sync',
				! as_has_scheduled_action( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK, null, 'oras-tickets' )
				|| empty(
					array_filter(
						as_get_scheduled_actions(
							array(
								'hook'     => \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
								'group'    => 'oras-tickets',
								'status'   => \ActionScheduler_Store::STATUS_PENDING,
								'per_page' => 100,
							)
						),
						static function ( $action ) use ( $reversal_lock_order ): bool {
							return method_exists( $action, 'get_args' )
							&& (int) ( $action->get_args()[0] ?? 0 ) === (int) $reversal_lock_order->get_id();
						}
					)
				)
			);
		}
		$reversal_lock_order = wc_get_order( (int) $reversal_lock_order->get_id() );
		oras_qbo_reclass_assert_same( 'reversal lock retry count is preserved', (string) $reversal_lock_order->get_meta( '_oras_qbo_reversal_lock_retry_count', true ), '3' );
		oras_qbo_reclass_assert_same( 'reversal lock exhaustion status', (string) $reversal_lock_order->get_meta( '_oras_qbo_sync_status', true ), 'reversal_failed' );
		oras_qbo_reclass_assert_same( 'reversal lock exhaustion audit', (string) $reversal_lock_order->get_meta( '_oras_qbo_last_audit_event', true ), 'reversal_lock_retry_exhausted' );

		$disabled_reversal_lock_order = oras_qbo_reclass_create_order( $product_id, 67.00, 67.00, '2026-03-21' );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
		( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->reverse_order_async( (int) $disabled_reversal_lock_order->get_id(), 0 );
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			oras_qbo_reclass_assert_true(
				'disabled sync schedules no reversal lock retry',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_REVERSAL_HOOK,
					array( (int) $disabled_reversal_lock_order->get_id(), 1 ),
					'oras-tickets'
				)
			);
		}

		$failed_count_order = oras_qbo_reclass_create_order( $product_id, 67.10, 67.10, '2026-03-21' );
		$failed_count_order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
		$failed_count_order->save();
		$failed_queued_count = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->retry_failed_orders( 1 );
		oras_qbo_reclass_assert_same( 'disabled retry queue reports zero accepted work', $failed_queued_count, 0 );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
			$failed_schedule_count_order = oras_qbo_reclass_create_order( $product_id, 67.15, 67.15, '2026-03-21' );
			$failed_schedule_count_order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
			$failed_schedule_count_order->save();
			as_unschedule_all_actions(
				\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
				array( (int) $failed_schedule_count_order->get_id(), 0 ),
				'oras-tickets'
			);
			$only_failed_schedule_count_order = static function ( array $query_args ) use ( $failed_schedule_count_order ): array {
				$query_args['post__in'] = array( (int) $failed_schedule_count_order->get_id() );
				return $query_args;
			};
			$reject_async_schedule = static function ( $pre, string $hook, array $args ) use ( $failed_schedule_count_order ) {
				if (
				$hook === \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK
				&& (int) ( $args[0] ?? 0 ) === (int) $failed_schedule_count_order->get_id()
				) {
					return 0;
				}
				return $pre;
			};
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $only_failed_schedule_count_order, 10, 1 );
			add_filter( 'pre_as_enqueue_async_action', $reject_async_schedule, 1, 6 );
			try {
				$failed_schedule_count = ( new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator() )->retry_failed_orders( 1 );
			} finally {
				remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $only_failed_schedule_count_order, 10 );
				remove_filter( 'pre_as_enqueue_async_action', $reject_async_schedule, 1 );
			}
			oras_qbo_reclass_assert_same( 'failed scheduler does not increase retry queue count', $failed_schedule_count, 0 );
		}

		// 39) Generic legacy possible-write evidence blocks automatic and manual
		// mutation while a provable pre-dispatch failure remains recoverable.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$legacy_order = oras_qbo_reclass_create_order( $product_id, 67.20, 67.20, '2026-03-21' );
		$legacy_order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
		$legacy_order->update_meta_data( '_oras_qbo_sync_error_code', 'http_request_failed' );
		$legacy_order->update_meta_data( '_oras_qbo_sync_error', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received.' );
		$legacy_order->update_meta_data( '_oras_qbo_doc_number', 'ORAS-RC-' . (string) $legacy_order->get_id() );
		$legacy_order->update_meta_data( '_oras_qbo_reclass_source_txn_key', 'salesreceipt:legacy-' . (string) $legacy_order->get_id() );
		$legacy_order->update_meta_data( '_oras_qbo_reclass_source_txn_id', 'legacy-' . (string) $legacy_order->get_id() );
		$legacy_order->update_meta_data( '_oras_qbo_retry_count', '2' );
		$legacy_order->add_meta_data(
			'_oras_qbo_audit_entry',
			wp_json_encode(
				array(
					'event' => 'sync_failure',
					'error' => 'Operation timed out after 30001 milliseconds.',
				)
			)
		);
		$legacy_order->save();
		$legacy_meta_before = oras_qbo_reclass_order_meta_snapshot( $legacy_order );
		$legacy_orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();
		$legacy_approval = $legacy_orchestrator->approve_order_sync( (int) $legacy_order->get_id(), false );
		oras_qbo_reclass_assert_error_code( 'legacy possible-write approval', $legacy_approval, 'oras_qbo_legacy_possible_write' );
		$legacy_order = wc_get_order( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_same( 'legacy possible-write approval preserves evidence', oras_qbo_reclass_order_meta_snapshot( $legacy_order ), $legacy_meta_before );

		// Lookup-only inventory remains reachable with synchronization disabled and
		// performs no lock, metadata, audit, scheduling, or POST side effects.
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'enabled' => false ) );
		$legacy_read_lock_queries = 0;
		$legacy_read_lock_spy = static function ( string $query ) use ( &$legacy_read_lock_queries ): string {
			if ( strpos( $query, 'GET_LOCK' ) !== false || strpos( $query, 'RELEASE_LOCK' ) !== false ) {
				++$legacy_read_lock_queries;
			}
			return $query;
		};
		$legacy_read_calls = array();
		add_filter( 'query', $legacy_read_lock_spy, 1, 1 );
		try {
			$legacy_read_result = oras_qbo_reclass_assert_lookup_read_only(
				'disabled legacy lookup',
				$legacy_order,
				static function () use ( $legacy_order, &$legacy_read_calls ) {
					return oras_qbo_reclass_reconcile_with_responder(
						(int) $legacy_order->get_id(),
						false,
						static function ( string $method, string $query ) use ( $legacy_order ): array {
							if ( strpos( $query, 'FROM JournalEntry' ) !== false ) {
								return array(
									'QueryResponse' => array(
										'JournalEntry' => array(
											array(
												'Id' => 'JE-legacy-read-only',
												'DocNumber' => 'ORAS-RC-' . (string) $legacy_order->get_id(),
											),
										),
									),
								);
							}
							return array( 'QueryResponse' => array() );
						},
						$legacy_read_calls
					);
				}
			);
		} finally {
			remove_filter( 'query', $legacy_read_lock_spy, 1 );
		}
		oras_qbo_reclass_assert_same( 'disabled legacy lookup reports remote finding', (string) ( $legacy_read_result['status'] ?? '' ), 'legacy_remote_found_read_only' );
		oras_qbo_reclass_assert_same( 'disabled legacy lookup does not claim fingerprint verification', (bool) ( $legacy_read_result['fingerprint_verified'] ?? true ), false );
		oras_qbo_reclass_assert_same( 'disabled legacy lookup uses no order or source lock', $legacy_read_lock_queries, 0 );
		oras_qbo_reclass_assert_same( 'disabled legacy lookup makes exactly one GET', count( $legacy_read_calls ), 1 );
		oras_qbo_reclass_assert_same( 'disabled legacy lookup makes no POST', count( array_filter( $legacy_read_calls, static fn ( array $call ): bool => $call['method'] === 'POST' ) ), 0 );
		$legacy_order = wc_get_order( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_same( 'disabled legacy lookup writes no metadata or audit', oras_qbo_reclass_order_meta_snapshot( $legacy_order ), $legacy_meta_before );

		$lookup_auth_cases = array(
			'expired token' => array(
				'access_token'     => 'expired-lookup-token',
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			'missing token' => array(
				'access_token'     => '',
				'token_expires_at' => '',
			),
		);
		foreach ( $lookup_auth_cases as $lookup_auth_label => $lookup_auth_settings ) {
			\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
				array_merge(
					array(
						'enabled'    => false,
						'last_error' => 'preserve lookup-only error state',
					),
					$lookup_auth_settings
				)
			);
			$lookup_auth_calls = array();
			$lookup_auth_result = oras_qbo_reclass_assert_lookup_read_only(
				'lookup-only ' . $lookup_auth_label,
				$legacy_order,
				static function () use ( $legacy_order, &$lookup_auth_calls ) {
					return oras_qbo_reclass_reconcile_with_responder(
						(int) $legacy_order->get_id(),
						false,
						static function (): \WP_Error {
							return new \WP_Error( 'unexpected_http', 'Lookup-only authentication failure must stop before HTTP.' );
						},
						$lookup_auth_calls
					);
				}
			);
			oras_qbo_reclass_assert_error_code( 'lookup-only ' . $lookup_auth_label, $lookup_auth_result, 'oras_qbo_lookup_auth_required' );
			oras_qbo_reclass_assert_same( 'lookup-only ' . $lookup_auth_label . ' makes no HTTP request', count( $lookup_auth_calls ), 0 );
		}

		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
			array(
				'enabled'          => false,
				'access_token'     => 'lookup-401-token',
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'last_error'       => 'preserve lookup-only 401 state',
			)
		);
		$lookup_401_calls = array();
		$lookup_401_result = oras_qbo_reclass_assert_lookup_read_only(
			'lookup-only 401',
			$legacy_order,
			static function () use ( $legacy_order, &$lookup_401_calls ) {
				return oras_qbo_reclass_reconcile_with_responder(
					(int) $legacy_order->get_id(),
					false,
					static function ( string $method, string $query, array $call ): array {
						if ( strpos( (string) $call['url'], 'oauth.platform.intuit.com' ) !== false ) {
							return array(
								'access_token'  => 'must-not-persist-lookup-refresh',
								'refresh_token' => 'must-not-persist-lookup-refresh',
								'expires_in'    => 3600,
								'x_refresh_token_expires_in' => 86400,
							);
						}
						return array(
							'__oras_http_status' => 401,
							'__oras_http_body'   => array( 'Fault' => array( 'Error' => array() ) ),
						);
					},
					$lookup_401_calls
				);
			}
		);
		oras_qbo_reclass_assert_error_code( 'lookup-only 401 result', $lookup_401_result, 'oras_qbo_lookup_auth_required' );
		oras_qbo_reclass_assert_same( 'lookup-only 401 performs one inventory GET', count( $lookup_401_calls ), 1 );
		oras_qbo_reclass_assert_same(
			'lookup-only 401 performs no token exchange',
			count( array_filter( $lookup_401_calls, static fn ( array $call ): bool => strpos( (string) $call['url'], 'oauth.platform.intuit.com' ) !== false ) ),
			0
		);

		$legacy_disabled_retry_calls = array();
		$legacy_disabled_retry = oras_qbo_reclass_reconcile_with_responder(
			(int) $legacy_order->get_id(),
			true,
			static function (): WP_Error {
				return new WP_Error( 'unexpected_http', 'Disabled write retry must stop before QuickBooks.' );
			},
			$legacy_disabled_retry_calls
		);
		oras_qbo_reclass_assert_error_code( 'disabled legacy write retry', $legacy_disabled_retry, 'oras_qbo_disabled' );
		oras_qbo_reclass_assert_same( 'disabled legacy write retry makes no HTTP request', count( $legacy_disabled_retry_calls ), 0 );
		\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_reclass_settings() );
		$legacy_reset = $legacy_orchestrator->reset_order_sync_state( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_error_code( 'legacy possible-write reset', $legacy_reset, 'oras_qbo_legacy_possible_write' );
		$legacy_resync = $legacy_orchestrator->resync_order( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_error_code( 'legacy possible-write resync', $legacy_resync, 'oras_qbo_legacy_possible_write' );
		$legacy_sync_calls = array();
		$legacy_sync = oras_qbo_reclass_sync_with_responder(
			(int) $legacy_order->get_id(),
			static function (): WP_Error {
				return new WP_Error( 'unexpected_http', 'Legacy possible-write order must not contact QuickBooks.' );
			},
			$legacy_sync_calls
		);
		oras_qbo_reclass_assert_error_code( 'legacy possible-write direct sync', $legacy_sync, 'oras_qbo_legacy_possible_write' );
		oras_qbo_reclass_assert_same( 'legacy possible-write direct sync makes no HTTP request', count( $legacy_sync_calls ), 0 );
		$legacy_order = wc_get_order( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_same( 'legacy possible-write evidence is preserved', oras_qbo_reclass_order_meta_snapshot( $legacy_order ), $legacy_meta_before );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK, array( (int) $legacy_order->get_id(), 0 ), 'oras-tickets' );
		}
		$only_legacy_failed_order = static function ( array $query_args ) use ( $legacy_order ): array {
			$query_args['include'] = array( (int) $legacy_order->get_id() );
			return $query_args;
		};
		add_filter( 'woocommerce_order_query_args', $only_legacy_failed_order, 10, 1 );
		try {
			$legacy_retry_count = $legacy_orchestrator->retry_failed_orders( 1 );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $only_legacy_failed_order, 10 );
		}
		oras_qbo_reclass_assert_true( 'legacy possible-write automatic retry returns a valid queued count', $legacy_retry_count >= 0 );
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			oras_qbo_reclass_assert_true(
				'legacy possible-write automatic retry has no scheduled action',
				! as_has_scheduled_action(
					\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
					array( (int) $legacy_order->get_id(), 0 ),
					'oras-tickets'
				)
			);
		}

		$legacy_order->update_meta_data( '_oras_qbo_sync_status', 'waiting_for_source_txn' );
		$legacy_order->save();
		$legacy_wait_meta_before = oras_qbo_reclass_order_meta_snapshot( $legacy_order );
		$legacy_wait_http = 0;
		$legacy_wait_spy = static function () use ( &$legacy_wait_http ): WP_Error {
			++$legacy_wait_http;
			return new WP_Error( 'unexpected_http', 'Legacy waiting queue must not contact QuickBooks.' );
		};
		add_filter( 'pre_http_request', $legacy_wait_spy, 1, 3 );
		try {
			$legacy_wait_count = $legacy_orchestrator->process_waiting_orders( 1 );
		} finally {
			remove_filter( 'pre_http_request', $legacy_wait_spy, 1 );
		}
		oras_qbo_reclass_assert_same( 'legacy possible-write waiting queue reports no processing', $legacy_wait_count, 0 );
		oras_qbo_reclass_assert_same( 'legacy possible-write waiting queue makes no HTTP request', $legacy_wait_http, 0 );
		$legacy_order = wc_get_order( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_same( 'legacy waiting evidence is preserved', oras_qbo_reclass_order_meta_snapshot( $legacy_order ), $legacy_wait_meta_before );

		$legacy_admin_meta_before = oras_qbo_reclass_order_meta_snapshot( $legacy_order );
		$legacy_admin_calls = array();
		oras_qbo_reclass_entrypoint_with_responder(
			static function () use ( $legacy_order ): void {
				oras_qbo_reclass_invoke_admin_handler(
					'handle_resync_order',
					'oras_tickets_qbo_resync_order',
					array( 'order_id' => (string) $legacy_order->get_id() )
				);
			},
			static function (): WP_Error {
				return new WP_Error( 'unexpected_http', 'Legacy admin resync must not contact QuickBooks.' );
			},
			$legacy_admin_calls
		);
		oras_qbo_reclass_assert_same( 'legacy admin resync makes no HTTP request', count( $legacy_admin_calls ), 0 );
		$legacy_order = wc_get_order( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_same( 'legacy admin resync preserves evidence', oras_qbo_reclass_order_meta_snapshot( $legacy_order ), $legacy_admin_meta_before );

		$legacy_cli_meta_before = oras_qbo_reclass_order_meta_snapshot( $legacy_order );
		$legacy_cli_calls = array();
		\ORAS\Tickets\Integrations\QuickBooks\Cli_Command::register(
			new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(),
			new \ORAS\Tickets\Integrations\QuickBooks\Api_Client()
		);
		$legacy_cli_result = oras_qbo_reclass_entrypoint_with_responder(
			static function () use ( $legacy_order ) {
				return \WP_CLI::runcommand(
					'oras-tickets qbo resync-order ' . (string) $legacy_order->get_id(),
					array(
						'return'     => 'all',
						'exit_error' => false,
						'launch'     => false,
					)
				);
			},
			static function (): WP_Error {
				return new WP_Error( 'unexpected_http', 'Legacy CLI resync must not contact QuickBooks.' );
			},
			$legacy_cli_calls
		);
		oras_qbo_reclass_assert_same( 'legacy CLI resync exits with failure', (int) ( $legacy_cli_result->return_code ?? 0 ), 1 );
		oras_qbo_reclass_assert_true(
			'legacy CLI resync explains possible prior write',
			strpos( (string) ( $legacy_cli_result->stderr ?? '' ), 'may have reached QuickBooks' ) !== false
		);
		oras_qbo_reclass_assert_same( 'legacy CLI resync makes no HTTP request', count( $legacy_cli_calls ), 0 );
		$legacy_order = wc_get_order( (int) $legacy_order->get_id() );
		oras_qbo_reclass_assert_same( 'legacy CLI resync preserves evidence', oras_qbo_reclass_order_meta_snapshot( $legacy_order ), $legacy_cli_meta_before );

		$legacy_incomplete_order = oras_qbo_reclass_create_order( $product_id, 67.30, 67.30, '2026-03-21' );
		$legacy_incomplete_order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
		$legacy_incomplete_order->update_meta_data( '_oras_qbo_pending_write', '{"doc_number":"incomplete"}' );
		$legacy_incomplete_order->update_meta_data( '_oras_qbo_write_state', 'pending' );
		$legacy_incomplete_order->save();
		$legacy_incomplete_reset = $legacy_orchestrator->reset_order_sync_state( (int) $legacy_incomplete_order->get_id() );
		oras_qbo_reclass_assert_error_code( 'incomplete legacy pending intent reset', $legacy_incomplete_reset, 'oras_qbo_legacy_possible_write' );

		$legacy_audit_order = oras_qbo_reclass_create_order( $product_id, 67.40, 67.40, '2026-03-21' );
		$legacy_audit_order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
		$legacy_audit_order->update_meta_data( '_oras_qbo_doc_number', 'ORAS-RC-' . (string) $legacy_audit_order->get_id() );
		$legacy_audit_order->add_meta_data( '_oras_qbo_audit_entry', wp_json_encode( array( 'event' => 'journal_entry_write_dispatched' ) ) );
		$legacy_audit_order->save();
		$legacy_audit_reset = $legacy_orchestrator->reset_order_sync_state( (int) $legacy_audit_order->get_id() );
		oras_qbo_reclass_assert_error_code( 'legacy audit dispatch evidence reset', $legacy_audit_reset, 'oras_qbo_legacy_possible_write' );

		$legacy_source_not_found_order = oras_qbo_reclass_create_order( $product_id, 67.45, 67.45, '2026-03-21' );
		$legacy_source_not_found_order->update_meta_data( '_oras_qbo_sync_status', 'waiting_for_source_txn' );
		$legacy_source_not_found_order->update_meta_data( '_oras_qbo_sync_error_code', 'oras_qbo_reclass_source_not_found' );
		$legacy_source_not_found_order->update_meta_data( '_oras_qbo_sync_error', 'No matching Stripe-posted QuickBooks transaction found yet for reclass split.' );
		$legacy_source_not_found_order->update_meta_data( '_oras_qbo_wait_attempts', '2' );
		$legacy_source_not_found_order->save();
		$legacy_source_not_found_reset = $legacy_orchestrator->reset_order_sync_state( (int) $legacy_source_not_found_order->get_id() );
		oras_qbo_reclass_assert_error_code( 'source-not-found with possible-write evidence remains protected', $legacy_source_not_found_reset, 'oras_qbo_legacy_possible_write' );

		$legacy_predispatch_order = oras_qbo_reclass_create_order( $product_id, 67.50, 67.50, '2026-03-21' );
		$legacy_predispatch_order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
		$legacy_predispatch_order->update_meta_data( '_oras_qbo_sync_error_code', 'oras_qbo_strict_mapping_failed' );
		$legacy_predispatch_order->update_meta_data( '_oras_qbo_sync_error', 'Strict mapping failed before any QuickBooks write preparation.' );
		$legacy_predispatch_order->save();
		$legacy_predispatch_reset = $legacy_orchestrator->reset_order_sync_state( (int) $legacy_predispatch_order->get_id() );
		oras_qbo_reclass_assert_same( 'provably pre-dispatch legacy failure remains resettable', $legacy_predispatch_reset, true );

		echo "QBO reclass safety tests passed.\n";
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
