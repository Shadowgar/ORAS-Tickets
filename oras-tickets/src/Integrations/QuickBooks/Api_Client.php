<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Api_Client {

    private const MINOR_VERSION = 75;
	public const REQUEST_POLICY_DEFAULT = 'default';
	public const REQUEST_POLICY_LOOKUP_ONLY = 'lookup_only';

    private OAuth_Client $oauth_client;
    private QuickBooks_Logger $logger;
	private ?Sync_Orchestrator $journal_entry_dispatcher = null;

    public function __construct( ?OAuth_Client $oauth_client = null, ?QuickBooks_Logger $logger = null ) {
        $this->logger       = $logger ?: new QuickBooks_Logger();
        $this->oauth_client = $oauth_client ?: new OAuth_Client( $this->logger );
	}

	/**
	 * Bind the single orchestrator instance permitted to dispatch JournalEntry
	 * writes through this client. A client cannot be rebound to another
	 * orchestrator after the first successful binding.
	 */
	public function bind_journal_entry_dispatcher( Sync_Orchestrator $orchestrator ): bool {
		if ( $this->journal_entry_dispatcher === null ) {
			$this->journal_entry_dispatcher = $orchestrator;
		}

		return $this->journal_entry_dispatcher === $orchestrator;
	}

    public function test_connection() {
        $settings = Settings::get_quickbooks_settings();
        $realm_id = isset( $settings['realm_id'] ) ? (string) $settings['realm_id'] : '';
        if ( $realm_id === '' ) {
            return new \WP_Error( 'oras_qbo_missing_realm', 'QuickBooks realm ID is missing. Complete OAuth connection first.' );
        }

        return $this->request(
            'GET',
            'companyinfo/' . rawurlencode( $realm_id ),
            array(
                'minorversion' => self::MINOR_VERSION,
            )
        );
    }

    public function fetch_accounts() {
        $query = 'SELECT Id, Name, FullyQualifiedName, AccountType, Active FROM Account WHERE Active = true ORDER BY Name';
        return $this->request(
            'GET',
            'query',
            array(
                'query'        => $query,
                'minorversion' => self::MINOR_VERSION,
            )
        );
    }

	/**
	 * Read the company currency preferences without persisting them locally.
	 *
	 * @return array{home_currency:string,multicurrency_enabled:bool}|\WP_Error
	 */
	public function get_company_currency_context( bool $require_sync_enabled = true ) {
		$response = $this->request(
			'GET',
			'preferences',
			array(
				'minorversion' => self::MINOR_VERSION,
			),
			null,
			null,
			null,
			$require_sync_enabled
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$preferences = isset( $response['Preferences'] ) && is_array( $response['Preferences'] )
			? $response['Preferences']
			: array();
		$currency_preferences = isset( $preferences['CurrencyPrefs'] ) && is_array( $preferences['CurrencyPrefs'] )
			? $preferences['CurrencyPrefs']
			: array();
		$home_currency_raw = $currency_preferences['HomeCurrency'] ?? '';
		$home_currency = is_array( $home_currency_raw )
			? (string) ( $home_currency_raw['value'] ?? $home_currency_raw['Code'] ?? '' )
			: (string) $home_currency_raw;
		$home_currency = strtoupper( trim( $home_currency ) );

		if ( $home_currency === '' ) {
			$error = new \WP_Error(
				'oras_qbo_company_currency_unverified',
				'QuickBooks did not return a verifiable company home currency.'
			);
			$error->add_data( array( 'retriable' => false ) );
			return $error;
		}

		return array(
			'home_currency'         => $home_currency,
			'multicurrency_enabled' => isset( $currency_preferences['MultiCurrencyEnabled'] )
				&& $currency_preferences['MultiCurrencyEnabled'] === true,
		);
	}

	/**
     * Execute a raw QuickBooks SQL-style query.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function run_query( string $query ) {
		return $this->run_query_with_controls( $query, false );
	}

	/**
	 * Execute a sync-owned query that must stop if synchronization is
	 * disabled while an operation is in progress.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run_sync_query( string $query ) {
		return $this->run_query_with_controls( $query, true );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run_query_with_controls( string $query, bool $require_sync_enabled ) {
        $query = trim( $query );
        if ( $query === '' ) {
            return new \WP_Error( 'oras_qbo_missing_query', 'QuickBooks query cannot be empty.' );
        }

        return $this->request(
            'GET',
            'query',
            array(
                'query'        => $query,
                'minorversion' => self::MINOR_VERSION,
			),
			null,
			null,
			null,
			$require_sync_enabled
        );
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
	public function find_journal_entry_by_doc_number(
		string $doc_number,
		bool $require_sync_enabled = true,
		string $request_policy = self::REQUEST_POLICY_DEFAULT
	) {
        $doc_number = trim( $doc_number );
        if ( $doc_number === '' ) {
            return new \WP_Error( 'oras_qbo_missing_doc_number', 'JournalEntry lookup requires a doc number.' );
        }

        $escaped = str_replace( "'", "\\'", $doc_number );
        $query   = sprintf(
			"SELECT * FROM JournalEntry WHERE DocNumber = '%s' MAXRESULTS 2",
            $escaped
        );

        $response = $this->request(
            'GET',
            'query',
            array(
                'query'        => $query,
                'minorversion' => self::MINOR_VERSION,
			),
			null,
			null,
			null,
			$require_sync_enabled,
			$request_policy
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $entries = isset( $response['QueryResponse']['JournalEntry'] ) && is_array( $response['QueryResponse']['JournalEntry'] )
            ? $response['QueryResponse']['JournalEntry']
            : array();

		if ( count( $entries ) > 1 ) {
			$error = new \WP_Error(
				'oras_qbo_duplicate_journal_entries',
				'Multiple QuickBooks JournalEntries use the deterministic document number; manual reconciliation is required.'
			);
			$error->add_data(
				array(
					'doc_number' => $doc_number,
					'entry_ids'  => array_values( array_filter( wp_list_pluck( $entries, 'Id' ) ) ),
					'retriable'  => false,
				)
			);
			return $error;
		}

        return array(
            'found' => ! empty( $entries ),
            'entry' => ! empty( $entries ) && is_array( $entries[0] ) ? $entries[0] : array(),
            'meta'  => isset( $response['__oras_meta'] ) && is_array( $response['__oras_meta'] ) ? $response['__oras_meta'] : array(),
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
	/**
	 * Backward-compatible hard stop for code that previously wrote a
	 * JournalEntry directly through the API client.
	 *
	 * @param array<string,mixed> $payload
	 * @return \WP_Error
	 */
	public function create_journal_entry(
		array $payload,
		?callable $before_dispatch = null,
		string $request_id = ''
	) {
		unset( $payload, $before_dispatch, $request_id );
		$error = new \WP_Error(
			'oras_qbo_orchestrator_required',
			'JournalEntry writes must run through the QuickBooks sync orchestrator.'
		);
		$this->add_dispatch_state( $error, false );
		return $error;
	}

	/**
	 * Dispatch an orchestrator-protected JournalEntry write. Object identity
	 * prevents ordinary application code from treating an arbitrary callback
	 * as write authorization.
	 *
	 * @internal Sync_Orchestrator is the only supported caller.
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|\WP_Error
	 */
	public function dispatch_journal_entry(
		Sync_Orchestrator $orchestrator,
		array $payload,
		callable $prepare_write,
		callable $mark_dispatch_started,
		string $request_id
	) {
		if ( $this->journal_entry_dispatcher !== $orchestrator ) {
			$error = new \WP_Error(
				'oras_qbo_orchestrator_required',
				'JournalEntry writes must run through the QuickBooks sync orchestrator.'
			);
			$this->add_dispatch_state( $error, false );
			return $error;
		}

		$request_id = trim( $request_id );
		$encoded_payload     = wp_json_encode( $payload );
		$expected_request_id = 'oras-' . substr( hash( 'sha256', is_string( $encoded_payload ) ? $encoded_payload : '' ), 0, 45 );
		if ( $request_id === '' || ! hash_equals( $expected_request_id, $request_id ) ) {
			$error = new \WP_Error(
				'oras_qbo_invalid_request_id',
				'JournalEntry request ID does not match the exact payload.'
			);
			$this->add_dispatch_state( $error, false );
			return $error;
		}

		$query = array(
			'minorversion' => self::MINOR_VERSION,
			'requestid'    => $request_id,
		);

        return $this->request(
            'POST',
            'journalentry',
			$query,
			$payload,
			$prepare_write,
			$mark_dispatch_started,
			true
        );
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     */
	private function request(
		string $method,
		string $endpoint,
		array $query = array(),
		?array $body = null,
		?callable $prepare_write = null,
		?callable $mark_dispatch_started = null,
		bool $require_sync_enabled = false,
		string $request_policy = self::REQUEST_POLICY_DEFAULT
	) {
		$policy_guard = $this->guard_request_policy( $method, $request_policy );
		if ( is_wp_error( $policy_guard ) ) {
			return $policy_guard;
		}

		$settings_guard = $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
		if ( is_wp_error( $settings_guard ) ) {
			return $settings_guard;
		}

		$settings_guard = function () use ( $method, $endpoint, $require_sync_enabled ) {
			return $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
		};
		$token = $this->oauth_client->get_valid_access_token( $settings_guard, $request_policy );
        if ( is_wp_error( $token ) ) {
			$this->add_dispatch_state( $token, false );
            return $token;
        }

		return $this->request_with_token(
			$method,
			$endpoint,
			$query,
			$body,
			(string) $token,
			$request_policy !== self::REQUEST_POLICY_LOOKUP_ONLY,
			$prepare_write,
			$mark_dispatch_started,
			$require_sync_enabled,
			$request_policy
		);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     */
	private function request_with_token(
		string $method,
		string $endpoint,
		array $query,
		?array $body,
		string $token,
		bool $allow_refresh_retry,
		?callable $prepare_write = null,
		?callable $mark_dispatch_started = null,
		bool $require_sync_enabled = false,
		string $request_policy = self::REQUEST_POLICY_DEFAULT
	) {
		$policy_guard = $this->guard_request_policy( $method, $request_policy );
		if ( is_wp_error( $policy_guard ) ) {
			return $policy_guard;
		}

		$settings_guard = $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
		if ( is_wp_error( $settings_guard ) ) {
			return $settings_guard;
		}

        $settings = Settings::get_quickbooks_settings();
        $realm_id = isset( $settings['realm_id'] ) ? (string) $settings['realm_id'] : '';

        if ( $realm_id === '' ) {
			$error = new \WP_Error( 'oras_qbo_missing_realm', 'QuickBooks realm ID is missing.' );
			$this->add_dispatch_state( $error, false );
			return $error;
        }

        $base_url = Settings::get_api_base_url();
        $endpoint = ltrim( $endpoint, '/' );
        $url      = $base_url . '/v3/company/' . rawurlencode( $realm_id ) . '/' . $endpoint;
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
        );

        if ( $body !== null ) {
			$encoded_body = wp_json_encode( $body );
			if ( ! is_string( $encoded_body ) ) {
				$error = new \WP_Error( 'oras_qbo_request_encode_failed', 'QuickBooks request payload could not be encoded.' );
				$error->add_data( array( 'retriable' => false ) );
				$this->add_dispatch_state( $error, false );
				return $error;
			}
			$args['body'] = $encoded_body;
		}

		if ( $prepare_write !== null ) {
			try {
				$ready = call_user_func( $prepare_write );
			} catch ( \Throwable $throwable ) {
				$ready = new \WP_Error(
					'oras_qbo_pre_dispatch_failed',
					'QuickBooks write preparation failed before the request was sent.'
				);
				$ready->add_data(
					array(
						'retriable' => true,
						'exception' => get_class( $throwable ),
					)
				);
			}
			if ( is_wp_error( $ready ) ) {
				$this->add_dispatch_state( $ready, false );
				return $ready;
			}
		}

		$settings_guard = $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
		if ( is_wp_error( $settings_guard ) ) {
			return $settings_guard;
		}

		if ( $request_policy !== self::REQUEST_POLICY_LOOKUP_ONLY ) {
			$this->logger->info(
				'QuickBooks API request',
				array(
					'method'   => $args['method'],
					'endpoint' => $endpoint,
					'query'    => $query,
				)
			);
		}

		$settings_guard = $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
		if ( is_wp_error( $settings_guard ) ) {
			return $settings_guard;
		}

		if ( $mark_dispatch_started !== null ) {
			try {
				$marked = call_user_func( $mark_dispatch_started );
			} catch ( \Throwable $throwable ) {
				$marked = new \WP_Error(
					'oras_qbo_dispatch_marker_failed',
					'QuickBooks dispatch marker could not be persisted; no request was sent.'
				);
				$marked->add_data(
					array(
						'retriable' => true,
						'exception' => get_class( $throwable ),
					)
				);
			}
			if ( is_wp_error( $marked ) ) {
				$this->add_dispatch_state( $marked, false );
				return $marked;
			}
		}

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
			$response->add_data(
				array(
					'endpoint'               => $endpoint,
					'retriable'              => true,
					'qbo_request_dispatched' => true,
					'qbo_write_outcome'      => $this->is_journal_entry_write( $method, $endpoint ) ? 'unknown' : '',
				)
			);
			$settings_guard = $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
			if ( ! is_wp_error( $settings_guard ) && $request_policy !== self::REQUEST_POLICY_LOOKUP_ONLY ) {
				$this->logger->error(
					'QuickBooks API request failed',
					array(
						'endpoint' => $endpoint,
						'error'    => $response->get_error_message(),
					)
				);
			}
            return $response;
        }

		$raw_status = isset( $response['response'] ) && is_array( $response['response'] )
			? ( $response['response']['code'] ?? null )
			: null;
		$valid_status = ( is_int( $raw_status ) || ( is_string( $raw_status ) && ctype_digit( $raw_status ) ) )
			&& (int) $raw_status >= 100
			&& (int) $raw_status <= 599;
		if ( ! $valid_status ) {
			$error = new \WP_Error( 'oras_qbo_invalid_http_status', 'QuickBooks returned a missing or malformed HTTP status.' );
			$error->add_data(
				array(
					'endpoint'               => $endpoint,
					'retriable'              => true,
					'qbo_request_dispatched' => true,
					'qbo_write_outcome'      => $this->is_journal_entry_write( $method, $endpoint ) ? 'unknown' : '',
				)
			);
			return $error;
		}

		$status = (int) $raw_status;
        $raw    = (string) wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );
        $intuit_tid = $this->extract_intuit_tid( $response );

        if ( $status === 401 && $allow_refresh_retry ) {
			$settings_guard = $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
			if ( is_wp_error( $settings_guard ) ) {
				return $this->mark_post_dispatch_error( $settings_guard, $method, $endpoint, $status, $intuit_tid );
			}
			$refresh = $this->oauth_client->refresh_access_token(
				function () use ( $method, $endpoint, $require_sync_enabled ) {
					return $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
				}
			);
			if ( is_wp_error( $refresh ) && in_array( $refresh->get_error_code(), array( 'oras_qbo_dry_run_read_only', 'oras_qbo_disabled' ), true ) ) {
				$refresh_data = $refresh->get_error_data();
				if ( ! is_array( $refresh_data ) ) {
					$refresh_data = array();
				}
				$refresh_data['status'] = $status;
				$refresh_data['intuit_tid'] = $intuit_tid;
				$refresh_data['endpoint'] = $endpoint;
				$refresh_data['qbo_request_dispatched'] = true;
				$refresh_data['qbo_write_outcome'] = $this->is_journal_entry_write( $method, $endpoint ) ? 'unknown' : '';
				$refresh->add_data( $refresh_data );
				return $refresh;
			}
            if ( ! is_wp_error( $refresh ) ) {
                $settings = Settings::get_quickbooks_settings();
                $token    = isset( $settings['access_token'] ) ? (string) $settings['access_token'] : '';
                if ( $token !== '' ) {
					$retry_response = $this->request_with_token(
						$method,
						$endpoint,
						$query,
						$body,
						$token,
						false,
						$prepare_write,
						$mark_dispatch_started,
						$require_sync_enabled,
						$request_policy
					);
					if ( is_wp_error( $retry_response ) ) {
						return $this->mark_post_dispatch_error( $retry_response, $method, $endpoint, $status, $intuit_tid );
					}
					return $retry_response;
                }
            }

            $reason = is_wp_error( $refresh ) ? $refresh->get_error_message() : 'Unknown refresh failure.';
            $error = new \WP_Error(
                'oras_qbo_auth_error_access',
                'Auth Error Access: QuickBooks access token is invalid or expired and refresh failed. ' . $reason
            );
            $error->add_data(
                array(
					'status'                 => $status,
					'intuit_tid'             => $intuit_tid,
					'endpoint'               => $endpoint,
					'retriable'              => false,
					'qbo_request_dispatched' => true,
					'qbo_write_outcome'      => $this->is_journal_entry_write( $method, $endpoint ) ? 'unknown' : '',
                )
            );
            return $error;
        }

		if ( $status === 401 && ! $allow_refresh_retry ) {
			if ( $request_policy === self::REQUEST_POLICY_LOOKUP_ONLY ) {
				$error = new \WP_Error(
					'oras_qbo_lookup_auth_required',
					'QuickBooks lookup authentication is no longer usable. Reconnect QuickBooks before retrying this read-only inventory.'
				);
				$error->add_data(
					array(
						'status'                 => $status,
						'intuit_tid'             => $intuit_tid,
						'endpoint'               => $endpoint,
						'retriable'              => false,
						'qbo_request_dispatched' => true,
						'request_policy'         => self::REQUEST_POLICY_LOOKUP_ONLY,
					)
				);
				return $error;
			}

            $error = new \WP_Error(
                'oras_qbo_auth_error_access',
                'Auth Error Access: QuickBooks access token authentication failed after refresh attempt. Reconnect QuickBooks.'
            );
            $error->add_data(
                array(
					'status'                 => $status,
					'intuit_tid'             => $intuit_tid,
					'endpoint'               => $endpoint,
					'retriable'              => false,
					'qbo_request_dispatched' => true,
					'qbo_write_outcome'      => $this->is_journal_entry_write( $method, $endpoint ) ? 'unknown' : '',
                )
            );
            return $error;
        }

        if ( $status < 200 || $status >= 300 ) {
            $message = 'QuickBooks request failed with HTTP ' . $status . '.';
            if ( is_array( $data ) && isset( $data['Fault']['Error'][0]['Message'] ) ) {
                $message = (string) $data['Fault']['Error'][0]['Message'];
                if ( isset( $data['Fault']['Error'][0]['Detail'] ) ) {
                    $message .= ' ' . (string) $data['Fault']['Error'][0]['Detail'];
                }
            }

			$settings_guard = $this->guard_request_settings( $method, $endpoint, $require_sync_enabled );
			if ( ! is_wp_error( $settings_guard ) && $request_policy !== self::REQUEST_POLICY_LOOKUP_ONLY ) {
				$this->logger->error(
					'QuickBooks API returned error status',
					array(
						'status'     => $status,
						'endpoint'   => $endpoint,
						'intuit_tid' => $intuit_tid,
					)
				);
			}

            $error = new \WP_Error( 'oras_qbo_api_http_' . $status, $message );
            $error->add_data(
                array(
					'status'                 => $status,
					'intuit_tid'             => $intuit_tid,
					'endpoint'               => $endpoint,
					'retriable'              => self::is_retriable_status( $status ),
					'qbo_request_dispatched' => true,
					'qbo_write_outcome'      => $this->is_journal_entry_write( $method, $endpoint )
						? ( $this->is_conclusive_rejection( $status, $data ) ? 'conclusive_rejection' : 'unknown' )
						: '',
                )
            );

            return $error;
        }

        if ( ! is_array( $data ) ) {
            $error = new \WP_Error( 'oras_qbo_invalid_json', 'QuickBooks returned invalid JSON.' );
            $error->add_data(
                array(
					'status'                 => $status,
					'intuit_tid'             => $intuit_tid,
					'endpoint'               => $endpoint,
					'retriable'              => false,
					'qbo_request_dispatched' => true,
					'qbo_write_outcome'      => $this->is_journal_entry_write( $method, $endpoint ) ? 'unknown' : '',
                )
            );
            return $error;
        }

        $data['__oras_meta'] = array(
            'status'     => $status,
            'intuit_tid' => $intuit_tid,
            'endpoint'   => $endpoint,
        );

        return $data;
	}

	private function add_dispatch_state( \WP_Error $error, bool $dispatched ): void {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['qbo_request_dispatched'] = ! empty( $data['qbo_request_dispatched'] ) || $dispatched;
		$error->add_data( $data );
	}

	private function mark_post_dispatch_error(
		\WP_Error $error,
		string $method,
		string $endpoint,
		int $status,
		string $intuit_tid
	): \WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['status'] = $status;
		$data['intuit_tid'] = $intuit_tid;
		$data['endpoint'] = $endpoint;
		$data['qbo_request_dispatched'] = true;
		$data['qbo_write_outcome'] = $this->is_journal_entry_write( $method, $endpoint ) ? 'unknown' : '';
		$error->add_data( $data );
		return $error;
	}

	/**
	 * Fail closed before every network boundary while dry-run is enabled, and
	 * before every JournalEntry write while synchronization is disabled.
	 *
	 * @return true|\WP_Error
	 */
	private function guard_request_settings( string $method, string $endpoint, bool $require_sync_enabled = false ) {
		$settings = Settings::get_quickbooks_settings();
		if ( ! empty( $settings['dry_run_mode'] ) ) {
			$error = new \WP_Error(
				'oras_qbo_dry_run_read_only',
				'QuickBooks request skipped because dry-run mode is enabled.'
			);
			$error->add_data(
				array(
					'retriable'              => false,
					'qbo_request_dispatched' => false,
				)
			);
			return $error;
		}

		if ( ( $require_sync_enabled || $this->is_journal_entry_write( $method, $endpoint ) ) && empty( $settings['enabled'] ) ) {
			$error = new \WP_Error(
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

	private function is_journal_entry_write( string $method, string $endpoint ): bool {
		return strtoupper( $method ) === 'POST' && trim( $endpoint, '/' ) === 'journalentry';
	}

	/**
	 * Lookup-only is an explicit GET-only policy. It is carried through token
	 * selection, request logging, 401 handling, and any recursive request path.
	 *
	 * @return true|\WP_Error
	 */
	private function guard_request_policy( string $method, string $request_policy ) {
		if ( ! in_array( $request_policy, array( self::REQUEST_POLICY_DEFAULT, self::REQUEST_POLICY_LOOKUP_ONLY ), true ) ) {
			return new \WP_Error( 'oras_qbo_invalid_request_policy', 'QuickBooks request policy is invalid.' );
		}
		if ( $request_policy === self::REQUEST_POLICY_LOOKUP_ONLY && strtoupper( $method ) !== 'GET' ) {
			return new \WP_Error( 'oras_qbo_lookup_policy_write_blocked', 'Lookup-only QuickBooks requests must use GET.' );
		}

		return true;
	}

	/**
	 * A response is conclusive only when a non-timeout 4xx contains the
	 * structured QuickBooks Fault/Error rejection envelope.
	 *
	 * @param mixed $data
	 */
	private function is_conclusive_rejection( int $status, $data ): bool {
		return $status >= 400
			&& $status <= 499
			&& $status !== 401
			&& $status !== 408
			&& is_array( $data )
			&& isset( $data['Fault']['Error'] )
			&& is_array( $data['Fault']['Error'] )
			&& ! empty( $data['Fault']['Error'] );
	}

    private function extract_intuit_tid( array $response ): string {
        $headers = wp_remote_retrieve_headers( $response );
        if ( is_object( $headers ) && method_exists( $headers, 'get' ) ) {
            return (string) $headers->get( 'intuit_tid' );
        }

        if ( is_array( $headers ) && isset( $headers['intuit_tid'] ) ) {
            return (string) $headers['intuit_tid'];
        }

        return '';
    }

    private static function is_retriable_status( int $status ): bool {
        if ( $status === 429 ) {
            return true;
        }

        return $status >= 500 && $status <= 599;
    }
}
