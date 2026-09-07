<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Retry_Handler {

    private const MAX_ATTEMPTS = 3;

    private QuickBooks_Logger $logger;

    public function __construct( ?QuickBooks_Logger $logger = null ) {
        $this->logger = $logger ?: new QuickBooks_Logger();
    }

    /**
     * @param \WC_Order $order
	 * @param callable(int,int):(bool|array<string,mixed>) $schedule_retry_callback
	 * @param null|callable(array<string,mixed>):bool $compensate_schedule_callback
	 * @return array{scheduled:bool,attempts:int,status:string,error_code:string,delay_minutes:int,schedule:array<string,mixed>}
     */
	public function record_failure( $order, string $error_message, string $error_code, bool $should_retry, callable $schedule_retry_callback, string $operation = 'sync', ?callable $compensate_schedule_callback = null ): array {
		$empty_schedule = array(
			'scheduled' => false,
			'created'   => false,
		);
		if ( $this->controls_block_persistence() ) {
			return array(
				'scheduled'     => false,
				'attempts'      => (int) $order->get_meta( '_oras_qbo_retry_count', true ),
				'status'        => 'blocked',
				'error_code'    => $error_code,
				'delay_minutes' => 0,
				'schedule'      => $empty_schedule,
			);
		}

        $attempts = (int) $order->get_meta( '_oras_qbo_retry_count', true );
        $attempts++;

		$schedule = $empty_schedule;
		$scheduled = false;
		$delay_minutes = 0;
		if ( $should_retry && $attempts < self::MAX_ATTEMPTS ) {
			if ( $this->controls_block_persistence() ) {
				return array(
					'scheduled'     => false,
					'attempts'      => $attempts - 1,
					'status'        => 'blocked',
					'error_code'    => $error_code,
					'delay_minutes' => 0,
					'schedule'      => $empty_schedule,
				);
			}
			$delay_minutes = min( 30, 5 * $attempts );
			try {
				$schedule_result = call_user_func( $schedule_retry_callback, (int) $order->get_id(), $delay_minutes );
				$schedule = is_array( $schedule_result )
					? $schedule_result
					: array(
						'scheduled' => $schedule_result === true,
						'created'   => false,
					);
				$scheduled = ! empty( $schedule['scheduled'] );
			} catch ( \Throwable $throwable ) {
				$scheduled = false;
			}
		}

		if ( $this->controls_block_persistence() ) {
			$compensated = $this->compensate_schedule( $schedule, $compensate_schedule_callback );
			if ( ! empty( $schedule['created'] ) ) {
				$schedule['compensated'] = $compensated;
				$schedule['scheduled'] = ! $compensated;
			}
			return array(
				'scheduled'     => ! empty( $schedule['scheduled'] ),
				'attempts'      => $attempts - 1,
				'status'        => $compensated ? 'blocked' : 'compensation_failed',
				'error_code'    => $compensated ? $error_code : 'oras_qbo_retry_compensation_failed',
				'delay_minutes' => $delay_minutes,
				'schedule'      => $schedule,
			);
		}

		$stored_error_code = $error_code;
		$stored_error_message = $error_message;
		$status = $operation === 'reversal' ? 'reversal_failed' : 'failed';
		if ( $scheduled ) {
			$status = $operation === 'reversal' ? 'reversal_retrying' : 'retrying';
		} elseif ( $should_retry && $attempts < self::MAX_ATTEMPTS ) {
			$stored_error_code = 'oras_qbo_retry_schedule_failed';
			$stored_error_message = 'QuickBooks retry could not be scheduled; automatic retry stopped. Original error: ' . $error_message;
		}

		$prior_meta = array(
			'_oras_qbo_retry_count'     => $order->get_meta( '_oras_qbo_retry_count', true ),
			'_oras_qbo_sync_status'     => $order->get_meta( '_oras_qbo_sync_status', true ),
			'_oras_qbo_sync_error_code' => $order->get_meta( '_oras_qbo_sync_error_code', true ),
			'_oras_qbo_sync_error'      => $order->get_meta( '_oras_qbo_sync_error', true ),
		);
		$order->update_meta_data( '_oras_qbo_retry_count', (string) $attempts );
		$order->update_meta_data( '_oras_qbo_sync_status', $status );
		$order->update_meta_data( '_oras_qbo_sync_error_code', sanitize_text_field( $stored_error_code ) );
		$order->update_meta_data( '_oras_qbo_sync_error', sanitize_text_field( $stored_error_message ) );
		if ( $this->controls_block_persistence() ) {
			$compensated = $this->compensate_schedule( $schedule, $compensate_schedule_callback );
			if ( ! empty( $schedule['created'] ) ) {
				$schedule['compensated'] = $compensated;
				$schedule['scheduled'] = ! $compensated;
			}
			$this->restore_retry_meta_on_order( $order, $prior_meta );
			return array(
				'scheduled'     => ! empty( $schedule['scheduled'] ),
				'attempts'      => $attempts - 1,
				'status'        => $compensated ? 'blocked' : 'compensation_failed',
				'error_code'    => $compensated ? $stored_error_code : 'oras_qbo_retry_compensation_failed',
				'delay_minutes' => $delay_minutes,
				'schedule'      => $schedule,
			);
		}
		try {
			$order->save();
		} catch ( \Throwable $throwable ) {
			$compensated = $this->compensate_schedule( $schedule, $compensate_schedule_callback );
			if ( ! empty( $schedule['created'] ) ) {
				$schedule['compensated'] = $compensated;
				$schedule['scheduled'] = ! $compensated;
			}
			$this->restore_retry_meta( (int) $order->get_id(), $prior_meta );
			return array(
				'scheduled'     => ! empty( $schedule['scheduled'] ),
				'attempts'      => $attempts - 1,
				'status'        => $compensated ? 'persistence_failed' : 'compensation_failed',
				'error_code'    => $compensated ? 'oras_qbo_retry_persistence_failed' : 'oras_qbo_retry_compensation_failed',
				'delay_minutes' => $delay_minutes,
				'schedule'      => $schedule,
			);
		}

		if ( $scheduled ) {
			if ( $this->controls_block_persistence() ) {
				$compensated = $this->compensate_schedule( $schedule, $compensate_schedule_callback );
				if ( ! empty( $schedule['created'] ) ) {
					$schedule['compensated'] = $compensated;
					$schedule['scheduled'] = ! $compensated;
				}
				$this->restore_retry_meta( (int) $order->get_id(), $prior_meta );
				return array(
					'scheduled'     => ! empty( $schedule['scheduled'] ),
					'attempts'      => $attempts - 1,
					'status'        => $compensated ? 'blocked' : 'compensation_failed',
					'error_code'    => $compensated ? $stored_error_code : 'oras_qbo_retry_compensation_failed',
					'delay_minutes' => $delay_minutes,
					'schedule'      => $schedule,
				);
			}
            $this->logger->warning(
                'Scheduled retry for QuickBooks sync failure',
                array(
                    'order_id'      => (int) $order->get_id(),
                    'retry_attempt' => $attempts,
                    'delay_minutes' => $delay_minutes,
                )
            );

			return array(
				'scheduled'     => true,
				'attempts'      => $attempts,
				'status'        => $status,
				'error_code'    => $stored_error_code,
				'delay_minutes' => $delay_minutes,
				'schedule'      => $schedule,
			);
        }

		if ( $this->controls_block_persistence() ) {
			return array(
				'scheduled'     => false,
				'attempts'      => $attempts,
				'status'        => $status,
				'error_code'    => $stored_error_code,
				'delay_minutes' => $delay_minutes,
				'schedule'      => $schedule,
			);
		}
        $this->logger->error(
			$stored_error_code === 'oras_qbo_retry_schedule_failed'
				? 'QuickBooks retry scheduling failed'
				: ( $should_retry ? 'QuickBooks sync exhausted retry attempts' : 'QuickBooks sync failure is non-retriable' ),
            array(
                'order_id'      => (int) $order->get_id(),
                'retry_attempt' => $attempts,
				'error'         => $stored_error_message,
				'error_code'    => $stored_error_code,
                'retriable'     => $should_retry,
            )
		);

		return array(
			'scheduled'     => false,
			'attempts'      => $attempts,
			'status'        => $status,
			'error_code'    => $stored_error_code,
			'delay_minutes' => $delay_minutes,
			'schedule'      => $schedule,
		);
    }

    /**
     * @param \WC_Order $order
     */
	public function mark_success( $order ): void {
		if ( $this->controls_block_persistence() ) {
			return;
		}
        $order->update_meta_data( '_oras_qbo_retry_count', '0' );
        $order->delete_meta_data( '_oras_qbo_sync_error_code' );
        $order->delete_meta_data( '_oras_qbo_sync_error' );
		if ( $this->controls_block_persistence() ) {
			return;
		}
        $order->save();
	}

	/**
	 * @param array<string,mixed> $schedule
	 * @param null|callable(array<string,mixed>):bool $callback
	 */
	private function compensate_schedule( array $schedule, ?callable $callback ): bool {
		if ( empty( $schedule['created'] ) ) {
			return true;
		}
		if ( $callback === null ) {
			return false;
		}
		try {
			return call_user_func( $callback, $schedule ) === true;
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Restore only the retry metadata owned by this failed persistence attempt.
	 *
	 * @param array<string,mixed> $prior_meta
	 */
	private function restore_retry_meta( int $order_id, array $prior_meta ): bool {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return false;
			}
			foreach ( $prior_meta as $key => $value ) {
				if ( $value === '' || $value === null ) {
					$order->delete_meta_data( $key );
				} else {
					$order->update_meta_data( $key, $value );
				}
			}
			$order->save_meta_data();
			return true;
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Restore unsaved in-memory retry fields when controls change immediately
	 * before persistence. No storage write is needed or permitted here.
	 *
	 * @param \WC_Order $order
	 * @param array<string,mixed> $prior_meta
	 */
	private function restore_retry_meta_on_order( $order, array $prior_meta ): void {
		foreach ( $prior_meta as $key => $value ) {
			if ( $value === '' || $value === null ) {
				$order->delete_meta_data( $key );
			} else {
				$order->update_meta_data( $key, $value );
			}
		}
	}

	/** @phpstan-impure */
	private function controls_block_persistence(): bool {
		$settings = Settings::get_quickbooks_settings();
		return empty( $settings['enabled'] ) || ! empty( $settings['dry_run_mode'] );
	}

    public static function should_retry_error( string $error_code, $error_data = null ): bool {
        if ( $error_code === 'http_request_failed' || $error_code === 'oras_qbo_network_error' ) {
            return true;
        }

        if ( strpos( $error_code, 'oras_qbo_api_http_' ) === 0 ) {
            $status = (int) substr( $error_code, strlen( 'oras_qbo_api_http_' ) );
            if ( $status === 429 ) {
                return true;
            }

            return $status >= 500 && $status <= 599;
        }

        if ( is_array( $error_data ) && array_key_exists( 'retriable', $error_data ) ) {
            return ! empty( $error_data['retriable'] );
        }

        return false;
    }
}
