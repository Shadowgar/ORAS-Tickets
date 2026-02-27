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

    public function __construct( Sync_Orchestrator $orchestrator, Api_Client $api_client ) {
        $this->orchestrator = $orchestrator;
        $this->api_client   = $api_client;
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
        \WP_CLI::success( sprintf( 'Sync status: %s%s', $status, $je_id !== '' ? ' (JE ID: ' . $je_id . ')' : '' ) );
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
        \WP_CLI::add_command( 'oras-tickets qbo', new self( $orchestrator, $api_client ) );
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
}
