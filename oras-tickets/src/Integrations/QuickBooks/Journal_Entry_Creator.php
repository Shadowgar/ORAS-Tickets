<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if (! defined('ABSPATH')) {
    exit;
}

final class Journal_Entry_Creator
{

	private const SOURCE_DATE_WINDOW_DAYS = 7;
	private const SOURCE_QUERY_PAGE_SIZE = 100;
	private const SOURCE_QUERY_MAX_ROWS = 1000;

	/**
	 * QuickBooks response-only fields ignored by fingerprint comparison.
	 * These values are server-generated identifiers, concurrency metadata,
	 * timestamps, sparse-response markers, or server-computed totals.
	 */
	private const FINGERPRINT_ROOT_READ_ONLY_FIELDS = array(
		'Id',
		'SyncToken',
		'MetaData',
		'domain',
		'sparse',
		'TotalAmt',
		'HomeTotalAmt',
	);

	/**
	 * QuickBooks assigns these fields to returned JournalEntry lines. They
	 * are never part of a create request and do not affect accounting intent.
	 */
	private const FINGERPRINT_LINE_READ_ONLY_FIELDS = array(
		'Id',
		'LineNum',
	);

	private const FINGERPRINT_DECIMAL_FIELDS = array(
		'Amount',
		'ExchangeRate',
		'TaxAmount',
		'TotalTax',
		'TaxPercent',
		'NetAmountTaxable',
		'TaxInclusiveAmt',
	);

    private Api_Client $api_client;
    private QuickBooks_Logger $logger;

    public function __construct(?Api_Client $api_client = null, ?QuickBooks_Logger $logger = null)
    {
        $this->logger     = $logger ?: new QuickBooks_Logger();
        $this->api_client = $api_client ?: new Api_Client(null, $this->logger);
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
	public function find_existing_journal_entry(
		string $doc_number,
		bool $require_sync_enabled = true,
		string $request_policy = Api_Client::REQUEST_POLICY_DEFAULT
	) {
		return $this->api_client->find_journal_entry_by_doc_number(
			$doc_number,
			$require_sync_enabled,
			$request_policy
		);
    }

    /**
     * @param \WC_Order $order
     * @param array<string,mixed> $split
     * @param array<string,mixed> $qbo_settings
	 * @return \WP_Error
     */
    public function create_for_order($order, array $split, array $qbo_settings)
    {
		return new \WP_Error(
			'oras_qbo_orchestrator_required',
			'JournalEntry writes must run through the QuickBooks sync orchestrator.'
		);
	}

	/**
	 * Write an already-prepared payload without rebuilding or re-running source lookup.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $prepared
	 * @return \WP_Error
	 */
	public function create_prepared_for_order(
		$order,
		array $prepared,
		bool $is_reversal = false,
		?callable $before_dispatch = null
	) {

		unset( $order, $prepared, $is_reversal, $before_dispatch );
		return new \WP_Error(
			'oras_qbo_orchestrator_required',
			'JournalEntry writes must run through the QuickBooks sync orchestrator.'
        );
    }

    /**
     * Create a reversing JournalEntry using the prior split snapshot.
     *
     * @param \WC_Order $order
     * @param array<string,mixed> $split_snapshot
     * @param array<string,mixed> $qbo_settings
	 * @return \WP_Error
     */
    public function create_reversal_for_order($order, array $split_snapshot, array $qbo_settings, string $original_je_id = '')
    {
		return new \WP_Error(
			'oras_qbo_orchestrator_required',
			'JournalEntry writes must run through the QuickBooks sync orchestrator.'
        );
    }

    /**
     * Build payload and run preflight checks without writing to QuickBooks.
     *
     * @param \WC_Order $order
     * @param array<string,mixed> $split
     * @param array<string,mixed> $qbo_settings
     * @return array<string,mixed>|\WP_Error
     */
	public function build_payload_for_order(
		$order,
		array $split,
		array $qbo_settings,
		bool $is_reversal = false,
		string $original_je_id = '',
		bool $allow_remote_lookup = true
	) {
        $posting_mode = sanitize_key((string) ($qbo_settings['posting_mode'] ?? 'clearing'));
        if (! in_array($posting_mode, array('clearing', 'reclass'), true)) {
            $posting_mode = 'clearing';
        }

        $source_match = null;
        $customer_entity = null;

        $counterparty_account_id = '';
        if ($posting_mode === 'reclass') {
            $counterparty_account_id = trim((string) ($qbo_settings['reclass_source_account_id'] ?? ''));
            if ($counterparty_account_id === '') {
                return new \WP_Error('oras_qbo_missing_reclass_source_account', 'QuickBooks reclass source account ID is not configured.');
            }
        } else {
            $counterparty_account_id = trim((string) ($qbo_settings['clearing_account_id'] ?? ''));
            if ($counterparty_account_id === '') {
                return new \WP_Error('oras_qbo_missing_clearing_account', 'QuickBooks clearing account ID is not configured.');
            }
        }

        $lines = isset($split['lines']) && is_array($split['lines']) ? $split['lines'] : array();
        if (empty($lines)) {
            return new \WP_Error('oras_qbo_empty_split_lines', 'No split lines were provided for JournalEntry creation.');
        }

        $split_total = round((float) ($split['split_total'] ?? 0.0), 2);
        if (abs($split_total) < 0.0001) {
            return new \WP_Error('oras_qbo_zero_split_total', 'Split total is zero. JournalEntry was not created.');
        }

		if ( $posting_mode === 'reclass' && ! $is_reversal && $allow_remote_lookup ) {
			$source_match = $this->find_reclass_source_transaction( $order );
            if (is_wp_error($source_match)) {
                return $source_match;
            }

            if (is_array($source_match)) {
                $customer_ref_id = trim((string) ($source_match['customer_ref_id'] ?? ''));
                if ($customer_ref_id !== '') {
                    $customer_entity = array(
                        'id'   => $customer_ref_id,
                        'name' => trim((string) ($source_match['customer_ref_name'] ?? '')),
                    );
                }
            }
        }

        $payload_lines = array();
        if (! $is_reversal) {
            $payload_lines[] = $this->build_line(
                $split_total,
                'Debit',
                $counterparty_account_id,
                $posting_mode === 'reclass'
                    ? 'ORAS Woo reclass debit for order #' . $order->get_order_number()
                    : 'ORAS Woo clearing debit for order #' . $order->get_order_number(),
                is_array($customer_entity) ? $customer_entity : null
            );

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $amount     = round((float) ($line['amount'] ?? 0.0), 2);
                $account_id = trim((string) ($line['account_id'] ?? ''));
                if (abs($amount) < 0.0001 || $account_id === '') {
                    continue;
                }

                $payload_lines[] = $this->build_line(
                    $amount,
                    'Credit',
                    $account_id,
                    (string) ($line['bucket_label'] ?? 'ORAS revenue split'),
                    is_array($customer_entity) ? $customer_entity : null
                );
            }
        } else {
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $amount     = round((float) ($line['amount'] ?? 0.0), 2);
                $account_id = trim((string) ($line['account_id'] ?? ''));
                if (abs($amount) < 0.0001 || $account_id === '') {
                    continue;
                }

                $payload_lines[] = $this->build_line(
                    $amount,
                    'Debit',
                    $account_id,
                    (string) ($line['bucket_label'] ?? 'ORAS reversal split line')
                );
            }

            $payload_lines[] = $this->build_line(
                $split_total,
                'Credit',
                $counterparty_account_id,
                $posting_mode === 'reclass'
                    ? 'ORAS reversal credit to reclass source for order #' . $order->get_order_number()
                    : 'ORAS reversal credit to clearing for order #' . $order->get_order_number()
            );
        }

        if (count($payload_lines) < 2) {
            return new \WP_Error('oras_qbo_invalid_je_lines', 'JournalEntry payload is invalid: expected debit and credit lines.');
        }

        $order_id   = (int) $order->get_id();
        $doc_number = $this->build_doc_number($order_id, $is_reversal, $posting_mode);
        $txn_date   = $order->get_date_paid() ? $order->get_date_paid()->date_i18n('Y-m-d') : gmdate('Y-m-d');

        $customer_note = $this->format_customer_note($order);

        $reversal_reference = $original_je_id;
        if ($reversal_reference === '') {
            $reversal_reference = 'unknown';
        }

        $private_note = ! $is_reversal
            ? sprintf(
                'ORAS Woo order #%1$s revenue split (%2$s, mode=%4$s). %3$s',
                $order->get_order_number(),
                (string) ($split['discount_mode'] ?? 'proportional'),
                $customer_note,
                $posting_mode
            )
            : sprintf(
                'ORAS Woo order #%1$s reversal for JE %2$s (mode=%4$s). %3$s',
                $order->get_order_number(),
                $reversal_reference,
                $customer_note,
                $posting_mode
            );

        $payload = array(
            'DocNumber'   => $doc_number,
            'TxnDate'     => $txn_date,
            'PrivateNote' => $private_note,
            'Line'        => $payload_lines,
        );
		$transaction_currency = strtoupper( trim( (string) $order->get_currency() ) );
		if ( $transaction_currency === '' ) {
			return new \WP_Error( 'oras_qbo_transaction_currency_unverified', 'WooCommerce order currency could not be verified.' );
		}
		$payload['CurrencyRef'] = array( 'value' => $transaction_currency );

		$home_currency = '';
		$multicurrency_enabled = false;
		$exchange_rate = '';
		if ( $allow_remote_lookup ) {
			$currency_context = $this->get_verified_company_currency_context();
			if ( is_wp_error( $currency_context ) ) {
				return $currency_context;
			}
			$home_currency = strtoupper( trim( (string) ( $currency_context['home_currency'] ?? '' ) ) );
			$multicurrency_enabled = isset( $currency_context['multicurrency_enabled'] )
				&& $currency_context['multicurrency_enabled'] === true;
			if ( $transaction_currency !== $home_currency && ! $multicurrency_enabled ) {
				$error = new \WP_Error(
					'oras_qbo_multicurrency_disabled',
					'QuickBooks multicurrency is disabled, so the foreign-currency JournalEntry was stopped before reservation.'
				);
				$error->add_data( array( 'retriable' => false ) );
				return $error;
			}

			if ( $transaction_currency !== $home_currency ) {
				$exchange_rate_value = is_array( $source_match )
					? ( $source_match['exchange_rate'] ?? '' )
					: '';
				if ( $is_reversal ) {
					$exchange_rate_value = $order->get_meta( '_oras_qbo_exchange_rate', true );
				}
				$exchange_rate = $this->normalize_decimal( $exchange_rate_value );
				if ( $exchange_rate === '' || $exchange_rate === '0' ) {
					$error = new \WP_Error(
						'oras_qbo_exchange_rate_unverified',
						'A verified ExchangeRate is required for this foreign-currency JournalEntry.'
					);
					$error->add_data( array( 'retriable' => false ) );
					return $error;
				}
				$payload['ExchangeRate'] = $exchange_rate;
			} else {
				$exchange_rate = '1';
			}

			if ( is_array( $source_match ) ) {
				$source_match['home_currency'] = $home_currency;
			}
		}

        $preflight = $this->validate_payload($payload, $split_total);
        if (is_wp_error($preflight)) {
            return $preflight;
        }

        return array(
			'payload'                 => $payload,
			'doc_number'              => $doc_number,
			'request_id'              => $this->get_request_id( $payload ),
			'txn_date'                => $txn_date,
			'split_total'             => $split_total,
			'source_match'            => is_array( $source_match ) ? $source_match : array(),
			'home_currency'           => $home_currency,
			'transaction_currency'    => $transaction_currency,
			'multicurrency_enabled'   => $multicurrency_enabled,
			'exchange_rate'           => $exchange_rate,
			'remote_source_validated' => $posting_mode !== 'reclass' || $is_reversal || $allow_remote_lookup,
			'accounting_fingerprint'  => $this->get_accounting_fingerprint( $payload, $home_currency ),
        );
	}

	/**
	 * Build the QuickBooks idempotency key from the exact serialized payload.
	 * The result is deterministic and remains within QuickBooks' 50-character
	 * requestid limit.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function get_request_id( array $payload ): string {
		$encoded_payload = wp_json_encode( $payload );
		$payload_hash    = hash( 'sha256', is_string( $encoded_payload ) ? $encoded_payload : '' );
		return 'oras-' . substr( $payload_hash, 0, 45 );
	}

	/**
	 * Canonicalize a persisted decimal for exact, non-rounding comparison.
	 *
	 * @param mixed $value
	 */
	public function normalize_decimal_for_comparison( $value ): string {
		return $this->normalize_decimal( $value );
	}

	/**
	 * Read fresh verified company currency context. Callers intentionally do
	 * not cache this value across orders or top-level operations.
	 *
	 * @return array{home_currency:string,multicurrency_enabled:bool}|\WP_Error
	 */
	public function get_verified_company_currency_context( bool $require_sync_enabled = true ) {
		$company_currency_context = $this->api_client->get_company_currency_context( $require_sync_enabled );
		if ( is_wp_error( $company_currency_context ) ) {
			return $company_currency_context;
		}

		if ( ! is_array( $company_currency_context ) ) {
			$error = new \WP_Error(
				'oras_qbo_company_currency_unverified',
				'QuickBooks did not return a verifiable company home currency.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		return $company_currency_context;
	}

	/**
	 * Build a stable fingerprint from the accounting fields that QuickBooks
	 * returns after creating a JournalEntry. QuickBooks-assigned IDs, names,
	 * and line ordering are intentionally excluded.
	 *
	 * @param array<string,mixed> $journal_entry
	 */
	public function get_accounting_fingerprint( array $journal_entry, string $home_currency = '' ): string {
		$home_currency = strtoupper( trim( $home_currency ) );
		$canonical_input = $journal_entry;
		$currency_ref = isset( $canonical_input['CurrencyRef'] ) && is_array( $canonical_input['CurrencyRef'] )
			? strtoupper( trim( (string) ( $canonical_input['CurrencyRef']['value'] ?? '' ) ) )
			: '';
		if ( $currency_ref === '' && $home_currency !== '' ) {
			$canonical_input['CurrencyRef'] = array( 'value' => $home_currency );
			$currency_ref = $home_currency;
		}
		if ( ! array_key_exists( 'Adjustment', $canonical_input ) ) {
			$canonical_input['Adjustment'] = false;
		}
		if (
			! array_key_exists( 'ExchangeRate', $canonical_input )
			&& $currency_ref !== ''
			&& $home_currency !== ''
			&& $currency_ref === $home_currency
		) {
			$canonical_input['ExchangeRate'] = '1';
		}

		$canonical = $this->canonicalize_fingerprint_value( $canonical_input );

		$encoded_canonical = wp_json_encode( $canonical );
		return hash( 'sha256', is_string( $encoded_canonical ) ? $encoded_canonical : '' );
	}

	/**
	 * Confirm that a JournalEntry returned by DocNumber is the exact
	 * accounting entry this order prepared, rather than an unrelated reuse of
	 * the same document number.
	 *
	 * @param array<string,mixed> $journal_entry
	 * @return true|\WP_Error
	 */
	public function validate_existing_journal_entry(
		array $journal_entry,
		string $expected_doc_number,
		string $expected_fingerprint,
		string $home_currency = ''
	) {
		$actual_doc_number = trim( (string) ( $journal_entry['DocNumber'] ?? '' ) );
		$actual_fingerprint = $this->get_accounting_fingerprint( $journal_entry, $home_currency );
		if (
			$expected_doc_number === ''
			|| $expected_fingerprint === ''
			|| $actual_doc_number !== $expected_doc_number
			|| ! hash_equals( $expected_fingerprint, $actual_fingerprint )
		) {
			$error = new \WP_Error(
				'oras_qbo_existing_je_mismatch',
				'QuickBooks returned a JournalEntry with the expected document number but different accounting content; no write or adoption occurred.'
			);
			$error->add_data(
				array(
					'retriable'           => false,
					'expected_doc_number' => $expected_doc_number,
					'actual_doc_number'   => $actual_doc_number,
				)
			);
			return $error;
		}

		return true;
	}

    /**
     * @param \WC_Order $order
     * @return array<string,mixed>|\WP_Error
     */
	private function find_reclass_source_transaction( $order ) {
		$total = round( abs( (float) $order->get_total() ), 2 );
        if ($total <= 0) {
			return new \WP_Error( 'oras_qbo_invalid_reclass_total', 'Cannot match reclass source SalesReceipt for a zero-value Woo order.' );
        }

        $paid_date   = $order->get_date_paid();
        $created_date = $order->get_date_created();
        $base_date   = $paid_date instanceof \WC_DateTime ? $paid_date : $created_date;
		$base_day    = $base_date instanceof \WC_DateTime ? $base_date->date_i18n( 'Y-m-d' ) : current_time( 'Y-m-d' );
		$base_ts     = strtotime( $base_day . ' 00:00:00 UTC' );
		if ( $base_ts === false ) {
			return new \WP_Error( 'oras_qbo_invalid_reclass_date', 'Cannot determine a valid Woo order date for source matching.' );
		}

		$from_date = gmdate( 'Y-m-d', $base_ts - ( self::SOURCE_DATE_WINDOW_DAYS * DAY_IN_SECONDS ) );
		$to_date   = gmdate( 'Y-m-d', $base_ts + ( self::SOURCE_DATE_WINDOW_DAYS * DAY_IN_SECONDS ) );
		$currency  = strtoupper( trim( (string) $order->get_currency() ) );
		$order_number = trim( (string) $order->get_order_number() );
		$stripe_identifiers = $this->get_order_stripe_identifiers( $order );

        $candidates = array();
		$claimed_candidates = array();
		$identity_conflicts = array();
		$unverified_currency_candidates = array();
		$seen_keys = array();
		$start_position = 1;
		$collected = 0;
		$hit_query_limit = false;

		while ( true ) {
			$query = sprintf(
				"SELECT * FROM SalesReceipt WHERE TxnDate >= '%s' AND TxnDate <= '%s' ORDER BY TxnDate DESC STARTPOSITION %d MAXRESULTS %d",
				$from_date,
				$to_date,
				$start_position,
				self::SOURCE_QUERY_PAGE_SIZE
			);
			$response = $this->api_client->run_sync_query( $query );
            if (is_wp_error($response)) {
                return $response;
            }

            $items = isset($response['QueryResponse']) && is_array($response['QueryResponse'])
                ? $response['QueryResponse']
                : array();

			$rows = isset( $items['SalesReceipt'] ) && is_array( $items['SalesReceipt'] )
				? $items['SalesReceipt']
				: array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$id = isset( $row['Id'] ) ? trim( (string) $row['Id'] ) : '';
				if ( $id === '' ) {
					continue;
				}

				$identity = $this->get_source_match_evidence( $row, $order_number, $stripe_identifiers );
				$match_evidence = isset( $identity['evidence'] ) && is_array( $identity['evidence'] )
					? $identity['evidence']
					: array();
				$conflicts = isset( $identity['conflicts'] ) && is_array( $identity['conflicts'] )
					? $identity['conflicts']
					: array();
				if ( ! empty( $conflicts ) ) {
					$identity_conflicts[ $id ] = $conflicts;
					continue;
				}
				if ( empty( $match_evidence ) ) {
					continue;
				}

				$row_txn_date = trim( (string) ( $row['TxnDate'] ?? '' ) );
				if (
					preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row_txn_date ) !== 1
					|| $row_txn_date < $from_date
					|| $row_txn_date > $to_date
				) {
					continue;
				}

				$row_total = round( (float) ( $row['TotalAmt'] ?? 0.0 ), 2 );
				if ( abs( $row_total - $total ) > 0.009 ) {
					continue;
				}

				$row_currency = '';
				if ( isset( $row['CurrencyRef'] ) && is_array( $row['CurrencyRef'] ) ) {
					$row_currency = strtoupper( trim( (string) ( $row['CurrencyRef']['value'] ?? '' ) ) );
				}
				$home_currency = '';
				if ( $currency !== '' && $row_currency === '' ) {
					$currency_context = $this->get_verified_company_currency_context();
					if ( is_wp_error( $currency_context ) ) {
						$unverified_currency_candidates[] = $id;
						continue;
					}
					$home_currency = strtoupper( trim( (string) ( $currency_context['home_currency'] ?? '' ) ) );
					if ( $home_currency === '' ) {
						$unverified_currency_candidates[] = $id;
						continue;
					}
					$row_currency = $home_currency;
				}
				if ( $currency !== '' && $row_currency !== '' && $currency !== $row_currency ) {
					continue;
				}

				$txn_key = 'salesreceipt:' . $id;
				if ( isset( $seen_keys[ $txn_key ] ) ) {
					continue;
				}
				$seen_keys[ $txn_key ] = true;

				$candidate = $this->build_source_candidate(
					$row,
					$id,
					$txn_key,
					$row_total,
					$match_evidence,
					$row_currency,
					$home_currency
				);
				if ( $this->is_reclass_source_key_claimed( $txn_key, (int) $order->get_id() ) ) {
					$claimed_candidates[] = $candidate;
					continue;
				}

				$candidates[] = $candidate;
			}

			$row_count = count( $rows );
			$collected += $row_count;
			if ( $row_count < self::SOURCE_QUERY_PAGE_SIZE ) {
				break;
			}

			if ( $collected >= self::SOURCE_QUERY_MAX_ROWS ) {
				$hit_query_limit = true;
				break;
			}

			$start_position += self::SOURCE_QUERY_PAGE_SIZE;
		}

		if ( ! empty( $identity_conflicts ) ) {
			$error = new \WP_Error(
				'oras_qbo_reclass_source_identity_conflict',
				'A QuickBooks SalesReceipt contains conflicting order or Stripe identity evidence.'
			);
			$error->add_data(
				array(
					'retriable' => false,
					'conflicts' => $identity_conflicts,
				)
			);
			return $error;
		}

		if ( ! empty( $unverified_currency_candidates ) ) {
			$error = new \WP_Error(
				'oras_qbo_reclass_source_currency_unverified',
				'A matching QuickBooks SalesReceipt omitted CurrencyRef and the company home currency could not be verified.'
			);
			$error->add_data(
				array(
					'retriable'     => false,
					'candidate_ids' => $unverified_currency_candidates,
				)
			);
			return $error;
		}

		if ( $hit_query_limit ) {
			$error = new \WP_Error(
				'oras_qbo_reclass_source_query_limit',
				'QuickBooks SalesReceipt source search reached its safety limit before uniqueness could be established.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		if ( ! empty( $claimed_candidates ) ) {
			$error = new \WP_Error(
				'oras_qbo_reclass_source_claimed',
				'The matching QuickBooks SalesReceipt is already claimed by another WooCommerce order.'
			);
			$error->add_data(
				array(
					'retriable'      => false,
					'candidate_keys' => wp_list_pluck( $claimed_candidates, 'key' ),
				)
			);
			return $error;
        }

        if (empty($candidates)) {
            $error = new \WP_Error('oras_qbo_reclass_source_not_found', 'No matching Stripe-posted QuickBooks transaction found yet for reclass split.');
            $error->add_data(array('retriable' => true));
            return $error;
        }

		if ( count( $candidates ) !== 1 ) {
			$error = new \WP_Error(
				'oras_qbo_reclass_source_ambiguous',
				'Multiple QuickBooks SalesReceipts match this WooCommerce order; no JournalEntry was created.'
			);
			$error->add_data(
				array(
					'retriable'      => false,
					'candidate_keys' => wp_list_pluck( $candidates, 'key' ),
				)
			);
			return $error;
		}

		return $candidates[0];
	}

	/**
	 * @param \WC_Order $order
	 * @return string[]
	 */
	private function get_order_stripe_identifiers( $order ): array {
		$identifiers = array();
		if ( method_exists( $order, 'get_transaction_id' ) ) {
			$identifiers[] = trim( (string) $order->get_transaction_id() );
		}

		$meta_keys = array(
			'_stripe_intent_id',
			'_stripe_charge_id',
			'_stripe_source_id',
			'_wc_stripe_intent_id',
			'_wc_stripe_charge_id',
		);
		foreach ( $meta_keys as $meta_key ) {
			$identifiers[] = trim( (string) $order->get_meta( $meta_key, true ) );
		}

		return array_values( array_unique( array_filter( $identifiers ) ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param string[] $stripe_identifiers
	 * @return array{evidence:string[],conflicts:string[]}
	 */
	private function get_source_match_evidence( array $row, string $order_number, array $stripe_identifiers ): array {
		$evidence = array();
		$conflicts = array();
		$doc_number = trim( (string) ( $row['DocNumber'] ?? '' ) );
		$searchable_text = implode( ' ', $this->get_source_searchable_text( $row ) );
		$expected_by_prefix = array();

		foreach ( $stripe_identifiers as $identifier ) {
			$pattern = '/(?<![a-z0-9_])' . preg_quote( $identifier, '/' ) . '(?![a-z0-9_])/i';
			if ( preg_match( $pattern, $searchable_text ) === 1 ) {
				$evidence[] = 'stripe_transaction_id';
			}

			if ( preg_match( '/^((?:pi|ch|py|src)_)[a-z0-9_-]+$/i', $identifier, $prefix_match ) === 1 ) {
				$prefix = strtolower( $prefix_match[1] );
				$expected_by_prefix[ $prefix ][] = strtolower( $identifier );
			}
		}

		$explicit_stripe_ids = array();
		preg_match_all( '/(?<![a-z0-9_])(?:pi|ch|py|src)_[a-z0-9_-]+(?![a-z0-9_-])/i', $searchable_text, $explicit_stripe_ids );
		$actual_by_prefix = array();
		foreach ( $explicit_stripe_ids[0] as $explicit_stripe_id ) {
			$normalized_identifier = strtolower( (string) $explicit_stripe_id );
			$separator_position = strpos( $normalized_identifier, '_' );
			if ( $separator_position === false ) {
				continue;
			}
			$prefix = substr( $normalized_identifier, 0, $separator_position + 1 );
			$actual_by_prefix[ $prefix ][] = $normalized_identifier;
		}

		foreach ( $expected_by_prefix as $prefix => $expected_identifiers ) {
			if ( empty( $actual_by_prefix[ $prefix ] ) ) {
				continue;
			}
			$expected_identifiers = array_values( array_unique( $expected_identifiers ) );
			$actual_identifiers = array_values( array_unique( $actual_by_prefix[ $prefix ] ) );
			if ( empty( array_intersect( $expected_identifiers, $actual_identifiers ) ) ) {
				$conflicts[] = 'stripe_' . rtrim( $prefix, '_' ) . '_mismatch';
				continue;
			}
			if ( ! empty( array_diff( $actual_identifiers, $expected_identifiers ) ) ) {
				$conflicts[] = 'stripe_' . rtrim( $prefix, '_' ) . '_multiple';
			}
		}

		if ( $order_number !== '' ) {
			if ( ltrim( $doc_number, '#' ) === $order_number ) {
				$evidence[] = 'order_number';
			} else {
				$pattern = '/(?:^|[^a-z0-9])order\s*#?\s*' . preg_quote( $order_number, '/' ) . '(?![a-z0-9_-])/i';
				if ( preg_match( $pattern, $searchable_text ) === 1 ) {
					$evidence[] = 'order_number';
				}
			}

			$custom_fields = isset( $row['CustomField'] ) && is_array( $row['CustomField'] ) ? $row['CustomField'] : array();
			foreach ( $custom_fields as $custom_field ) {
				if ( ! is_array( $custom_field ) ) {
					continue;
				}
				$field_name = strtolower( trim( (string) ( $custom_field['Name'] ?? '' ) ) );
				$field_value = trim( (string) ( $custom_field['StringValue'] ?? '' ) );
				if ( strpos( $field_name, 'order' ) !== false && ltrim( $field_value, '#' ) === $order_number ) {
					$evidence[] = 'order_number';
					break;
				}
			}

			$explicit_order_numbers = array();
			preg_match_all(
				'/(?:^|[^a-z0-9])order\s*#?\s*([0-9][a-z0-9_-]*)(?![a-z0-9_-])/i',
				$searchable_text,
				$explicit_order_numbers
			);
			foreach ( $explicit_order_numbers[1] as $explicit_order_number ) {
				if ( (string) $explicit_order_number !== $order_number ) {
					$conflicts[] = 'order_number_mismatch';
				}
			}

			$custom_fields = isset( $row['CustomField'] ) && is_array( $row['CustomField'] ) ? $row['CustomField'] : array();
			foreach ( $custom_fields as $custom_field ) {
				if ( ! is_array( $custom_field ) ) {
					continue;
				}
				$field_name = strtolower( trim( (string) ( $custom_field['Name'] ?? '' ) ) );
				$field_value = ltrim( trim( (string) ( $custom_field['StringValue'] ?? '' ) ), '#' );
				if ( strpos( $field_name, 'order' ) !== false && $field_value !== '' && $field_value !== $order_number ) {
					$conflicts[] = 'order_custom_field_mismatch';
				}
			}
		}

		// Different identifiers on an otherwise unrelated SalesReceipt are
		// not conflicts for this order. They become conflicts only when the
		// receipt also contains at least one exact identifier for this order.
		if ( empty( $evidence ) ) {
			$conflicts = array();
		}

		return array(
			'evidence'  => array_values( array_unique( $evidence ) ),
			'conflicts' => array_values( array_unique( $conflicts ) ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return string[]
	 */
	private function get_source_searchable_text( array $row ): array {
		$values = array(
			(string) ( $row['DocNumber'] ?? '' ),
			(string) ( $row['PrivateNote'] ?? '' ),
			(string) ( $row['PaymentRefNum'] ?? '' ),
		);

		if ( isset( $row['CustomerMemo'] ) ) {
			$values[] = is_array( $row['CustomerMemo'] )
				? (string) ( $row['CustomerMemo']['value'] ?? '' )
				: (string) $row['CustomerMemo'];
		}

		$lines = isset( $row['Line'] ) && is_array( $row['Line'] ) ? $row['Line'] : array();
		foreach ( $lines as $line ) {
			if ( is_array( $line ) ) {
				$values[] = (string) ( $line['Description'] ?? '' );
			}
		}

		$custom_fields = isset( $row['CustomField'] ) && is_array( $row['CustomField'] ) ? $row['CustomField'] : array();
		foreach ( $custom_fields as $custom_field ) {
			if ( is_array( $custom_field ) ) {
				$values[] = (string) ( $custom_field['StringValue'] ?? '' );
			}
		}

		return array_values( array_filter( array_map( 'trim', $values ) ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param string[] $match_evidence
	 * @return array<string,mixed>
	 */
	private function build_source_candidate(
		array $row,
		string $id,
		string $txn_key,
		float $row_total,
		array $match_evidence,
		string $transaction_currency,
		string $home_currency
	): array {
		$memo = isset( $row['PrivateNote'] ) ? (string) $row['PrivateNote'] : '';
		if ( $memo === '' && isset( $row['CustomerMemo'] ) ) {
			$memo = is_array( $row['CustomerMemo'] )
				? (string) ( $row['CustomerMemo']['value'] ?? '' )
				: (string) $row['CustomerMemo'];
		}

		$customer_ref = isset( $row['CustomerRef'] ) && is_array( $row['CustomerRef'] )
			? $row['CustomerRef']
			: array();

		return array(
			'entity'               => 'SalesReceipt',
			'id'                   => $id,
			'key'                  => $txn_key,
			'txn_date'             => isset( $row['TxnDate'] ) ? (string) $row['TxnDate'] : '',
			'total'                => $row_total,
			'doc_number'           => isset( $row['DocNumber'] ) ? (string) $row['DocNumber'] : '',
			'memo'                 => $memo,
			'customer_ref_id'      => isset( $customer_ref['value'] ) ? trim( (string) $customer_ref['value'] ) : '',
			'customer_ref_name'    => isset( $customer_ref['name'] ) ? trim( (string) $customer_ref['name'] ) : '',
			'match_evidence'       => $match_evidence,
			'transaction_currency' => $transaction_currency,
			'home_currency'        => $home_currency,
			'exchange_rate'        => $this->normalize_decimal( $row['ExchangeRate'] ?? '' ),
		);
	}

	private function normalize_fingerprint_text( string $value ): string {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
	}

	/**
	 * Recursively retain every material response field while removing only
	 * the documented server-generated/read-only fields listed above.
	 * Reference display names are response-only labels; their `value` IDs
	 * remain material. Only the documented JournalEntry Line collection is
	 * order-insensitive; unknown nested lists retain order and fail closed.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function canonicalize_fingerprint_value( $value, string $field_name = '', string $path = '' ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$canonical = array();
			foreach ( $value as $key => $child ) {
				$key = (string) $key;
				if ( $this->is_read_only_fingerprint_field( $key, $path ) ) {
					continue;
				}
				$reference_value = $value['value'] ?? null;
				if (
					$key === 'name'
					&& array_key_exists( 'value', $value )
					&& is_scalar( $reference_value )
					&& trim( (string) $reference_value ) !== ''
				) {
					continue;
				}
				$child_path = $is_list
					? $path . '[]'
					: ( $path === '' ? $key : $path . '.' . $key );
				$canonical[ $key ] = $this->canonicalize_fingerprint_value( $child, $key, $child_path );
			}

			if ( $is_list ) {
				$canonical = array_values( $canonical );
				if ( $path === 'Line' ) {
					usort(
						$canonical,
						static function ( $left, $right ): int {
							$encoded_left  = wp_json_encode( $left );
							$encoded_right = wp_json_encode( $right );
							return strcmp(
								is_string( $encoded_left ) ? $encoded_left : '',
								is_string( $encoded_right ) ? $encoded_right : ''
							);
						}
					);
				}
				return $canonical;
			}

			ksort( $canonical, SORT_STRING );
			return $canonical;
		}

		if ( in_array( $field_name, self::FINGERPRINT_DECIMAL_FIELDS, true ) ) {
			return $this->normalize_decimal( $value );
		}

		if ( is_string( $value ) ) {
			$normalized = $this->normalize_fingerprint_text( $value );
			if ( $path === 'CurrencyRef.value' && preg_match( '/^[A-Za-z]{3}$/', $normalized ) === 1 ) {
				return strtoupper( $normalized );
			}
			return $normalized;
		}

		return $value;
	}

	/**
	 * Ignore response-only fields only at the exact object level where
	 * QuickBooks generates them. A similarly named field inside an unknown
	 * extension remains material and therefore fails closed.
	 */
	private function is_read_only_fingerprint_field( string $field_name, string $parent_path ): bool {
		if ( $parent_path === '' ) {
			return in_array( $field_name, self::FINGERPRINT_ROOT_READ_ONLY_FIELDS, true );
		}

		return $parent_path === 'Line[]'
			&& in_array( $field_name, self::FINGERPRINT_LINE_READ_ONLY_FIELDS, true );
	}

	/**
	 * Normalize decimal notation without applying a precision cap or rounding.
	 *
	 * @param mixed $value
	 */
	private function normalize_decimal( $value ): string {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return '';
		}

		$raw = strtolower( trim( (string) $value ) );
		if ( $raw === '' || preg_match( '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/', $raw ) !== 1 ) {
			return '';
		}

		$sign = '';
		if ( $raw[0] === '-' || $raw[0] === '+' ) {
			$sign = $raw[0] === '-' ? '-' : '';
			$raw = substr( $raw, 1 );
		}

		$parts = explode( 'e', $raw, 2 );
		$mantissa = $parts[0];
		$exponent = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$mantissa_parts = explode( '.', $mantissa, 2 );
		$integer = $mantissa_parts[0] === '' ? '0' : $mantissa_parts[0];
		$fraction = $mantissa_parts[1] ?? '';
		$digits = $integer . $fraction;
		$decimal_position = strlen( $integer ) + $exponent;

		if ( $decimal_position <= 0 ) {
			$normalized = '0.' . str_repeat( '0', -$decimal_position ) . $digits;
		} elseif ( $decimal_position >= strlen( $digits ) ) {
			$normalized = $digits . str_repeat( '0', $decimal_position - strlen( $digits ) );
		} else {
			$normalized = substr( $digits, 0, $decimal_position ) . '.' . substr( $digits, $decimal_position );
		}

		$normalized_parts = explode( '.', $normalized, 2 );
		$normalized_integer = ltrim( $normalized_parts[0], '0' );
		$normalized_integer = $normalized_integer === '' ? '0' : $normalized_integer;
		$normalized_fraction = isset( $normalized_parts[1] ) ? rtrim( $normalized_parts[1], '0' ) : '';
		$normalized = $normalized_integer . ( $normalized_fraction !== '' ? '.' . $normalized_fraction : '' );

		if ( $normalized === '0' ) {
			return '0';
		}

		return $sign . $normalized;
	}

	public function is_reclass_source_key_claimed( string $txn_key, int $current_order_id ): bool {
        if ($txn_key === '' || ! function_exists('wc_get_orders')) {
            return false;
        }

		$query_args = array(
			'type'    => 'shop_order',
			'return'  => 'ids',
			'limit'   => 1,
			'exclude' => array( $current_order_id ),
		);
		$hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos_enabled ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => '_oras_qbo_reclass_source_txn_key',
					'value'   => $txn_key,
					'compare' => '=',
				),
			);
		} else {
			$query_args['meta_key'] = '_oras_qbo_reclass_source_txn_key';
			$query_args['meta_value'] = $txn_key;
		}

        $orders = wc_get_orders(
			$query_args
        );

        return ! empty($orders);
    }

    /**
     * @param array<string,mixed> $payload
     * @return true|\WP_Error
     */
    private function validate_payload(array $payload, float $expected_total)
    {
        $doc_number = isset($payload['DocNumber']) ? trim((string) $payload['DocNumber']) : '';
        if ($doc_number === '' || strlen($doc_number) > 21) {
            return new \WP_Error('oras_qbo_preflight_doc_number', 'QuickBooks preflight failed: DocNumber must be 1-21 characters.');
        }

        $txn_date = isset($payload['TxnDate']) ? (string) $payload['TxnDate'] : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $txn_date)) {
            return new \WP_Error('oras_qbo_preflight_txn_date', 'QuickBooks preflight failed: TxnDate must be YYYY-MM-DD.');
        }

        $lines = isset($payload['Line']) && is_array($payload['Line']) ? $payload['Line'] : array();
        if (count($lines) < 2) {
            return new \WP_Error('oras_qbo_preflight_lines', 'QuickBooks preflight failed: payload requires at least two lines.');
        }

        $debit_total  = 0.0;
        $credit_total = 0.0;

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                return new \WP_Error('oras_qbo_preflight_line_type', 'QuickBooks preflight failed: payload line is malformed at index ' . (string) $index . '.');
            }

            $amount = isset($line['Amount']) ? round((float) $line['Amount'], 2) : 0.0;
            if ($amount <= 0) {
                return new \WP_Error('oras_qbo_preflight_line_amount', 'QuickBooks preflight failed: payload line amount must be greater than zero.');
            }

            $line_detail  = isset($line['JournalEntryLineDetail']) && is_array($line['JournalEntryLineDetail'])
                ? $line['JournalEntryLineDetail']
                : array();
            $posting_type = isset($line_detail['PostingType']) ? (string) $line_detail['PostingType'] : '';
            $account_ref  = isset($line_detail['AccountRef']['value']) ? trim((string) $line_detail['AccountRef']['value']) : '';
            if ($account_ref === '') {
                return new \WP_Error('oras_qbo_preflight_account', 'QuickBooks preflight failed: payload line is missing AccountRef value.');
            }

            if ($posting_type === 'Debit') {
                $debit_total += $amount;
                continue;
            }

            if ($posting_type === 'Credit') {
                $credit_total += $amount;
                continue;
            }

            return new \WP_Error('oras_qbo_preflight_posting_type', 'QuickBooks preflight failed: PostingType must be Debit or Credit.');
        }

        $debit_total  = round($debit_total, 2);
        $credit_total = round($credit_total, 2);
        $expected_total = round(abs($expected_total), 2);

        if (abs($debit_total - $credit_total) > 0.009) {
            return new \WP_Error('oras_qbo_preflight_balance', 'QuickBooks preflight failed: debit and credit totals are not balanced.');
        }

        if (abs($debit_total - $expected_total) > 0.009) {
            return new \WP_Error('oras_qbo_preflight_total', 'QuickBooks preflight failed: payload total does not match split total.');
        }

        return true;
    }

    private function build_doc_number(int $order_id, bool $is_reversal, string $posting_mode = 'clearing'): string
    {
        $suffix      = (string) max(0, $order_id);
        $base_prefix = 'ORAS-WO-';
        if ($posting_mode === 'reclass') {
            $base_prefix = 'ORAS-RC-';
        }
        if ($is_reversal) {
            $base_prefix = 'ORAS-RV-';
        }
        $allowed     = 21 - strlen($suffix);

        if ($allowed < 1) {
            return substr($suffix, -21);
        }

        return substr($base_prefix, 0, $allowed) . $suffix;
    }

    /**
     * @return array<string,mixed>
     */
    private function build_line(float $amount, string $posting_type, string $account_id, string $description, ?array $customer_entity = null): array
    {
        $line_detail = array(
            'PostingType' => $posting_type,
            'AccountRef'  => array(
                'value' => $account_id,
            ),
        );

        if (is_array($customer_entity)) {
            $customer_id = trim((string) ($customer_entity['id'] ?? ''));
            if ($customer_id !== '') {
                $entity = array(
                    'Type'      => 'Customer',
                    'EntityRef' => array(
                        'value' => $customer_id,
                    ),
                );

                $customer_name = trim((string) ($customer_entity['name'] ?? ''));
                if ($customer_name !== '') {
                    $entity['EntityRef']['name'] = $customer_name;
                }

                $line_detail['Entity'] = $entity;
            }
        }

        return array(
            'Amount'                 => round(abs($amount), 2),
            'Description'            => $description,
            'DetailType'             => 'JournalEntryLineDetail',
            'JournalEntryLineDetail' => $line_detail,
        );
    }

    /**
     * @param \WC_Order $order
     */
    private function format_customer_note($order): string
    {
        $name = trim((string) $order->get_formatted_billing_full_name());
        if ($name === '') {
            $name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
        }

        $email = trim((string) $order->get_billing_email());
        $name_part = $name !== '' ? $name : 'Unknown customer';
        $email_part = $email !== '' ? sprintf('<%s>', $email) : '';

        return sprintf('Customer: %1$s %2$s', $name_part, $email_part);
    }
}
