<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-CLI entry points for ORAS QuickBooks sync.
 */
final class Cli_Command extends \WP_CLI_Command {

    private Sync_Orchestrator $orchestrator;
    private Api_Client $api_client;
    private Split_Calculator $split_calculator;

    public function __construct( Sync_Orchestrator $orchestrator, Api_Client $api_client, ?Split_Calculator $split_calculator = null ) {
        $this->orchestrator    = $orchestrator;
        $this->api_client      = $api_client;
        $this->split_calculator = $split_calculator ?: new Split_Calculator();
    }

    /**
     * Verify QuickBooks API connectivity and refresh account cache.
     *
     * ## EXAMPLES
     *
     *     wp oras-tickets qbo test-connection
     */
    public function test_connection( array $args, array $assoc_args ): void {
        $test = $this->api_client->test_connection();
        if ( is_wp_error( $test ) ) {
            \WP_CLI::error( $test->get_error_message() );
            return;
        }

        $accounts = $this->api_client->fetch_accounts();
        if ( is_wp_error( $accounts ) ) {
            \WP_CLI::warning( 'Connected, but account refresh failed: ' . $accounts->get_error_message() );
            \WP_CLI::success( 'QuickBooks connection test passed.' );
            return;
        }

        $account_rows = $this->extract_account_cache_rows( $accounts );
        Settings::update_quickbooks_settings(
            array(
                'account_cache' => $account_rows,
                'last_error'    => '',
            )
        );

        \WP_CLI::success( sprintf( 'QuickBooks connection test passed. Cached %d accounts.', count( $account_rows ) ) );
    }

    /**
     * Sync one paid Woo order to QuickBooks as JournalEntry split.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Woo order ID.
     *
     * [--force]
     * : Ignore idempotent JE checks and force re-run.
     *
     * ## EXAMPLES
     *
     *     wp oras-tickets qbo sync-order 4096
     *     wp oras-tickets qbo sync-order 4096 --force
     *
     * @param string[] $args
     * @param array<string,mixed> $assoc_args
     */
    public function sync_order( array $args, array $assoc_args ): void {
        $order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( $order_id <= 0 ) {
            \WP_CLI::error( 'You must pass a valid Woo order ID.' );
            return;
        }

        $force  = ! empty( $assoc_args['force'] );
        $result = $this->orchestrator->sync_order( $order_id, $force );
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        $status = isset( $result['status'] ) ? (string) $result['status'] : 'unknown';
        $je_id  = isset( $result['je_id'] ) ? (string) $result['je_id'] : '';
        if ( $status === 'dry_run' ) {
            \WP_CLI::warning( 'Dry run mode is enabled. No QuickBooks write was performed.' );
        }
        \WP_CLI::success( sprintf( 'Sync status: %s%s', $status, $je_id !== '' ? ' (JE ID: ' . $je_id . ')' : '' ) );
    }

    /**
     * Reset local ORAS QuickBooks sync state and re-run sync for an order.
     *
     * Useful when migrating old orders to reclass mode after initial testing.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Woo order ID.
     *
     * @subcommand resync-order
     */
    public function resync_order( array $args, array $assoc_args ): void {
        $order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( $order_id <= 0 ) {
            \WP_CLI::error( 'You must pass a valid Woo order ID.' );
            return;
        }

        $reset = $this->orchestrator->reset_order_sync_state( $order_id );
        if ( is_wp_error( $reset ) ) {
            \WP_CLI::error( $reset->get_error_message() );
            return;
        }

        $result = $this->orchestrator->sync_order( $order_id, false );
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        $status = isset( $result['status'] ) ? (string) $result['status'] : 'unknown';
        $je_id  = isset( $result['je_id'] ) ? (string) $result['je_id'] : '';
        \WP_CLI::success( sprintf( 'Resync status: %s%s', $status, $je_id !== '' ? ' (JE ID: ' . $je_id . ')' : '' ) );
    }

    /**
     * Approve one pending order for QuickBooks sync.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Woo order ID.
     *
     * [--sync-now]
     * : Execute sync immediately after approval.
     *
     * @subcommand approve-order
     */
    public function approve_order( array $args, array $assoc_args ): void {
        $order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( $order_id <= 0 ) {
            \WP_CLI::error( 'You must pass a valid Woo order ID.' );
            return;
        }

        $sync_now = ! empty( $assoc_args['sync-now'] );
        $result   = $this->orchestrator->approve_order_sync( $order_id, $sync_now );
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        $status = isset( $result['status'] ) ? (string) $result['status'] : 'approved';
        \WP_CLI::success( sprintf( 'Approval status: %s', $status ) );
    }

    /**
     * Create a reversing JournalEntry for one synced order.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Woo order ID.
     *
     * [--force]
     * : Allow reversal attempt even if a reversal JE ID is already stored.
     *
     * @subcommand reverse-order
     */
    public function reverse_order( array $args, array $assoc_args ): void {
        $order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( $order_id <= 0 ) {
            \WP_CLI::error( 'You must pass a valid Woo order ID.' );
            return;
        }

        $force  = ! empty( $assoc_args['force'] );
        $result = $this->orchestrator->reverse_order( $order_id, $force );
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        $status       = isset( $result['status'] ) ? (string) $result['status'] : 'unknown';
        $reversal_je  = isset( $result['reversal_je_id'] ) ? (string) $result['reversal_je_id'] : '';
        if ( $status === 'reversal_dry_run' ) {
            \WP_CLI::warning( 'Dry run mode is enabled. Reversal payload was validated but not sent to QuickBooks.' );
        }
        \WP_CLI::success( sprintf( 'Reversal status: %s%s', $status, $reversal_je !== '' ? ' (JE ID: ' . $reversal_je . ')' : '' ) );
    }

    /**
     * Show order-level QuickBooks audit details.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Woo order ID.
     *
     * [--limit=<number>]
     * : Max audit entries to show. Default 25.
     *
     * [--format=<format>]
     * : Output format. table|json. Default: table.
     *
     * @subcommand audit-order
     */
    public function audit_order( array $args, array $assoc_args ): void {
        $order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( $order_id <= 0 ) {
            \WP_CLI::error( 'You must pass a valid Woo order ID.' );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            \WP_CLI::error( 'WooCommerce order not found.' );
            return;
        }

        $limit  = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 25;
        $format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';

        $summary = array(
            'order_id'                 => (int) $order->get_id(),
            'order_number'             => (string) $order->get_order_number(),
            'sync_status'              => (string) $order->get_meta( '_oras_qbo_sync_status', true ),
            'je_id'                    => (string) $order->get_meta( '_oras_qbo_je_id', true ),
            'reversal_je_id'           => (string) $order->get_meta( '_oras_qbo_reversal_je_id', true ),
            'doc_number'               => (string) $order->get_meta( '_oras_qbo_doc_number', true ),
            'synced_at'                => (string) $order->get_meta( '_oras_qbo_synced_at', true ),
            'manual_approved_at'       => (string) $order->get_meta( '_oras_qbo_manual_approved_at', true ),
            'last_error_code'          => (string) $order->get_meta( '_oras_qbo_sync_error_code', true ),
            'last_error'               => (string) $order->get_meta( '_oras_qbo_sync_error', true ),
            'last_intuit_tid'          => (string) $order->get_meta( '_oras_qbo_last_intuit_tid', true ),
            'last_audit_event'         => (string) $order->get_meta( '_oras_qbo_last_audit_event', true ),
            'retry_count'              => (string) $order->get_meta( '_oras_qbo_retry_count', true ),
        );

        $raw_entries = get_post_meta( $order_id, '_oras_qbo_audit_entry', false );
        $entries     = array();
        if ( is_array( $raw_entries ) ) {
            $raw_entries = array_slice( array_reverse( $raw_entries ), 0, $limit );
            foreach ( $raw_entries as $raw_entry ) {
                if ( ! is_string( $raw_entry ) || $raw_entry === '' ) {
                    continue;
                }
                $decoded = json_decode( $raw_entry, true );
                if ( is_array( $decoded ) ) {
                    $entries[] = $decoded;
                }
            }
        }

        if ( $format === 'json' ) {
            \WP_CLI::line(
                wp_json_encode(
                    array(
                        'summary' => $summary,
                        'entries' => $entries,
                    ),
                    JSON_PRETTY_PRINT
                )
            );
            return;
        }

        \WP_CLI::line( 'summary_key\tsummary_value' );
        foreach ( $summary as $key => $value ) {
            \WP_CLI::line( (string) $key . "\t" . (string) $value );
        }

        \WP_CLI::line( '' );
        \WP_CLI::line( 'timestamp_utc\tevent\tactor_user_id\tmode\tcontext' );
        foreach ( $entries as $entry ) {
            $context = isset( $entry['context'] ) ? wp_json_encode( $entry['context'] ) : '';
            \WP_CLI::line(
                (string) ( $entry['timestamp_utc'] ?? '' ) . "\t" .
                (string) ( $entry['event'] ?? '' ) . "\t" .
                (string) ( $entry['actor_user_id'] ?? '' ) . "\t" .
                (string) ( $entry['mode'] ?? '' ) . "\t" .
                (string) $context
            );
        }
    }

    /**
     * Reconcile Woo completed orders against ORAS QuickBooks sync metadata.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : Start date in YYYY-MM-DD (inclusive). Default: 30 days ago.
     *
     * [--to=<date>]
     * : End date in YYYY-MM-DD (inclusive). Default: today.
     *
     * [--format=<format>]
     * : Output format. table|json. Default: table.
     *
     * [--limit=<number>]
     * : Max mismatch rows to print in table format. Default: 50.
     *
     * ## EXAMPLES
     *
     *     wp oras-tickets qbo reconcile-report --from=2026-02-01 --to=2026-02-28
     *     wp oras-tickets qbo reconcile-report --from=2026-02-01 --to=2026-02-28 --format=json
     *
     * @subcommand reconcile-report
     */
    public function reconcile_report( array $args, array $assoc_args ): void {
        $from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
        $to   = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : gmdate( 'Y-m-d' );

        $from_date = $this->normalize_cli_date( $from );
        $to_date   = $this->normalize_cli_date( $to );
        if ( $from_date === '' || $to_date === '' ) {
            \WP_CLI::error( 'Invalid --from/--to date. Use YYYY-MM-DD.' );
            return;
        }

        if ( strcmp( $from_date, $to_date ) > 0 ) {
            \WP_CLI::error( '--from must be less than or equal to --to.' );
            return;
        }

        $report = $this->build_reconciliation_report( $from_date, $to_date );
        if ( is_wp_error( $report ) ) {
            \WP_CLI::error( $report->get_error_message() );
            return;
        }

        $format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
        if ( $format === 'json' ) {
            \WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
            return;
        }

        $summary = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
        \WP_CLI::line( 'metric\tvalue' );
        foreach ( $summary as $metric => $value ) {
            \WP_CLI::line( (string) $metric . "\t" . (string) $value );
        }

        $mismatches = isset( $report['mismatches'] ) && is_array( $report['mismatches'] ) ? $report['mismatches'] : array();
        $limit      = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 50;
        $mismatches = array_slice( $mismatches, 0, $limit );

        \WP_CLI::line( '' );
        \WP_CLI::line( 'order_id\tstatus\tje_id\treversal_je_id\tline_item_total\tqbo_net_amount\tvariance\tsnapshot_source' );
        foreach ( $mismatches as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            \WP_CLI::line(
                (string) ( $row['order_id'] ?? '' ) . "\t" .
                (string) ( $row['sync_status'] ?? '' ) . "\t" .
                (string) ( $row['je_id'] ?? '' ) . "\t" .
                (string) ( $row['reversal_je_id'] ?? '' ) . "\t" .
                number_format( (float) ( $row['line_item_total'] ?? 0.0 ), 2, '.', '' ) . "\t" .
                number_format( (float) ( $row['qbo_net_amount'] ?? 0.0 ), 2, '.', '' ) . "\t" .
                number_format( (float) ( $row['variance'] ?? 0.0 ), 2, '.', '' ) . "\t" .
                (string) ( $row['snapshot_source'] ?? '' )
            );
        }

        \WP_CLI::success( sprintf( 'Reconciliation complete for %s to %s.', $from_date, $to_date ) );
    }

    /**
     * Preview split mapping for one order without creating a JournalEntry.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Woo order ID.
     *
     * [--format=<format>]
     * : Output format. table|json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp oras-tickets qbo preview-order 4096
     *     wp oras-tickets qbo preview-order 4096 --format=json
     *
     * @param string[] $args
     * @param array<string,mixed> $assoc_args
     *
     * @subcommand preview-order
     */
    public function preview_order( array $args, array $assoc_args ): void {
        $order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( $order_id <= 0 ) {
            \WP_CLI::error( 'You must pass a valid Woo order ID.' );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            \WP_CLI::error( 'WooCommerce order not found.' );
            return;
        }

        $settings = Settings::get_quickbooks_settings();
        $split    = $this->split_calculator->calculate( $order, $settings );
        if ( is_wp_error( $split ) ) {
            \WP_CLI::error( $split->get_error_message() );
            return;
        }

        $lines = isset( $split['lines'] ) && is_array( $split['lines'] ) ? $split['lines'] : array();
        $rows  = array();
        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }
            $rows[] = array(
                'bucket_key'   => isset( $line['bucket_key'] ) ? (string) $line['bucket_key'] : '',
                'bucket_label' => isset( $line['bucket_label'] ) ? (string) $line['bucket_label'] : '',
                'account_id'   => isset( $line['account_id'] ) ? (string) $line['account_id'] : '',
                'amount'       => isset( $line['amount'] ) ? number_format( (float) $line['amount'], 2, '.', '' ) : '0.00',
            );
        }

        $format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
        if ( $format === 'json' ) {
            \WP_CLI::line( wp_json_encode( array(
                'order_id'      => $order_id,
                'order_number'  => isset( $split['order_number'] ) ? (string) $split['order_number'] : '',
                'split_total'   => isset( $split['split_total'] ) ? (float) $split['split_total'] : 0.0,
                'discount_total' => isset( $split['discount_total'] ) ? (float) $split['discount_total'] : 0.0,
                'lines'         => $rows,
                'warnings'      => isset( $split['warnings'] ) ? $split['warnings'] : array(),
            ), JSON_PRETTY_PRINT ) );
        } else {
            \WP_CLI::line( "bucket_key\tbucket_label\taccount_id\tamount" );
            foreach ( $rows as $row ) {
                \WP_CLI::line(
                    (string) $row['bucket_key'] . "\t" .
                    (string) $row['bucket_label'] . "\t" .
                    (string) $row['account_id'] . "\t" .
                    (string) $row['amount']
                );
            }
            \WP_CLI::line( sprintf( 'split_total=%0.2f discount_total=%0.2f', (float) ( $split['split_total'] ?? 0.0 ), (float) ( $split['discount_total'] ?? 0.0 ) ) );
            if ( ! empty( $split['warnings'] ) && is_array( $split['warnings'] ) ) {
                foreach ( $split['warnings'] as $warning ) {
                    \WP_CLI::warning( (string) $warning );
                }
            }
        }

        \WP_CLI::success( 'Preview complete. No QuickBooks write was performed.' );
    }

    /**
     * Queue retry for failed order sync entries.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Max failed orders to queue. Default 25.
     *
     * ## EXAMPLES
     *
     *     wp oras-tickets qbo retry-failed
     *     wp oras-tickets qbo retry-failed --limit=100
     *
     * @param array<string,mixed> $assoc_args
     */
    public function retry_failed( array $args, array $assoc_args ): void {
        $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 25;
        $count = $this->orchestrator->retry_failed_orders( max( 1, $limit ) );
        \WP_CLI::success( sprintf( 'Queued %d failed order(s) for retry.', $count ) );
    }

    /**
     * Register command in WP-CLI.
     */
    public static function register( Sync_Orchestrator $orchestrator, Api_Client $api_client ): void {
        \WP_CLI::add_command( 'oras-tickets qbo', new self( $orchestrator, $api_client, new Split_Calculator() ) );
    }

    /**
     * @param array<string,mixed> $accounts_response
     * @return array<int,array<string,string>>
     */
    private function extract_account_cache_rows( array $accounts_response ): array {
        $accounts = isset( $accounts_response['QueryResponse']['Account'] ) && is_array( $accounts_response['QueryResponse']['Account'] )
            ? $accounts_response['QueryResponse']['Account']
            : array();

        $cache = array();
        foreach ( $accounts as $account ) {
            if ( ! is_array( $account ) ) {
                continue;
            }
            $id = isset( $account['Id'] ) ? (string) $account['Id'] : '';
            if ( $id === '' ) {
                continue;
            }
            $label = isset( $account['FullyQualifiedName'] ) ? (string) $account['FullyQualifiedName'] : (string) ( $account['Name'] ?? $id );
            $cache[] = array(
                'id'    => $id,
                'label' => $label,
                'type'  => isset( $account['AccountType'] ) ? (string) $account['AccountType'] : '',
            );
        }

        return $cache;
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    private function build_reconciliation_report( string $from_date, string $to_date ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return new \WP_Error( 'oras_qbo_woo_unavailable', 'WooCommerce order query API is unavailable.' );
        }

        $order_ids = wc_get_orders(
            array(
                'type'         => 'shop_order',
                'status'       => array( 'completed' ),
                'limit'        => -1,
                'return'       => 'ids',
                'date_created' => $from_date . '...' . $to_date,
            )
        );
        if ( ! is_array( $order_ids ) ) {
            $order_ids = array();
        }

        $summary = array(
            'from'                     => $from_date,
            'to'                       => $to_date,
            'order_count_completed'    => 0,
            'order_count_synced'       => 0,
            'order_count_pending'      => 0,
            'order_count_failed'       => 0,
            'order_count_dry_run'      => 0,
            'order_count_reversed'     => 0,
            'order_count_unsynced'     => 0,
            'woo_total_order_amount'   => 0.0,
            'woo_total_line_items'     => 0.0,
            'qbo_total_synced_split'   => 0.0,
            'qbo_total_reversed_split' => 0.0,
            'qbo_net_posted'           => 0.0,
            'variance_woo_vs_qbo_net'  => 0.0,
        );

        $mismatches = array();

        foreach ( $order_ids as $order_id ) {
            $order_id = absint( $order_id );
            if ( $order_id <= 0 ) {
                continue;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $summary['order_count_completed']++;

            $sync_status     = (string) $order->get_meta( '_oras_qbo_sync_status', true );
            $je_id           = trim( (string) $order->get_meta( '_oras_qbo_je_id', true ) );
            $reversal_je_id  = trim( (string) $order->get_meta( '_oras_qbo_reversal_je_id', true ) );
            $line_item_total = $this->calculate_order_line_item_total( $order );
            $order_total     = round( (float) $order->get_total(), 2 );

            $summary['woo_total_order_amount'] += $order_total;
            $summary['woo_total_line_items'] += $line_item_total;

            if ( $sync_status === 'pending_qbo_review' ) {
                $summary['order_count_pending']++;
            }
            if ( $sync_status === 'failed' ) {
                $summary['order_count_failed']++;
            }
            if ( $sync_status === 'dry_run' || $sync_status === 'reversal_dry_run' ) {
                $summary['order_count_dry_run']++;
            }

            $snapshot_info = $this->get_order_snapshot_total( $order, $line_item_total, $je_id !== '' );
            $snapshot_total = (float) ( $snapshot_info['amount'] ?? 0.0 );
            $snapshot_source = (string) ( $snapshot_info['source'] ?? 'none' );

            if ( $je_id !== '' ) {
                $summary['order_count_synced']++;
                $summary['qbo_total_synced_split'] += $snapshot_total;
            } else {
                $summary['order_count_unsynced']++;
            }

            if ( $reversal_je_id !== '' ) {
                $summary['order_count_reversed']++;
                $summary['qbo_total_reversed_split'] += $snapshot_total;
            }

            $qbo_net_amount = ( $je_id !== '' ? $snapshot_total : 0.0 ) - ( $reversal_je_id !== '' ? $snapshot_total : 0.0 );
            $variance       = round( $line_item_total - $qbo_net_amount, 2 );

            if ( abs( $variance ) > 0.009 ) {
                $mismatches[] = array(
                    'order_id'        => $order_id,
                    'sync_status'     => $sync_status,
                    'je_id'           => $je_id,
                    'reversal_je_id'  => $reversal_je_id,
                    'line_item_total' => round( $line_item_total, 2 ),
                    'qbo_net_amount'  => round( $qbo_net_amount, 2 ),
                    'variance'        => $variance,
                    'snapshot_source' => $snapshot_source,
                );
            }
        }

        $summary['woo_total_order_amount']   = round( (float) $summary['woo_total_order_amount'], 2 );
        $summary['woo_total_line_items']     = round( (float) $summary['woo_total_line_items'], 2 );
        $summary['qbo_total_synced_split']   = round( (float) $summary['qbo_total_synced_split'], 2 );
        $summary['qbo_total_reversed_split'] = round( (float) $summary['qbo_total_reversed_split'], 2 );
        $summary['qbo_net_posted']           = round( (float) $summary['qbo_total_synced_split'] - (float) $summary['qbo_total_reversed_split'], 2 );
        $summary['variance_woo_vs_qbo_net']  = round( (float) $summary['woo_total_line_items'] - (float) $summary['qbo_net_posted'], 2 );

        usort(
            $mismatches,
            static function ( array $a, array $b ): int {
                $a_var = abs( (float) $a['variance'] );
                $b_var = abs( (float) $b['variance'] );
                if ( $a_var === $b_var ) {
                    return (int) $a['order_id'] <=> (int) $b['order_id'];
                }

                return $b_var <=> $a_var;
            }
        );

        return array(
            'summary'    => $summary,
            'mismatches' => $mismatches,
        );
    }

    private function normalize_cli_date( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
        if ( ! $dt instanceof \DateTimeImmutable ) {
            return '';
        }

        return $dt->format( 'Y-m-d' ) === $value ? $value : '';
    }

    /**
     * @param \WC_Order $order
     */
    private function calculate_order_line_item_total( $order ): float {
        $total = 0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }
            $total += round( (float) $item->get_total(), 2 );
        }

        return round( $total, 2 );
    }

    /**
     * @param \WC_Order $order
     * @return array{amount:float,source:string}
     */
    private function get_order_snapshot_total( $order, float $line_item_total, bool $has_je ): array {
        $snapshot_raw = (string) $order->get_meta( '_oras_qbo_split_snapshot', true );
        $snapshot     = json_decode( $snapshot_raw, true );
        if ( is_array( $snapshot ) && isset( $snapshot['split_total'] ) ) {
            return array(
                'amount' => round( (float) $snapshot['split_total'], 2 ),
                'source' => 'snapshot',
            );
        }

        if ( $has_je ) {
            return array(
                'amount' => round( $line_item_total, 2 ),
                'source' => 'fallback_line_items',
            );
        }

        return array(
            'amount' => 0.0,
            'source' => 'none',
        );
    }
}
