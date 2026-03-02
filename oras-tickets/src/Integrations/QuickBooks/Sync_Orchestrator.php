<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Orchestrator
{

    public const ACTION_HOOK = 'oras_tickets_qbo_sync_order';
    public const ACTION_WAITING_SWEEP_HOOK = 'oras_tickets_qbo_waiting_queue_sweep';
    private const AS_GROUP    = 'oras-tickets';
    private const META_SYNCED = '_oras_qbo_synced';
    private const META_APPROVED_AT = '_oras_qbo_manual_approved_at';
    private const META_DOC_NUMBER = '_oras_qbo_doc_number';
    private const META_LAST_INTUIT_TID = '_oras_qbo_last_intuit_tid';
    private const META_SPLIT_SNAPSHOT = '_oras_qbo_split_snapshot';
    private const META_WAIT_FIRST_AT = '_oras_qbo_wait_first_at';
    private const META_WAIT_LAST_CHECK_AT = '_oras_qbo_wait_last_check_at';
    private const META_WAIT_NEXT_CHECK_AT = '_oras_qbo_wait_next_check_at';
    private const META_WAIT_ATTEMPTS = '_oras_qbo_wait_attempts';

    private Split_Calculator $split_calculator;
    private Journal_Entry_Creator $journal_entry_creator;
    private Retry_Handler $retry_handler;
    private QuickBooks_Logger $logger;

    public function __construct(
        ?Split_Calculator $split_calculator = null,
        ?Journal_Entry_Creator $journal_entry_creator = null,
        ?Retry_Handler $retry_handler = null,
        ?QuickBooks_Logger $logger = null
    ) {
        $this->logger                = $logger ?: new QuickBooks_Logger();
        $this->split_calculator      = $split_calculator ?: new Split_Calculator($this->logger);
        $this->journal_entry_creator = $journal_entry_creator ?: new Journal_Entry_Creator(null, $this->logger);
        $this->retry_handler         = $retry_handler ?: new Retry_Handler($this->logger);
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_completed', array($this, 'enqueue_order_sync'), 10, 1);
        add_action(self::ACTION_HOOK, array($this, 'sync_order_async'), 10, 1);
        add_action(self::ACTION_WAITING_SWEEP_HOOK, array($this, 'process_waiting_queue_async'));
        $this->ensure_waiting_queue_schedule();
    }

    public function process_waiting_queue_async(): void
    {
        $this->process_waiting_orders(50);
    }

    public function enqueue_order_sync(int $order_id): void
    {
        if (! Settings::is_enabled()) {
            return;
        }

        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return;
        }

        if ($this->has_scheduled_action($order_id)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $queue_guard_error = $this->validate_sync_safeguards($order);
        if (is_wp_error($queue_guard_error)) {
            $this->clear_queue_state($order);
            $this->clear_scheduled_actions((int) $order->get_id());
            $this->append_audit_entry(
                $order,
                'queue_skipped_by_safeguard',
                array(
                    'reason' => $queue_guard_error->get_error_message(),
                    'code'   => $queue_guard_error->get_error_code(),
                )
            );
            $this->logger->warning(
                'QuickBooks sync queue skipped by safeguard',
                array(
                    'order_id' => (int) $order->get_id(),
                    'reason'   => $queue_guard_error->get_error_message(),
                    'code'     => $queue_guard_error->get_error_code(),
                )
            );
            return;
        }

        $qbo_settings = Settings::get_quickbooks_settings();
        if ($order->get_meta('_oras_qbo_je_id', true)) {
            if ($this->requires_reclass_migration($order, $qbo_settings)) {
                $order->update_meta_data('_oras_qbo_sync_status', 'migration_required');
                $order->save();
                $this->append_audit_entry($order, 'reclass_migration_required', array());
                $this->logger->warning(
                    'QuickBooks sync migration required for legacy order in reclass mode',
                    array(
                        'order_id' => (int) $order->get_id(),
                        'doc_number' => (string) $order->get_meta(self::META_DOC_NUMBER, true),
                    )
                );
            }
            return;
        }

        if ($this->should_require_manual_approval($qbo_settings) && trim((string) $order->get_meta(self::META_APPROVED_AT, true)) === '') {
            $order->update_meta_data('_oras_qbo_sync_status', 'pending_qbo_review');
            $order->save();
            $this->append_audit_entry($order, 'queued_for_manual_review', array());
            return;
        }

        if (trim((string) $order->get_meta(self::META_APPROVED_AT, true)) === '') {
            $order->update_meta_data(self::META_APPROVED_AT, gmdate('Y-m-d H:i:s'));
        }

        $order->update_meta_data('_oras_qbo_sync_status', 'queued');
        $order->save();

        $this->append_audit_entry($order, 'queued_for_sync', array());
        $this->schedule_sync($order_id, $this->get_initial_sync_delay_minutes());
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function approve_order_sync(int $order_id, bool $sync_now = false)
    {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return new \WP_Error('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return new \WP_Error('oras_qbo_order_not_found', 'WooCommerce order not found.');
        }

        $guard_error = $this->validate_sync_safeguards($order);
        if (is_wp_error($guard_error)) {
            return $guard_error;
        }

        $order->update_meta_data(self::META_APPROVED_AT, gmdate('Y-m-d H:i:s'));
        $order->update_meta_data('_oras_qbo_sync_status', 'approved_for_sync');
        $order->save();

        $this->append_audit_entry($order, 'manual_approval_granted', array());

        if ($sync_now) {
            return $this->sync_order($order_id, false);
        }

        if (! $this->has_scheduled_action($order_id)) {
            $this->schedule_sync($order_id, $this->get_initial_sync_delay_minutes());
        }

        return array(
            'status'   => 'approved_and_queued',
            'order_id' => $order_id,
        );
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function reverse_order(int $order_id, bool $force = false)
    {
        if ($order_id <= 0) {
            return new \WP_Error('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

        if (! Settings::is_enabled()) {
            return new \WP_Error('oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.');
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return new \WP_Error('oras_qbo_order_not_found', 'WooCommerce order not found.');
        }

        $original_je_id = trim((string) $order->get_meta('_oras_qbo_je_id', true));
        if ($original_je_id === '') {
            return new \WP_Error('oras_qbo_no_je_to_reverse', 'Order has no synced JournalEntry to reverse.');
        }

        $existing_reversal = trim((string) $order->get_meta('_oras_qbo_reversal_je_id', true));
        if ($existing_reversal !== '' && ! $force) {
            return new \WP_Error('oras_qbo_already_reversed', 'Order already has a reversal JournalEntry.');
        }

        $snapshot_raw = (string) $order->get_meta(self::META_SPLIT_SNAPSHOT, true);
        $snapshot     = json_decode($snapshot_raw, true);
        if (! is_array($snapshot) || empty($snapshot['lines']) || ! isset($snapshot['split_total'])) {
            return new \WP_Error('oras_qbo_missing_split_snapshot', 'Cannot reverse: split snapshot is missing from the order sync metadata.');
        }

        $qbo_settings   = Settings::get_quickbooks_settings();
        $prepared       = $this->journal_entry_creator->build_payload_for_order($order, $snapshot, $qbo_settings, true, $original_je_id);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $doc_number = isset($prepared['doc_number']) ? (string) $prepared['doc_number'] : '';
        if ($doc_number !== '') {
            $existing = $this->journal_entry_creator->find_existing_journal_entry($doc_number);
            if (! is_wp_error($existing) && ! empty($existing['found'])) {
                $entry = isset($existing['entry']) && is_array($existing['entry']) ? $existing['entry'] : array();
                $reversal_id = isset($entry['Id']) ? (string) $entry['Id'] : '';
                if ($reversal_id !== '') {
                    $order->update_meta_data('_oras_qbo_reversal_je_id', $reversal_id);
                    $order->update_meta_data('_oras_qbo_reversal_at', gmdate('Y-m-d H:i:s'));
                    $order->update_meta_data('_oras_qbo_sync_status', 'reversed');
                    $order->save();

                    $this->append_audit_entry(
                        $order,
                        'reversal_detected_existing',
                        array(
                            'reversal_je_id' => $reversal_id,
                            'doc_number'     => $doc_number,
                        )
                    );

                    return array(
                        'status'         => 'already_reversed_remote',
                        'order_id'       => (int) $order->get_id(),
                        'reversal_je_id' => $reversal_id,
                    );
                }
            }
        }

        if (! empty($qbo_settings['dry_run_mode'])) {
            $order->update_meta_data('_oras_qbo_sync_status', 'reversal_dry_run');
            $order->save();

            $this->append_audit_entry(
                $order,
                'reversal_dry_run_payload_ready',
                array(
                    'doc_number' => $doc_number,
                )
            );

            return array(
                'status'     => 'reversal_dry_run',
                'order_id'   => (int) $order->get_id(),
                'doc_number' => $doc_number,
                'payload'    => isset($prepared['payload']) ? (array) $prepared['payload'] : array(),
            );
        }

        $reversal = $this->journal_entry_creator->create_reversal_for_order($order, $snapshot, $qbo_settings, $original_je_id);
        if (is_wp_error($reversal)) {
            return $reversal;
        }

        $reversal_je_id = isset($reversal['je_id']) ? (string) $reversal['je_id'] : '';
        $intuit_tid     = isset($reversal['intuit_tid']) ? (string) $reversal['intuit_tid'] : '';

        $order->update_meta_data('_oras_qbo_reversal_je_id', $reversal_je_id);
        $order->update_meta_data('_oras_qbo_reversal_at', gmdate('Y-m-d H:i:s'));
        $order->update_meta_data('_oras_qbo_sync_status', 'reversed');
        $order->update_meta_data(self::META_LAST_INTUIT_TID, $intuit_tid);
        $order->save();

        $this->append_audit_entry(
            $order,
            'reversal_synced',
            array(
                'reversal_je_id' => $reversal_je_id,
                'doc_number'     => isset($reversal['doc_number']) ? (string) $reversal['doc_number'] : '',
                'intuit_tid'     => $intuit_tid,
            )
        );

        return array(
            'status'         => 'reversed',
            'order_id'       => (int) $order->get_id(),
            'reversal_je_id' => $reversal_je_id,
        );
    }

    public function sync_order_async(int $order_id): void
    {
        $this->sync_order(absint($order_id));
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function sync_order(int $order_id, bool $force = false)
    {
        if ($order_id <= 0) {
            return new \WP_Error('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

        if (! Settings::is_enabled()) {
            return new \WP_Error('oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.');
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return new \WP_Error('oras_qbo_order_not_found', 'WooCommerce order not found.');
        }

        $guard_error = $this->validate_sync_safeguards($order);
        if (is_wp_error($guard_error)) {
            $this->clear_queue_state($order);
            return $guard_error;
        }

        $qbo_settings = Settings::get_quickbooks_settings();
        if (! $force && $this->requires_reclass_migration($order, $qbo_settings)) {
            $order->update_meta_data('_oras_qbo_sync_status', 'migration_required');
            $order->save();
            $this->append_audit_entry($order, 'reclass_migration_required', array());
            return new \WP_Error(
                'oras_qbo_reclass_migration_required',
                'Order was already synced with legacy clearing mode and requires migration before reclass sync. Use resync-order.'
            );
        }

        $is_dry_run   = ! empty($qbo_settings['dry_run_mode']);
        if (! $force && $this->should_require_manual_approval($qbo_settings) && trim((string) $order->get_meta(self::META_APPROVED_AT, true)) === '') {
            $order->update_meta_data('_oras_qbo_sync_status', 'pending_qbo_review');
            $order->save();
            $this->append_audit_entry($order, 'sync_blocked_manual_approval_required', array());
            return new \WP_Error('oras_qbo_manual_approval_required', 'Order requires manual approval before QuickBooks sync.');
        }

        $order_hash    = $this->build_order_hash($order);
        $existing_je   = (string) $order->get_meta('_oras_qbo_je_id', true);
        $existing_hash = (string) $order->get_meta('_oras_qbo_je_hash', true);

        if (! $force && $existing_je !== '' && $existing_hash === $order_hash) {
            $order->update_meta_data('_oras_qbo_sync_status', 'synced');
            $order->save();
            return array(
                'status' => 'already_synced',
                'je_id'  => $existing_je,
            );
        }

        if (! $force && $existing_je !== '' && $existing_hash !== '' && $existing_hash !== $order_hash) {
            $order->update_meta_data('_oras_qbo_sync_status', 'changed_after_sync');
            $order->save();
            return new \WP_Error(
                'oras_qbo_already_synced_changed',
                'Order was already synced to QuickBooks and changed afterwards. Manual review is required.'
            );
        }

        $order->update_meta_data('_oras_qbo_sync_status', 'syncing');
        $order->update_meta_data('_oras_qbo_last_attempt_at', gmdate('Y-m-d H:i:s'));
        $order->update_meta_data(self::META_WAIT_LAST_CHECK_AT, gmdate('Y-m-d H:i:s'));
        $order->save();

        $this->append_audit_entry($order, 'sync_started', array());

        $split = $this->split_calculator->calculate($order, $qbo_settings);
        if (is_wp_error($split)) {
            $this->handle_sync_failure($order, $split);
            return $split;
        }

        if (! empty($qbo_settings['strict_mapping_mode'])) {
            $warnings              = isset($split['warnings']) && is_array($split['warnings']) ? $split['warnings'] : array();
            $unmapped_lines        = (int) ($split['unmapped_lines'] ?? 0);
            $missing_account_lines = (int) ($split['missing_account_lines'] ?? 0);
            if ($unmapped_lines > 0 || $missing_account_lines > 0 || ! empty($warnings)) {
                $error = new \WP_Error(
                    'oras_qbo_strict_mapping_failed',
                    'Strict mapping mode blocked sync: order contains unmapped or unresolved account lines.'
                );
                $error->add_data(
                    array(
                        'unmapped_lines'        => $unmapped_lines,
                        'missing_account_lines' => $missing_account_lines,
                        'warnings'              => $warnings,
                        'retriable'             => false,
                    )
                );
                $this->handle_sync_failure($order, $error);
                return $error;
            }
        }

        $prepared = $this->journal_entry_creator->build_payload_for_order($order, $split, $qbo_settings, false);
        if (is_wp_error($prepared)) {
            $this->handle_sync_failure($order, $prepared);
            return $prepared;
        }

        $doc_number = isset($prepared['doc_number']) ? (string) $prepared['doc_number'] : '';
        if (! $is_dry_run && $doc_number !== '' && $existing_je === '') {
            $existing_remote = $this->journal_entry_creator->find_existing_journal_entry($doc_number);
            if (is_wp_error($existing_remote)) {
                if (! empty($qbo_settings['strict_mapping_mode'])) {
                    $error = new \WP_Error(
                        'oras_qbo_duplicate_lookup_failed',
                        'Duplicate check failed before write; sync blocked in strict mode. ' . $existing_remote->get_error_message()
                    );
                    $error->add_data($existing_remote->get_error_data());
                    $this->handle_sync_failure($order, $error);
                    return $error;
                }
            } elseif (! empty($existing_remote['found'])) {
                $entry         = isset($existing_remote['entry']) && is_array($existing_remote['entry']) ? $existing_remote['entry'] : array();
                $existing_id   = isset($entry['Id']) ? (string) $entry['Id'] : '';
                $existing_meta = isset($existing_remote['meta']) && is_array($existing_remote['meta']) ? $existing_remote['meta'] : array();
                $intuit_tid    = isset($existing_meta['intuit_tid']) ? (string) $existing_meta['intuit_tid'] : '';

                if ($existing_id !== '') {
                    $order->update_meta_data('_oras_qbo_je_id', $existing_id);
                    $order->update_meta_data('_oras_qbo_je_hash', $order_hash);
                    $order->update_meta_data('_oras_qbo_sync_status', 'synced');
                    $order->update_meta_data(self::META_SYNCED, gmdate('Y-m-d H:i:s'));
                    $order->update_meta_data('_oras_qbo_synced_at', gmdate('Y-m-d H:i:s'));
                    $order->update_meta_data(self::META_DOC_NUMBER, $doc_number);
                    $order->update_meta_data(self::META_LAST_INTUIT_TID, $intuit_tid);
                    $this->deleteOrderMeta($order, '_oras_qbo_sync_error');
                    $order->save();

                    $this->retry_handler->mark_success($order);
                    $this->append_audit_entry(
                        $order,
                        'duplicate_detected_remote',
                        array(
                            'je_id'      => $existing_id,
                            'doc_number' => $doc_number,
                            'intuit_tid' => $intuit_tid,
                        )
                    );

                    return array(
                        'status'   => 'already_synced_remote',
                        'order_id' => $order_id,
                        'je_id'    => $existing_id,
                    );
                }
            }
        }

        if ($is_dry_run) {
            $order->update_meta_data('_oras_qbo_sync_status', 'dry_run');
            $order->update_meta_data(self::META_DOC_NUMBER, $doc_number);
            $order->update_meta_data('_oras_qbo_last_preview_at', gmdate('Y-m-d H:i:s'));
            $order->save();

            $this->append_audit_entry(
                $order,
                'dry_run_payload_ready',
                array(
                    'doc_number' => $doc_number,
                    'split_total' => (float) ($split['split_total'] ?? 0.0),
                )
            );

            return array(
                'status'     => 'dry_run',
                'order_id'   => $order_id,
                'doc_number' => $doc_number,
                'payload'    => isset($prepared['payload']) && is_array($prepared['payload']) ? $prepared['payload'] : array(),
                'split'      => $split,
            );
        }

        $result = $this->journal_entry_creator->create_for_order($order, $split, $qbo_settings);
        if (is_wp_error($result)) {
            $this->handle_sync_failure($order, $result);
            return $result;
        }

        $je_id = isset($result['je_id']) ? (string) $result['je_id'] : '';
        if ($je_id === '') {
            $error = new \WP_Error('oras_qbo_missing_je_id', 'QuickBooks sync completed without a JournalEntry ID.');
            $this->handle_sync_failure($order, $error);
            return $error;
        }

        $intuit_tid = isset($result['intuit_tid']) ? (string) $result['intuit_tid'] : '';
        $payload    = isset($prepared['payload']) && is_array($prepared['payload']) ? $prepared['payload'] : array();
        $source_match = isset($prepared['source_match']) && is_array($prepared['source_match']) ? $prepared['source_match'] : array();

        $snapshot = array(
            'lines'         => isset($split['lines']) && is_array($split['lines']) ? $split['lines'] : array(),
            'split_total'   => round((float) ($split['split_total'] ?? 0.0), 2),
            'discount_mode' => (string) ($split['discount_mode'] ?? 'proportional'),
        );

        $order->update_meta_data('_oras_qbo_je_id', $je_id);
        $order->update_meta_data('_oras_qbo_je_hash', $order_hash);
        $order->update_meta_data('_oras_qbo_sync_status', 'synced');
        $order->update_meta_data(self::META_SYNCED, gmdate('Y-m-d H:i:s'));
        $order->update_meta_data('_oras_qbo_synced_at', gmdate('Y-m-d H:i:s'));
        $order->update_meta_data(self::META_DOC_NUMBER, $doc_number);
        $order->update_meta_data(self::META_LAST_INTUIT_TID, $intuit_tid);
        $order->update_meta_data(self::META_SPLIT_SNAPSHOT, wp_json_encode($snapshot));
        $order->update_meta_data('_oras_qbo_last_payload_hash', hash('sha256', wp_json_encode($payload) ?: ''));
        $this->deleteOrderMeta($order, self::META_WAIT_NEXT_CHECK_AT);
        $this->deleteOrderMeta($order, self::META_WAIT_LAST_CHECK_AT);
        $this->deleteOrderMeta($order, self::META_WAIT_FIRST_AT);
        $this->deleteOrderMeta($order, self::META_WAIT_ATTEMPTS);

        if (! empty($source_match)) {
            $order->update_meta_data('_oras_qbo_reclass_source_txn_key', (string) ($source_match['key'] ?? ''));
            $order->update_meta_data('_oras_qbo_reclass_source_txn_id', (string) ($source_match['id'] ?? ''));
            $order->update_meta_data('_oras_qbo_reclass_source_txn_type', (string) ($source_match['entity'] ?? ''));
            $order->update_meta_data('_oras_qbo_reclass_source_txn_date', (string) ($source_match['txn_date'] ?? ''));
        }

        $this->deleteOrderMeta($order, '_oras_qbo_sync_error');
        $this->deleteOrderMeta($order, '_oras_qbo_sync_error_code');
        $order->save();

        $this->retry_handler->mark_success($order);
        $this->append_audit_entry(
            $order,
            'sync_success',
            array(
                'je_id'      => $je_id,
                'doc_number' => $doc_number,
                'intuit_tid' => $intuit_tid,
                'source_txn' => isset($source_match['key']) ? (string) $source_match['key'] : '',
            )
        );

        return array(
            'status'   => 'synced',
            'order_id' => $order_id,
            'je_id'    => $je_id,
            'split'    => $split,
        );
    }

    /**
     * Queue a retry for failed entries.
     */
    public function retry_failed_orders(int $limit = 25): int
    {
        if (! function_exists('wc_get_orders')) {
            return 0;
        }

        $order_ids = wc_get_orders(
            array(
                'type'       => 'shop_order',
                'limit'      => max(1, $limit),
                'return'     => 'ids',
                'meta_key'   => '_oras_qbo_sync_status',
                'meta_value' => 'failed',
                'orderby'    => 'date',
                'order'      => 'DESC',
            )
        );

        $count = 0;
        foreach ($order_ids as $order_id) {
            $order_id = absint($order_id);
            if ($order_id <= 0) {
                continue;
            }

            $order = wc_get_order($order_id);
            if (! $order) {
                continue;
            }

            if (is_wp_error($this->validate_sync_safeguards($order))) {
                continue;
            }

            $qbo_settings = Settings::get_quickbooks_settings();
            if ($this->should_require_manual_approval($qbo_settings) && trim((string) $order->get_meta(self::META_APPROVED_AT, true)) === '') {
                continue;
            }

            $this->schedule_sync($order_id, 0);
            $count++;
        }

        return $count;
    }

    /**
     * Process orders currently waiting for a Stripe-posted source transaction
     * in QuickBooks and attempt sync again.
     */
    public function process_waiting_orders(int $limit = 50): int
    {
        if (! function_exists('wc_get_orders')) {
            return 0;
        }

        $order_ids = wc_get_orders(
            array(
                'type'       => 'shop_order',
                'limit'      => max(1, $limit),
                'return'     => 'ids',
                'meta_key'   => '_oras_qbo_sync_status',
                'meta_value' => 'waiting_for_source_txn',
                'orderby'    => 'date',
                'order'      => 'ASC',
            )
        );

        $processed = 0;
        foreach ($order_ids as $order_id) {
            $order_id = absint($order_id);
            if ($order_id <= 0) {
                continue;
            }

            if ($this->has_scheduled_action($order_id)) {
                continue;
            }

            $result = $this->sync_order($order_id, false);
            if (! is_wp_error($result)) {
                $processed++;
                continue;
            }

            // Count as processed when we actively re-checked and remained waiting.
            if ($result->get_error_code() === 'oras_qbo_reclass_source_not_found') {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Reset local QuickBooks sync metadata for one order so it can be re-synced
     * under a different posting mode (for example, migrating to reclass mode).
     *
     * @return true|\WP_Error
     */
    public function reset_order_sync_state(int $order_id)
    {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return new \WP_Error('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return new \WP_Error('oras_qbo_order_not_found', 'WooCommerce order not found.');
        }

        $meta_keys = array(
            '_oras_qbo_synced',
            '_oras_qbo_synced_at',
            '_oras_qbo_je_id',
            '_oras_qbo_je_hash',
            '_oras_qbo_doc_number',
            '_oras_qbo_last_payload_hash',
            '_oras_qbo_sync_status',
            '_oras_qbo_sync_error',
            '_oras_qbo_sync_error_code',
            '_oras_qbo_retry_count',
            '_oras_qbo_reclass_source_txn_key',
            '_oras_qbo_reclass_source_txn_id',
            '_oras_qbo_reclass_source_txn_type',
            '_oras_qbo_reclass_source_txn_date',
            self::META_WAIT_FIRST_AT,
            self::META_WAIT_LAST_CHECK_AT,
            self::META_WAIT_NEXT_CHECK_AT,
            self::META_WAIT_ATTEMPTS,
        );

        foreach ($meta_keys as $meta_key) {
            $this->deleteOrderMeta($order, $meta_key);
        }

        $order->save();
        $this->clear_scheduled_actions($order_id);

        $this->append_audit_entry($order, 'sync_state_reset', array());

        return true;
    }

    /**
     * @param \WC_Order $order
     * @param string|\WP_Error $error
     */
    private function handle_sync_failure($order, $error): void
    {
        $error_message = is_wp_error($error) ? (string) $error->get_error_message() : (string) $error;
        $error_code    = is_wp_error($error) ? (string) $error->get_error_code() : 'oras_qbo_sync_failure';
        $error_data    = is_wp_error($error) ? $error->get_error_data() : null;

        if ($error_code === 'oras_qbo_reclass_source_not_found') {
            $wait_attempts = absint((string) $order->get_meta(self::META_WAIT_ATTEMPTS, true));
            $wait_attempts++;

            $first_waited_at = trim((string) $order->get_meta(self::META_WAIT_FIRST_AT, true));
            if ($first_waited_at === '') {
                $first_waited_at = gmdate('Y-m-d H:i:s');
                $order->update_meta_data(self::META_WAIT_FIRST_AT, $first_waited_at);
            }

            $max_days = max(1, absint(Settings::get_quickbooks_settings()['source_match_max_wait_days'] ?? 180));
            $first_wait_ts = strtotime($first_waited_at . ' UTC');
            $is_expired = false;
            if ($first_wait_ts !== false) {
                $is_expired = (time() - $first_wait_ts) >= ($max_days * DAY_IN_SECONDS);
            }

            if ($is_expired) {
                $order->update_meta_data('_oras_qbo_sync_status', 'needs_review');
                $order->update_meta_data('_oras_qbo_sync_error_code', 'oras_qbo_wait_expired');
                $order->update_meta_data('_oras_qbo_sync_error', sprintf('Source transaction still not found after %d day(s).', $max_days));
                $order->update_meta_data(self::META_WAIT_LAST_CHECK_AT, gmdate('Y-m-d H:i:s'));
                $this->deleteOrderMeta($order, self::META_WAIT_NEXT_CHECK_AT);
                $order->update_meta_data(self::META_WAIT_ATTEMPTS, (string) $wait_attempts);
                $order->save();

                $this->append_audit_entry(
                    $order,
                    'source_wait_expired_requires_review',
                    array(
                        'wait_attempts' => $wait_attempts,
                        'max_wait_days' => $max_days,
                    )
                );

                return;
            }

            $delay_minutes = $this->get_source_match_poll_interval_minutes($wait_attempts);
            $next_check_at = gmdate('Y-m-d H:i:s', time() + ($delay_minutes * MINUTE_IN_SECONDS));

            $order->update_meta_data('_oras_qbo_sync_status', 'waiting_for_source_txn');
            $order->update_meta_data('_oras_qbo_sync_error_code', sanitize_text_field($error_code));
            $order->update_meta_data('_oras_qbo_sync_error', sanitize_text_field($error_message));
            $order->update_meta_data('_oras_qbo_retry_count', '0');
            $order->update_meta_data(self::META_WAIT_LAST_CHECK_AT, gmdate('Y-m-d H:i:s'));
            $order->update_meta_data(self::META_WAIT_NEXT_CHECK_AT, $next_check_at);
            $order->update_meta_data(self::META_WAIT_ATTEMPTS, (string) $wait_attempts);
            $order->save();

            $this->append_audit_entry(
                $order,
                'waiting_for_source_transaction',
                array(
                    'wait_attempts' => $wait_attempts,
                    'next_check_at' => $next_check_at,
                    'delay_minutes' => $delay_minutes,
                )
            );

            $this->schedule_sync((int) $order->get_id(), $delay_minutes);
            return;
        }

        $should_retry  = Retry_Handler::should_retry_error($error_code, $error_data);

        $order->update_meta_data('_oras_qbo_sync_status', 'failed');
        $order->update_meta_data('_oras_qbo_sync_error_code', sanitize_text_field($error_code));
        $order->update_meta_data('_oras_qbo_sync_error', sanitize_text_field($error_message));
        $order->save();

        $this->append_audit_entry(
            $order,
            'sync_failure',
            array(
                'error_code'   => $error_code,
                'error'        => $error_message,
                'retriable'    => $should_retry,
                'error_data'   => is_array($error_data) ? $error_data : array(),
            )
        );

        $this->retry_handler->record_failure(
            $order,
            $error_message,
            $error_code,
            $should_retry,
            function (int $order_id, int $delay_minutes): void {
                $this->schedule_sync($order_id, $delay_minutes);
            }
        );
    }

    private function has_scheduled_action(int $order_id): bool
    {
        if (function_exists('as_has_scheduled_action')) {
            $scheduled = as_has_scheduled_action(self::ACTION_HOOK, array($order_id), self::AS_GROUP);
            return (bool) $scheduled;
        }

        $timestamp = function_exists('wp_next_scheduled')
            ? call_user_func('wp_next_scheduled', self::ACTION_HOOK, array($order_id))
            : false;
        return ! empty($timestamp);
    }

    private function ensure_waiting_queue_schedule(): void
    {
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            $scheduled = as_has_scheduled_action(self::ACTION_WAITING_SWEEP_HOOK, array(), self::AS_GROUP);
            if (! $scheduled) {
                as_schedule_recurring_action(time() + (5 * MINUTE_IN_SECONDS), 30 * MINUTE_IN_SECONDS, self::ACTION_WAITING_SWEEP_HOOK, array(), self::AS_GROUP);
            }
            return;
        }

        if (! function_exists('wp_next_scheduled') || ! call_user_func('wp_next_scheduled', self::ACTION_WAITING_SWEEP_HOOK)) {
            if (function_exists('wp_schedule_event')) {
                call_user_func('wp_schedule_event', time() + (5 * MINUTE_IN_SECONDS), 'hourly', self::ACTION_WAITING_SWEEP_HOOK);
            }
        }
    }

    private function schedule_sync(int $order_id, int $delay_minutes): void
    {
        $delay_seconds = max(0, $delay_minutes) * 60;

        if (function_exists('as_enqueue_async_action') && $delay_seconds === 0) {
            as_enqueue_async_action(self::ACTION_HOOK, array($order_id), self::AS_GROUP);
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay_seconds, self::ACTION_HOOK, array($order_id), self::AS_GROUP);
            return;
        }

        if (function_exists('wp_schedule_single_event')) {
            call_user_func('wp_schedule_single_event', time() + $delay_seconds, self::ACTION_HOOK, array($order_id));
        }
    }

    private function get_initial_sync_delay_minutes(): int
    {
        $qbo_settings = Settings::get_quickbooks_settings();
        return max(0, absint($qbo_settings['initial_sync_delay_minutes'] ?? 0));
    }

    private function get_source_match_poll_interval_minutes(int $wait_attempts): int
    {
        $qbo_settings   = Settings::get_quickbooks_settings();
        $base_interval  = max(5, absint($qbo_settings['source_match_poll_interval_minutes'] ?? 30));
        $wait_attempts  = max(1, $wait_attempts);

        if ($wait_attempts <= 48) {
            return $base_interval;
        }

        return min(240, $base_interval * 4);
    }

    /**
     * In reclass mode, allow unattended processing so treasury only needs to
     * approve/categorize in QuickBooks.
     *
     * @param array<string,mixed> $qbo_settings
     */
    private function should_require_manual_approval(array $qbo_settings): bool
    {
        $posting_mode = sanitize_key((string) ($qbo_settings['posting_mode'] ?? 'clearing'));
        if ($posting_mode === 'reclass') {
            return false;
        }

        return ! empty($qbo_settings['require_manual_approval']);
    }

    /**
     * Determine whether an order synced under legacy clearing mode must be
     * migrated when reclass mode is now enabled.
     *
     * @param \WC_Order $order
     * @param array<string,mixed> $qbo_settings
     */
    private function requires_reclass_migration($order, array $qbo_settings): bool
    {
        $posting_mode = sanitize_key((string) ($qbo_settings['posting_mode'] ?? 'clearing'));
        if ($posting_mode !== 'reclass') {
            return false;
        }

        $existing_je = trim((string) $order->get_meta('_oras_qbo_je_id', true));
        if ($existing_je === '') {
            return false;
        }

        $doc_number = trim((string) $order->get_meta(self::META_DOC_NUMBER, true));
        return strpos($doc_number, 'ORAS-WO-') === 0;
    }

    private function clear_scheduled_actions(int $order_id): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_HOOK, array($order_id), self::AS_GROUP);
            return;
        }

        if (function_exists('wp_clear_scheduled_hook')) {
            call_user_func('wp_clear_scheduled_hook', self::ACTION_HOOK, array($order_id));
        }
    }

    /**
     * Remove stale "queued" state when a safeguard blocks sync.
     *
     * @param \WC_Order $order
     */
    private function clear_queue_state($order): void
    {
        $this->deleteOrderMeta($order, '_oras_qbo_sync_status');
        $order->save();
    }

    /**
     * Generate deterministic hash for idempotency checks.
     */
    private function build_order_hash($order): string
    {
        $items = array();
        foreach ($order->get_items('line_item') as $item) {
            $items[] = array(
                'product_id'           => method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0,
                'variation_id'         => method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0,
                'quantity'             => method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0,
                'subtotal'             => round((float) $item->get_subtotal(), 2),
                'total'                => round((float) $item->get_total(), 2),
                'oras_ticket_event_id' => (string) $item->get_meta('_oras_ticket_event_id', true),
                'oras_ticket_name'     => (string) $item->get_meta('_oras_ticket_name', true),
            );
        }

        $signature = array(
            'order_id'         => (int) $order->get_id(),
            'status'           => (string) $order->get_status(),
            'currency'         => (string) $order->get_currency(),
            'line_items_total' => round((float) $order->get_subtotal(), 2),
            'order_total'      => round((float) $order->get_total(), 2),
            'discount_total'   => round((float) $order->get_discount_total(), 2),
            'discount_tax'     => round((float) $order->get_discount_tax(), 2),
            'shipping_total'   => round((float) $order->get_shipping_total(), 2),
            'tax_total'        => round((float) $order->get_total_tax(), 2),
            'items'            => $items,
        );

        return hash('sha256', wp_json_encode($signature) ?: '');
    }

    /**
     * Validate strict pre-sync safeguards.
     *
     * Required conditions:
     * - Status must be completed.
     * - Order must be created on/after the configured cutoff date.
     * - _oras_qbo_synced meta key must be empty.
     *
     * @param \WC_Order $order
     * @return true|\WP_Error
     */
    private function validate_sync_safeguards($order)
    {
        $status = (string) $order->get_status();
        if ($status !== 'completed') {
            return new \WP_Error('oras_qbo_order_not_completed', 'Order must be completed before syncing.');
        }

        $already_synced = trim((string) $order->get_meta(self::META_SYNCED, true));
        if ($already_synced !== '') {
            return new \WP_Error('oras_qbo_already_synced', 'Order already has _oras_qbo_synced meta and cannot be synced again.');
        }

        $qbo_settings = Settings::get_quickbooks_settings();
        $cutoff_raw   = trim((string) ($qbo_settings['sync_cutoff_date'] ?? ''));
        if ($cutoff_raw === '') {
            return new \WP_Error('oras_qbo_missing_cutoff_date', 'QuickBooks sync cutoff date is required before syncing orders.');
        }

        $cutoff_ts = strtotime($cutoff_raw . ' 00:00:00 UTC');
        if ($cutoff_ts === false) {
            return new \WP_Error('oras_qbo_invalid_cutoff_date', 'QuickBooks sync cutoff date is invalid.');
        }

        $created = $order->get_date_created();
        if (! is_object($created) || ! method_exists($created, 'getTimestamp')) {
            return new \WP_Error('oras_qbo_missing_order_created_date', 'Order created date is missing.');
        }

        if ((int) $created->getTimestamp() < (int) $cutoff_ts) {
            return new \WP_Error('oras_qbo_order_before_cutoff', 'Order was created before the configured QuickBooks sync cutoff date.');
        }

        $excluded_methods_raw = (string) ($qbo_settings['excluded_payment_methods'] ?? '');
        if ($excluded_methods_raw !== '') {
            $excluded_methods = array_filter(
                array_map(
                    static function (string $method): string {
                        return sanitize_key(trim($method));
                    },
                    explode(',', $excluded_methods_raw)
                )
            );

            $order_method = $this->getOrderPaymentMethod($order);
            if ($order_method !== '' && in_array($order_method, $excluded_methods, true)) {
                return new \WP_Error(
                    'oras_qbo_excluded_payment_method',
                    sprintf('Order payment method "%s" is excluded from QuickBooks sync.', $order_method)
                );
            }
        }

        return true;
    }

    /**
     * Append immutable audit event to order meta.
     *
     * @param \WC_Order $order
     * @param array<string,mixed> $context
     */
    private function append_audit_entry($order, string $event, array $context): void
    {
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return;
        }

        $entry = array(
            'timestamp_utc' => gmdate('c'),
            'event'         => sanitize_key($event),
            'order_id'      => $order_id,
            'actor_user_id' => (int) get_current_user_id(),
            'mode'          => Settings::is_sandbox() ? 'sandbox' : 'live',
            'context'       => $this->sanitize_audit_context($context),
        );

        if (function_exists('add_post_meta')) {
            call_user_func('add_post_meta', $order_id, '_oras_qbo_audit_entry', wp_json_encode($entry), false);
        }
        $order->update_meta_data('_oras_qbo_last_audit_event', (string) $entry['event']);
        $order->save();
    }

    private function deleteOrderMeta($order, string $meta_key): void
    {
        if (is_object($order) && method_exists($order, 'delete_meta_data')) {
            $order->delete_meta_data($meta_key);
        }
    }

    private function getOrderPaymentMethod($order): string
    {
        if (! is_object($order) || ! method_exists($order, 'get_payment_method')) {
            return '';
        }

        return sanitize_key((string) $order->get_payment_method());
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitize_audit_context(array $context): array
    {
        $clean = array();

        foreach ($context as $key => $value) {
            $clean_key = sanitize_key((string) $key);
            if ($clean_key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$clean_key] = is_string($value) ? sanitize_text_field($value) : $value;
                continue;
            }

            if (is_array($value)) {
                $clean[$clean_key] = wp_json_encode($value);
                continue;
            }

            $clean[$clean_key] = sanitize_text_field(wp_json_encode($value) ?: '');
        }

        return $clean;
    }
}
