<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

require_once __DIR__ . '/Settings.php'; // NOSONAR
require_once __DIR__ . '/QuickBooks_Logger.php'; // NOSONAR
require_once __DIR__ . '/OAuth_Client.php'; // NOSONAR
require_once __DIR__ . '/Api_Client.php'; // NOSONAR
require_once __DIR__ . '/Split_Calculator.php'; // NOSONAR
require_once __DIR__ . '/Journal_Entry_Creator.php'; // NOSONAR
require_once __DIR__ . '/Retry_Handler.php'; // NOSONAR
require_once __DIR__ . '/Sync_Orchestrator.php'; // NOSONAR

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/Cli_Command.php'; // NOSONAR
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Module {

    private QuickBooks_Logger $logger;
    private OAuth_Client $oauth_client;
    private Api_Client $api_client;
    private Sync_Orchestrator $orchestrator;

    public function __construct() {
        $this->logger       = new QuickBooks_Logger();
        $this->oauth_client = new OAuth_Client( $this->logger );
        $this->api_client   = new Api_Client( $this->oauth_client, $this->logger );
        $this->orchestrator = new Sync_Orchestrator(
            new Split_Calculator( $this->logger ),
            new Journal_Entry_Creator( $this->api_client, $this->logger ),
            new Retry_Handler( $this->logger ),
            $this->logger
        );
    }

    public function register(): void {
        $this->orchestrator->register();

        if ( is_admin() ) {
            add_action( 'admin_post_oras_tickets_qbo_oauth_start', array( $this, 'handle_oauth_start' ) );
            add_action( 'admin_post_oras_tickets_qbo_oauth_callback', array( $this, 'handle_oauth_callback' ) );
            add_action( 'admin_post_oras_tickets_qbo_test_connection', array( $this, 'handle_test_connection' ) );
            add_action( 'admin_post_oras_tickets_qbo_test_journal_entry', array( $this, 'handle_test_journal_entry' ) );
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            Cli_Command::register( $this->orchestrator, $this->api_client );
        }
    }

    public function handle_oauth_start(): void {
        $this->assert_settings_access( 'oras_tickets_qbo_oauth_start' );
        $this->capture_posted_client_credentials();

        if ( ! $this->oauth_client->has_client_credentials() ) {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( 'Set QuickBooks Client ID and Client Secret first.' ),
                )
            );
        }

        $state = $this->generate_oauth_state();
        set_transient( 'oras_tickets_qbo_state_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );

        $url = $this->oauth_client->get_authorize_url( $state );
        $missing = $this->get_missing_authorize_params( $url );
        if ( ! empty( $missing ) ) {
            $this->logger->error(
                'QuickBooks OAuth authorize URL missing required parameters',
                array(
                    'missing' => $missing,
                    'url'     => $url,
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( 'QuickBooks OAuth authorization request is invalid. Missing: ' . implode( ', ', $missing ) ),
                )
            );
        }

        // OAuth authorization must redirect to Intuit's external domain.
        wp_redirect( $url ); // phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit
        exit;
    }

    public function handle_oauth_callback(): void {
        $state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $realm_id = isset( $_GET['realmId'] ) ? sanitize_text_field( wp_unslash( $_GET['realmId'] ) ) : '';

        if ( $state === '' || $code === '' || $realm_id === '' ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => 'QuickBooks OAuth callback is missing required fields.',
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( 'QuickBooks OAuth callback is missing required fields.' ),
                )
            );
        }

        $state_owner = get_transient( 'oras_tickets_qbo_state_' . $state );
        delete_transient( 'oras_tickets_qbo_state_' . $state );

        if ( ! $state_owner ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => 'QuickBooks OAuth state validation failed.',
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( 'QuickBooks OAuth state validation failed.' ),
                )
            );
        }

        $current_user_id = get_current_user_id();
        if ( $current_user_id > 0 && (int) $state_owner !== (int) $current_user_id ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => 'QuickBooks OAuth state owner mismatch.',
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( 'QuickBooks OAuth state owner mismatch.' ),
                )
            );
        }

        $exchange = $this->oauth_client->exchange_code( $code, $realm_id );
        if ( is_wp_error( $exchange ) ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $exchange->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( $exchange->get_error_message() ),
                )
            );
        }

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode( 'QuickBooks connected successfully.' ),
            )
        );
    }

    public function handle_test_connection(): void {
        $this->assert_settings_access( 'oras_tickets_qbo_test_connection' );

        $test = $this->api_client->test_connection();
        if ( is_wp_error( $test ) ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $test->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( $test->get_error_message() ),
                )
            );
        }

        $accounts = $this->api_client->fetch_accounts();
        if ( is_wp_error( $accounts ) ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $accounts->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( 'Connected but failed to refresh account list: ' . $accounts->get_error_message() ),
                )
            );
        }

        $account_cache = $this->extract_account_cache_rows( $accounts );
        Settings::update_quickbooks_settings(
            array(
                'account_cache' => $account_cache,
                'last_error'    => '',
            )
        );

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode( sprintf( 'QuickBooks test connection succeeded. Cached %d account(s).', count( $account_cache ) ) ),
            )
        );
    }

    public function handle_test_journal_entry(): void {
        $this->assert_settings_access( 'oras_tickets_qbo_test_journal_entry' );

        $settings          = Settings::get_quickbooks_settings();
        $clearing_account  = trim( (string) ( $settings['clearing_account_id'] ?? '' ) );
        $default_income    = trim( (string) ( $settings['tickets_default_account_id'] ?? '' ) );

        if ( $clearing_account === '' || $default_income === '' ) {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( 'Set Clearing Account and Default Ticket Income Account before running JE test.' ),
                )
            );
        }

        $amount  = 0.01;
        $payload = array(
            // QBO DocNumber max length is 21 chars.
            'DocNumber'   => 'ORASQBO' . gmdate( 'YmdHis' ),
            'TxnDate'     => gmdate( 'Y-m-d' ),
            'PrivateNote' => 'ORAS Tickets QuickBooks test JournalEntry',
            'Line'        => array(
                array(
                    'Amount'                 => $amount,
                    'Description'            => 'ORAS test debit',
                    'DetailType'             => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => array(
                        'PostingType' => 'Debit',
                        'AccountRef'  => array(
                            'value' => $clearing_account,
                        ),
                    ),
                ),
                array(
                    'Amount'                 => $amount,
                    'Description'            => 'ORAS test credit',
                    'DetailType'             => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => array(
                        'PostingType' => 'Credit',
                        'AccountRef'  => array(
                            'value' => $default_income,
                        ),
                    ),
                ),
            ),
        );

        $response = $this->api_client->create_journal_entry( $payload );
        if ( is_wp_error( $response ) ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $response->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode( $response->get_error_message() ),
                )
            );
        }

        $je_id = '';
        if ( isset( $response['JournalEntry']['Id'] ) ) {
            $je_id = (string) $response['JournalEntry']['Id'];
        }

        Settings::update_quickbooks_settings(
            array(
                'last_error' => '',
            )
        );

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode( 'QuickBooks test JournalEntry created successfully. ID: ' . $je_id ),
            )
        );
    }

    private function assert_settings_access( string $nonce_action ): void {
        if ( ! current_user_can( 'oras_tickets_manage_settings' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage ORAS Tickets settings.', 'oras-tickets' ), '', array( 'response' => 403 ) );
        }

        $nonce_key = '_wpnonce';
        if ( ! isset( $_REQUEST[ $nonce_key ] ) ) {
            wp_die( esc_html__( 'Missing security nonce.', 'oras-tickets' ), '', array( 'response' => 400 ) );
        }

        $nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_key ] ) );
        if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
            wp_die( esc_html__( 'Security check failed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
        }
    }

    /**
     * Persist credentials posted by the Connect action when the settings form
     * has not been submitted yet.
     */
    private function capture_posted_client_credentials(): void {
        $posted_client_id = isset( $_POST['oras_qbo_client_id'] )
            ? sanitize_text_field( (string) wp_unslash( $_POST['oras_qbo_client_id'] ) )
            : '';
        $posted_secret = isset( $_POST['oras_qbo_client_secret'] )
            ? sanitize_text_field( (string) wp_unslash( $_POST['oras_qbo_client_secret'] ) )
            : '';

        if ( $posted_client_id === '' && $posted_secret === '' ) {
            return;
        }

        $updates = array();

        if ( $posted_client_id !== '' ) {
            $updates['client_id'] = $posted_client_id;
        }

        if ( $posted_secret !== '' ) {
            $updates['client_secret'] = $posted_secret;
        }

        if ( empty( $updates ) ) {
            return;
        }

        Settings::update_quickbooks_settings( $updates );
    }

    /**
     * @param array<string,string> $args
     */
    private function redirect_to_settings( array $args = array() ): void {
        $url = add_query_arg(
            array_merge(
                array(
                    'page' => 'oras-tickets-quickbooks',
                ),
                $args
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $url );
        exit;
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
            $type  = isset( $account['AccountType'] ) ? (string) $account['AccountType'] : '';

            $cache[] = array(
                'id'    => $id,
                'label' => $label,
                'type'  => $type,
            );
        }

        return $cache;
    }

    /**
     * @return string[]
     */
    private function get_missing_authorize_params( string $url ): array {
        $query = wp_parse_url( $url, PHP_URL_QUERY );
        if ( ! is_string( $query ) || $query === '' ) {
            return array( 'client_id', 'response_type', 'scope', 'redirect_uri', 'state' );
        }

        $params = array();
        parse_str( $query, $params );

        $required = array( 'client_id', 'response_type', 'scope', 'redirect_uri', 'state' );
        $missing  = array();
        foreach ( $required as $key ) {
            if ( empty( $params[ $key ] ) ) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function generate_oauth_state(): string {
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( \Exception $e ) {
            return wp_generate_password( 32, false, false );
        }
    }
}
