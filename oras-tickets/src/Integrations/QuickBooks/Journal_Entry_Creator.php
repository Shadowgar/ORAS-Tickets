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
        $clearing_account_id = trim( (string) ( $qbo_settings['clearing_account_id'] ?? '' ) );
        if ( $clearing_account_id === '' ) {
            return new \WP_Error( 'oras_qbo_missing_clearing_account', 'QuickBooks clearing account ID is not configured.' );
        }

        $lines = isset( $split['lines'] ) && is_array( $split['lines'] ) ? $split['lines'] : array();
        if ( empty( $lines ) ) {
            return new \WP_Error( 'oras_qbo_empty_split_lines', 'No split lines were provided for JournalEntry creation.' );
        }

        $split_total = round( (float) ( $split['split_total'] ?? 0.0 ), 2 );
        if ( abs( $split_total ) < 0.0001 ) {
            return new \WP_Error( 'oras_qbo_zero_split_total', 'Split total is zero. JournalEntry was not created.' );
        }

        $payload_lines = array();
        if ( ! $is_reversal ) {
            $payload_lines[] = $this->build_line(
                $split_total,
                'Debit',
                $clearing_account_id,
                'ORAS Woo clearing debit for order #' . $order->get_order_number()
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
                    (string) ( $line['bucket_label'] ?? 'ORAS revenue split' )
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
                $clearing_account_id,
                'ORAS reversal credit to clearing for order #' . $order->get_order_number()
            );
        }

        if ( count( $payload_lines ) < 2 ) {
            return new \WP_Error( 'oras_qbo_invalid_je_lines', 'JournalEntry payload is invalid: expected debit and credit lines.' );
        }

        $order_id   = (int) $order->get_id();
        $doc_number = $this->build_doc_number( $order_id, $is_reversal );
        $txn_date   = $order->get_date_paid() ? $order->get_date_paid()->date_i18n( 'Y-m-d' ) : gmdate( 'Y-m-d' );

        $private_note = ! $is_reversal
            ? sprintf(
                'ORAS Woo order #%1$s revenue split (%2$s).',
                $order->get_order_number(),
                (string) ( $split['discount_mode'] ?? 'proportional' )
            )
            : sprintf(
                'ORAS Woo order #%1$s reversal for JE %2$s.',
                $order->get_order_number(),
                $original_je_id !== '' ? $original_je_id : 'unknown'
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
        );
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

    private function build_doc_number( int $order_id, bool $is_reversal ): string {
        $suffix      = (string) max( 0, $order_id );
        $base_prefix = $is_reversal ? 'ORAS-RV-' : 'ORAS-WO-';
        $allowed     = 21 - strlen( $suffix );

        if ( $allowed < 1 ) {
            return substr( $suffix, -21 );
        }

        return substr( $base_prefix, 0, $allowed ) . $suffix;
    }

    /**
     * @return array<string,mixed>
     */
    private function build_line( float $amount, string $posting_type, string $account_id, string $description ): array {
        return array(
            'Amount'                 => round( abs( $amount ), 2 ),
            'Description'            => $description,
            'DetailType'             => 'JournalEntryLineDetail',
            'JournalEntryLineDetail' => array(
                'PostingType' => $posting_type,
                'AccountRef'  => array(
                    'value' => $account_id,
                ),
            ),
        );
    }
}
