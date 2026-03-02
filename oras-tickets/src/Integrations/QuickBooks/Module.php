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

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/Cli_Command.php'; // NOSONAR
}

if (! defined('ABSPATH')) {
    exit;
}

final class Module
{

    private QuickBooks_Logger $logger;
    private OAuth_Client $oauth_client;
    private Api_Client $api_client;
    private Sync_Orchestrator $orchestrator;

    public function __construct()
    {
        $this->logger       = new QuickBooks_Logger();
        $this->oauth_client = new OAuth_Client($this->logger);
        $this->api_client   = new Api_Client($this->oauth_client, $this->logger);
        $this->orchestrator = new Sync_Orchestrator(
            new Split_Calculator($this->logger),
            new Journal_Entry_Creator($this->api_client, $this->logger),
            new Retry_Handler($this->logger),
            $this->logger
        );
    }

    public function register(): void
    {
        $this->orchestrator->register();

        if (is_admin()) {
            add_action('admin_post_oras_tickets_qbo_oauth_start', array($this, 'handle_oauth_start'));
            add_action('admin_post_oras_tickets_qbo_oauth_callback', array($this, 'handle_oauth_callback'));
            add_action('admin_post_oras_tickets_qbo_test_connection', array($this, 'handle_test_connection'));
            add_action('admin_post_oras_tickets_qbo_test_journal_entry', array($this, 'handle_test_journal_entry'));
            add_action('admin_post_oras_tickets_qbo_process_waiting_queue', array($this, 'handle_process_waiting_queue'));
            add_action('admin_post_oras_tickets_qbo_sync_order_now', array($this, 'handle_sync_order_now'));
            add_action('admin_post_oras_tickets_qbo_approve_order', array($this, 'handle_approve_order'));
            add_action('admin_post_oras_tickets_qbo_reverse_order', array($this, 'handle_reverse_order'));
            add_action('admin_post_oras_tickets_qbo_resync_order', array($this, 'handle_resync_order'));
            add_action('admin_post_oras_tickets_qbo_auto_map_event_accounts', array($this, 'handle_auto_map_event_accounts'));
            add_action('save_post_tribe_events', array($this, 'handle_event_saved_auto_map'), 20, 3);
        }

        if (defined('WP_CLI') && WP_CLI) {
            Cli_Command::register($this->orchestrator, $this->api_client);
        }
    }

    public function handle_oauth_start(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_oauth_start');
        $this->capture_posted_client_credentials();

        if (! $this->oauth_client->has_client_credentials()) {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Set QuickBooks Client ID and Client Secret first.'),
                )
            );
        }

        $security_error = $this->get_production_security_error();
        if ($security_error !== '') {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $security_error,
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($security_error),
                )
            );
        }

        $state = $this->generate_oauth_state();
        set_transient('oras_tickets_qbo_state_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS);

        $url = $this->oauth_client->get_authorize_url($state);
        $missing = $this->get_missing_authorize_params($url);
        if (! empty($missing)) {
            $this->logger->error(
                'QuickBooks OAuth authorize URL missing required parameters',
                array(
                    'missing' => $missing,
                    'url'     => $url,
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('QuickBooks OAuth authorization request is invalid. Missing: ' . implode(', ', $missing)),
                )
            );
        }

        // OAuth authorization must redirect to Intuit's external domain.
        wp_redirect($url); // phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit
        exit;
    }

    public function handle_oauth_callback(): void
    {
        $state    = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code     = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $realm_id = isset($_GET['realmId']) ? sanitize_text_field(wp_unslash($_GET['realmId'])) : '';

        if ($state === '') {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => 'CSRF Error: missing OAuth state parameter.',
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('CSRF Error: missing OAuth state parameter.'),
                )
            );
        }

        if ($code === '' || $realm_id === '') {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => 'Auth Error Grant: QuickBooks OAuth callback is missing required grant fields.',
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Auth Error Grant: QuickBooks OAuth callback is missing required grant fields.'),
                )
            );
        }

        $state_owner = get_transient('oras_tickets_qbo_state_' . $state);
        delete_transient('oras_tickets_qbo_state_' . $state);

        if (! $state_owner) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => 'CSRF Error: QuickBooks OAuth state validation failed.',
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('CSRF Error: QuickBooks OAuth state validation failed.'),
                )
            );
        }

        $current_user_id = get_current_user_id();
        if ($current_user_id > 0 && (int) $state_owner !== (int) $current_user_id) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => 'CSRF Error: QuickBooks OAuth state owner mismatch.',
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('CSRF Error: QuickBooks OAuth state owner mismatch.'),
                )
            );
        }

        $exchange = $this->oauth_client->exchange_code($code, $realm_id);
        if (is_wp_error($exchange)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $exchange->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($exchange->get_error_message()),
                )
            );
        }

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode('QuickBooks connected successfully.'),
            )
        );
    }

    public function handle_test_connection(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_test_connection');

        $test = $this->api_client->test_connection();
        if (is_wp_error($test)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $test->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($test->get_error_message()),
                )
            );
        }

        $accounts = $this->api_client->fetch_accounts();
        if (is_wp_error($accounts)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $accounts->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Connected but failed to refresh account list: ' . $accounts->get_error_message()),
                )
            );
        }

        $account_cache = $this->extract_account_cache_rows($accounts);
        $auto_map_result = $this->auto_map_event_accounts($account_cache);
        $auto_added = 0;
        $auto_kept = 0;
        $auto_unmatched = 0;

        if (is_wp_error($auto_map_result)) {
            $this->logger->warning(
                'QuickBooks test connection succeeded but auto-map failed',
                array(
                    'error' => $auto_map_result->get_error_message(),
                )
            );
        } else {
            $auto_added = isset($auto_map_result['added']) ? (int) $auto_map_result['added'] : 0;
            $auto_kept = isset($auto_map_result['kept']) ? (int) $auto_map_result['kept'] : 0;
            $auto_unmatched = isset($auto_map_result['unmatched']) ? (int) $auto_map_result['unmatched'] : 0;
        }

        Settings::update_quickbooks_settings(
            array(
                'account_cache' => $account_cache,
                'last_error'    => '',
            )
        );

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode(sprintf('QuickBooks test connection succeeded. Cached %1$d account(s). Auto-map added %2$d, kept %3$d, unmatched %4$d.', count($account_cache), $auto_added, $auto_kept, $auto_unmatched)),
            )
        );
    }

    public function handle_test_journal_entry(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_test_journal_entry');

        $settings          = Settings::get_quickbooks_settings();
        $clearing_account  = trim((string) ($settings['clearing_account_id'] ?? ''));
        $default_income    = trim((string) ($settings['tickets_default_account_id'] ?? ''));

        if ($clearing_account === '' || $default_income === '') {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Set Clearing Account and Default Ticket Income Account before running JE test.'),
                )
            );
        }

        $amount  = 0.01;
        $payload = array(
            // QBO DocNumber max length is 21 chars.
            'DocNumber'   => 'ORASQBO' . gmdate('YmdHis'),
            'TxnDate'     => gmdate('Y-m-d'),
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

        $response = $this->api_client->create_journal_entry($payload);
        if (is_wp_error($response)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $response->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($response->get_error_message()),
                )
            );
        }

        $je_id = '';
        if (isset($response['JournalEntry']['Id'])) {
            $je_id = (string) $response['JournalEntry']['Id'];
        }

        Settings::update_quickbooks_settings(
            array(
                'last_error' => '',
            )
        );

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode('QuickBooks test JournalEntry created successfully. ID: ' . $je_id),
            )
        );
    }

    public function handle_process_waiting_queue(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_process_waiting_queue');
        $target_tab = $this->get_requested_quickbooks_tab('pending');

        $limit = isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 50;
        $limit = max(1, min(250, $limit));

        $processed = $this->orchestrator->process_waiting_orders($limit);
        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode(sprintf('Processed %d waiting order(s).', $processed)),
                'oras_qbo_tab'    => $target_tab,
            )
        );
    }

    public function handle_sync_order_now(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_sync_order_now');
        $target_tab = $this->get_requested_quickbooks_tab('pending');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($order_id <= 0) {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Provide a valid Woo order ID for sync.'),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $sync = $this->orchestrator->sync_order($order_id, false);
        if (is_wp_error($sync)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $sync->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($sync->get_error_message()),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $status = isset($sync['status']) ? (string) $sync['status'] : 'unknown';
        $je_id  = isset($sync['je_id']) ? (string) $sync['je_id'] : '';
        $notice = sprintf('Order #%1$d sync complete. Status: %2$s', $order_id, $status);
        if ($je_id !== '') {
            $notice .= ' (JE ID: ' . $je_id . ')';
        }

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode($notice),
                'oras_qbo_tab'    => $target_tab,
            )
        );
    }

    public function handle_approve_order(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_approve_order');
        $target_tab = $this->get_requested_quickbooks_tab('pending');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $sync_now = ! empty($_POST['sync_now']);
        if ($order_id <= 0) {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Provide a valid Woo order ID for approval.'),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $result = $this->orchestrator->approve_order_sync($order_id, $sync_now);
        if (is_wp_error($result)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $result->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($result->get_error_message()),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $status = isset($result['status']) ? (string) $result['status'] : 'approved';
        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode(sprintf('Order #%d approval complete. Status: %s', $order_id, $status)),
                'oras_qbo_tab'    => $target_tab,
            )
        );
    }

    public function handle_reverse_order(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_reverse_order');
        $target_tab = $this->get_requested_quickbooks_tab('pending');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($order_id <= 0) {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Provide a valid Woo order ID for reversal.'),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $force  = ! empty($_POST['force_reversal']);
        $result = $this->orchestrator->reverse_order($order_id, $force);
        if (is_wp_error($result)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $result->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($result->get_error_message()),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $status      = isset($result['status']) ? (string) $result['status'] : 'reversed';
        $reversal_je = isset($result['reversal_je_id']) ? (string) $result['reversal_je_id'] : '';
        $notice      = sprintf('Order #%1$d reversal status: %2$s', $order_id, $status);
        if ($reversal_je !== '') {
            $notice .= ' (JE ID: ' . $reversal_je . ')';
        }

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode($notice),
                'oras_qbo_tab'    => $target_tab,
            )
        );
    }

    public function handle_resync_order(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_resync_order');
        $target_tab = $this->get_requested_quickbooks_tab('pending');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($order_id <= 0) {
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Provide a valid Woo order ID for resync.'),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $reset = $this->orchestrator->reset_order_sync_state($order_id);
        if (is_wp_error($reset)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $reset->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($reset->get_error_message()),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $sync = $this->orchestrator->sync_order($order_id, false);
        if (is_wp_error($sync)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $sync->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($sync->get_error_message()),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $status = isset($sync['status']) ? (string) $sync['status'] : 'unknown';
        $je_id  = isset($sync['je_id']) ? (string) $sync['je_id'] : '';
        $notice = sprintf('Order #%1$d resync complete. Status: %2$s', $order_id, $status);
        if ($je_id !== '') {
            $notice .= ' (JE ID: ' . $je_id . ')';
        }

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode($notice),
                'oras_qbo_tab'    => $target_tab,
            )
        );
    }

    public function handle_auto_map_event_accounts(): void
    {
        $this->assert_settings_access('oras_tickets_qbo_auto_map_event_accounts');
        $target_tab = $this->get_requested_quickbooks_tab('settings');

        $accounts = $this->api_client->fetch_accounts();
        if (is_wp_error($accounts)) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $accounts->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode('Unable to fetch QuickBooks accounts for auto-map: ' . $accounts->get_error_message()),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $account_cache = $this->extract_account_cache_rows($accounts);
        $result        = $this->auto_map_event_accounts($account_cache);

        if (is_wp_error($result)) {
            Settings::update_quickbooks_settings(
                array(
                    'account_cache' => $account_cache,
                    'last_error'    => $result->get_error_message(),
                )
            );
            $this->redirect_to_settings(
                array(
                    'oras_qbo_error' => rawurlencode($result->get_error_message()),
                    'oras_qbo_tab'   => $target_tab,
                )
            );
        }

        $added     = isset($result['added']) ? (int) $result['added'] : 0;
        $kept      = isset($result['kept']) ? (int) $result['kept'] : 0;
        $unmatched = isset($result['unmatched']) ? (int) $result['unmatched'] : 0;

        $this->redirect_to_settings(
            array(
                'oras_qbo_notice' => rawurlencode(sprintf('Auto-map complete. Added %1$d new mapping(s); kept %2$d existing; unmatched events: %3$d.', $added, $kept, $unmatched)),
                'oras_qbo_tab'    => $target_tab,
            )
        );
    }

    /**
     * @param int $post_id
     * @param mixed $post
     * @param bool $update
     */
    public function handle_event_saved_auto_map(int $post_id, $post, bool $update): void
    {
        if (! $update || wp_is_post_revision($post_id)) {
            return;
        }

        if (! $post instanceof \WP_Post) {
            return;
        }

        if ($post->post_type !== 'tribe_events') {
            return;
        }

        if ($post->post_status !== 'publish' && $post->post_status !== 'future') {
            return;
        }

        $qbo_settings = Settings::get_quickbooks_settings();
        $account_cache = isset($qbo_settings['account_cache']) && is_array($qbo_settings['account_cache'])
            ? $qbo_settings['account_cache']
            : array();

        if (empty($account_cache)) {
            return;
        }

        $result = $this->auto_map_event_accounts($account_cache, array($post_id));
        if (is_wp_error($result)) {
            $this->logger->warning(
                'QuickBooks auto-map on event save failed',
                array(
                    'event_id' => $post_id,
                    'error'    => $result->get_error_message(),
                )
            );
        }
    }

    private function get_requested_quickbooks_tab(string $fallback = 'pending'): string
    {
        $tab = isset($_REQUEST['oras_qbo_tab']) ? sanitize_key((string) wp_unslash($_REQUEST['oras_qbo_tab'])) : $fallback;
        return in_array($tab, array('settings', 'pending', 'history'), true) ? $tab : $fallback;
    }

    private function assert_settings_access(string $nonce_action): void
    {
        if (! current_user_can('oras_tickets_manage_settings')) {
            wp_die(esc_html__('You do not have permission to manage ORAS Tickets settings.', 'oras-tickets'), '', array('response' => 403));
        }

        $nonce_key = '_wpnonce';
        if (! isset($_REQUEST[$nonce_key])) {
            wp_die(esc_html__('Missing security nonce.', 'oras-tickets'), '', array('response' => 400));
        }

        $nonce = sanitize_text_field(wp_unslash($_REQUEST[$nonce_key]));
        if (! wp_verify_nonce($nonce, $nonce_action)) {
            wp_die(esc_html__('Security check failed.', 'oras-tickets'), '', array('response' => 403));
        }
    }

    /**
     * Persist credentials posted by the Connect action when the settings form
     * has not been submitted yet.
     */
    private function capture_posted_client_credentials(): void
    {
        $posted_client_id = isset($_POST['oras_qbo_client_id'])
            ? sanitize_text_field((string) wp_unslash($_POST['oras_qbo_client_id']))
            : '';
        $posted_secret = isset($_POST['oras_qbo_client_secret'])
            ? sanitize_text_field((string) wp_unslash($_POST['oras_qbo_client_secret']))
            : '';

        if ($posted_client_id === '' && $posted_secret === '') {
            return;
        }

        $updates = array();

        if ($posted_client_id !== '') {
            $updates['client_id'] = $posted_client_id;
        }

        if ($posted_secret !== '') {
            $updates['client_secret'] = $posted_secret;
        }

        if (empty($updates)) {
            return;
        }

        Settings::update_quickbooks_settings($updates);
    }

    /**
     * @param array<string,string> $args
     */
    private function redirect_to_settings(array $args = array()): void
    {
        $url = add_query_arg(
            array_merge(
                array(
                    'page' => 'oras-tickets-quickbooks',
                ),
                $args
            ),
            admin_url('admin.php')
        );

        /**
         * Allow tests/observers to capture QuickBooks settings redirects.
         *
         * @param string               $url  Redirect URL.
         * @param array<string,string> $args Redirect query args.
         */
        do_action('oras_tickets_qbo_redirecting', $url, $args);

        wp_safe_redirect($url);

        /**
         * Filter whether QuickBooks admin redirects should terminate execution.
         * Default true to preserve production behavior.
         *
         * @param bool                 $should_exit Default true.
         * @param string               $url         Redirect URL.
         * @param array<string,string> $args        Redirect query args.
         */
        $should_exit = (bool) apply_filters('oras_tickets_qbo_exit_after_redirect', true, $url, $args);
        if ($should_exit) {
            exit;
        }
    }

    /**
     * @param array<string,mixed> $accounts_response
     * @return array<int,array<string,string>>
     */
    private function extract_account_cache_rows(array $accounts_response): array
    {
        $accounts = isset($accounts_response['QueryResponse']['Account']) && is_array($accounts_response['QueryResponse']['Account'])
            ? $accounts_response['QueryResponse']['Account']
            : array();

        $cache = array();
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $id = isset($account['Id']) ? (string) $account['Id'] : '';
            if ($id === '') {
                continue;
            }

            $label = isset($account['FullyQualifiedName']) ? (string) $account['FullyQualifiedName'] : (string) ($account['Name'] ?? $id);
            $type  = isset($account['AccountType']) ? (string) $account['AccountType'] : '';

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
    private function get_missing_authorize_params(string $url): array
    {
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return array('client_id', 'response_type', 'scope', 'redirect_uri', 'state');
        }

        $params = array();
        parse_str($query, $params);

        $required = array('client_id', 'response_type', 'scope', 'redirect_uri', 'state');
        $missing  = array();
        foreach ($required as $key) {
            if (empty($params[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function generate_oauth_state(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return wp_generate_password(32, false, false);
        }
    }

    /**
     * @param array<int,array<string,string>> $account_cache
     * @return array<string,string>
     */
    private function build_income_account_match_index(array $account_cache): array
    {
        $index = array();

        foreach ($account_cache as $row) {
            if (! is_array($row)) {
                continue;
            }

            $account_id = isset($row['id']) ? trim((string) $row['id']) : '';
            $type       = isset($row['type']) ? strtolower(trim((string) $row['type'])) : '';
            $label      = isset($row['label']) ? trim((string) $row['label']) : '';

            if ($account_id === '' || $label === '') {
                continue;
            }

            if ($type !== 'income' && $type !== 'other income') {
                continue;
            }

            $normalized_label = $this->normalize_auto_map_label($label);
            if ($normalized_label !== '' && ! isset($index[$normalized_label])) {
                $index[$normalized_label] = $account_id;
            }

            $parts = array_filter(array_map('trim', explode(':', $label)));
            if (! empty($parts)) {
                $leaf = (string) end($parts);
                $normalized_leaf = $this->normalize_auto_map_label($leaf);
                if ($normalized_leaf !== '' && ! isset($index[$normalized_leaf])) {
                    $index[$normalized_leaf] = $account_id;
                }
            }
        }

        return $index;
    }

    private function normalize_auto_map_label(string $value): string
    {
        $value = strtolower(wp_strip_all_tags($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value);
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<int,array<string,string>> $account_cache
     * @param array<int,int>|null $event_ids
     * @return array<string,int>|\WP_Error
     */
    private function auto_map_event_accounts(array $account_cache, ?array $event_ids = null)
    {
        $income_match_index = $this->build_income_account_match_index($account_cache);
        if (empty($income_match_index)) {
            return new \WP_Error('oras_qbo_auto_map_no_income_accounts', 'No active QuickBooks income accounts were found to auto-map events.');
        }

        $query_args = array(
            'post_type'      => 'tribe_events',
            'post_status'    => array('publish', 'future'),
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if (is_array($event_ids) && ! empty($event_ids)) {
            $query_args['post__in'] = array_values(array_unique(array_map('absint', $event_ids)));
            $query_args['orderby']  = 'post__in';
        }

        $events = get_posts($query_args);
        if (! is_array($events) || empty($events)) {
            Settings::update_quickbooks_settings(
                array(
                    'account_cache' => $account_cache,
                    'last_error'    => '',
                )
            );

            return array(
                'added'     => 0,
                'kept'      => 0,
                'unmatched' => 0,
            );
        }

        $qbo_settings   = Settings::get_quickbooks_settings();
        $existing_map   = Settings::parse_event_account_map((string) ($qbo_settings['event_account_map'] ?? ''));
        $new_pairs      = array();
        $kept_existing  = 0;
        $unmatched      = 0;

        foreach ($events as $event_id) {
            $event_id = absint($event_id);
            if ($event_id <= 0) {
                continue;
            }

            $event_post = get_post($event_id);
            if (! $event_post instanceof \WP_Post) {
                continue;
            }

            $event_slug = sanitize_title((string) $event_post->post_name);
            if ($event_slug === '') {
                $event_slug = sanitize_title((string) $event_post->post_title);
            }

            if ($event_slug === '') {
                continue;
            }

            if (isset($existing_map[$event_slug]) && trim((string) $existing_map[$event_slug]) !== '') {
                $kept_existing++;
                continue;
            }

            $event_title           = trim((string) $event_post->post_title);
            $normalized_event      = $this->normalize_auto_map_label($event_title);
            $normalized_event_slug = $this->normalize_auto_map_label(str_replace('-', ' ', $event_slug));

            $matched_account_id = '';
            if ($normalized_event !== '' && isset($income_match_index[$normalized_event])) {
                $matched_account_id = (string) $income_match_index[$normalized_event];
            } elseif ($normalized_event_slug !== '' && isset($income_match_index[$normalized_event_slug])) {
                $matched_account_id = (string) $income_match_index[$normalized_event_slug];
            }

            if ($matched_account_id === '') {
                $unmatched++;
                continue;
            }

            $new_pairs[$event_slug] = $matched_account_id;
        }

        if (! empty($new_pairs)) {
            $merged_map = $existing_map;
            foreach ($new_pairs as $slug => $account_id) {
                if (! isset($merged_map[$slug]) || trim((string) $merged_map[$slug]) === '') {
                    $merged_map[$slug] = (string) $account_id;
                }
            }

            Settings::update_quickbooks_settings(
                array(
                    'event_account_map' => $this->serialize_event_account_map($merged_map),
                    'account_cache'     => $account_cache,
                    'last_error'        => '',
                )
            );
        } else {
            Settings::update_quickbooks_settings(
                array(
                    'account_cache' => $account_cache,
                    'last_error'    => '',
                )
            );
        }

        return array(
            'added'     => count($new_pairs),
            'kept'      => $kept_existing,
            'unmatched' => $unmatched,
        );
    }

    /**
     * @param array<string,string> $event_account_map
     */
    private function serialize_event_account_map(array $event_account_map): string
    {
        $lines = array();
        ksort($event_account_map);

        foreach ($event_account_map as $slug => $account_id) {
            $clean_slug       = sanitize_title((string) $slug);
            $clean_account_id = trim(sanitize_text_field((string) $account_id));
            if ($clean_slug === '' || $clean_account_id === '') {
                continue;
            }

            $lines[] = $clean_slug . '=' . $clean_account_id;
        }

        return implode("\n", $lines);
    }

    private function get_production_security_error(): string
    {
        if (Settings::is_sandbox()) {
            return '';
        }

        $redirect_uri = Settings::get_redirect_uri();
        if (stripos($redirect_uri, 'https://') !== 0) {
            return 'QuickBooks production OAuth requires an HTTPS redirect URI.';
        }

        if (! Settings::has_explicit_encryption_key()) {
            return 'Define ORAS_TICKETS_QBO_AES_KEY in wp-config.php before connecting QuickBooks in production mode.';
        }

        return '';
    }
}
