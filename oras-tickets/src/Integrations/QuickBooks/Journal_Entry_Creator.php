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
     * @param \WC_Order $order
     * @param array<string,mixed> $split
     * @param array<string,mixed> $qbo_settings
     * @return array<string,mixed>|\WP_Error
     */
    public function create_for_order( $order, array $split, array $qbo_settings ) {
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

        $payload_lines   = array();
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

        if ( count( $payload_lines ) < 2 ) {
            return new \WP_Error( 'oras_qbo_invalid_je_lines', 'JournalEntry payload is invalid: expected debit and at least one credit line.' );
        }

        $order_id   = (int) $order->get_id();
        $doc_number = 'ORAS-WOO-' . $order_id;
        $txn_date   = $order->get_date_paid() ? $order->get_date_paid()->date_i18n( 'Y-m-d' ) : gmdate( 'Y-m-d' );

        $private_note = sprintf(
            'ORAS Woo order #%1$s revenue split (%2$s).',
            $order->get_order_number(),
            (string) ( $split['discount_mode'] ?? 'proportional' )
        );

        $payload = array(
            'DocNumber'   => $doc_number,
            'TxnDate'     => $txn_date,
            'PrivateNote' => $private_note,
            'Line'        => $payload_lines,
        );

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

        $this->logger->info(
            'QuickBooks JournalEntry created for Woo order',
            array(
                'order_id' => $order_id,
                'je_id'    => $je_id,
            )
        );

        return array(
            'je_id'    => $je_id,
            'payload'  => $payload,
            'response' => $response,
        );
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
