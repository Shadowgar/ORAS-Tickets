<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

use ORAS\Tickets\Support\DbLock;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Orchestrator
{

    public const ACTION_HOOK = 'oras_tickets_qbo_sync_order';
	public const ACTION_REVERSAL_HOOK = 'oras_tickets_qbo_reverse_order_retry';
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
	private const META_PENDING_WRITE = '_oras_qbo_pending_write';
	private const META_WRITE_STATE = '_oras_qbo_write_state';
	private const META_SOURCE_CLAIM_STATE = '_oras_qbo_source_claim_state';
	private const META_SOURCE_CLAIM_ATTEMPT = '_oras_qbo_source_claim_attempt_id';
	private const META_SOURCE_CLAIM_REQUEST = '_oras_qbo_source_claim_request_id';
	private const META_SOURCE_CLAIM_OPERATION = '_oras_qbo_source_claim_operation';
	private const META_DOC_NUMBER_ATTEMPT = '_oras_qbo_doc_number_attempt_id';
	private const UNKNOWN_WRITE_RETRY_GRACE_SECONDS = 300;
	private const MAX_ASYNC_LOCK_RETRIES = 3;

    private Split_Calculator $split_calculator;
    private Journal_Entry_Creator $journal_entry_creator;
    private Retry_Handler $retry_handler;
    private QuickBooks_Logger $logger;
	private Api_Client $api_client;

    public function __construct(
        ?Split_Calculator $split_calculator = null,
        ?Journal_Entry_Creator $journal_entry_creator = null,
        ?Retry_Handler $retry_handler = null,
		?QuickBooks_Logger $logger = null,
		?Api_Client $api_client = null
    ) {
        $this->logger                = $logger ?: new QuickBooks_Logger();
		$this->api_client            = $api_client !== null ? $api_client : new Api_Client( null, $this->logger );
		$this->api_client->bind_journal_entry_dispatcher( $this );
        $this->split_calculator      = $split_calculator ?: new Split_Calculator($this->logger);
		$this->journal_entry_creator = $journal_entry_creator !== null ? $journal_entry_creator : new Journal_Entry_Creator( $this->api_client, $this->logger );
        $this->retry_handler         = $retry_handler ?: new Retry_Handler($this->logger);
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_completed', array($this, 'enqueue_order_sync'), 10, 1);
		add_action( self::ACTION_HOOK, array( $this, 'sync_order_async' ), 10, 2 );
		add_action( self::ACTION_REVERSAL_HOOK, array( $this, 'reverse_order_async' ), 10, 2 );
        add_action(self::ACTION_WAITING_SWEEP_HOOK, array($this, 'process_waiting_queue_async'));
		if ( ! $this->is_dry_run_mode() ) {
			$this->ensure_waiting_queue_schedule();
		}
    }

    public function process_waiting_queue_async(): void
    {
		if ( $this->controls_block_persistence() ) {
			return;
		}
        $this->process_waiting_orders(50);
    }

	public function enqueue_order_sync( int $order_id )
    {
		if ( ! Settings::is_enabled() || $this->is_dry_run_mode() ) {
			return $this->makeWpError( 'oras_qbo_schedule_blocked', 'QuickBooks synchronization controls do not permit queueing.' );
        }

        $order_id = absint($order_id);
        if ($order_id <= 0) {
			return $this->makeWpError( 'oras_qbo_invalid_order_id', 'Order ID must be a positive integer.' );
        }

        if ($this->has_scheduled_action($order_id)) {
			return array(
				'status'   => 'already_queued',
				'order_id' => $order_id,
			);
        }

        $order = wc_get_order($order_id);
        if (! $order) {
			return $this->makeWpError( 'oras_qbo_order_not_found', 'WooCommerce order not found.' );
		}

		if ( $this->has_legacy_possible_write_evidence( $order ) ) {
			return $this->make_legacy_possible_write_error();
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
			return $queue_guard_error;
		}

        $qbo_settings = Settings::get_quickbooks_settings();
        if ($order->get_meta('_oras_qbo_je_id', true)) {
            if ($this->requires_reclass_migration($order, $qbo_settings)) {
                $order->update_meta_data('_oras_qbo_sync_status', 'migration_required');
				if ( $this->controls_block_persistence() ) {
					return $this->makeWpError( 'oras_qbo_schedule_blocked', 'QuickBooks synchronization controls changed before queue state could be saved.' );
				}
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
			return array(
				'status'   => 'already_synced',
				'order_id' => $order_id,
			);
		}

        if ($this->should_require_manual_approval($qbo_settings) && trim((string) $order->get_meta(self::META_APPROVED_AT, true)) === '') {
            $order->update_meta_data('_oras_qbo_sync_status', 'pending_qbo_review');
			if ( $this->controls_block_persistence() ) {
				return $this->makeWpError( 'oras_qbo_schedule_blocked', 'QuickBooks synchronization controls changed before review state could be saved.' );
			}
            $order->save();
            $this->append_audit_entry($order, 'queued_for_manual_review', array());
			return array(
				'status'   => 'pending_qbo_review',
				'order_id' => $order_id,
			);
        }

		$schedule = $this->schedule_sync( $order_id, $this->get_initial_sync_delay_minutes() );
		if ( empty( $schedule['scheduled'] ) ) {
			return $this->schedule_error_from_result( $schedule );
		}

		$committed = $this->commit_scheduled_order_state(
			$order,
			$schedule,
			function () use ( $order ): void {
				if ( trim( (string) $order->get_meta( self::META_APPROVED_AT, true ) ) === '' ) {
					$order->update_meta_data( self::META_APPROVED_AT, gmdate( 'Y-m-d H:i:s' ) );
				}
				$order->update_meta_data( '_oras_qbo_sync_status', 'queued' );
				$order->save();
				$this->append_audit_entry( $order, 'queued_for_sync', array() );
			}
		);
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}

		return array(
			'status'   => 'queued',
			'order_id' => $order_id,
		);
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function approve_order_sync(int $order_id, bool $sync_now = false)
    {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return $this->makeWpError('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return $this->makeWpError('oras_qbo_order_not_found', 'WooCommerce order not found.');
        }

		if ( $this->is_dry_run_mode() ) {
			if ( $sync_now ) {
				return $this->build_dry_run_sync_preview( $order_id );
			}

			return array(
				'status'                  => 'dry_run',
				'order_id'                => $order_id,
				'remote_source_validated' => false,
			);
		}

		if ( $this->has_legacy_possible_write_evidence( $order ) ) {
			return $this->make_legacy_possible_write_error();
		}

		$guard_error = $this->validate_sync_safeguards($order);
		if (is_wp_error($guard_error)) {
			return $guard_error;
		}

		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.' );
		}

		if ( $sync_now ) {
			$order->update_meta_data( self::META_APPROVED_AT, gmdate( 'Y-m-d H:i:s' ) );
			$order->update_meta_data( '_oras_qbo_sync_status', 'approved_for_sync' );
			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$order->save();
			$this->append_audit_entry( $order, 'manual_approval_granted', array() );
            return $this->sync_order($order_id, false);
        }

		$schedule = $this->has_scheduled_action( $order_id )
			? $this->existing_schedule_result( self::ACTION_HOOK, array( $order_id, 0 ) )
			: $this->schedule_sync( $order_id, $this->get_initial_sync_delay_minutes() );
		if ( empty( $schedule['scheduled'] ) ) {
			return $this->schedule_error_from_result( $schedule );
		}

		$committed = $this->commit_scheduled_order_state(
			$order,
			$schedule,
			function () use ( $order ): void {
				$order->update_meta_data( self::META_APPROVED_AT, gmdate( 'Y-m-d H:i:s' ) );
				$order->update_meta_data( '_oras_qbo_sync_status', 'approved_for_sync' );
				$order->save();
				$this->append_audit_entry( $order, 'manual_approval_granted', array() );
			}
		);
		if ( is_wp_error( $committed ) ) {
			return $committed;
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
            return $this->makeWpError('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

		if ( $this->is_dry_run_mode() ) {
			return $this->build_dry_run_reversal_preview( $order_id, $force );
		}
		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.' );
		}
		$result = DbLock::withLock(
			'qbo-sync-order:' . (string) $order_id,
			function () use ( $order_id, $force ) {
				return $this->reverse_order_locked( $order_id, $force );
			},
			1
		);

		if ( is_wp_error( $result ) && $result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$error = $this->makeWpError(
				'oras_qbo_sync_in_progress',
				'Another QuickBooks synchronization is already in progress for this order.'
			);
			$error->add_data( array( 'retriable' => true ) );
			return $error;
		}

		return $result;
	}

	/**
	 * Reverse one order while the per-order database lock is held.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function reverse_order_locked( int $order_id, bool $force = false ) {

		if ( $this->is_dry_run_mode() ) {
			return $this->build_dry_run_reversal_preview( $order_id, $force );
		}

        if (! Settings::is_enabled()) {
            return $this->makeWpError('oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.');
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return $this->makeWpError('oras_qbo_order_not_found', 'WooCommerce order not found.');
		}

		$pending_recovery = $this->reconcile_pending_write( $order );
		if ( $pending_recovery !== null ) {
			return $pending_recovery;
		}

        $original_je_id = trim((string) $order->get_meta('_oras_qbo_je_id', true));
        if ($original_je_id === '') {
            return $this->makeWpError('oras_qbo_no_je_to_reverse', 'Order has no synced JournalEntry to reverse.');
        }

        $existing_reversal = trim((string) $order->get_meta('_oras_qbo_reversal_je_id', true));
        if ($existing_reversal !== '' && ! $force) {
            return $this->makeWpError('oras_qbo_already_reversed', 'Order already has a reversal JournalEntry.');
        }

        $snapshot_raw = (string) $order->get_meta(self::META_SPLIT_SNAPSHOT, true);
        $snapshot     = json_decode($snapshot_raw, true);
        if (! is_array($snapshot) || empty($snapshot['lines']) || ! isset($snapshot['split_total'])) {
            return $this->makeWpError('oras_qbo_missing_split_snapshot', 'Cannot reverse: split snapshot is missing from the order sync metadata.');
        }

		$qbo_settings = Settings::get_quickbooks_settings();
		$prepared     = $this->journal_entry_creator->build_payload_for_order( $order, $snapshot, $qbo_settings, true, $original_je_id );
        if (is_wp_error($prepared)) {
			$this->handle_sync_failure( $order, $prepared, 'reversal' );
            return $prepared;
        }

        $doc_number = isset($prepared['doc_number']) ? (string) $prepared['doc_number'] : '';
        if ($doc_number !== '') {
            $existing = $this->journal_entry_creator->find_existing_journal_entry($doc_number);
			if ( is_wp_error( $existing ) ) {
				if ( $this->is_control_stop_error( $existing ) ) {
					return $existing;
				}
				$error = $this->make_duplicate_lookup_error(
					'Duplicate check failed before reversal write; reversal was stopped. ',
					$existing
				);
				$this->handle_sync_failure( $order, $error, 'reversal' );
				return $error;
			}

			if ( ! empty( $existing['found'] ) ) {
                $entry = isset($existing['entry']) && is_array($existing['entry']) ? $existing['entry'] : array();
				$validation = $this->journal_entry_creator->validate_existing_journal_entry(
					$entry,
					$doc_number,
					(string) ( $prepared['accounting_fingerprint'] ?? '' ),
					(string) ( $prepared['home_currency'] ?? '' )
				);
				if ( is_wp_error( $validation ) ) {
					$this->handle_sync_failure( $order, $validation, 'reversal' );
					return $validation;
				}

				$reversal_id = isset( $entry['Id'] ) ? (string) $entry['Id'] : '';
				if ( $reversal_id !== '' ) {
					$guard = $this->get_dispatch_guard_error();
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					$meta = isset( $existing['meta'] ) && is_array( $existing['meta'] ) ? $existing['meta'] : array();
					$completed = $this->complete_reversal_sync(
                        $order,
                        'reversal_detected_existing',
						$reversal_id,
						$doc_number,
						(string) ( $meta['intuit_tid'] ?? '' )
                    );
					if ( is_wp_error( $completed ) ) {
						return $completed;
					}

                    return array(
                        'status'         => 'already_reversed_remote',
                        'order_id'       => (int) $order->get_id(),
                        'reversal_je_id' => $reversal_id,
                    );
                }

				$error = $this->makeWpError(
					'oras_qbo_duplicate_lookup_invalid',
					'QuickBooks reported an existing reversal JournalEntry without an ID; reversal was stopped.'
				);
				$error->add_data( array( 'retriable' => false ) );
				$this->handle_sync_failure( $order, $error, 'reversal' );
				return $error;
            }
        }

		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload = isset( $prepared['payload'] ) && is_array( $prepared['payload'] ) ? $prepared['payload'] : array();
		$encoded_payload = wp_json_encode( $payload );
		$payload_hash    = hash( 'sha256', is_string( $encoded_payload ) ? $encoded_payload : '' );
		$attempt_id = $this->generate_attempt_id();
		$pending_intent = null;
		$prepare_write = function () use ( $order, $prepared, $snapshot, $doc_number, $payload, $payload_hash, $attempt_id, &$pending_intent ) {
			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			if ( is_array( $pending_intent ) ) {
				return $this->verify_pending_write_persisted( $order, $pending_intent, array(), 'reversal' );
			}
			$recorded = $this->record_pending_write(
                $order,
				$this->build_order_hash( $order ),
				$doc_number,
				(string) ( $prepared['request_id'] ?? '' ),
				$payload_hash,
				(string) ( $prepared['accounting_fingerprint'] ?? '' ),
				(string) ( $prepared['home_currency'] ?? '' ),
				(string) ( $prepared['transaction_currency'] ?? '' ),
				! empty( $prepared['multicurrency_enabled'] ),
				(string) ( $prepared['exchange_rate'] ?? '' ),
				$payload,
				$snapshot,
				array(),
				'reversal',
				$attempt_id
            );
			if ( is_array( $recorded ) ) {
				$pending_intent = $recorded;
				return true;
			}
			return $recorded;
		};
		$mark_dispatch_started = function () use ( $order, &$pending_intent ) {
			if ( ! is_array( $pending_intent ) ) {
				return $this->makeWpError(
					'oras_qbo_dispatch_marker_failed',
					'QuickBooks reversal intent was not durably prepared; no request was sent.'
				);
			}

			return $this->mark_pending_write_dispatched( $order, $pending_intent, array(), 'reversal' );
		};

		$reversal = $this->dispatch_prepared_journal_entry(
			$order,
			$prepared,
			true,
			$prepare_write,
			$mark_dispatch_started
		);
        if (is_wp_error($reversal)) {
			if ( $this->is_unknown_write_error( $reversal ) ) {
				return $this->mark_write_outcome_unknown( $order, $reversal );
			}

			$this->clear_pending_write( $order, is_array( $pending_intent ) ? $pending_intent : array() );
			if ( $this->is_control_stop_error( $reversal ) ) {
				return $reversal;
			}
			$this->handle_sync_failure( $order, $reversal, 'reversal' );
            return $reversal;
        }

        $reversal_je_id = isset($reversal['je_id']) ? (string) $reversal['je_id'] : '';
        $intuit_tid     = isset($reversal['intuit_tid']) ? (string) $reversal['intuit_tid'] : '';
		if ( $reversal_je_id === '' ) {
			return $this->mark_write_outcome_unknown(
				$order,
				$this->makeWpError( 'oras_qbo_missing_reversal_je_id', 'QuickBooks reversal completed without a JournalEntry ID.' )
			);
		}

		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$completed = $this->complete_reversal_sync(
            $order,
            'reversal_synced',
			$reversal_je_id,
			isset( $reversal['doc_number'] ) ? (string) $reversal['doc_number'] : '',
			$intuit_tid
        );
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}

        return array(
            'status'         => 'reversed',
            'order_id'       => (int) $order->get_id(),
            'reversal_je_id' => $reversal_je_id,
        );
    }

	public function sync_order_async( int $order_id, int $lock_attempt = 0 ) {
		if ( $this->controls_block_persistence() ) {
			return $this->makeWpError( 'oras_qbo_schedule_blocked', 'QuickBooks synchronization controls do not permit asynchronous work.' );
		}
		$order_id = absint( $order_id );
		$lock_attempt = max( 0, $lock_attempt );
		$result = $this->sync_order( $order_id );
		if ( ! is_wp_error( $result ) || $result->get_error_code() !== 'oras_qbo_sync_in_progress' ) {
			return $result;
		}

		if ( $lock_attempt >= self::MAX_ASYNC_LOCK_RETRIES ) {
			$this->logger->warning(
				'QuickBooks asynchronous sync stopped after bounded lock retries',
				array(
					'order_id'     => $order_id,
					'lock_attempt' => $lock_attempt,
				)
			);
			$error = $this->makeWpError(
				'oras_qbo_sync_lock_retry_exhausted',
				'QuickBooks asynchronous sync stopped after the bounded order-lock retry limit.'
			);
			$error->add_data(
				array(
					'order_id'     => $order_id,
					'lock_attempt' => $lock_attempt,
					'scheduled'    => false,
				)
			);
			return $error;
		}

		$next_attempt = $lock_attempt + 1;
		$delay_minutes = 2 ** ( $next_attempt - 1 );
		$schedule = $this->schedule_sync( $order_id, $delay_minutes, $next_attempt );
		if ( ! empty( $schedule['scheduled'] ) ) {
			$schedule['status'] = 'lock_retry_scheduled';
			$schedule['lock_attempt'] = $next_attempt;
			$schedule['delay_minutes'] = $delay_minutes;
			return $schedule;
		}

		$error = $this->schedule_error_from_result( $schedule );
		$error_data = $error->get_error_data();
		$error->add_data(
			array_merge(
				is_array( $error_data ) ? $error_data : array(),
				array(
					'operation'     => 'sync',
					'lock_attempt'  => $next_attempt,
					'delay_minutes' => $delay_minutes,
				)
			)
		);
		return $error;
	}

	public function reverse_order_async( int $order_id, int $lock_attempt = 0 ) {
		if ( $this->controls_block_persistence() ) {
			return $this->makeWpError( 'oras_qbo_schedule_blocked', 'QuickBooks synchronization controls do not permit asynchronous reversal work.' );
		}

		$order_id = absint( $order_id );
		$lock_attempt = max( 0, $lock_attempt );
		$result = $this->reverse_order( $order_id );
		if ( ! is_wp_error( $result ) || $result->get_error_code() !== 'oras_qbo_sync_in_progress' ) {
			return $result;
		}

		if ( $lock_attempt >= self::MAX_ASYNC_LOCK_RETRIES ) {
			$order = wc_get_order( $order_id );
			if ( $order && ! $this->controls_block_persistence() ) {
				$order->update_meta_data( '_oras_qbo_reversal_lock_retry_count', (string) self::MAX_ASYNC_LOCK_RETRIES );
				$order->update_meta_data( '_oras_qbo_sync_status', 'reversal_failed' );
				$order->update_meta_data( '_oras_qbo_sync_error_code', 'oras_qbo_reversal_lock_retry_exhausted' );
				$order->update_meta_data(
					'_oras_qbo_sync_error',
					'QuickBooks reversal stopped after the bounded order-lock retry limit.'
				);
				$order->save();
				$this->append_audit_entry(
					$order,
					'reversal_lock_retry_exhausted',
					array( 'lock_attempt' => $lock_attempt )
				);
			}
			$this->logger->warning(
				'QuickBooks reversal stopped after bounded lock retries',
				array(
					'order_id'     => $order_id,
					'lock_attempt' => $lock_attempt,
				)
			);
			$error = $this->makeWpError(
				'oras_qbo_reversal_lock_retry_exhausted',
				'QuickBooks reversal stopped after the bounded order-lock retry limit.'
			);
			$error->add_data(
				array(
					'order_id'     => $order_id,
					'lock_attempt' => $lock_attempt,
					'scheduled'    => false,
				)
			);
			return $error;
		}

		$next_attempt = $lock_attempt + 1;
		$delay_minutes = 2 ** ( $next_attempt - 1 );
		$schedule = $this->schedule_reversal( $order_id, $delay_minutes, $next_attempt );
		if ( empty( $schedule['scheduled'] ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && ! $this->controls_block_persistence() ) {
				$order->update_meta_data( '_oras_qbo_sync_status', 'reversal_failed' );
				$order->update_meta_data( '_oras_qbo_sync_error_code', 'oras_qbo_reversal_lock_retry_schedule_failed' );
				$order->update_meta_data( '_oras_qbo_sync_error', 'QuickBooks reversal order-lock retry could not be scheduled.' );
				$order->save();
				$this->append_audit_entry(
					$order,
					'reversal_lock_retry_schedule_failed',
					array( 'lock_attempt' => $next_attempt )
				);
			}
			return $this->schedule_error_from_result( $schedule );
		}

		$order = wc_get_order( $order_id );
		if ( $order && ! $this->controls_block_persistence() ) {
			$committed = $this->commit_scheduled_order_state(
				$order,
				$schedule,
				function () use ( $order, $next_attempt, $delay_minutes ): void {
					$order->update_meta_data( '_oras_qbo_reversal_lock_retry_count', (string) $next_attempt );
					$order->update_meta_data( '_oras_qbo_sync_status', 'reversal_lock_retrying' );
					$order->save();
					$this->append_audit_entry(
						$order,
						'reversal_lock_retry_scheduled',
						array(
							'lock_attempt'  => $next_attempt,
							'delay_minutes' => $delay_minutes,
						)
					);
				}
			);
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
		}
		$schedule['status'] = 'reversal_lock_retry_scheduled';
		$schedule['lock_attempt'] = $next_attempt;
		$schedule['delay_minutes'] = $delay_minutes;
		return $schedule;
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function sync_order(int $order_id, bool $force = false)
    {
        if ($order_id <= 0) {
            return $this->makeWpError('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

		if ( $this->is_dry_run_mode() ) {
			return $this->build_dry_run_sync_preview( $order_id );
		}
        if (! Settings::is_enabled()) {
            return $this->makeWpError('oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.');
        }
		$result = DbLock::withLock(
			'qbo-sync-order:' . (string) $order_id,
			function () use ( $order_id, $force ) {
				return $this->sync_order_locked( $order_id, $force );
			},
			1
		);

		if ( is_wp_error( $result ) && $result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$error = $this->makeWpError(
				'oras_qbo_sync_in_progress',
				'Another QuickBooks synchronization is already in progress for this order.'
			);
			$error->add_data( array( 'retriable' => true ) );
			return $error;
		}

		return $result;
	}

	/**
	 * Resolve a pending or unknown JournalEntry outcome using its persisted,
	 * deterministic payload. A new POST is allowed only when explicitly
	 * requested after a conclusive empty lookup and the consistency grace.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function reconcile_unknown_write( int $order_id, bool $retry_if_absent = false ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return $this->makeWpError( 'oras_qbo_invalid_order_id', 'Order ID must be a positive integer.' );
		}

		if ( $this->is_dry_run_mode() ) {
			$error = $this->makeWpError(
				'oras_qbo_dry_run_read_only',
				'Unknown-outcome reconciliation is unavailable in dry-run mode because it requires a QuickBooks lookup.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		if ( ! $retry_if_absent ) {
			return $this->reconcile_unknown_write_read_only( $order_id );
		}

		if ( ! Settings::is_enabled() ) {
			$error = $this->makeWpError(
				'oras_qbo_disabled',
				'QuickBooks Revenue Split Sync is disabled; no reconciliation retry was sent.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		$result = DbLock::withLock(
			'qbo-sync-order:' . (string) $order_id,
			function () use ( $order_id, $retry_if_absent ) {
				return $this->reconcile_unknown_write_locked( $order_id, $retry_if_absent );
			},
			1
		);

		if ( is_wp_error( $result ) && $result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$error = $this->makeWpError(
				'oras_qbo_sync_in_progress',
				'Another QuickBooks synchronization is already in progress for this order.'
			);
			$error->add_data( array( 'retriable' => true ) );
			return $error;
		}

		return $result;
	}

	/**
	 * Query-only reconciliation that is intentionally available while sync is
	 * disabled. It acquires no locks and never adopts, clears, retries, audits,
	 * schedules, or otherwise mutates local state.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function reconcile_unknown_write_read_only( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->makeWpError( 'oras_qbo_order_not_found', 'WooCommerce order not found.' );
		}

		$pending_raw = trim( (string) $order->get_meta( self::META_PENDING_WRITE, true ) );
		$pending = $pending_raw !== '' ? json_decode( $pending_raw, true ) : null;
		$write_state = sanitize_key( (string) $order->get_meta( self::META_WRITE_STATE, true ) );
		$legacy = $this->has_legacy_possible_write_evidence( $order );

		if ( $legacy ) {
			$doc_number = is_array( $pending ) ? trim( (string) ( $pending['doc_number'] ?? '' ) ) : '';
			if ( $doc_number === '' ) {
				$doc_number = trim( (string) $order->get_meta( self::META_DOC_NUMBER, true ) );
			}
			if ( $doc_number === '' ) {
				$error = $this->make_legacy_possible_write_error();
				$error->add_data(
					array(
						'reconciliation_status' => 'unqueryable',
						'operator_action'       => 'Inventory QuickBooks manually before reset or resync.',
					)
				);
				return $error;
			}

			$remote = $this->journal_entry_creator->find_existing_journal_entry(
				$doc_number,
				false,
				Api_Client::REQUEST_POLICY_LOOKUP_ONLY
			);
			if ( is_wp_error( $remote ) ) {
				if ( $remote->get_error_code() === 'oras_qbo_lookup_auth_required' ) {
					return $remote;
				}
				return $this->make_duplicate_lookup_error(
					'Legacy read-only reconciliation could not query QuickBooks; preserved evidence was not changed. ',
					$remote
				);
			}

			return array(
				'status'               => ! empty( $remote['found'] ) ? 'legacy_remote_found_read_only' : 'legacy_remote_absent_read_only',
				'order_id'             => $order_id,
				'doc_number'           => $doc_number,
				'je_id'                => ! empty( $remote['found'] ) ? trim( (string) ( $remote['entry']['Id'] ?? '' ) ) : '',
				'fingerprint_verified' => false,
				'operator_action'      => 'Review this read-only result and preserved legacy evidence before approving any mutation.',
			);
		}

		if (
			$pending_raw === ''
			|| ! is_array( $pending )
			|| ! in_array( $write_state, array( 'pending', 'unknown_outcome' ), true )
		) {
			$error = $this->makeWpError(
				'oras_qbo_no_unknown_write',
				'This order has no pending or unknown QuickBooks JournalEntry write to reconcile.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		$prepared = $this->validate_pending_write_intent( $pending, $order_id, false );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$remote = $this->journal_entry_creator->find_existing_journal_entry(
			(string) $prepared['doc_number'],
			false,
			Api_Client::REQUEST_POLICY_LOOKUP_ONLY
		);
		if ( is_wp_error( $remote ) ) {
			if ( $remote->get_error_code() === 'oras_qbo_lookup_auth_required' ) {
				return $remote;
			}
			return $this->make_duplicate_lookup_error(
				'Read-only reconciliation could not query QuickBooks; pending evidence was not changed. ',
				$remote
			);
		}

		if ( empty( $remote['found'] ) ) {
			return array(
				'status'               => 'remote_absent_read_only',
				'order_id'             => $order_id,
				'doc_number'           => (string) $prepared['doc_number'],
				'fingerprint_verified' => false,
			);
		}

		$entry = isset( $remote['entry'] ) && is_array( $remote['entry'] ) ? $remote['entry'] : array();
		$validation = $this->journal_entry_creator->validate_existing_journal_entry(
			$entry,
			(string) $prepared['doc_number'],
			(string) $prepared['accounting_fingerprint'],
			(string) $prepared['home_currency']
		);
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$je_id = trim( (string) ( $entry['Id'] ?? '' ) );
		if ( $je_id === '' ) {
			$error = $this->makeWpError(
				'oras_qbo_pending_write_missing_id',
				'QuickBooks returned the pending JournalEntry without an ID.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		return array(
			'status'               => 'remote_found_read_only',
			'order_id'             => $order_id,
			'doc_number'           => (string) $prepared['doc_number'],
			'je_id'                => $je_id,
			'operation'            => (string) $prepared['operation'],
			'fingerprint_verified' => true,
		);
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function reconcile_unknown_write_locked( int $order_id, bool $retry_if_absent ) {
		if ( $this->is_dry_run_mode() ) {
			return $this->make_dry_run_read_only_error( 'Unknown-outcome reconciliation' );
		}
		if ( $retry_if_absent && ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled; no reconciliation retry was sent.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->makeWpError( 'oras_qbo_order_not_found', 'WooCommerce order not found.' );
		}

		if ( $this->has_legacy_possible_write_evidence( $order ) ) {
			return $this->make_legacy_possible_write_error();
		}

		$pending_raw = trim( (string) $order->get_meta( self::META_PENDING_WRITE, true ) );
		$write_state = trim( (string) $order->get_meta( self::META_WRITE_STATE, true ) );
		$pending = json_decode( $pending_raw, true );
		if ( $pending_raw === '' || ! is_array( $pending ) || ! in_array( $write_state, array( 'pending', 'unknown_outcome' ), true ) ) {
			$error = $this->makeWpError(
				'oras_qbo_no_unknown_write',
				'This order has no pending or unknown QuickBooks JournalEntry write to reconcile.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		$prepared = $this->validate_pending_write_intent( $pending, (int) $order->get_id() );
		if ( is_wp_error( $prepared ) ) {
			return $this->retain_unknown_reconciliation_error(
				$order,
				'pending_reconciliation_invalid',
				$prepared
			);
		}

		$doc_number = (string) $prepared['doc_number'];
		$expected_fingerprint = (string) $prepared['accounting_fingerprint'];
		$home_currency = (string) $prepared['home_currency'];
		$operation = (string) $prepared['operation'];
		$source_match = (array) ( $prepared['source_match'] ?? array() );
		$is_reversal = $operation === 'reversal';
		$source_key = ! $is_reversal ? trim( (string) ( $source_match['key'] ?? '' ) ) : '';

		$reconcile = function () use (
			$order,
			$pending,
			$prepared,
			$retry_if_absent,
			$doc_number,
			$expected_fingerprint,
			$home_currency,
			$operation,
			$source_match,
			$is_reversal
		) {
			if (
				! $is_reversal
				&& ! empty( $source_match )
				&& $this->journal_entry_creator->is_reclass_source_key_claimed(
					(string) ( $source_match['key'] ?? '' ),
					(int) $order->get_id()
				)
			) {
				$error = $this->makeWpError(
					'oras_qbo_reclass_source_claimed',
					'The pending QuickBooks SalesReceipt is now claimed by another WooCommerce order; no adoption or retry occurred.'
				);
				$error->add_data( array( 'retriable' => false ) );
				return $this->retain_unknown_reconciliation_error(
					$order,
					'pending_reconciliation_source_claimed',
					$error
				);
			}

			$existing_remote = $this->journal_entry_creator->find_existing_journal_entry( $doc_number );
			if ( is_wp_error( $existing_remote ) ) {
				if ( $this->is_control_stop_error( $existing_remote ) ) {
					return $existing_remote;
				}
				return $this->retain_unknown_reconciliation_error(
					$order,
					'pending_reconciliation_lookup_failed',
					$this->make_duplicate_lookup_error(
						'Pending write reconciliation could not query QuickBooks; unknown state was retained. ',
						$existing_remote
					)
				);
			}

			if ( ! empty( $existing_remote['found'] ) ) {
				$entry = isset( $existing_remote['entry'] ) && is_array( $existing_remote['entry'] )
				? $existing_remote['entry']
				: array();
				$je_id = trim( (string) ( $entry['Id'] ?? '' ) );
				if ( $je_id === '' ) {
					return $this->retain_unknown_reconciliation_error(
						$order,
						'pending_reconciliation_missing_id',
						$this->makeWpError(
							'oras_qbo_pending_write_missing_id',
							'QuickBooks returned the pending JournalEntry without an ID.'
						)
					);
				}

				$validation = $this->journal_entry_creator->validate_existing_journal_entry(
					$entry,
					$doc_number,
					$expected_fingerprint,
					$home_currency
				);
				if ( is_wp_error( $validation ) ) {
					return $this->retain_unknown_reconciliation_error(
						$order,
						'pending_reconciliation_fingerprint_mismatch',
						$validation
					);
				}

				return $this->adopt_reconciled_journal_entry( $order, $pending, $existing_remote, $je_id );
			}

			$dispatch_started_at = trim( (string) ( $pending['dispatch_started_at'] ?? $pending['started_at'] ?? '' ) );
			$dispatch_started_ts = $dispatch_started_at !== '' ? strtotime( $dispatch_started_at ) : false;
			if ( $dispatch_started_ts === false ) {
				return $this->retain_unknown_reconciliation_error(
					$order,
					'pending_reconciliation_invalid_time',
					$this->makeWpError(
						'oras_qbo_pending_write_invalid',
						'Pending QuickBooks write has no valid dispatch timestamp; unknown state was retained.'
					)
				);
			}

			$age_seconds = max( 0, time() - $dispatch_started_ts );
			if ( ! $retry_if_absent ) {
				$error = $this->makeWpError(
					'oras_qbo_pending_write_not_confirmed',
					'QuickBooks did not return the pending JournalEntry. Unknown state was retained; an explicit retry was not requested.'
				);
				$error->add_data(
					array(
						'retriable'   => false,
						'age_seconds' => $age_seconds,
					)
				);
				return $this->retain_unknown_reconciliation_error( $order, 'pending_reconciliation_absent', $error );
			}

			if ( $age_seconds < self::UNKNOWN_WRITE_RETRY_GRACE_SECONDS ) {
				$error = $this->makeWpError(
					'oras_qbo_pending_write_grace_period',
					'The pending JournalEntry is still within the consistency grace period; no retry was sent.'
				);
				$error->add_data(
					array(
						'retriable'            => false,
						'age_seconds'          => $age_seconds,
						'required_age_seconds' => self::UNKNOWN_WRITE_RETRY_GRACE_SECONDS,
					)
				);
				return $this->retain_unknown_reconciliation_error( $order, 'pending_reconciliation_grace_blocked', $error );
			}

			$retry_intent_prepared = false;
			$prepare_write = function () use ( $order, &$pending, $operation, &$retry_intent_prepared ) {
				$guard = $this->get_dispatch_guard_error();
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
				$source_match = isset( $pending['source_match'] ) && is_array( $pending['source_match'] )
				? $pending['source_match']
				: array();
				if ( $retry_intent_prepared ) {
					return $this->verify_pending_write_persisted( $order, $pending, $source_match, $operation );
				}

				try {
					$previous_dispatch = trim( (string) ( $pending['dispatch_started_at'] ?? '' ) );
					if ( $previous_dispatch !== '' ) {
						$history = isset( $pending['dispatch_history'] ) && is_array( $pending['dispatch_history'] )
						? $pending['dispatch_history']
						: array();
						$history[] = $previous_dispatch;
						$pending['dispatch_history'] = array_values( array_unique( array_map( 'strval', $history ) ) );
					}
					$pending['dispatch_started_at'] = '';
					$pending['retry_prepared_at'] = gmdate( 'c' );
					$order->update_meta_data( self::META_PENDING_WRITE, wp_json_encode( $pending ) );
					$order->update_meta_data( self::META_WRITE_STATE, 'pending' );
					$order->update_meta_data( '_oras_qbo_sync_status', 'pending_qbo_write' );
					$guard = $this->get_dispatch_guard_error();
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					$order->save();

					$verification = $this->verify_pending_write_persisted( $order, $pending, $source_match, $operation );
					if ( is_wp_error( $verification ) ) {
						return $verification;
					}
					$retry_intent_prepared = true;
				} catch ( \Throwable $throwable ) {
					$error = $this->makeWpError(
						'oras_qbo_write_reservation_failed',
						'Could not persist the prepared QuickBooks reconciliation retry; no request was sent.'
					);
					$error->add_data(
						array(
							'retriable'              => true,
							'qbo_request_dispatched' => false,
							'exception'              => get_class( $throwable ),
						)
					);
					return $error;
				}

				return true;
			};
			$mark_dispatch_started = function () use ( $order, &$pending, $operation ) {
				$source_match = isset( $pending['source_match'] ) && is_array( $pending['source_match'] )
				? $pending['source_match']
				: array();
				return $this->mark_pending_write_dispatched( $order, $pending, $source_match, $operation );
			};

			$dispatch_retry = function () use ( $order, $prepared, $is_reversal, $prepare_write, $mark_dispatch_started ) {
				if ( ! Settings::is_enabled() ) {
					$error = $this->makeWpError(
						'oras_qbo_disabled',
						'QuickBooks Revenue Split Sync was disabled before reconciliation dispatch; no retry was sent.'
					);
					$error->add_data(
						array(
							'retriable'              => false,
							'qbo_request_dispatched' => false,
						)
					);
					return $error;
				}

				return $this->dispatch_prepared_journal_entry(
					$order,
					$prepared,
					$is_reversal,
					$prepare_write,
					$mark_dispatch_started
				);
			};

			$result = $dispatch_retry();
			if ( is_wp_error( $result ) ) {
				if ( $this->is_unknown_write_error( $result ) ) {
					return $this->mark_write_outcome_unknown( $order, $result );
				}

				return $this->retain_unknown_reconciliation_error(
					$order,
					'pending_reconciliation_retry_failed_before_dispatch',
					$result
				);
			}

			$je_id = trim( (string) ( $result['je_id'] ?? '' ) );
			if ( $je_id === '' ) {
				$error = $this->makeWpError(
					$is_reversal ? 'oras_qbo_missing_reversal_je_id' : 'oras_qbo_missing_je_id',
					'QuickBooks returned no JournalEntry ID after the reconciliation retry.'
				);
				$error->add_data( array( 'qbo_request_dispatched' => true ) );
				return $this->mark_write_outcome_unknown( $order, $error );
			}

			$remote = array(
				'entry' => isset( $result['remote_entry'] ) && is_array( $result['remote_entry'] )
					? $result['remote_entry']
					: array(),
				'meta'  => array( 'intuit_tid' => (string) ( $result['intuit_tid'] ?? '' ) ),
			);
			return $this->adopt_reconciled_journal_entry( $order, $pending, $remote, $je_id, true );
		};

		$result = $source_key === ''
			? $reconcile()
			: DbLock::withLock( 'qbo-source:' . $source_key, $reconcile, 1 );
		if ( is_wp_error( $result ) && $result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$lock_error = $this->makeWpError(
				'oras_qbo_source_lock_in_progress',
				'Another order is currently reserving this QuickBooks SalesReceipt; unknown state was retained.'
			);
			$lock_error->add_data( array( 'retriable' => true ) );
			return $this->retain_unknown_reconciliation_error(
				$order,
				'pending_reconciliation_source_lock_failed',
				$lock_error
			);
		}

		return $result;
	}

	/**
	 * @param \WC_Order $order
	 * @param array<string,mixed> $pending
	 * @param array<string,mixed> $remote
	 * @return array<string,mixed>|\WP_Error
	 */
	private function adopt_reconciled_journal_entry( $order, array $pending, array $remote, string $je_id, bool $was_retried = false ) {
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$meta = isset( $remote['meta'] ) && is_array( $remote['meta'] ) ? $remote['meta'] : array();
		$operation = sanitize_key( (string) ( $pending['operation'] ?? 'sync' ) );
		$doc_number = trim( (string) ( $pending['doc_number'] ?? '' ) );
		$audit_suffix = $was_retried ? 'retry_confirmed' : 'detected_remote';

		if ( $operation === 'reversal' ) {
			$completed = $this->complete_reversal_sync(
				$order,
				'pending_reconciliation_' . $audit_suffix,
				$je_id,
				$doc_number,
				(string) ( $meta['intuit_tid'] ?? '' )
			);
			if ( is_wp_error( $completed ) ) {
				return $completed;
			}

			return array(
				'status'         => $was_retried ? 'reversed' : 'already_reversed_remote',
				'order_id'       => (int) $order->get_id(),
				'reversal_je_id' => $je_id,
			);
		}

		$split = isset( $pending['split'] ) && is_array( $pending['split'] ) ? $pending['split'] : array();
		$source_match = isset( $pending['source_match'] ) && is_array( $pending['source_match'] )
			? $pending['source_match']
			: array();
		$completed = $this->complete_order_sync(
			$order,
			(string) ( $pending['order_hash'] ?? '' ),
			$je_id,
			$doc_number,
			(string) ( $meta['intuit_tid'] ?? '' ),
			$split,
			$source_match,
			(string) ( $pending['payload_hash'] ?? '' ),
			'pending_reconciliation_' . $audit_suffix,
			$was_retried ? 'synced' : 'already_synced_remote'
		);
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}

		return array(
			'status'   => $was_retried ? 'synced' : 'already_synced_remote',
			'order_id' => (int) $order->get_id(),
			'je_id'    => $je_id,
		);
	}

	/**
	 * @param \WC_Order $order
	 * @return \WP_Error
	 */
	private function retain_unknown_reconciliation_error( $order, string $audit_event, \WP_Error $error ): \WP_Error {
		if ( $this->is_dry_run_mode() || ! Settings::is_enabled() ) {
			return $error;
		}
		$order->update_meta_data( self::META_WRITE_STATE, 'unknown_outcome' );
		$order->update_meta_data( '_oras_qbo_sync_status', 'qbo_write_unknown' );
		$order->update_meta_data( '_oras_qbo_sync_error_code', sanitize_text_field( (string) $error->get_error_code() ) );
		$order->update_meta_data( '_oras_qbo_sync_error', sanitize_text_field( (string) $error->get_error_message() ) );
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$order->save();
		$this->append_audit_entry(
			$order,
			$audit_event,
			array(
				'error_code' => (string) $error->get_error_code(),
				'doc_number' => (string) $order->get_meta( self::META_DOC_NUMBER, true ),
			)
		);

		return $error;
	}

	/**
	 * Run one order sync while the per-order database lock is held.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function sync_order_locked( int $order_id, bool $force = false ) {
		if ( $this->is_dry_run_mode() ) {
			return $this->build_dry_run_sync_preview( $order_id );
		}
		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->makeWpError( 'oras_qbo_order_not_found', 'WooCommerce order not found.' );
		}

		if ( $this->has_legacy_possible_write_evidence( $order ) ) {
			return $this->make_legacy_possible_write_error();
		}

		$guard_error = $this->validate_sync_safeguards( $order );
		if ( is_wp_error( $guard_error ) ) {
			$this->clear_queue_state( $order );
			return $guard_error;
		}

		$qbo_settings = Settings::get_quickbooks_settings();
		$pending_recovery = $this->reconcile_pending_write( $order );
		if ( $pending_recovery !== null ) {
			return $pending_recovery;
		}

		if ( ! $force && $this->requires_reclass_migration( $order, $qbo_settings ) ) {
			$order->update_meta_data( '_oras_qbo_sync_status', 'migration_required' );
			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$order->save();
			$this->append_audit_entry( $order, 'reclass_migration_required', array() );
			return $this->makeWpError(
				'oras_qbo_reclass_migration_required',
				'Order was already synced with legacy clearing mode and requires migration before reclass sync. Use resync-order.'
			);
		}

		if ( ! $force && $this->should_require_manual_approval( $qbo_settings ) && trim( (string) $order->get_meta( self::META_APPROVED_AT, true ) ) === '' ) {
			$order->update_meta_data( '_oras_qbo_sync_status', 'pending_qbo_review' );
			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$order->save();
			$this->append_audit_entry( $order, 'sync_blocked_manual_approval_required', array() );
			return $this->makeWpError( 'oras_qbo_manual_approval_required', 'Order requires manual approval before QuickBooks sync.' );
		}

		$order_hash    = $this->build_order_hash( $order );
		$existing_je   = (string) $order->get_meta( '_oras_qbo_je_id', true );
		$existing_hash = (string) $order->get_meta( '_oras_qbo_je_hash', true );

		if ( ! $force && $existing_je !== '' && $existing_hash === $order_hash ) {
			$order->update_meta_data( '_oras_qbo_sync_status', 'synced' );
			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$order->save();
			return array(
				'status' => 'already_synced',
				'je_id'  => $existing_je,
			);
		}

		if ( ! $force && $existing_je !== '' && $existing_hash !== '' && $existing_hash !== $order_hash ) {
			$order->update_meta_data( '_oras_qbo_sync_status', 'changed_after_sync' );
			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$order->save();
			return $this->makeWpError(
				'oras_qbo_already_synced_changed',
				'Order was already synced to QuickBooks and changed afterwards. Manual review is required.'
			);
		}

		$order->update_meta_data( '_oras_qbo_sync_status', 'syncing' );
		$order->update_meta_data( '_oras_qbo_last_attempt_at', gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( self::META_WAIT_LAST_CHECK_AT, gmdate( 'Y-m-d H:i:s' ) );
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$order->save();

		$this->append_audit_entry( $order, 'sync_started', array() );

		$split = $this->split_calculator->calculate( $order, $qbo_settings );
		if ( is_wp_error( $split ) ) {
			$this->handle_sync_failure( $order, $split );
			return $split;
		}

		if ( ! empty( $qbo_settings['strict_mapping_mode'] ) ) {
			$warnings              = isset( $split['warnings'] ) && is_array( $split['warnings'] ) ? $split['warnings'] : array();
			$unmapped_lines        = (int) ( $split['unmapped_lines'] ?? 0 );
			$missing_account_lines = (int) ( $split['missing_account_lines'] ?? 0 );
			if ( $unmapped_lines > 0 || $missing_account_lines > 0 || ! empty( $warnings ) ) {
				$error = $this->makeWpError(
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
				$this->handle_sync_failure( $order, $error );
				return $error;
			}
		}

		$prepared = $this->journal_entry_creator->build_payload_for_order( $order, $split, $qbo_settings, false );
		if ( is_wp_error( $prepared ) ) {
			$this->handle_sync_failure( $order, $prepared );
			return $prepared;
		}

		$source_match = isset( $prepared['source_match'] ) && is_array( $prepared['source_match'] ) ? $prepared['source_match'] : array();
		$source_key = trim( (string) ( $source_match['key'] ?? '' ) );
		$dispatch = function () use ( $order, $order_hash, $prepared, $split, $source_match, $existing_je, $order_id ) {
			if (
				! empty( $source_match )
				&& $this->journal_entry_creator->is_reclass_source_key_claimed(
					(string) ( $source_match['key'] ?? '' ),
					(int) $order->get_id()
				)
			) {
				$error = $this->makeWpError(
					'oras_qbo_reclass_source_claimed',
					'The matching QuickBooks SalesReceipt was claimed by another WooCommerce order before this write could be reserved.'
				);
				$error->add_data( array( 'retriable' => false ) );
				$this->handle_sync_failure( $order, $error );
				return $error;
			}

			$doc_number = isset( $prepared['doc_number'] ) ? (string) $prepared['doc_number'] : '';
			if ( $doc_number !== '' ) {
				$existing_remote = $this->journal_entry_creator->find_existing_journal_entry( $doc_number );
				if ( is_wp_error( $existing_remote ) ) {
					if ( $this->is_control_stop_error( $existing_remote ) ) {
						return $existing_remote;
					}
					$error = $this->make_duplicate_lookup_error(
						'Duplicate check failed before write; synchronization was stopped. ',
						$existing_remote
					);
					$this->handle_sync_failure( $order, $error );
					return $error;
				}

				if ( ! empty( $existing_remote['found'] ) ) {
					$entry = isset( $existing_remote['entry'] ) && is_array( $existing_remote['entry'] )
						? $existing_remote['entry']
						: array();
					$existing_id = isset( $entry['Id'] ) ? (string) $entry['Id'] : '';
					$existing_meta = isset( $existing_remote['meta'] ) && is_array( $existing_remote['meta'] )
						? $existing_remote['meta']
						: array();

					$validation = $this->journal_entry_creator->validate_existing_journal_entry(
						$entry,
						$doc_number,
						(string) ( $prepared['accounting_fingerprint'] ?? '' ),
						(string) ( $prepared['home_currency'] ?? '' )
					);
					if ( is_wp_error( $validation ) ) {
						$this->handle_sync_failure( $order, $validation );
						return $validation;
					}

					if ( $existing_id === '' ) {
						$error = $this->makeWpError(
							'oras_qbo_duplicate_lookup_invalid',
							'QuickBooks reported an existing JournalEntry without an ID; synchronization was stopped.'
						);
						$error->add_data( array( 'retriable' => false ) );
						$this->handle_sync_failure( $order, $error );
						return $error;
					}

					$guard = $this->get_dispatch_guard_error();
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					$payload = isset( $prepared['payload'] ) && is_array( $prepared['payload'] )
						? $prepared['payload']
						: array();
					$completed = $this->complete_order_sync(
						$order,
						$order_hash,
						$existing_id,
						$doc_number,
						(string) ( $existing_meta['intuit_tid'] ?? '' ),
						$split,
						$source_match,
						hash( 'sha256', is_string( wp_json_encode( $payload ) ) ? wp_json_encode( $payload ) : '' ),
						'duplicate_detected_remote',
						'already_synced_remote'
					);
					if ( is_wp_error( $completed ) ) {
						return $completed;
					}

					return array(
						'status'   => 'already_synced_remote',
						'order_id' => $order_id,
						'je_id'    => $existing_id,
					);
				}

				if ( $existing_je !== '' ) {
					$error = $this->makeWpError(
						'oras_qbo_local_je_unconfirmed',
						'A local JournalEntry ID exists, but QuickBooks did not confirm the deterministic document number; synchronization was stopped.'
					);
					$error->add_data( array( 'retriable' => false ) );
					$this->handle_sync_failure( $order, $error );
					return $error;
				}
			}

			return $this->dispatch_prepared_sync( $order, $order_hash, $prepared, $split, $source_match );
		};

		if ( $source_key === '' ) {
			return $dispatch();
		}

		$source_result = DbLock::withLock( 'qbo-source:' . $source_key, $dispatch, 1 );
		if ( is_wp_error( $source_result ) && $source_result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$error = $this->makeWpError(
				'oras_qbo_source_lock_in_progress',
				'Another order is currently reserving this QuickBooks SalesReceipt; synchronization will retry later.'
			);
			$error->add_data( array( 'retriable' => true ) );
			$this->handle_sync_failure( $order, $error );
			return $error;
		}

		return $source_result;
	}

	/**
	 * The sole application-level JournalEntry dispatch boundary. Durable
	 * intent, order/source locks, and final settings checks are established by
	 * the calling orchestration path before this method invokes Api_Client.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $prepared
	 * @return array<string,mixed>|\WP_Error
	 */
	private function dispatch_prepared_journal_entry(
		$order,
		array $prepared,
		bool $is_reversal,
		callable $prepare_write,
		callable $mark_dispatch_started
	) {
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload = isset( $prepared['payload'] ) && is_array( $prepared['payload'] ) ? $prepared['payload'] : array();
		$request_id = trim( (string) ( $prepared['request_id'] ?? '' ) );
		$expected_request_id = $this->journal_entry_creator->get_request_id( $payload );
		if ( $request_id === '' || empty( $payload ) || ! hash_equals( $expected_request_id, $request_id ) ) {
			$error = $this->makeWpError( 'oras_qbo_invalid_request_id', 'Prepared JournalEntry request ID does not match the exact payload.' );
			$error->add_data(
				array(
					'qbo_request_dispatched' => false,
					'retriable'              => false,
				)
			);
			return $error;
		}

		$response = $this->api_client->dispatch_journal_entry(
			$this,
			$payload,
			$prepare_write,
			$mark_dispatch_started,
			$request_id
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$journal_entry = isset( $response['JournalEntry'] ) && is_array( $response['JournalEntry'] )
			? $response['JournalEntry']
			: array();
		$je_id = trim( (string) ( $journal_entry['Id'] ?? '' ) );
		if ( $je_id === '' ) {
			$error = $this->makeWpError(
				$is_reversal ? 'oras_qbo_missing_reversal_je_id' : 'oras_qbo_missing_je_id',
				'QuickBooks response did not include a JournalEntry ID.'
			);
			$error->add_data(
				array(
					'qbo_request_dispatched' => true,
					'qbo_write_outcome'      => 'unknown',
					'retriable'              => false,
				)
			);
			return $error;
		}

		$doc_number = trim( (string) ( $prepared['doc_number'] ?? '' ) );
		$expected_fingerprint = trim( (string) ( $prepared['accounting_fingerprint'] ?? '' ) );
		$home_currency = trim( (string) ( $prepared['home_currency'] ?? '' ) );
		$validation = $this->journal_entry_creator->validate_existing_journal_entry(
			$journal_entry,
			$doc_number,
			$expected_fingerprint,
			$home_currency
		);
		if ( is_wp_error( $validation ) && $this->is_incomplete_journal_entry_response( $journal_entry ) ) {
			$lookup = $this->journal_entry_creator->find_existing_journal_entry( $doc_number );
			if ( is_wp_error( $lookup ) ) {
				return $this->make_dispatched_verification_error( 'lookup_failed', $lookup );
			}

			$lookup_entry = ! empty( $lookup['found'] ) && isset( $lookup['entry'] ) && is_array( $lookup['entry'] )
				? $lookup['entry']
				: array();
			$lookup_je_id = trim( (string) ( $lookup_entry['Id'] ?? '' ) );
			if ( $lookup_je_id === '' ) {
				return $this->make_dispatched_verification_error( 'lookup_not_found', $validation );
			}
			if ( ! hash_equals( $je_id, $lookup_je_id ) ) {
				return $this->make_dispatched_verification_error( 'identity_mismatch', $validation );
			}

			$validation = $this->journal_entry_creator->validate_existing_journal_entry(
				$lookup_entry,
				$doc_number,
				$expected_fingerprint,
				$home_currency
			);
			if ( ! is_wp_error( $validation ) ) {
				$journal_entry = $lookup_entry;
			}
		}
		if ( is_wp_error( $validation ) ) {
			return $this->make_dispatched_verification_error( 'content_mismatch', $validation );
		}

		$meta = isset( $response['__oras_meta'] ) && is_array( $response['__oras_meta'] )
			? $response['__oras_meta']
			: array();

		return array(
			'je_id'        => $je_id,
			'doc_number'   => $doc_number,
			'payload'      => $payload,
			'remote_entry' => $journal_entry,
			'response'     => $response,
			'intuit_tid'   => (string) ( $meta['intuit_tid'] ?? '' ),
		);
	}

	/**
	 * An incomplete create response may be confirmed by one guarded DocNumber
	 * lookup. A response containing material JournalEntry fields is validated
	 * as returned and is never replaced after a mismatch.
	 *
	 * @param array<string,mixed> $journal_entry
	 */
	private function is_incomplete_journal_entry_response( array $journal_entry ): bool {
		return trim( (string) ( $journal_entry['DocNumber'] ?? '' ) ) === ''
			|| ! isset( $journal_entry['Line'] )
			|| ! is_array( $journal_entry['Line'] )
			|| empty( $journal_entry['Line'] );
	}

	/**
	 * Any failed confirmation after a POST retains the durable pending marker:
	 * the remote write outcome is unknown and the request must not be repeated.
	 */
	private function make_dispatched_verification_error( string $reason, $verification_error ): \WP_Error {
		$error = $this->makeWpError(
			'oras_qbo_write_verification_failed',
			'QuickBooks did not return a JournalEntry that exactly matches the dispatched accounting payload.'
		);
		$error->add_data(
			array(
				'qbo_request_dispatched'  => true,
				'qbo_write_outcome'       => 'unknown',
				'retriable'               => false,
				'verification_reason'     => $reason,
				'verification_error_code' => is_wp_error( $verification_error )
					? $verification_error->get_error_code()
					: '',
			)
		);
		return $error;
	}

	/**
	 * Dispatch one prepared primary write while all required locks are held.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $prepared
	 * @param array<string,mixed> $split
	 * @param array<string,mixed> $source_match
	 * @return array<string,mixed>|\WP_Error
	 */
	private function dispatch_prepared_sync( $order, string $order_hash, array $prepared, array $split, array $source_match ) {
		$doc_number = (string) ( $prepared['doc_number'] ?? '' );
		$request_id = (string) ( $prepared['request_id'] ?? '' );
		$payload = isset( $prepared['payload'] ) && is_array( $prepared['payload'] ) ? $prepared['payload'] : array();
		$encoded_payload = wp_json_encode( $payload );
		$payload_hash    = hash( 'sha256', is_string( $encoded_payload ) ? $encoded_payload : '' );
		$attempt_id = $this->generate_attempt_id();
		$pending_intent = null;
		$prepare_write = function () use (
			$order,
			$order_hash,
			$doc_number,
			$request_id,
			$payload_hash,
			$prepared,
			$payload,
			$split,
			$source_match,
			$attempt_id,
			&$pending_intent
		) {
			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			if ( is_array( $pending_intent ) ) {
				return $this->verify_pending_write_persisted( $order, $pending_intent, $source_match, 'sync' );
			}
			$recorded = $this->record_pending_write(
				$order,
				$order_hash,
				$doc_number,
				$request_id,
				$payload_hash,
				(string) ( $prepared['accounting_fingerprint'] ?? '' ),
				(string) ( $prepared['home_currency'] ?? '' ),
				(string) ( $prepared['transaction_currency'] ?? '' ),
				! empty( $prepared['multicurrency_enabled'] ),
				(string) ( $prepared['exchange_rate'] ?? '' ),
				$payload,
				$split,
				$source_match,
				'sync',
				$attempt_id
			);
			if ( is_array( $recorded ) ) {
				$pending_intent = $recorded;
				return true;
			}
			return $recorded;
		};
		$mark_dispatch_started = function () use ( $order, $source_match, &$pending_intent ) {
			if ( ! is_array( $pending_intent ) ) {
				return $this->makeWpError(
					'oras_qbo_dispatch_marker_failed',
					'QuickBooks write intent was not durably prepared; no request was sent.'
				);
			}

			return $this->mark_pending_write_dispatched( $order, $pending_intent, $source_match, 'sync' );
		};

		$result = $this->dispatch_prepared_journal_entry(
			$order,
			$prepared,
			false,
			$prepare_write,
			$mark_dispatch_started
		);
		if ( is_wp_error( $result ) ) {
			if ( $this->is_unknown_write_error( $result ) ) {
				return $this->mark_write_outcome_unknown( $order, $result );
			}

			$this->clear_pending_write( $order, is_array( $pending_intent ) ? $pending_intent : array() );
			if ( $this->is_control_stop_error( $result ) ) {
				return $result;
			}
			$this->handle_sync_failure( $order, $result );
			return $result;
		}

		$je_id = isset( $result['je_id'] ) ? (string) $result['je_id'] : '';
		if ( $je_id === '' ) {
			$error = $this->makeWpError( 'oras_qbo_missing_je_id', 'QuickBooks sync completed without a JournalEntry ID.' );
			$error->add_data( array( 'qbo_request_dispatched' => true ) );
			return $this->mark_write_outcome_unknown( $order, $error );
		}

		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$completed = $this->complete_order_sync(
			$order,
			$order_hash,
			$je_id,
			$doc_number,
			(string) ( $result['intuit_tid'] ?? '' ),
			$split,
			$source_match,
			$payload_hash,
			'sync_success',
			'synced'
		);
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}

		return array(
			'status'   => 'synced',
			'order_id' => (int) $order->get_id(),
			'je_id'    => $je_id,
			'split'    => $split,
		);
	}

	/**
	 * Validate the exact persisted payload, request ID, fingerprint, operation,
	 * and current company home-currency context before reconciliation.
	 *
	 * @param array<string,mixed> $pending
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_pending_write_intent(
		array $pending,
		int $expected_order_id = 0,
		bool $require_sync_enabled = true
	) {
		$doc_number = trim( (string) ( $pending['doc_number'] ?? '' ) );
		$request_id = trim( (string) ( $pending['request_id'] ?? '' ) );
		$payload = isset( $pending['payload'] ) && is_array( $pending['payload'] ) ? $pending['payload'] : array();
		$expected_fingerprint = trim( (string) ( $pending['accounting_fingerprint'] ?? '' ) );
		$stored_home_currency = strtoupper( trim( (string) ( $pending['home_currency'] ?? '' ) ) );
		$stored_transaction_currency = strtoupper( trim( (string) ( $pending['transaction_currency'] ?? '' ) ) );
		$stored_multicurrency = $pending['multicurrency_enabled'] ?? null;
		$stored_exchange_rate = trim( (string) ( $pending['exchange_rate'] ?? '' ) );
		$attempt_id = trim( (string) ( $pending['attempt_id'] ?? '' ) );
		$order_id = absint( $pending['order_id'] ?? 0 );
		$operation = sanitize_key( (string) ( $pending['operation'] ?? '' ) );
		$stored_payload_hash = trim( (string) ( $pending['payload_hash'] ?? '' ) );
		$encoded_payload     = wp_json_encode( $payload );
		$actual_payload_hash = hash( 'sha256', is_string( $encoded_payload ) ? $encoded_payload : '' );
		$expected_request_id = $this->journal_entry_creator->get_request_id( $payload );

		if (
			$doc_number === ''
			|| $request_id === ''
			|| empty( $payload )
			|| $expected_fingerprint === ''
			|| $stored_home_currency === ''
			|| $stored_transaction_currency === ''
			|| ! is_bool( $stored_multicurrency )
			|| $stored_exchange_rate === ''
			|| $attempt_id === ''
			|| $order_id <= 0
			|| ( $expected_order_id > 0 && $order_id !== $expected_order_id )
			|| ! in_array( $operation, array( 'sync', 'reversal' ), true )
			|| trim( (string) ( $payload['DocNumber'] ?? '' ) ) !== $doc_number
			|| $stored_payload_hash === ''
			|| ! hash_equals( $stored_payload_hash, $actual_payload_hash )
			|| ! hash_equals( $expected_request_id, $request_id )
		) {
			$error = $this->makeWpError(
				'oras_qbo_pending_write_invalid',
				'Pending QuickBooks write metadata does not contain the exact payload, request ID, and fingerprint required for safe reconciliation.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		$currency_context = $this->journal_entry_creator->get_verified_company_currency_context( $require_sync_enabled );
		if ( is_wp_error( $currency_context ) ) {
			return $currency_context;
		}
		$home_currency = strtoupper( trim( (string) ( $currency_context['home_currency'] ?? '' ) ) );
		$multicurrency_enabled = isset( $currency_context['multicurrency_enabled'] )
			&& $currency_context['multicurrency_enabled'] === true;
		if ( $home_currency === '' || ! hash_equals( $stored_home_currency, $home_currency ) ) {
			$error = $this->makeWpError(
				'oras_qbo_pending_home_currency_mismatch',
				'The current QuickBooks company home currency does not match the verified pending write context.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}
		if ( $stored_multicurrency !== $multicurrency_enabled ) {
			$error = $this->makeWpError(
				'oras_qbo_pending_multicurrency_mismatch',
				'The current QuickBooks multicurrency preference does not match the verified pending write context.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}
		if ( $stored_transaction_currency !== $home_currency && ! $multicurrency_enabled ) {
			$error = $this->makeWpError(
				'oras_qbo_multicurrency_disabled',
				'QuickBooks multicurrency is disabled, so this foreign-currency pending write cannot be reconciled or retried.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}
		$payload_currency = isset( $payload['CurrencyRef'] ) && is_array( $payload['CurrencyRef'] )
			? strtoupper( trim( (string) ( $payload['CurrencyRef']['value'] ?? '' ) ) )
			: '';
		$payload_exchange_rate = trim( (string) ( $payload['ExchangeRate'] ?? ( $stored_transaction_currency === $home_currency ? '1' : '' ) ) );
		if (
			$payload_currency !== $stored_transaction_currency
			|| $this->journal_entry_creator->normalize_decimal_for_comparison( $payload_exchange_rate )
				!== $this->journal_entry_creator->normalize_decimal_for_comparison( $stored_exchange_rate )
		) {
			$error = $this->makeWpError(
				'oras_qbo_pending_currency_context_mismatch',
				'Pending QuickBooks currency or ExchangeRate metadata does not match the exact persisted payload.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		$actual_fingerprint = $this->journal_entry_creator->get_accounting_fingerprint( $payload, $home_currency );
		if ( ! hash_equals( $expected_fingerprint, $actual_fingerprint ) ) {
			$error = $this->makeWpError(
				'oras_qbo_pending_write_invalid',
				'Pending QuickBooks write metadata does not contain the exact payload, request ID, and fingerprint required for safe reconciliation.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		return array(
			'payload'                => $payload,
			'doc_number'             => $doc_number,
			'request_id'             => $request_id,
			'accounting_fingerprint' => $expected_fingerprint,
			'home_currency'          => $home_currency,
			'transaction_currency'   => $stored_transaction_currency,
			'multicurrency_enabled'  => $multicurrency_enabled,
			'exchange_rate'          => $stored_exchange_rate,
			'attempt_id'             => $attempt_id,
			'order_id'               => $order_id,
			'operation'              => $operation,
			'source_match'           => isset( $pending['source_match'] ) && is_array( $pending['source_match'] )
				? $pending['source_match']
				: array(),
		);
	}

	/**
	 * If a previous process stopped after persisting write intent, query by
	 * deterministic DocNumber and never issue another write automatically.
	 *
	 * @param \WC_Order $order
	 * @return array<string,mixed>|\WP_Error|null
	 */
	private function reconcile_pending_write( $order ) {
		$write_state = trim( (string) $order->get_meta( self::META_WRITE_STATE, true ) );
		$pending_raw = trim( (string) $order->get_meta( self::META_PENDING_WRITE, true ) );
		if ( $write_state === '' && $pending_raw === '' ) {
			return null;
		}

		$pending = json_decode( $pending_raw, true );
		if ( ! is_array( $pending ) ) {
			return $this->mark_write_outcome_unknown(
				$order,
				$this->makeWpError( 'oras_qbo_pending_write_invalid', 'Pending QuickBooks write metadata is incomplete.' )
			);
		}

		$prepared = $this->validate_pending_write_intent( $pending, (int) $order->get_id() );
		if ( is_wp_error( $prepared ) ) {
			return $this->mark_write_outcome_unknown(
				$order,
				$prepared
			);
		}

		$dispatch_started_at = trim( (string) ( $pending['dispatch_started_at'] ?? '' ) );
		$dispatch_history = isset( $pending['dispatch_history'] ) && is_array( $pending['dispatch_history'] )
			? array_filter( array_map( 'strval', $pending['dispatch_history'] ) )
			: array();
		$retry_prepared_at = trim( (string) ( $pending['retry_prepared_at'] ?? '' ) );
		if ( $dispatch_started_at === '' && empty( $dispatch_history ) && $retry_prepared_at === '' ) {
			$this->clear_pending_write( $order, $pending );
			$reloaded = wc_get_order( (int) $order->get_id() );
			if ( ! $reloaded || trim( (string) $reloaded->get_meta( self::META_PENDING_WRITE, true ) ) !== '' ) {
				$error = $this->makeWpError(
					'oras_qbo_pre_dispatch_cleanup_failed',
					'A conclusively pre-dispatch QuickBooks reservation could not be released; no request was sent.'
				);
				$error->add_data(
					array(
						'retriable'              => true,
						'qbo_request_dispatched' => false,
					)
				);
				return $error;
			}

			return null;
		}

		$doc_number = (string) $prepared['doc_number'];
		$operation = (string) $prepared['operation'];
		$source_match = (array) ( $prepared['source_match'] ?? array() );
		$source_key = $operation === 'sync' ? trim( (string) ( $source_match['key'] ?? '' ) ) : '';

		$reconcile = function () use ( $order, $pending, $prepared, $doc_number, $operation, $source_match ) {
			if (
				$operation === 'sync'
				&& ! empty( $source_match )
				&& $this->journal_entry_creator->is_reclass_source_key_claimed(
					(string) ( $source_match['key'] ?? '' ),
					(int) $order->get_id()
				)
			) {
				$error = $this->makeWpError(
					'oras_qbo_reclass_source_claimed',
					'The pending QuickBooks SalesReceipt is now claimed by another WooCommerce order; no adoption occurred.'
				);
				$error->add_data( array( 'retriable' => false ) );
				return $this->retain_unknown_reconciliation_error(
					$order,
					'pending_write_source_claimed',
					$error
				);
			}

			$existing_remote = $this->journal_entry_creator->find_existing_journal_entry( $doc_number );
			if ( is_wp_error( $existing_remote ) ) {
				if ( $this->is_control_stop_error( $existing_remote ) ) {
					return $existing_remote;
				}
				return $this->retain_unknown_reconciliation_error(
					$order,
					'pending_write_lookup_failed',
					$this->make_duplicate_lookup_error(
						'Pending write reconciliation could not query QuickBooks; no write was attempted. ',
						$existing_remote
					)
				);
			}

			if ( empty( $existing_remote['found'] ) ) {
				return $this->mark_write_outcome_unknown(
					$order,
					$this->makeWpError(
						'oras_qbo_pending_write_not_confirmed',
						'QuickBooks did not return the pending JournalEntry; manual confirmation is required before another write.'
					)
				);
			}

			$entry = isset( $existing_remote['entry'] ) && is_array( $existing_remote['entry'] ) ? $existing_remote['entry'] : array();
			$je_id = trim( (string) ( $entry['Id'] ?? '' ) );
			if ( $je_id === '' ) {
				return $this->mark_write_outcome_unknown(
					$order,
					$this->makeWpError( 'oras_qbo_pending_write_missing_id', 'QuickBooks returned the pending JournalEntry without an ID.' )
				);
			}

			$validation = $this->journal_entry_creator->validate_existing_journal_entry(
				$entry,
				$doc_number,
				(string) $prepared['accounting_fingerprint'],
				(string) $prepared['home_currency']
			);
			if ( is_wp_error( $validation ) ) {
				return $this->retain_unknown_reconciliation_error(
					$order,
					'pending_write_fingerprint_mismatch',
					$validation
				);
			}

			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			$meta = isset( $existing_remote['meta'] ) && is_array( $existing_remote['meta'] ) ? $existing_remote['meta'] : array();
			if ( $operation === 'reversal' ) {
				$completed = $this->complete_reversal_sync(
					$order,
					'pending_reversal_write_detected_remote',
					$je_id,
					$doc_number,
					(string) ( $meta['intuit_tid'] ?? '' )
				);
				if ( is_wp_error( $completed ) ) {
					return $completed;
				}

				return array(
					'status'         => 'already_reversed_remote',
					'order_id'       => (int) $order->get_id(),
					'reversal_je_id' => $je_id,
				);
			}

			$split = isset( $pending['split'] ) && is_array( $pending['split'] ) ? $pending['split'] : array();
			$completed = $this->complete_order_sync(
				$order,
				(string) ( $pending['order_hash'] ?? '' ),
				$je_id,
				$doc_number,
				(string) ( $meta['intuit_tid'] ?? '' ),
				$split,
				$source_match,
				(string) ( $pending['payload_hash'] ?? '' ),
				'pending_write_detected_remote',
				'already_synced_remote'
			);
			if ( is_wp_error( $completed ) ) {
				return $completed;
			}

			return array(
				'status'   => 'already_synced_remote',
				'order_id' => (int) $order->get_id(),
				'je_id'    => $je_id,
			);
		};

		$result = $source_key === ''
			? $reconcile()
			: DbLock::withLock( 'qbo-source:' . $source_key, $reconcile, 1 );
		if ( is_wp_error( $result ) && $result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$error = $this->makeWpError(
				'oras_qbo_source_lock_in_progress',
				'Another order is currently reserving this QuickBooks SalesReceipt; unknown state was retained.'
			);
			$error->add_data( array( 'retriable' => true ) );
			return $this->retain_unknown_reconciliation_error(
				$order,
				'pending_write_source_lock_failed',
				$error
			);
		}

		return $result;
	}

	/**
	 * @param \WC_Order $order
	 * @param array<string,mixed> $split
	 * @param array<string,mixed> $source_match
	 */
	private function record_pending_write(
		$order,
		string $order_hash,
		string $doc_number,
		string $request_id,
		string $payload_hash,
		string $accounting_fingerprint,
		string $home_currency,
		string $transaction_currency,
		bool $multicurrency_enabled,
		string $exchange_rate,
		array $payload,
		array $split,
		array $source_match,
		string $operation,
		string $attempt_id
	) {
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$snapshot = $this->build_split_snapshot( $split );
		$started_at = gmdate( 'c' );
		$existing_doc_number = trim( (string) $order->get_meta( self::META_DOC_NUMBER, true ) );
		$provisional_doc_number = $operation === 'sync' && $existing_doc_number === '';
		$pending = array(
			'order_id'               => (int) $order->get_id(),
			'attempt_id'             => $attempt_id,
			'doc_number'             => $doc_number,
			'request_id'             => $request_id,
			'order_hash'             => $order_hash,
			'payload_hash'           => $payload_hash,
			'accounting_fingerprint' => $accounting_fingerprint,
			'home_currency'          => $home_currency,
			'transaction_currency'   => $transaction_currency,
			'multicurrency_enabled'  => $multicurrency_enabled,
			'exchange_rate'          => $exchange_rate,
			'operation'              => $operation,
			'provisional_doc_number' => $provisional_doc_number,
			'payload'                => $payload,
			'split'                  => $snapshot,
			'source_match'           => $source_match,
			'started_at'             => $started_at,
			'dispatch_started_at'    => '',
		);

		try {
			$order->update_meta_data( self::META_PENDING_WRITE, wp_json_encode( $pending ) );
			$order->update_meta_data( self::META_WRITE_STATE, 'pending' );
			$order->update_meta_data( '_oras_qbo_sync_status', 'pending_qbo_write' );
			if ( $operation === 'sync' ) {
				$order->update_meta_data( self::META_DOC_NUMBER, $doc_number );
				if ( $provisional_doc_number ) {
					$order->update_meta_data( self::META_DOC_NUMBER_ATTEMPT, $attempt_id );
				}
			}

			if ( ! empty( $source_match ) ) {
				$order->update_meta_data( '_oras_qbo_reclass_source_txn_key', (string) ( $source_match['key'] ?? '' ) );
				$order->update_meta_data( '_oras_qbo_reclass_source_txn_id', (string) ( $source_match['id'] ?? '' ) );
				$order->update_meta_data( '_oras_qbo_reclass_source_txn_type', (string) ( $source_match['entity'] ?? '' ) );
				$order->update_meta_data( '_oras_qbo_reclass_source_txn_date', (string) ( $source_match['txn_date'] ?? '' ) );
				$order->update_meta_data( self::META_SOURCE_CLAIM_STATE, 'pending' );
				$order->update_meta_data( self::META_SOURCE_CLAIM_ATTEMPT, $attempt_id );
				$order->update_meta_data( self::META_SOURCE_CLAIM_REQUEST, $request_id );
				$order->update_meta_data( self::META_SOURCE_CLAIM_OPERATION, $operation );
			}

			$guard = $this->get_dispatch_guard_error();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$order->save();

			$verification = $this->verify_pending_write_persisted( $order, $pending, $source_match, $operation );
			if ( is_wp_error( $verification ) ) {
				$this->clear_pending_write( $order, $pending );
				return $verification;
			}

			$this->append_audit_entry(
				$order,
				'journal_entry_write_pending',
				array(
					'doc_number'   => $doc_number,
					'request_id'   => $request_id,
					'payload_hash' => $payload_hash,
					'source_txn'   => (string) ( $source_match['key'] ?? '' ),
					'operation'    => $operation,
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->clear_pending_write( $order, $pending );
			$error = $this->makeWpError(
				'oras_qbo_write_reservation_failed',
				'Could not persist the QuickBooks write reservation; no request was sent.'
			);
			$error->add_data(
				array(
					'retriable' => true,
					'exception' => get_class( $throwable ),
				)
			);
			return $error;
		}

		return $pending;
	}

	/**
	 * Confirm the exact write intent and source reservation can be read from a
	 * newly-loaded order before any QuickBooks POST is allowed to start.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $pending
	 * @param array<string,mixed> $source_match
	 * @return true|\WP_Error
	 */
	private function verify_pending_write_persisted( $order, array $pending, array $source_match, string $operation ) {
		$order_id = (int) $order->get_id();
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $order_id );
		}

		$persisted_order = wc_get_order( $order_id );
		$expected_pending = wp_json_encode( $pending );
		$verified = $persisted_order
			&& is_string( $expected_pending )
			&& (int) ( $pending['order_id'] ?? 0 ) === $order_id
			&& trim( (string) ( $pending['attempt_id'] ?? '' ) ) !== ''
			&& hash_equals( $expected_pending, (string) $persisted_order->get_meta( self::META_PENDING_WRITE, true ) )
			&& (string) $persisted_order->get_meta( self::META_WRITE_STATE, true ) === 'pending'
			&& (string) $persisted_order->get_meta( '_oras_qbo_sync_status', true ) === 'pending_qbo_write';

		if ( $verified && $operation === 'sync' ) {
			$verified = (string) $persisted_order->get_meta( self::META_DOC_NUMBER, true )
				=== (string) ( $pending['doc_number'] ?? '' );
			if ( $verified && ! empty( $pending['provisional_doc_number'] ) ) {
				$verified = (string) $persisted_order->get_meta( self::META_DOC_NUMBER_ATTEMPT, true )
					=== (string) ( $pending['attempt_id'] ?? '' );
			}
		}

		if ( $verified && ! empty( $source_match ) ) {
			$verified = (string) $persisted_order->get_meta( '_oras_qbo_reclass_source_txn_key', true )
				=== (string) ( $source_match['key'] ?? '' )
				&& (string) $persisted_order->get_meta( '_oras_qbo_reclass_source_txn_id', true )
				=== (string) ( $source_match['id'] ?? '' )
				&& (string) $persisted_order->get_meta( '_oras_qbo_reclass_source_txn_type', true )
				=== (string) ( $source_match['entity'] ?? '' )
				&& (string) $persisted_order->get_meta( '_oras_qbo_reclass_source_txn_date', true )
				=== (string) ( $source_match['txn_date'] ?? '' )
				&& (string) $persisted_order->get_meta( self::META_SOURCE_CLAIM_STATE, true ) === 'pending';
			$verified = $verified
				&& (string) $persisted_order->get_meta( self::META_SOURCE_CLAIM_ATTEMPT, true )
					=== (string) ( $pending['attempt_id'] ?? '' )
				&& (string) $persisted_order->get_meta( self::META_SOURCE_CLAIM_REQUEST, true )
					=== (string) ( $pending['request_id'] ?? '' )
				&& (string) $persisted_order->get_meta( self::META_SOURCE_CLAIM_OPERATION, true )
					=== $operation;
		}

		if ( $verified ) {
			return true;
		}

		$error = $this->makeWpError(
			'oras_qbo_write_reservation_failed',
			'Could not verify the persisted QuickBooks write reservation; no request was sent.'
		);
		$error->add_data(
			array(
				'retriable'              => true,
				'qbo_request_dispatched' => false,
			)
		);
		return $error;
	}

	/**
	 * Persist and reload the dispatch marker at the final HTTP boundary. The
	 * API client has already run its last control guard, so this method must
	 * perform no control check that could abort after the marker is durable.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $pending
	 * @param array<string,mixed> $source_match
	 * @return true|\WP_Error
	 */
	private function mark_pending_write_dispatched( $order, array &$pending, array $source_match, string $operation ) {
		try {
			$pending['dispatch_started_at'] = gmdate( 'c' );
			$order->update_meta_data( self::META_PENDING_WRITE, wp_json_encode( $pending ) );
			$order->update_meta_data( self::META_WRITE_STATE, 'pending' );
			$order->update_meta_data( '_oras_qbo_sync_status', 'pending_qbo_write' );
			$order->save();

			$verification = $this->verify_pending_write_persisted( $order, $pending, $source_match, $operation );
			if ( is_wp_error( $verification ) ) {
				$error = $this->makeWpError(
					'oras_qbo_dispatch_marker_failed',
					'QuickBooks dispatch marker could not be verified; no request was sent.'
				);
				$error->add_data(
					array(
						'retriable'               => true,
						'qbo_request_dispatched'  => false,
						'verification_error_code' => $verification->get_error_code(),
					)
				);
				return $error;
			}
		} catch ( \Throwable $throwable ) {
			$error = $this->makeWpError(
				'oras_qbo_dispatch_marker_failed',
				'QuickBooks dispatch marker could not be persisted; no request was sent.'
			);
			$error->add_data(
				array(
					'retriable'              => true,
					'qbo_request_dispatched' => false,
					'exception'              => get_class( $throwable ),
				)
			);
			return $error;
		}

		return true;
	}

	/**
	 * @param \WC_Order $order
	 * @param \WP_Error $original_error
	 * @return \WP_Error
	 */
	private function mark_write_outcome_unknown( $order, $original_error ) {
		if ( $this->is_dry_run_mode() || ! Settings::is_enabled() ) {
			$error = $this->makeWpError(
				'oras_qbo_write_outcome_unknown',
				'QuickBooks may have accepted the JournalEntry, but no conclusive response was received. Pending protection was retained without further local changes.'
			);
			$error->add_data(
				array(
					'retriable'           => false,
					'original_error_code' => $original_error->get_error_code(),
				)
			);
			return $error;
		}
		$pending = json_decode( (string) $order->get_meta( self::META_PENDING_WRITE, true ), true );
		$pending_doc_number = is_array( $pending ) ? (string) ( $pending['doc_number'] ?? '' ) : '';
		$order->update_meta_data( self::META_WRITE_STATE, 'unknown_outcome' );
		$order->update_meta_data( '_oras_qbo_sync_status', 'qbo_write_unknown' );
		$order->update_meta_data( '_oras_qbo_sync_error_code', 'oras_qbo_write_outcome_unknown' );
		$order->update_meta_data(
			'_oras_qbo_sync_error',
			sanitize_text_field( 'QuickBooks JournalEntry write outcome is unknown. ' . $original_error->get_error_message() )
		);
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $this->makeWpError(
				'oras_qbo_write_outcome_unknown',
				'QuickBooks may have accepted the JournalEntry, but no conclusive response was received. Pending protection was retained without further local changes.',
				array(
					'retriable'           => false,
					'original_error_code' => $original_error->get_error_code(),
				)
			);
		}
		$order->save();

		$this->append_audit_entry(
			$order,
			'journal_entry_write_outcome_unknown',
			array(
				'original_error_code' => $original_error->get_error_code(),
				'doc_number'          => $pending_doc_number,
			)
		);

		$error = $this->makeWpError(
			'oras_qbo_write_outcome_unknown',
			'QuickBooks may have accepted the JournalEntry, but no conclusive response was received. No automatic rewrite will occur.'
		);
		$error->add_data(
			array(
				'retriable'           => false,
				'original_error_code' => $original_error->get_error_code(),
			)
		);
		return $error;
	}

	/**
	 * Clear only metadata owned by the exact conclusively rejected attempt.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $expected_pending
	 */
	private function clear_pending_write( $order, array $expected_pending ): void {
		if ( empty( $expected_pending ) || $this->is_dry_run_mode() || ! Settings::is_enabled() ) {
			return;
		}

		$current_pending_raw = (string) $order->get_meta( self::META_PENDING_WRITE, true );
		$current_pending = json_decode( $current_pending_raw, true );
		$pending_owned = is_array( $current_pending )
			? $this->pending_attempt_ownership_matches( $order, $expected_pending, $current_pending )
			: $this->reservation_ownership_matches( $order, $expected_pending );
		if ( ! $pending_owned ) {
			return;
		}

		$source_match = isset( $expected_pending['source_match'] ) && is_array( $expected_pending['source_match'] )
			? $expected_pending['source_match']
			: array();
		$source_owned = ! empty( $source_match )
			&& (string) $order->get_meta( self::META_SOURCE_CLAIM_STATE, true ) === 'pending'
			&& (string) $order->get_meta( self::META_SOURCE_CLAIM_ATTEMPT, true ) === (string) ( $expected_pending['attempt_id'] ?? '' )
			&& (string) $order->get_meta( self::META_SOURCE_CLAIM_REQUEST, true ) === (string) ( $expected_pending['request_id'] ?? '' )
			&& (string) $order->get_meta( self::META_SOURCE_CLAIM_OPERATION, true ) === (string) ( $expected_pending['operation'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_key', true ) === (string) ( $source_match['key'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_id', true ) === (string) ( $source_match['id'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_type', true ) === (string) ( $source_match['entity'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_date', true ) === (string) ( $source_match['txn_date'] ?? '' );
		if ( $source_owned ) {
			$this->deleteOrderMeta( $order, '_oras_qbo_reclass_source_txn_key' );
			$this->deleteOrderMeta( $order, '_oras_qbo_reclass_source_txn_id' );
			$this->deleteOrderMeta( $order, '_oras_qbo_reclass_source_txn_type' );
			$this->deleteOrderMeta( $order, '_oras_qbo_reclass_source_txn_date' );
			$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_STATE );
			$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_ATTEMPT );
			$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_REQUEST );
			$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_OPERATION );
		}

		if (
			! empty( $expected_pending['provisional_doc_number'] )
			&& (string) $order->get_meta( self::META_DOC_NUMBER_ATTEMPT, true ) === (string) ( $expected_pending['attempt_id'] ?? '' )
			&& (string) $order->get_meta( self::META_DOC_NUMBER, true ) === (string) ( $expected_pending['doc_number'] ?? '' )
			&& trim( (string) $order->get_meta( '_oras_qbo_je_id', true ) ) === ''
			&& trim( (string) $order->get_meta( '_oras_qbo_reversal_je_id', true ) ) === ''
		) {
			$this->deleteOrderMeta( $order, self::META_DOC_NUMBER );
			$this->deleteOrderMeta( $order, self::META_DOC_NUMBER_ATTEMPT );
		}

		$this->deleteOrderMeta( $order, self::META_PENDING_WRITE );
		$this->deleteOrderMeta( $order, self::META_WRITE_STATE );
		if ( ! $this->controls_block_persistence() ) {
			$order->save();
		}
	}

	/**
	 * @param array<string,mixed> $expected
	 * @param array<string,mixed> $current
	 */
	private function pending_attempt_ownership_matches( $order, array $expected, array $current ): bool {
		$expected_source = isset( $expected['source_match'] ) && is_array( $expected['source_match'] )
			? (string) ( $expected['source_match']['key'] ?? '' )
			: '';
		$current_source = isset( $current['source_match'] ) && is_array( $current['source_match'] )
			? (string) ( $current['source_match']['key'] ?? '' )
			: '';

		return (int) ( $expected['order_id'] ?? 0 ) === (int) $order->get_id()
			&& (int) ( $current['order_id'] ?? 0 ) === (int) $order->get_id()
			&& trim( (string) ( $expected['attempt_id'] ?? '' ) ) !== ''
			&& hash_equals( (string) $expected['attempt_id'], (string) ( $current['attempt_id'] ?? '' ) )
			&& hash_equals( (string) ( $expected['request_id'] ?? '' ), (string) ( $current['request_id'] ?? '' ) )
			&& (string) ( $expected['operation'] ?? '' ) === (string) ( $current['operation'] ?? '' )
			&& $expected_source === $current_source;
	}

	/**
	 * Allow cleanup after the pending JSON itself failed to persist only when
	 * a source or DocNumber owner marker proves this exact attempt owns the
	 * provisional metadata that remains.
	 *
	 * @param array<string,mixed> $expected
	 */
	private function reservation_ownership_matches( $order, array $expected ): bool {
		if ( (int) ( $expected['order_id'] ?? 0 ) !== (int) $order->get_id() ) {
			return false;
		}
		$attempt_id = trim( (string) ( $expected['attempt_id'] ?? '' ) );
		if ( $attempt_id === '' ) {
			return false;
		}
		$doc_owned = ! empty( $expected['provisional_doc_number'] )
			&& (string) $order->get_meta( self::META_DOC_NUMBER_ATTEMPT, true ) === $attempt_id;
		$expected_source = isset( $expected['source_match'] ) && is_array( $expected['source_match'] )
			? $expected['source_match']
			: array();
		$source_owned = ! empty( $expected_source )
			&& (string) $order->get_meta( self::META_SOURCE_CLAIM_ATTEMPT, true ) === $attempt_id
			&& (string) $order->get_meta( self::META_SOURCE_CLAIM_REQUEST, true ) === (string) ( $expected['request_id'] ?? '' )
			&& (string) $order->get_meta( self::META_SOURCE_CLAIM_OPERATION, true ) === (string) ( $expected['operation'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_key', true ) === (string) ( $expected_source['key'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_id', true ) === (string) ( $expected_source['id'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_type', true ) === (string) ( $expected_source['entity'] ?? '' )
			&& (string) $order->get_meta( '_oras_qbo_reclass_source_txn_date', true ) === (string) ( $expected_source['txn_date'] ?? '' );
		return $doc_owned || $source_owned;
	}

	private function is_unknown_write_error( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) || empty( $data['qbo_request_dispatched'] ) ) {
			return false;
		}
		return (string) ( $data['qbo_write_outcome'] ?? 'unknown' ) !== 'conclusive_rejection';
	}

	/**
	 * Duplicate lookups always stop the operation and never enter automatic
	 * retry handling, even when the underlying transport error was retriable.
	 */
	private function make_duplicate_lookup_error( string $message_prefix, $lookup_error ): \WP_Error {
		$data = is_wp_error( $lookup_error ) && is_array( $lookup_error->get_error_data() )
			? $lookup_error->get_error_data()
			: array();
		$data['retriable'] = false;
		$data['lookup_error_code'] = is_wp_error( $lookup_error ) ? $lookup_error->get_error_code() : '';

		$error = $this->makeWpError(
			'oras_qbo_duplicate_lookup_failed',
			$message_prefix . ( is_wp_error( $lookup_error ) ? $lookup_error->get_error_message() : 'Unknown lookup failure.' )
		);
		$error->add_data( $data );
		return $error;
	}

	/**
	 * Persist a confirmed reversal and clear only the transient write-intent
	 * metadata. The original sync and source claim remain intact.
	 *
	 * @param \WC_Order $order
	 * @return true|\WP_Error
	 */
	private function complete_reversal_sync(
		$order,
		string $audit_event,
		string $reversal_je_id,
		string $doc_number,
		string $intuit_tid
	) {
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$order->update_meta_data( '_oras_qbo_reversal_je_id', $reversal_je_id );
		$order->update_meta_data( '_oras_qbo_reversal_at', gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( '_oras_qbo_sync_status', 'reversed' );
		$order->update_meta_data( self::META_LAST_INTUIT_TID, $intuit_tid );
		$this->deleteOrderMeta( $order, self::META_PENDING_WRITE );
		$this->deleteOrderMeta( $order, self::META_WRITE_STATE );
		$this->deleteOrderMeta( $order, self::META_DOC_NUMBER_ATTEMPT );
		$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_ATTEMPT );
		$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_REQUEST );
		$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_OPERATION );
		$this->deleteOrderMeta( $order, '_oras_qbo_sync_error' );
		$this->deleteOrderMeta( $order, '_oras_qbo_sync_error_code' );
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$order->save();

		$this->append_audit_entry(
			$order,
			$audit_event,
			array(
				'reversal_je_id' => $reversal_je_id,
				'doc_number'     => $doc_number,
				'intuit_tid'     => $intuit_tid,
			)
		);
		return true;
	}

	/**
	 * @param \WC_Order $order
	 * @param array<string,mixed> $split
	 * @param array<string,mixed> $source_match
	 * @return true|\WP_Error
	 */
	private function complete_order_sync(
		$order,
		string $order_hash,
		string $je_id,
		string $doc_number,
		string $intuit_tid,
		array $split,
		array $source_match,
		string $payload_hash,
		string $audit_event,
		string $result_status
	) {
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
        $order->update_meta_data('_oras_qbo_je_id', $je_id);
        $order->update_meta_data('_oras_qbo_je_hash', $order_hash);
        $order->update_meta_data('_oras_qbo_sync_status', 'synced');
		$order->update_meta_data( self::META_SYNCED, $now );
		$order->update_meta_data( '_oras_qbo_synced_at', $now );
        $order->update_meta_data(self::META_DOC_NUMBER, $doc_number);
        $order->update_meta_data(self::META_LAST_INTUIT_TID, $intuit_tid);
		$order->update_meta_data( self::META_SPLIT_SNAPSHOT, wp_json_encode( $this->build_split_snapshot( $split ) ) );
		$order->update_meta_data( '_oras_qbo_last_payload_hash', $payload_hash );
        $this->deleteOrderMeta($order, self::META_WAIT_NEXT_CHECK_AT);
        $this->deleteOrderMeta($order, self::META_WAIT_LAST_CHECK_AT);
        $this->deleteOrderMeta($order, self::META_WAIT_FIRST_AT);
        $this->deleteOrderMeta($order, self::META_WAIT_ATTEMPTS);
		$this->deleteOrderMeta( $order, self::META_PENDING_WRITE );
		$this->deleteOrderMeta( $order, self::META_WRITE_STATE );
		$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_STATE );
		$this->deleteOrderMeta( $order, self::META_DOC_NUMBER_ATTEMPT );
		$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_ATTEMPT );
		$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_REQUEST );
		$this->deleteOrderMeta( $order, self::META_SOURCE_CLAIM_OPERATION );

        if (! empty($source_match)) {
            $order->update_meta_data('_oras_qbo_reclass_source_txn_key', (string) ($source_match['key'] ?? ''));
            $order->update_meta_data('_oras_qbo_reclass_source_txn_id', (string) ($source_match['id'] ?? ''));
            $order->update_meta_data('_oras_qbo_reclass_source_txn_type', (string) ($source_match['entity'] ?? ''));
            $order->update_meta_data('_oras_qbo_reclass_source_txn_date', (string) ($source_match['txn_date'] ?? ''));
			$exchange_rate = trim( (string) ( $source_match['exchange_rate'] ?? '' ) );
			if ( $exchange_rate !== '' ) {
				$order->update_meta_data( '_oras_qbo_exchange_rate', $exchange_rate );
			}
        }

        $this->deleteOrderMeta($order, '_oras_qbo_sync_error');
        $this->deleteOrderMeta($order, '_oras_qbo_sync_error_code');
		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
        $order->save();

        $this->retry_handler->mark_success($order);
        $this->append_audit_entry(
            $order,
			$audit_event,
            array(
                'je_id'      => $je_id,
                'doc_number' => $doc_number,
                'intuit_tid' => $intuit_tid,
				'source_txn' => (string) ( $source_match['key'] ?? '' ),
				'result'     => $result_status,
            )
        );
		return true;
	}

	/**
	 * @param array<string,mixed> $split
	 * @return array<string,mixed>
	 */
	private function build_split_snapshot( array $split ): array {
        return array(
			'lines'         => isset( $split['lines'] ) && is_array( $split['lines'] ) ? $split['lines'] : array(),
			'split_total'   => round( (float) ( $split['split_total'] ?? 0.0 ), 2 ),
			'discount_mode' => (string) ( $split['discount_mode'] ?? 'proportional' ),
        );
    }

    /**
     * Queue a retry for failed entries.
     */
    public function retry_failed_orders(int $limit = 25): int
    {
		if ( $this->is_dry_run_mode() || ! Settings::is_enabled() ) {
			return 0;
		}

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

			if ( $this->has_legacy_possible_write_evidence( $order ) ) {
				continue;
			}

            if (is_wp_error($this->validate_sync_safeguards($order))) {
                continue;
            }

            $qbo_settings = Settings::get_quickbooks_settings();
            if ($this->should_require_manual_approval($qbo_settings) && trim((string) $order->get_meta(self::META_APPROVED_AT, true)) === '') {
                continue;
            }

			$schedule = $this->schedule_sync( $order_id, 0 );
			if ( ! empty( $schedule['scheduled'] ) ) {
				++$count;
			}
        }

        return $count;
    }

    /**
     * Process orders currently waiting for a Stripe-posted source transaction
     * in QuickBooks and attempt sync again.
     */
    public function process_waiting_orders(int $limit = 50): int
    {
		if ( $this->is_dry_run_mode() ) {
			return 0;
		}

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

			$order = wc_get_order( $order_id );
			if ( ! $order || $this->has_legacy_possible_write_evidence( $order ) ) {
				continue;
			}

			$result = $this->sync_order( $order_id, false );
			if ( ! is_wp_error( $result ) ) {
				++$processed;
				continue;
			}

			// Count as processed when we actively re-checked and remained waiting.
			if ( $result->get_error_code() === 'oras_qbo_reclass_source_not_found' ) {
				++$processed;
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
	public function reset_order_sync_state( int $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return $this->makeWpError( 'oras_qbo_invalid_order_id', 'Order ID must be a positive integer.' );
		}

		if ( $this->is_dry_run_mode() ) {
			$error = $this->makeWpError(
				'oras_qbo_dry_run_read_only',
				'QuickBooks sync state cannot be reset while dry-run mode is enabled.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}
		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled; existing sync metadata was preserved.' );
		}

		$result = DbLock::withLock(
			'qbo-sync-order:' . (string) $order_id,
			function () use ( $order_id ) {
				return $this->reset_order_sync_state_locked( $order_id );
			},
			1
		);

		if ( is_wp_error( $result ) && $result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$error = $this->makeWpError(
				'oras_qbo_sync_in_progress',
				'Another QuickBooks synchronization is already in progress for this order.'
			);
			$error->add_data( array( 'retriable' => true ) );
			return $error;
		}

		return $result;
	}

	/**
	 * Reset and resync without releasing the per-order lock between operations.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resync_order( int $order_id ) {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return $this->makeWpError('oras_qbo_invalid_order_id', 'Order ID must be a positive integer.');
        }

		if ( $this->is_dry_run_mode() ) {
			return $this->build_dry_run_sync_preview( $order_id );
		}

		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled; existing sync metadata was preserved.' );
		}
		$result = DbLock::withLock(
			'qbo-sync-order:' . (string) $order_id,
			function () use ( $order_id ) {
				if ( $this->is_dry_run_mode() ) {
					return $this->build_dry_run_sync_preview( $order_id );
				}
				if ( ! Settings::is_enabled() ) {
					return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync was disabled after lock acquisition; existing sync metadata was preserved.' );
				}
				$reset = $this->reset_order_sync_state_locked( $order_id );
				if ( is_wp_error( $reset ) ) {
					return $reset;
				}

				return $this->sync_order_locked( $order_id, false );
			},
			1
		);

		if ( is_wp_error( $result ) && $result->get_error_code() === 'oras_tickets_lock_timeout' ) {
			$error = $this->makeWpError(
				'oras_qbo_sync_in_progress',
				'Another QuickBooks synchronization is already in progress for this order.'
			);
			$error->add_data( array( 'retriable' => true ) );
			return $error;
		}

		return $result;
	}

	/**
	 * @return true|\WP_Error
	 */
	private function reset_order_sync_state_locked( int $order_id ) {

		if ( $this->is_dry_run_mode() ) {
			return $this->make_dry_run_read_only_error( 'QuickBooks sync-state reset' );
		}
		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled; existing sync metadata was preserved.' );
		}

        $order = wc_get_order($order_id);
        if (! $order) {
            return $this->makeWpError('oras_qbo_order_not_found', 'WooCommerce order not found.');
		}

		if ( $this->has_legacy_possible_write_evidence( $order ) ) {
			return $this->make_legacy_possible_write_error();
		}

		if (
			trim( (string) $order->get_meta( self::META_PENDING_WRITE, true ) ) !== ''
			|| trim( (string) $order->get_meta( self::META_WRITE_STATE, true ) ) !== ''
		) {
			$error = $this->makeWpError(
				'oras_qbo_pending_write_reset_blocked',
				'QuickBooks write outcome is pending or unknown; confirm the deterministic JournalEntry before resetting sync state.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
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
			self::META_SOURCE_CLAIM_STATE,
			self::META_SOURCE_CLAIM_ATTEMPT,
			self::META_SOURCE_CLAIM_REQUEST,
			self::META_SOURCE_CLAIM_OPERATION,
			self::META_DOC_NUMBER_ATTEMPT,
			'_oras_qbo_exchange_rate',
            self::META_WAIT_FIRST_AT,
            self::META_WAIT_LAST_CHECK_AT,
            self::META_WAIT_NEXT_CHECK_AT,
            self::META_WAIT_ATTEMPTS,
        );

        foreach ($meta_keys as $meta_key) {
            $this->deleteOrderMeta($order, $meta_key);
        }

		$guard = $this->get_dispatch_guard_error();
		if ( is_wp_error( $guard ) ) {
			return $guard;
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
	private function handle_sync_failure( $order, $error, string $operation = 'sync' ): void {
		if ( $this->controls_block_persistence() ) {
			return;
		}
        $error_message = is_wp_error($error) ? (string) $error->get_error_message() : (string) $error;
        $error_code    = is_wp_error($error) ? (string) $error->get_error_code() : 'oras_qbo_sync_failure';
        $error_data    = is_wp_error($error) ? $error->get_error_data() : null;

        if ($error_code === 'oras_qbo_reclass_source_not_found') {
            $wait_attempts = absint((string) $order->get_meta(self::META_WAIT_ATTEMPTS, true));
            $wait_attempts++;

			$first_waited_at = trim((string) $order->get_meta(self::META_WAIT_FIRST_AT, true));
			$set_first_waited_at = false;
			if ($first_waited_at === '') {
				$first_waited_at = gmdate('Y-m-d H:i:s');
				$set_first_waited_at = true;
            }

            $max_days = max(1, absint(Settings::get_quickbooks_settings()['source_match_max_wait_days'] ?? 180));
            $first_wait_ts = strtotime($first_waited_at . ' UTC');
            $is_expired = false;
            if ($first_wait_ts !== false) {
                $is_expired = (time() - $first_wait_ts) >= ($max_days * DAY_IN_SECONDS);
            }

			if ($is_expired) {
				if ( $set_first_waited_at ) {
					$order->update_meta_data( self::META_WAIT_FIRST_AT, $first_waited_at );
				}
                $order->update_meta_data('_oras_qbo_sync_status', 'needs_review');
                $order->update_meta_data('_oras_qbo_sync_error_code', 'oras_qbo_wait_expired');
                $order->update_meta_data('_oras_qbo_sync_error', sprintf('Source transaction still not found after %d day(s).', $max_days));
                $order->update_meta_data(self::META_WAIT_LAST_CHECK_AT, gmdate('Y-m-d H:i:s'));
                $this->deleteOrderMeta($order, self::META_WAIT_NEXT_CHECK_AT);
                $order->update_meta_data(self::META_WAIT_ATTEMPTS, (string) $wait_attempts);
				if ( $this->controls_block_persistence() ) {
					return;
				}
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
			$schedule = $this->schedule_sync( (int) $order->get_id(), $delay_minutes );
			$scheduled = ! empty( $schedule['scheduled'] );

			$persist_wait_state = function () use ( $order, $scheduled, $error_code, $error_message, $next_check_at, $wait_attempts, $delay_minutes, $set_first_waited_at, $first_waited_at ): void {
				if ( $set_first_waited_at ) {
					$order->update_meta_data( self::META_WAIT_FIRST_AT, $first_waited_at );
				}
				$order->update_meta_data( '_oras_qbo_sync_status', $scheduled ? 'waiting_for_source_txn' : 'needs_review' );
				$order->update_meta_data( '_oras_qbo_sync_error_code', $scheduled ? sanitize_text_field( $error_code ) : 'oras_qbo_wait_schedule_failed' );
				$order->update_meta_data(
					'_oras_qbo_sync_error',
					$scheduled ? sanitize_text_field( $error_message ) : 'QuickBooks source lookup retry could not be scheduled; manual review is required.'
				);
				$order->update_meta_data( '_oras_qbo_retry_count', '0' );
				$order->update_meta_data( self::META_WAIT_LAST_CHECK_AT, gmdate( 'Y-m-d H:i:s' ) );
				if ( $scheduled ) {
					$order->update_meta_data( self::META_WAIT_NEXT_CHECK_AT, $next_check_at );
				} else {
					$this->deleteOrderMeta( $order, self::META_WAIT_NEXT_CHECK_AT );
				}
				$order->update_meta_data( self::META_WAIT_ATTEMPTS, (string) $wait_attempts );
				$order->save();
				$this->append_audit_entry(
					$order,
					$scheduled ? 'waiting_for_source_transaction' : 'source_wait_schedule_failed',
					array(
						'wait_attempts' => $wait_attempts,
						'next_check_at' => $next_check_at,
						'delay_minutes' => $delay_minutes,
					)
				);
			};
			if ( $scheduled ) {
				$this->commit_scheduled_order_state( $order, $schedule, $persist_wait_state );
			} elseif ( ! $this->controls_block_persistence() ) {
				$persist_wait_state();
			}
			return;
        }

        $should_retry  = Retry_Handler::should_retry_error($error_code, $error_data);

		$retry_result = $this->retry_handler->record_failure(
			$order,
			$error_message,
			$error_code,
			$should_retry,
			function ( int $order_id, int $delay_minutes ) use ( $operation ): array {
				if ( $operation === 'reversal' ) {
					return $this->schedule_reversal( $order_id, $delay_minutes );
				}
				return $this->schedule_sync( $order_id, $delay_minutes );
			},
			$operation,
			function ( array $schedule ): bool {
				return $this->compensate_scheduled_action( $schedule );
			}
		);

		if ( $this->controls_block_persistence() ) {
			return;
		}
		$scheduled = ! empty( $retry_result['scheduled'] );
		$schedule_failed = (string) ( $retry_result['error_code'] ?? '' ) === 'oras_qbo_retry_schedule_failed';
		$audit_event = $operation === 'reversal' ? 'reversal_failure' : 'sync_failure';
		if ( $scheduled ) {
			$audit_event = $operation === 'reversal' ? 'reversal_retry_scheduled' : 'sync_retry_scheduled';
		} elseif ( $schedule_failed ) {
			$audit_event = $operation === 'reversal' ? 'reversal_retry_schedule_failed' : 'sync_retry_schedule_failed';
		}
		$this->append_audit_entry(
			$order,
			$audit_event,
			array(
				'error_code'    => (string) ( $retry_result['error_code'] ?? $error_code ),
				'error'         => $error_message,
				'retriable'     => $should_retry,
				'scheduled'     => $scheduled,
				'retry_attempt' => (int) ( $retry_result['attempts'] ?? 0 ),
				'error_data'    => is_array( $error_data ) ? $error_data : array(),
			)
		);
    }

    private function has_scheduled_action(int $order_id): bool
    {
        if (function_exists('as_has_scheduled_action')) {
			if ( as_has_scheduled_action( self::ACTION_HOOK, array( $order_id ), self::AS_GROUP ) ) {
				return true;
			}
			for ( $attempt = 0; $attempt <= self::MAX_ASYNC_LOCK_RETRIES; $attempt++ ) {
				if ( as_has_scheduled_action( self::ACTION_HOOK, array( $order_id, $attempt ), self::AS_GROUP ) ) {
					return true;
				}
			}
			return false;
		}

		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}
		if ( call_user_func( 'wp_next_scheduled', self::ACTION_HOOK, array( $order_id ) ) ) {
			return true;
		}
		for ( $attempt = 0; $attempt <= self::MAX_ASYNC_LOCK_RETRIES; $attempt++ ) {
			if ( call_user_func( 'wp_next_scheduled', self::ACTION_HOOK, array( $order_id, $attempt ) ) ) {
				return true;
			}
		}
		return false;
    }

    private function ensure_waiting_queue_schedule(): void
    {
		if ( $this->controls_block_persistence() ) {
			return;
		}
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

	/**
	 * Create a provisional primary action. A newly created action is committed
	 * only after its caller persists the matching order state successfully.
	 *
	 * @return array<string,mixed>
	 */
	private function schedule_sync( int $order_id, int $delay_minutes, int $lock_attempt = 0 ): array {
		return $this->schedule_action(
			self::ACTION_HOOK,
			array( $order_id, max( 0, $lock_attempt ) ),
			$delay_minutes
		);
	}

	/**
	 * Create a provisional reversal action on the reversal-only hook.
	 *
	 * @return array<string,mixed>
	 */
	private function schedule_reversal( int $order_id, int $delay_minutes, int $lock_attempt = 0 ): array {
		return $this->schedule_action(
			self::ACTION_REVERSAL_HOOK,
			array( $order_id, max( 0, $lock_attempt ) ),
			$delay_minutes
		);
    }

	/**
	 * @param array<int,int> $action_args
	 * @return array<string,mixed>
	 */
	private function schedule_action( string $hook, array $action_args, int $delay_minutes ): array {
		$result = array(
			'scheduled'   => false,
			'created'     => false,
			'committed'   => false,
			'compensated' => false,
			'backend'     => '',
			'action_id'   => 0,
			'timestamp'   => 0,
			'hook'        => $hook,
			'args'        => $action_args,
			'error_code'  => 'oras_qbo_schedule_failed',
		);

		if ( $this->controls_block_persistence() ) {
			$result['error_code'] = 'oras_qbo_schedule_blocked';
			return $result;
		}

		$delay_seconds = max( 0, $delay_minutes ) * MINUTE_IN_SECONDS;
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $action_args, self::AS_GROUP ) ) {
			return $this->existing_schedule_result( $hook, $action_args );
		}

		if ( function_exists( 'as_enqueue_async_action' ) && $delay_seconds === 0 ) {
			$result['backend'] = 'action_scheduler';
			$result['action_id'] = (int) as_enqueue_async_action( $hook, $action_args, self::AS_GROUP, true ); // @phpstan-ignore arguments.count (WooCommerce Action Scheduler supports the unique fourth argument; a legacy test stub does not.)
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			$result['backend'] = 'action_scheduler';
			$result['timestamp'] = time() + $delay_seconds;
			$result['action_id'] = (int) as_schedule_single_action( (int) $result['timestamp'], $hook, $action_args, self::AS_GROUP, true );
		} elseif ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) ) {
			$result['backend'] = 'wp_cron';
			$existing_timestamp = (int) call_user_func( 'wp_next_scheduled', $hook, $action_args );
			if ( $existing_timestamp > 0 ) {
				$result['scheduled'] = true;
				$result['committed'] = true;
				$result['timestamp'] = $existing_timestamp;
				$result['error_code'] = '';
				return $result;
			}
			$result['timestamp'] = time() + $delay_seconds;
			$result['created'] = (bool) call_user_func( 'wp_schedule_single_event', (int) $result['timestamp'], $hook, $action_args );
		}

		if ( $result['backend'] === 'action_scheduler' ) {
			$result['created'] = (int) $result['action_id'] > 0;
			$result['scheduled'] = $result['created']
				&& function_exists( 'as_has_scheduled_action' )
				&& (bool) as_has_scheduled_action( $hook, $action_args, self::AS_GROUP );
		} elseif ( $result['backend'] === 'wp_cron' ) {
			$result['scheduled'] = $result['created']
				&& (bool) call_user_func( 'wp_next_scheduled', $hook, $action_args );
		}

		if ( ! $result['scheduled'] ) {
			return $result;
		}

		if ( $this->controls_block_persistence() ) {
			$result['compensated'] = $this->compensate_scheduled_action( $result );
			$result['scheduled'] = ! $result['compensated'];
			$result['error_code'] = $result['compensated']
				? 'oras_qbo_schedule_controls_changed'
				: 'oras_qbo_schedule_compensation_failed';
			return $result;
		}

		$result['error_code'] = '';
		return $result;
	}

	/**
	 * @param array<int,int> $action_args
	 * @return array<string,mixed>
	 */
	private function existing_schedule_result( string $hook, array $action_args ): array {
		return array(
			'scheduled'   => true,
			'created'     => false,
			'committed'   => true,
			'compensated' => false,
			'backend'     => function_exists( 'as_has_scheduled_action' ) ? 'action_scheduler' : 'wp_cron',
			'action_id'   => 0,
			'timestamp'   => 0,
			'hook'        => $hook,
			'args'        => $action_args,
			'error_code'  => '',
		);
	}

	/**
	 * Cancel only an action proven to have been created by the current operation.
	 *
	 * @param array<string,mixed> $schedule
	 */
	private function compensate_scheduled_action( array $schedule ): bool {
		if ( empty( $schedule['created'] ) ) {
			return true;
		}

		$hook = (string) ( $schedule['hook'] ?? '' );
		$args = isset( $schedule['args'] ) && is_array( $schedule['args'] ) ? $schedule['args'] : array();
		if ( (string) ( $schedule['backend'] ?? '' ) === 'action_scheduler' ) {
			$action_id = (int) ( $schedule['action_id'] ?? 0 );
			if ( $action_id <= 0 ) {
				return false;
			}
			try {
				if ( class_exists( '\ActionScheduler' ) ) {
					\ActionScheduler::store()->cancel_action( $action_id );
					return ! function_exists( 'as_has_scheduled_action' )
						|| ! as_has_scheduled_action( $hook, $args, self::AS_GROUP );
				}
			} catch ( \Throwable $throwable ) {
				return false;
			}
			return false;
		}

		$timestamp = (int) ( $schedule['timestamp'] ?? 0 );
		return $timestamp > 0
			&& function_exists( 'wp_unschedule_event' )
			&& (bool) call_user_func( 'wp_unschedule_event', $timestamp, $hook, $args );
	}

	/**
	 * Scheduling becomes committed only after this method has persisted the
	 * matching order state and completed its final controls check. Failures
	 * compensate only the exact newly created action and restore prior QBO meta.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $schedule
	 * @return array<string,mixed>|\WP_Error
	 */
	private function commit_scheduled_order_state( $order, array $schedule, callable $persist ) {
		if ( empty( $schedule['scheduled'] ) ) {
			return $this->schedule_error_from_result( $schedule );
		}

		$meta_snapshot = $this->capture_qbo_meta_snapshot( $order );
		$error_code = 'oras_qbo_schedule_persistence_failed';
		$error_message = 'QuickBooks work was scheduled, but its matching local queue state could not be persisted.';
		try {
			if ( $this->controls_block_persistence() ) {
				$error_code = 'oras_qbo_schedule_controls_changed';
				$error_message = 'QuickBooks synchronization controls changed after action creation; the new action was cancelled.';
				throw new \RuntimeException( $error_message );
			}
			$persist();
			if ( $this->controls_block_persistence() ) {
				$error_code = 'oras_qbo_schedule_controls_changed';
				$error_message = 'QuickBooks synchronization controls changed before scheduling committed; the new action was cancelled.';
				throw new \RuntimeException( $error_message );
			}
		} catch ( \Throwable $throwable ) {
			$compensated = $this->compensate_scheduled_action( $schedule );
			$restored = $this->restore_qbo_meta_snapshot( (int) $order->get_id(), $meta_snapshot );
			if ( ! $compensated && ! empty( $schedule['created'] ) ) {
				$error_code = 'oras_qbo_schedule_compensation_failed';
				$error_message = 'QuickBooks queue state did not commit and the exact new action could not be cancelled.';
			}
			$error = $this->makeWpError( $error_code, $error_message );
			$error->add_data(
				array(
					'scheduled'            => ! $compensated && ! empty( $schedule['created'] ),
					'action_created'       => ! empty( $schedule['created'] ),
					'action_id'            => (int) ( $schedule['action_id'] ?? 0 ),
					'action_hook'          => (string) ( $schedule['hook'] ?? '' ),
					'compensated'          => $compensated,
					'order_state_restored' => $restored,
					'persistence_error'    => $throwable->getMessage(),
				)
			);
			return $error;
		}

		$schedule['committed'] = true;
		return $schedule;
	}

	/**
	 * @param \WC_Order $order
	 * @return array<string,array<int,mixed>>
	 */
	private function capture_qbo_meta_snapshot( $order ): array {
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
		return $snapshot;
	}

	/**
	 * @param array<string,array<int,mixed>> $snapshot
	 */
	private function restore_qbo_meta_snapshot( int $order_id, array $snapshot ): bool {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return false;
			}
			$keys = array();
			foreach ( $order->get_meta_data() as $meta ) {
				$data = $meta->get_data();
				$key = (string) ( $data['key'] ?? '' );
				if ( strpos( $key, '_oras_qbo_' ) === 0 ) {
					$keys[ $key ] = true;
				}
			}
			foreach ( array_keys( $keys ) as $key ) {
				$order->delete_meta_data( $key );
			}
			foreach ( $snapshot as $key => $values ) {
				foreach ( $values as $value ) {
					$order->add_meta_data( $key, $value, false );
				}
			}
			$order->save_meta_data();
			return true;
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $schedule
	 */
	private function schedule_error_from_result( array $schedule ): \WP_Error {
		$error_code = (string) ( $schedule['error_code'] ?? 'oras_qbo_schedule_failed' );
		$messages = array(
			'oras_qbo_schedule_blocked'             => 'QuickBooks synchronization controls do not permit scheduling.',
			'oras_qbo_schedule_controls_changed'    => 'QuickBooks synchronization controls changed after action creation; the new action was cancelled.',
			'oras_qbo_schedule_compensation_failed' => 'QuickBooks synchronization controls changed, but the exact new action could not be cancelled.',
			'oras_qbo_schedule_failed'              => 'QuickBooks work could not be scheduled.',
		);
		$error = $this->makeWpError( $error_code, $messages[ $error_code ] ?? $messages['oras_qbo_schedule_failed'] );
		$error->add_data(
			array(
				'scheduled'      => ! empty( $schedule['scheduled'] ),
				'action_created' => ! empty( $schedule['created'] ),
				'action_id'      => (int) ( $schedule['action_id'] ?? 0 ),
				'action_hook'    => (string) ( $schedule['hook'] ?? '' ),
				'compensated'    => ! empty( $schedule['compensated'] ),
			)
		);
		return $error;
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
		if ( $this->controls_block_persistence() ) {
			return;
		}
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_HOOK, array($order_id), self::AS_GROUP);
			for ( $attempt = 0; $attempt <= self::MAX_ASYNC_LOCK_RETRIES; $attempt++ ) {
				as_unschedule_all_actions( self::ACTION_HOOK, array( $order_id, $attempt ), self::AS_GROUP );
			}
            return;
        }

        if (function_exists('wp_clear_scheduled_hook')) {
            call_user_func('wp_clear_scheduled_hook', self::ACTION_HOOK, array($order_id));
			for ( $attempt = 0; $attempt <= self::MAX_ASYNC_LOCK_RETRIES; $attempt++ ) {
				call_user_func( 'wp_clear_scheduled_hook', self::ACTION_HOOK, array( $order_id, $attempt ) );
			}
        }
    }

    /**
     * Remove stale "queued" state when a safeguard blocks sync.
     *
     * @param \WC_Order $order
     */
    private function clear_queue_state($order): void
    {
		if ( $this->controls_block_persistence() ) {
			return;
		}
        $this->deleteOrderMeta($order, '_oras_qbo_sync_status');
		if ( $this->controls_block_persistence() ) {
			return;
		}
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
            return $this->makeWpError('oras_qbo_order_not_completed', 'Order must be completed before syncing.');
        }

        $already_synced = trim((string) $order->get_meta(self::META_SYNCED, true));
        if ($already_synced !== '') {
            return $this->makeWpError('oras_qbo_already_synced', 'Order already has _oras_qbo_synced meta and cannot be synced again.');
        }

        $qbo_settings = Settings::get_quickbooks_settings();
        $cutoff_raw   = trim((string) ($qbo_settings['sync_cutoff_date'] ?? ''));
        if ($cutoff_raw === '') {
            return $this->makeWpError('oras_qbo_missing_cutoff_date', 'QuickBooks sync cutoff date is required before syncing orders.');
        }

        $cutoff_ts = strtotime($cutoff_raw . ' 00:00:00 UTC');
        if ($cutoff_ts === false) {
            return $this->makeWpError('oras_qbo_invalid_cutoff_date', 'QuickBooks sync cutoff date is invalid.');
        }

        $created = $order->get_date_created();
        if (! is_object($created) || ! method_exists($created, 'getTimestamp')) {
            return $this->makeWpError('oras_qbo_missing_order_created_date', 'Order created date is missing.');
        }

        if ((int) $created->getTimestamp() < (int) $cutoff_ts) {
            return $this->makeWpError('oras_qbo_order_before_cutoff', 'Order was created before the configured QuickBooks sync cutoff date.');
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
                return $this->makeWpError(
                    'oras_qbo_excluded_payment_method',
                    sprintf('Order payment method "%s" is excluded from QuickBooks sync.', $order_method)
                );
            }
        }

        return true;
    }

	private function is_dry_run_mode(): bool {
		$settings = Settings::get_quickbooks_settings();
		return ! empty( $settings['dry_run_mode'] );
	}

	/**
	 * Read controls again at a persistence boundary. This method is
	 * intentionally impure because another request or a settings hook may
	 * change the stored controls during a long-running synchronization.
	 *
	 * @phpstan-impure
	 */
	private function controls_block_persistence(): bool {
		$settings = Settings::get_quickbooks_settings();
		return empty( $settings['enabled'] ) || ! empty( $settings['dry_run_mode'] );
	}

	/**
	 * @return true|\WP_Error
	 */
	private function get_dispatch_guard_error() {
		if ( $this->is_dry_run_mode() ) {
			return $this->make_dry_run_read_only_error( 'QuickBooks JournalEntry dispatch' );
		}
		if ( ! Settings::is_enabled() ) {
			$error = $this->makeWpError(
				'oras_qbo_disabled',
				'QuickBooks Revenue Split Sync was disabled before dispatch; no request was sent.'
			);
			$error->add_data(
				array(
					'retriable'              => false,
					'qbo_request_dispatched' => false,
				)
			);
			return $error;
		}
		return true;
	}

	/**
	 * Detect pre-patch records whose incomplete local evidence cannot prove a
	 * QuickBooks write was never dispatched. Complete current-format pending
	 * intents are handled by the normal deterministic reconciliation path.
	 *
	 * @param \WC_Order $order
	 */
	private function has_legacy_possible_write_evidence( $order ): bool {
		$pending_raw = trim( (string) $order->get_meta( self::META_PENDING_WRITE, true ) );
		$write_state = sanitize_key( (string) $order->get_meta( self::META_WRITE_STATE, true ) );
		$pending = $pending_raw !== '' ? json_decode( $pending_raw, true ) : null;
		$pending_complete = is_array( $pending ) && $this->is_complete_pending_intent_metadata( $pending, (int) $order->get_id() );

		if ( ( $pending_raw !== '' || $write_state !== '' ) && ! $pending_complete ) {
			return true;
		}

		if ( $pending_complete ) {
			return false;
		}

		$status = sanitize_key( (string) $order->get_meta( '_oras_qbo_sync_status', true ) );
		$error_code = strtolower( trim( (string) $order->get_meta( '_oras_qbo_sync_error_code', true ) ) );
		$error_message = strtolower( trim( (string) $order->get_meta( '_oras_qbo_sync_error', true ) ) );
		$audit_text = strtolower( $this->get_legacy_audit_evidence_text( $order ) );
		$uncertain_text = implode( ' ', array( $error_code, $error_message, $audit_text ) );

		$uncertain_patterns = array(
			'curl error 28',
			'timed out',
			'timeout',
			'0 bytes received',
			'connection reset',
			'connection aborted',
			'connection uncertainty',
			'network error',
			'malformed response',
			'missing response',
			'empty response',
			'invalid json',
			'oras_qbo_reclass_source_not_found',
			'no matching stripe-posted quickbooks transaction found',
			'unknown outcome',
			'outcome unknown',
			'http 408',
			'http 500',
			'http 502',
			'http 503',
			'http 504',
		);
		$has_uncertainty = false;
		foreach ( $uncertain_patterns as $pattern ) {
			if ( strpos( $uncertain_text, $pattern ) !== false ) {
				$has_uncertainty = true;
				break;
			}
		}

		$has_dispatch_audit = strpos( $audit_text, 'journal_entry_write_dispatched' ) !== false
			|| strpos( $audit_text, 'write_outcome_unknown' ) !== false
			|| strpos( $audit_text, 'retry_dispatched' ) !== false;
		$has_attempt_evidence = trim( (string) $order->get_meta( self::META_DOC_NUMBER, true ) ) !== ''
			|| trim( (string) $order->get_meta( '_oras_qbo_reclass_source_txn_key', true ) ) !== ''
			|| trim( (string) $order->get_meta( '_oras_qbo_reclass_source_txn_id', true ) ) !== ''
			|| (int) $order->get_meta( '_oras_qbo_retry_count', true ) > 0
			|| (int) $order->get_meta( self::META_WAIT_ATTEMPTS, true ) > 0
			|| $has_dispatch_audit;
		$relevant_status = in_array(
			$status,
			array(
				'failed',
				'retrying',
				'waiting_for_source_txn',
				'pending_qbo_write',
				'qbo_write_unknown',
				'reversal_failed',
				'reversal_retrying',
			),
			true
		);

		return $has_dispatch_audit || ( $relevant_status && $has_uncertainty && $has_attempt_evidence );
	}

	/**
	 * Distinguish a structurally complete current-format intent from incomplete
	 * legacy metadata. Integrity hashes and currency are deliberately validated
	 * later by the explicit reconciliation path.
	 *
	 * @param array<string,mixed> $pending
	 */
	private function is_complete_pending_intent_metadata( array $pending, int $order_id ): bool {
		$payload = isset( $pending['payload'] ) && is_array( $pending['payload'] ) ? $pending['payload'] : array();
		$request_id = trim( (string) ( $pending['request_id'] ?? '' ) );
		$operation = sanitize_key( (string) ( $pending['operation'] ?? '' ) );

		return (int) ( $pending['order_id'] ?? 0 ) === $order_id
			&& trim( (string) ( $pending['attempt_id'] ?? '' ) ) !== ''
			&& trim( (string) ( $pending['doc_number'] ?? '' ) ) !== ''
			&& ! empty( $payload )
			&& trim( (string) ( $pending['payload_hash'] ?? '' ) ) !== ''
			&& $request_id !== ''
			&& trim( (string) ( $pending['accounting_fingerprint'] ?? '' ) ) !== ''
			&& trim( (string) ( $pending['home_currency'] ?? '' ) ) !== ''
			&& trim( (string) ( $pending['transaction_currency'] ?? '' ) ) !== ''
			&& is_bool( $pending['multicurrency_enabled'] ?? null )
			&& trim( (string) ( $pending['exchange_rate'] ?? '' ) ) !== ''
			&& in_array( $operation, array( 'sync', 'reversal' ), true );
	}

	/**
	 * @param \WC_Order $order
	 */
	private function get_legacy_audit_evidence_text( $order ): string {
		$values = array( (string) $order->get_meta( '_oras_qbo_last_audit_event', true ) );
		$entries = $order->get_meta( '_oras_qbo_audit_entry', false );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( is_object( $entry ) && method_exists( $entry, 'get_data' ) ) {
					$entry_data = $entry->get_data();
					$entry = is_array( $entry_data ) ? ( $entry_data['value'] ?? '' ) : '';
				}
				if ( is_scalar( $entry ) ) {
					$values[] = (string) $entry;
				}
			}
		}

		return implode( ' ', $values );
	}

	private function make_legacy_possible_write_error(): \WP_Error {
		$error = $this->makeWpError(
			'oras_qbo_legacy_possible_write',
			'A prior QuickBooks JournalEntry write may have reached QuickBooks. Existing evidence was preserved; use explicit read-only reconciliation or inventory before any reset or resync.'
		);
		$error->add_data(
			array(
				'retriable'              => false,
				'qbo_request_dispatched' => false,
			)
		);
		return $error;
	}

	private function make_dry_run_read_only_error( string $operation ): \WP_Error {
		$error = $this->makeWpError(
			'oras_qbo_dry_run_read_only',
			$operation . ' skipped because dry-run mode is enabled.'
		);
		$error->add_data(
			array(
				'retriable'              => false,
				'qbo_request_dispatched' => false,
			)
		);
		return $error;
	}

	private function is_control_stop_error( $error ): bool {
		return is_wp_error( $error )
			&& in_array( (string) $error->get_error_code(), array( 'oras_qbo_dry_run_read_only', 'oras_qbo_disabled' ), true );
	}

	private function generate_attempt_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Build a local-only classification preview. No source transaction or
	 * duplicate JournalEntry is queried in dry-run mode.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_dry_run_sync_preview( int $order_id ) {
		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->makeWpError( 'oras_qbo_order_not_found', 'WooCommerce order not found.' );
		}

		$guard_error = $this->validate_sync_safeguards( $order );
		if ( is_wp_error( $guard_error ) ) {
			return $guard_error;
		}

		$qbo_settings = Settings::get_quickbooks_settings();
		$split = $this->split_calculator->calculate( $order, $qbo_settings );
		if ( is_wp_error( $split ) ) {
			return $split;
		}

		if ( ! empty( $qbo_settings['strict_mapping_mode'] ) ) {
			$warnings = isset( $split['warnings'] ) && is_array( $split['warnings'] ) ? $split['warnings'] : array();
			if ( (int) ( $split['unmapped_lines'] ?? 0 ) > 0 || (int) ( $split['missing_account_lines'] ?? 0 ) > 0 || ! empty( $warnings ) ) {
				$error = $this->makeWpError(
					'oras_qbo_strict_mapping_failed',
					'Strict mapping mode blocked the dry-run preview: order contains unmapped or unresolved account lines.'
				);
				$error->add_data( array( 'retriable' => false ) );
				return $error;
			}
		}

		$prepared = $this->journal_entry_creator->build_payload_for_order(
			$order,
			$split,
			$qbo_settings,
			false,
			'',
			false
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return array(
			'status'                  => 'dry_run',
			'order_id'                => $order_id,
			'doc_number'              => (string) ( $prepared['doc_number'] ?? '' ),
			'payload'                 => isset( $prepared['payload'] ) && is_array( $prepared['payload'] ) ? $prepared['payload'] : array(),
			'split'                   => $split,
			'remote_source_validated' => (bool) ( $prepared['remote_source_validated'] ?? false ),
		);
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_dry_run_reversal_preview( int $order_id, bool $force ) {
		if ( ! Settings::is_enabled() ) {
			return $this->makeWpError( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->makeWpError( 'oras_qbo_order_not_found', 'WooCommerce order not found.' );
		}

		$original_je_id = trim( (string) $order->get_meta( '_oras_qbo_je_id', true ) );
		if ( $original_je_id === '' ) {
			return $this->makeWpError( 'oras_qbo_no_je_to_reverse', 'Order has no synced JournalEntry to reverse.' );
		}

		if ( trim( (string) $order->get_meta( '_oras_qbo_reversal_je_id', true ) ) !== '' && ! $force ) {
			return $this->makeWpError( 'oras_qbo_already_reversed', 'Order already has a reversal JournalEntry.' );
		}

		$snapshot = json_decode( (string) $order->get_meta( self::META_SPLIT_SNAPSHOT, true ), true );
		if ( ! is_array( $snapshot ) || empty( $snapshot['lines'] ) || ! isset( $snapshot['split_total'] ) ) {
			return $this->makeWpError( 'oras_qbo_missing_split_snapshot', 'Cannot reverse: split snapshot is missing from the order sync metadata.' );
		}

		$prepared = $this->journal_entry_creator->build_payload_for_order(
			$order,
			$snapshot,
			Settings::get_quickbooks_settings(),
			true,
			$original_je_id,
			false
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return array(
			'status'                  => 'reversal_dry_run',
			'order_id'                => $order_id,
			'doc_number'              => (string) ( $prepared['doc_number'] ?? '' ),
			'payload'                 => isset( $prepared['payload'] ) && is_array( $prepared['payload'] ) ? $prepared['payload'] : array(),
			'remote_source_validated' => false,
		);
	}

    /**
     * Append immutable audit event to order meta.
     *
     * @param \WC_Order $order
     * @param array<string,mixed> $context
     */
    private function append_audit_entry($order, string $event, array $context): void
    {
		if ( $this->controls_block_persistence() ) {
			return;
		}
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

		if ( $this->controls_block_persistence() ) {
			return;
		}
        if (function_exists('add_post_meta')) {
            call_user_func('add_post_meta', $order_id, '_oras_qbo_audit_entry', wp_json_encode($entry), false);
        }
        $order->update_meta_data('_oras_qbo_last_audit_event', (string) $entry['event']);
		if ( $this->controls_block_persistence() ) {
			return;
		}
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

    private function makeWpError(string $code, string $message, $data = null)
    {
        if (class_exists('WP_Error')) {
            $factory = new \ReflectionClass('WP_Error');
            return $factory->newInstance($code, $message, $data);
        }

        return new class($code, $message, $data) {
            private string $code;
            private string $message;
            private $data;

            public function __construct(string $code, string $message, $data)
            {
                $this->code    = $code;
                $this->message = $message;
                $this->data    = $data;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_data()
            {
                return $this->data;
            }

            public function add_data($data): void
            {
                $this->data = $data;
            }
        };
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
