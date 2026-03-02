<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Journal_Entry_Creator {

    private Api_Client $api_client;
    private QuickBooks_Logger $logger;

    public function __construct( ?Api_Client $api_client = null, ?QuickBooks_Logger $logger = null ) {
        $this->logger     = $logger ?: new QuickBooks_Logger();
        $this->api_client = $api_client ?: new Api_Client( null, $this->logger );
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function find_existing_journal_entry( string $doc_number ) {
        return $this->api_client->find_journal_entry_by_doc_number( $doc_number );
    }

    /**
     * @param \WC_Order $order
     * @param array<string,mixed> $split
     * @param array<string,mixed> $qbo_settings
     * @return array<string,mixed>|\WP_Error
     */
    public function create_for_order( $order, array $split, array $qbo_settings ) {
        $prepared = $this->build_payload_for_order( $order, $split, $qbo_settings, false );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }

        $payload    = (array) ( $prepared['payload'] ?? array() );
        $doc_number = (string) ( $prepared['doc_number'] ?? '' );
        $order_id   = (int) $order->get_id();

        $response = $this->api_client->create_journal_entry( $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $journal_entry = isset( $response['JournalEntry'] ) && is_array( $response['JournalEntry'] )
            ? $response['JournalEntry']
            : array();
        $je_id = isset( $journal_entry['Id'] ) ? (string) $journal_entry['Id'] : '';
        if ( $je_id === '' ) {
            return new \WP_Error( 'oras_qbo_missing_je_id', 'QuickBooks response did not include a JournalEntry ID.' );
        }

        $meta       = isset( $response['__oras_meta'] ) && is_array( $response['__oras_meta'] ) ? $response['__oras_meta'] : array();
        $intuit_tid = isset( $meta['intuit_tid'] ) ? (string) $meta['intuit_tid'] : '';

        $this->logger->info(
            'QuickBooks JournalEntry created for Woo order',
            array(
                'order_id'   => $order_id,
                'je_id'      => $je_id,
                'doc_number' => $doc_number,
                'intuit_tid' => $intuit_tid,
            )
        );

        return array(
            'je_id'      => $je_id,
            'doc_number' => $doc_number,
            'payload'    => $payload,
            'response'   => $response,
            'intuit_tid' => $intuit_tid,
        );
    }

    /**
     * Create a reversing JournalEntry using the prior split snapshot.
     *
     * @param \WC_Order $order
     * @param array<string,mixed> $split_snapshot
     * @param array<string,mixed> $qbo_settings
     * @return array<string,mixed>|\WP_Error
     */
    public function create_reversal_for_order( $order, array $split_snapshot, array $qbo_settings, string $original_je_id = '' ) {
        $prepared = $this->build_payload_for_order( $order, $split_snapshot, $qbo_settings, true, $original_je_id );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }

        $payload    = (array) ( $prepared['payload'] ?? array() );
        $doc_number = (string) ( $prepared['doc_number'] ?? '' );
        $order_id   = (int) $order->get_id();

        $response = $this->api_client->create_journal_entry( $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $journal_entry = isset( $response['JournalEntry'] ) && is_array( $response['JournalEntry'] )
            ? $response['JournalEntry']
            : array();
        $je_id = isset( $journal_entry['Id'] ) ? (string) $journal_entry['Id'] : '';
        if ( $je_id === '' ) {
            return new \WP_Error( 'oras_qbo_missing_reversal_je_id', 'QuickBooks response did not include a reversal JournalEntry ID.' );
        }

        $meta       = isset( $response['__oras_meta'] ) && is_array( $response['__oras_meta'] ) ? $response['__oras_meta'] : array();
        $intuit_tid = isset( $meta['intuit_tid'] ) ? (string) $meta['intuit_tid'] : '';

        $this->logger->info(
            'QuickBooks reversal JournalEntry created for Woo order',
            array(
                'order_id'   => $order_id,
                'je_id'      => $je_id,
                'doc_number' => $doc_number,
                'intuit_tid' => $intuit_tid,
            )
        );

        return array(
            'je_id'      => $je_id,
            'doc_number' => $doc_number,
            'payload'    => $payload,
            'response'   => $response,
            'intuit_tid' => $intuit_tid,
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
    public function build_payload_for_order( $order, array $split, array $qbo_settings, bool $is_reversal = false, string $original_je_id = '' ) {
        $posting_mode = sanitize_key( (string) ( $qbo_settings['posting_mode'] ?? 'clearing' ) );
        if ( ! in_array( $posting_mode, array( 'clearing', 'reclass' ), true ) ) {
            $posting_mode = 'clearing';
        }

        $source_match = null;
        $customer_entity = null;

        $counterparty_account_id = '';
        if ( $posting_mode === 'reclass' ) {
            $counterparty_account_id = trim( (string) ( $qbo_settings['reclass_source_account_id'] ?? '' ) );
            if ( $counterparty_account_id === '' ) {
                return new \WP_Error( 'oras_qbo_missing_reclass_source_account', 'QuickBooks reclass source account ID is not configured.' );
            }
        } else {
            $counterparty_account_id = trim( (string) ( $qbo_settings['clearing_account_id'] ?? '' ) );
            if ( $counterparty_account_id === '' ) {
                return new \WP_Error( 'oras_qbo_missing_clearing_account', 'QuickBooks clearing account ID is not configured.' );
            }
        }

        $lines = isset( $split['lines'] ) && is_array( $split['lines'] ) ? $split['lines'] : array();
        if ( empty( $lines ) ) {
            return new \WP_Error( 'oras_qbo_empty_split_lines', 'No split lines were provided for JournalEntry creation.' );
        }

        $split_total = round( (float) ( $split['split_total'] ?? 0.0 ), 2 );
        if ( abs( $split_total ) < 0.0001 ) {
            return new \WP_Error( 'oras_qbo_zero_split_total', 'Split total is zero. JournalEntry was not created.' );
        }

        if ( $posting_mode === 'reclass' && ! $is_reversal ) {
            $source_match = $this->find_reclass_source_transaction( $order, $split_total );
            if ( is_wp_error( $source_match ) ) {
                return $source_match;
            }

            if ( is_array( $source_match ) ) {
                $customer_ref_id = trim( (string) ( $source_match['customer_ref_id'] ?? '' ) );
                if ( $customer_ref_id !== '' ) {
                    $customer_entity = array(
                        'id'   => $customer_ref_id,
                        'name' => trim( (string) ( $source_match['customer_ref_name'] ?? '' ) ),
                    );
                }
            }
        }

        $payload_lines = array();
        if ( ! $is_reversal ) {
            $payload_lines[] = $this->build_line(
                $split_total,
                'Debit',
                $counterparty_account_id,
                $posting_mode === 'reclass'
                    ? 'ORAS Woo reclass debit for order #' . $order->get_order_number()
                    : 'ORAS Woo clearing debit for order #' . $order->get_order_number(),
                is_array( $customer_entity ) ? $customer_entity : null
            );

            foreach ( $lines as $line ) {
                if ( ! is_array( $line ) ) {
                    continue;
                }

                $amount     = round( (float) ( $line['amount'] ?? 0.0 ), 2 );
                $account_id = trim( (string) ( $line['account_id'] ?? '' ) );
                if ( abs( $amount ) < 0.0001 || $account_id === '' ) {
                    continue;
                }

                $payload_lines[] = $this->build_line(
                    $amount,
                    'Credit',
                    $account_id,
                    (string) ( $line['bucket_label'] ?? 'ORAS revenue split' ),
                    is_array( $customer_entity ) ? $customer_entity : null
                );
            }
        } else {
            foreach ( $lines as $line ) {
                if ( ! is_array( $line ) ) {
                    continue;
                }

                $amount     = round( (float) ( $line['amount'] ?? 0.0 ), 2 );
                $account_id = trim( (string) ( $line['account_id'] ?? '' ) );
                if ( abs( $amount ) < 0.0001 || $account_id === '' ) {
                    continue;
                }

                $payload_lines[] = $this->build_line(
                    $amount,
                    'Debit',
                    $account_id,
                    (string) ( $line['bucket_label'] ?? 'ORAS reversal split line' )
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

        if ( count( $payload_lines ) < 2 ) {
            return new \WP_Error( 'oras_qbo_invalid_je_lines', 'JournalEntry payload is invalid: expected debit and credit lines.' );
        }

        $order_id   = (int) $order->get_id();
        $doc_number = $this->build_doc_number( $order_id, $is_reversal, $posting_mode );
        $txn_date   = $order->get_date_paid() ? $order->get_date_paid()->date_i18n( 'Y-m-d' ) : gmdate( 'Y-m-d' );

        $customer_note = $this->format_customer_note( $order );

        $reversal_reference = $original_je_id;
        if ( $reversal_reference === '' ) {
            $reversal_reference = 'unknown';
        }

        $private_note = ! $is_reversal
            ? sprintf(
                'ORAS Woo order #%1$s revenue split (%2$s, mode=%4$s). %3$s',
                $order->get_order_number(),
                (string) ( $split['discount_mode'] ?? 'proportional' ),
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

        $preflight = $this->validate_payload( $payload, $split_total );
        if ( is_wp_error( $preflight ) ) {
            return $preflight;
        }

        return array(
            'payload'    => $payload,
            'doc_number' => $doc_number,
            'txn_date'   => $txn_date,
            'split_total' => $split_total,
            'source_match' => is_array( $source_match ) ? $source_match : array(),
        );
    }

    /**
     * @param \WC_Order $order
     * @return array<string,mixed>|\WP_Error
     */
    private function find_reclass_source_transaction( $order, float $split_total ) {
        $total = round( abs( $split_total ), 2 );
        if ( $total <= 0 ) {
            return new \WP_Error( 'oras_qbo_invalid_reclass_total', 'Cannot match reclass source transaction for zero-value order.' );
        }

        $paid_date   = $order->get_date_paid();
        $created_date = $order->get_date_created();
        $base_date   = $paid_date instanceof \WC_DateTime ? $paid_date : $created_date;
        $base_ts     = $base_date instanceof \WC_DateTime ? (int) $base_date->getTimestamp() : time();
        $from_date   = gmdate( 'Y-m-d', $base_ts - ( 7 * DAY_IN_SECONDS ) );
        $to_date     = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );

        $queries = array(
            "SELECT Id, DocNumber, TxnDate, TotalAmt, PrivateNote, CustomerMemo, CustomerRef FROM SalesReceipt WHERE TxnDate >= '" . $from_date . "' AND TxnDate <= '" . $to_date . "' ORDER BY TxnDate DESC MAXRESULTS 100",
            "SELECT Id, DocNumber, TxnDate, TotalAmt, PrivateNote, CustomerRef FROM Payment WHERE TxnDate >= '" . $from_date . "' AND TxnDate <= '" . $to_date . "' ORDER BY TxnDate DESC MAXRESULTS 100",
            "SELECT Id, DocNumber, TxnDate, TotalAmt, PrivateNote FROM Deposit WHERE TxnDate >= '" . $from_date . "' AND TxnDate <= '" . $to_date . "' ORDER BY TxnDate DESC MAXRESULTS 100",
        );

        $candidates = array();
        foreach ( $queries as $query ) {
            $response = $this->api_client->run_query( $query );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $items = isset( $response['QueryResponse'] ) && is_array( $response['QueryResponse'] )
                ? $response['QueryResponse']
                : array();

            foreach ( array( 'SalesReceipt', 'Payment', 'Deposit' ) as $entity_type ) {
                $rows = isset( $items[ $entity_type ] ) && is_array( $items[ $entity_type ] )
                    ? $items[ $entity_type ]
                    : array();
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }

                    $row_total = round( (float) ( $row['TotalAmt'] ?? 0.0 ), 2 );
                    if ( abs( $row_total - $total ) > 0.009 ) {
                        continue;
                    }

                    $id = isset( $row['Id'] ) ? trim( (string) $row['Id'] ) : '';
                    if ( $id === '' ) {
                        continue;
                    }

                    $txn_key = strtolower( $entity_type ) . ':' . $id;
                    if ( $this->is_reclass_source_key_already_used( $txn_key, (int) $order->get_id() ) ) {
                        continue;
                    }

                    $doc_number = isset( $row['DocNumber'] ) ? (string) $row['DocNumber'] : '';
                    $memo       = isset( $row['PrivateNote'] ) ? (string) $row['PrivateNote'] : '';
                    if ( $memo === '' && isset( $row['CustomerMemo'] ) ) {
                        $memo = is_array( $row['CustomerMemo'] )
                            ? (string) ( $row['CustomerMemo']['value'] ?? '' )
                            : (string) $row['CustomerMemo'];
                    }

                    $customer_ref_id = '';
                    $customer_ref_name = '';
                    if ( isset( $row['CustomerRef'] ) ) {
                        $customer_ref = is_array( $row['CustomerRef'] ) ? $row['CustomerRef'] : array();
                        $customer_ref_id = isset( $customer_ref['value'] ) ? trim( (string) $customer_ref['value'] ) : '';
                        $customer_ref_name = isset( $customer_ref['name'] ) ? trim( (string) $customer_ref['name'] ) : '';
                    }

                    $candidates[] = array(
                        'entity'     => $entity_type,
                        'id'         => $id,
                        'key'        => $txn_key,
                        'txn_date'   => isset( $row['TxnDate'] ) ? (string) $row['TxnDate'] : '',
                        'total'      => $row_total,
                        'doc_number' => $doc_number,
                        'memo'       => $memo,
                        'customer_ref_id' => $customer_ref_id,
                        'customer_ref_name' => $customer_ref_name,
                    );
                }
            }
        }

        if ( empty( $candidates ) ) {
            $error = new \WP_Error( 'oras_qbo_reclass_source_not_found', 'No matching Stripe-posted QuickBooks transaction found yet for reclass split.' );
            $error->add_data( array( 'retriable' => true ) );
            return $error;
        }

        $order_number = (string) $order->get_order_number();
        $customer_name = trim( (string) $order->get_formatted_billing_full_name() );

        foreach ( $candidates as $index => $candidate ) {
            $score = 0;
            $haystack = strtolower( trim( (string) ( $candidate['doc_number'] . ' ' . $candidate['memo'] ) ) );

            if ( $order_number !== '' && strpos( $haystack, strtolower( $order_number ) ) !== false ) {
                $score += 100;
            }

            if ( strpos( $haystack, 'oras order' ) !== false ) {
                $score += 30;
            }

            if ( $customer_name !== '' && strpos( $haystack, strtolower( $customer_name ) ) !== false ) {
                $score += 20;
            }

            $candidate_customer_name = trim( (string) ( $candidate['customer_ref_name'] ?? '' ) );
            if ( $customer_name !== '' && $candidate_customer_name !== '' && strpos( strtolower( $candidate_customer_name ), strtolower( $customer_name ) ) !== false ) {
                $score += 40;
            }

            $txn_ts = strtotime( (string) ( $candidate['txn_date'] ?? '' ) . ' 00:00:00 UTC' );
            if ( $txn_ts !== false ) {
                $day_diff = (int) abs( floor( ( $txn_ts - $base_ts ) / DAY_IN_SECONDS ) );
                $score   += max( 0, 10 - $day_diff );
            }

            $candidates[ $index ]['score'] = $score;
        }

        usort(
            $candidates,
            static function ( array $left, array $right ): int {
                $left_score  = isset( $left['score'] ) ? (int) $left['score'] : 0;
                $right_score = isset( $right['score'] ) ? (int) $right['score'] : 0;
                if ( $left_score !== $right_score ) {
                    return $right_score <=> $left_score;
                }

                return strcmp( (string) ( $right['txn_date'] ?? '' ), (string) ( $left['txn_date'] ?? '' ) );
            }
        );

        return $candidates[0];
    }

    private function is_reclass_source_key_already_used( string $txn_key, int $current_order_id ): bool {
        if ( $txn_key === '' || ! function_exists( 'wc_get_orders' ) ) {
            return false;
        }

        $orders = wc_get_orders(
            array(
                'type'       => 'shop_order',
                'return'     => 'ids',
                'limit'      => 1,
                'exclude'    => array( $current_order_id ),
                'meta_key'   => '_oras_qbo_reclass_source_txn_key',
                'meta_value' => $txn_key,
            )
        );

        return ! empty( $orders );
    }

    /**
     * @param array<string,mixed> $payload
     * @return true|\WP_Error
     */
    private function validate_payload( array $payload, float $expected_total ) {
        $doc_number = isset( $payload['DocNumber'] ) ? trim( (string) $payload['DocNumber'] ) : '';
        if ( $doc_number === '' || strlen( $doc_number ) > 21 ) {
            return new \WP_Error( 'oras_qbo_preflight_doc_number', 'QuickBooks preflight failed: DocNumber must be 1-21 characters.' );
        }

        $txn_date = isset( $payload['TxnDate'] ) ? (string) $payload['TxnDate'] : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $txn_date ) ) {
            return new \WP_Error( 'oras_qbo_preflight_txn_date', 'QuickBooks preflight failed: TxnDate must be YYYY-MM-DD.' );
        }

        $lines = isset( $payload['Line'] ) && is_array( $payload['Line'] ) ? $payload['Line'] : array();
        if ( count( $lines ) < 2 ) {
            return new \WP_Error( 'oras_qbo_preflight_lines', 'QuickBooks preflight failed: payload requires at least two lines.' );
        }

        $debit_total  = 0.0;
        $credit_total = 0.0;

        foreach ( $lines as $index => $line ) {
            if ( ! is_array( $line ) ) {
                return new \WP_Error( 'oras_qbo_preflight_line_type', 'QuickBooks preflight failed: payload line is malformed at index ' . (string) $index . '.' );
            }

            $amount = isset( $line['Amount'] ) ? round( (float) $line['Amount'], 2 ) : 0.0;
            if ( $amount <= 0 ) {
                return new \WP_Error( 'oras_qbo_preflight_line_amount', 'QuickBooks preflight failed: payload line amount must be greater than zero.' );
            }

            $line_detail  = isset( $line['JournalEntryLineDetail'] ) && is_array( $line['JournalEntryLineDetail'] )
                ? $line['JournalEntryLineDetail']
                : array();
            $posting_type = isset( $line_detail['PostingType'] ) ? (string) $line_detail['PostingType'] : '';
            $account_ref  = isset( $line_detail['AccountRef']['value'] ) ? trim( (string) $line_detail['AccountRef']['value'] ) : '';
            if ( $account_ref === '' ) {
                return new \WP_Error( 'oras_qbo_preflight_account', 'QuickBooks preflight failed: payload line is missing AccountRef value.' );
            }

            if ( $posting_type === 'Debit' ) {
                $debit_total += $amount;
                continue;
            }

            if ( $posting_type === 'Credit' ) {
                $credit_total += $amount;
                continue;
            }

            return new \WP_Error( 'oras_qbo_preflight_posting_type', 'QuickBooks preflight failed: PostingType must be Debit or Credit.' );
        }

        $debit_total  = round( $debit_total, 2 );
        $credit_total = round( $credit_total, 2 );
        $expected_total = round( abs( $expected_total ), 2 );

        if ( abs( $debit_total - $credit_total ) > 0.009 ) {
            return new \WP_Error( 'oras_qbo_preflight_balance', 'QuickBooks preflight failed: debit and credit totals are not balanced.' );
        }

        if ( abs( $debit_total - $expected_total ) > 0.009 ) {
            return new \WP_Error( 'oras_qbo_preflight_total', 'QuickBooks preflight failed: payload total does not match split total.' );
        }

        return true;
    }

    private function build_doc_number( int $order_id, bool $is_reversal, string $posting_mode = 'clearing' ): string {
        $suffix      = (string) max( 0, $order_id );
        $base_prefix = 'ORAS-WO-';
        if ( $posting_mode === 'reclass' ) {
            $base_prefix = 'ORAS-RC-';
        }
        if ( $is_reversal ) {
            $base_prefix = 'ORAS-RV-';
        }
        $allowed     = 21 - strlen( $suffix );

        if ( $allowed < 1 ) {
            return substr( $suffix, -21 );
        }

        return substr( $base_prefix, 0, $allowed ) . $suffix;
    }

    /**
     * @return array<string,mixed>
     */
    private function build_line( float $amount, string $posting_type, string $account_id, string $description, ?array $customer_entity = null ): array {
        $line_detail = array(
            'PostingType' => $posting_type,
            'AccountRef'  => array(
                'value' => $account_id,
            ),
        );

        if ( is_array( $customer_entity ) ) {
            $customer_id = trim( (string) ( $customer_entity['id'] ?? '' ) );
            if ( $customer_id !== '' ) {
                $entity = array(
                    'Type'      => 'Customer',
                    'EntityRef' => array(
                        'value' => $customer_id,
                    ),
                );

                $customer_name = trim( (string) ( $customer_entity['name'] ?? '' ) );
                if ( $customer_name !== '' ) {
                    $entity['EntityRef']['name'] = $customer_name;
                }

                $line_detail['Entity'] = $entity;
            }
        }

        return array(
            'Amount'                 => round( abs( $amount ), 2 ),
            'Description'            => $description,
            'DetailType'             => 'JournalEntryLineDetail',
            'JournalEntryLineDetail' => $line_detail,
        );
    }

    /**
     * @param \WC_Order $order
     */
    private function format_customer_note( $order ): string {
        $name = trim( (string) $order->get_formatted_billing_full_name() );
        if ( $name === '' ) {
            $name = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
        }

        $email = trim( (string) $order->get_billing_email() );
        $name_part = $name !== '' ? $name : 'Unknown customer';
        $email_part = $email !== '' ? sprintf( '<%s>', $email ) : '';

        return sprintf( 'Customer: %1$s %2$s', $name_part, $email_part );
    }
}
