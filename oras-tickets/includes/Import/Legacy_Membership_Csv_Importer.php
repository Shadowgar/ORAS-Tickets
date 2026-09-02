<?php

namespace ORAS\Tickets\Import;

use ORAS\Tickets\Reporting\Membership_Report_Service;
use ORAS\Tickets\Security\CsvSafety;
use ORAS\Tickets\Storage\Legacy_Membership_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Legacy_Membership_Csv_Importer {
	public const MAX_UPLOAD_BYTES = 1048576;
	public const MAX_ROWS = 2000;
	public const PREVIEW_TTL = 900;

	public const CLASS_NEW = 'valid_new';
	public const CLASS_EXACT_EMAIL = 'exact_email_match';
	public const CLASS_DUPLICATE = 'existing_legacy_duplicate';
	public const CLASS_POSSIBLE_NAME = 'possible_name_match';
	public const CLASS_REVIEW = 'needs_review';

	/** @var array<int,array<string,mixed>> */
	private array $website_rows;
	/** @var array<int,array<string,mixed>> */
	private array $legacy_rows;

	/**
	 * @param array<int,array<string,mixed>>|null $website_rows Optional website-membership snapshot.
	 * @param array<int,array<string,mixed>>|null $legacy_rows Optional legacy-membership snapshot.
	 */
	public function __construct( ?array $website_rows = null, ?array $legacy_rows = null ) {
		if ( null === $website_rows ) {
			$report = ( new Membership_Report_Service() )->get_report( array( 'per_page' => 100 ) );
			$all_rows = isset( $report['all_rows'] ) && is_array( $report['all_rows'] ) ? $report['all_rows'] : array();
			$website_rows = array_values(
				array_filter(
					$all_rows,
					static fn( array $row ): bool => Membership_Report_Service::SOURCE_WEBSITE === ( $row['source'] ?? '' )
				)
			);
		}
		$this->website_rows = $website_rows;
		$this->legacy_rows = $legacy_rows ?? Legacy_Membership_Store::query();
	}

	/**
	 * Parse a bounded CSV into normalized preview rows only.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function preview_file( string $path ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'legacy_import_unreadable', __( 'The uploaded CSV could not be read.', 'oras-tickets' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			return new \WP_Error( 'legacy_import_size', __( 'The CSV must be non-empty and no larger than 1 MB.', 'oras-tickets' ) );
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a bounded temporary CSV avoids loading raw upload contents into memory.
		if ( false === $handle ) {
			return new \WP_Error( 'legacy_import_open', __( 'The uploaded CSV could not be opened.', 'oras-tickets' ) );
		}

		try {
			$header = fgetcsv( $handle, 65536, ',', '"', '\\' );
			if ( false === $header || 0 === count( $header ) || count( $header ) > 32 ) {
				return new \WP_Error( 'legacy_import_headers', __( 'The CSV header row is missing or unsupported.', 'oras-tickets' ) );
			}
			$columns = $this->map_headers( $header );
			if ( is_wp_error( $columns ) ) {
				return $columns;
			}

			$website_emails = $this->index_values( $this->website_rows, 'email', true );
			$website_names = $this->index_values( $this->website_rows, 'member_name', false );
			$legacy_emails = $this->index_values( $this->legacy_rows, 'email', true );
			$legacy_references = $this->index_values( $this->legacy_rows, 'paypal_reference', false );
			$rows = array();
			$row_number = 1;
			while ( false !== ( $values = fgetcsv( $handle, 65536, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Stream rows until EOF without retaining the raw file.
				++$row_number;
				if ( $row_number > self::MAX_ROWS + 1 ) {
					return new \WP_Error( 'legacy_import_rows', __( 'The CSV contains more than 2,000 data rows.', 'oras-tickets' ) );
				}
				if ( $this->row_is_empty( $values ) ) {
					continue;
				}
				$raw = $this->extract_row( $columns, $values );
				$row = $this->build_preview_row( $raw, $row_number, $website_emails, $website_names, $legacy_emails, $legacy_references );
				$rows[] = $row;
				if ( true === $row['importable'] ) {
					$email = $this->normalize_email( (string) ( $row['record']['email'] ?? '' ) );
					$reference = $this->normalize_text( (string) ( $row['record']['paypal_reference'] ?? '' ) );
					if ( '' !== $email ) {
						$legacy_emails[ $email ] = true;
					}
					if ( '' !== $reference ) {
						$legacy_references[ $reference ] = true;
					}
				}
			}
		} finally {
			fclose( $handle );
		}

		return array(
			'schema_version' => 1,
			'rows'           => $rows,
			'counts'         => $this->count_classifications( $rows ),
			'total'          => count( $rows ),
		);
	}

	/** @param array<string,mixed> $preview */
	public static function store_preview( int $user_id, array $preview ): string {
		$token = strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$payload = array(
			'schema_version' => 1,
			'user_id'        => max( 0, $user_id ),
			'created_at'     => time(),
			'rows'           => isset( $preview['rows'] ) && is_array( $preview['rows'] ) ? $preview['rows'] : array(),
			'counts'         => isset( $preview['counts'] ) && is_array( $preview['counts'] ) ? $preview['counts'] : array(),
			'total'          => absint( $preview['total'] ?? 0 ),
		);
		set_transient( self::transient_key( $user_id, $token ), $payload, self::PREVIEW_TTL );

		return $token;
	}

	/** @return array<string,mixed>|null */
	public static function get_preview( int $user_id, string $token ): ?array {
		if ( $user_id <= 0 || ! self::valid_token( $token ) ) {
			return null;
		}
		$payload = get_transient( self::transient_key( $user_id, $token ) );

		return is_array( $payload ) && $user_id === absint( $payload['user_id'] ?? 0 ) ? $payload : null;
	}

	public static function delete_preview( int $user_id, string $token ): void {
		if ( $user_id > 0 && self::valid_token( $token ) ) {
			delete_transient( self::transient_key( $user_id, $token ) );
		}
	}

	/**
	 * @param array<string,mixed> $preview Stored normalized preview payload.
	 * @param string[] $approved_tokens Explicit row approvals.
	 * @return array{created:int,skipped:int,errors:int}
	 */
	public static function commit_preview( array $preview, array $approved_tokens, int $actor_user_id ): array {
		$approved = array_fill_keys( array_map( 'sanitize_key', $approved_tokens ), true );
		$legacy_rows = Legacy_Membership_Store::query();
		$emails = self::static_index( $legacy_rows, 'email', true );
		$references = self::static_index( $legacy_rows, 'paypal_reference', false );
		$result = array(
			'created' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);
		$rows = isset( $preview['rows'] ) && is_array( $preview['rows'] ) ? $preview['rows'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || true !== ( $row['importable'] ?? false ) ) {
				continue;
			}
			$row_token = sanitize_key( (string) ( $row['row_token'] ?? '' ) );
			if ( '' === $row_token || ! isset( $approved[ $row_token ] ) ) {
				continue;
			}
			$record = isset( $row['record'] ) && is_array( $row['record'] ) ? Legacy_Membership_Store::sanitize_record( $row['record'] ) : new \WP_Error( 'legacy_import_record', __( 'Invalid preview row.', 'oras-tickets' ) );
			if ( is_wp_error( $record ) ) {
				++$result['errors'];
				continue;
			}
			$email = strtolower( trim( (string) $record['email'] ) );
			$reference = strtolower( trim( (string) $record['paypal_reference'] ) );
			if ( ( '' !== $email && isset( $emails[ $email ] ) ) || ( '' !== $reference && isset( $references[ $reference ] ) ) ) {
				++$result['skipped'];
				continue;
			}
			$created = Legacy_Membership_Store::create( $record, $actor_user_id );
			if ( is_wp_error( $created ) ) {
				++$result['errors'];
				continue;
			}
			++$result['created'];
			if ( '' !== $email ) {
				$emails[ $email ] = true;
			}
			if ( '' !== $reference ) {
				$references[ $reference ] = true;
			}
		}

		return $result;
	}

	/** @param string[] $headers @return array<int,string>|\WP_Error */
	private function map_headers( array $headers ) {
		$aliases = array(
			'member_name'      => array( 'member_name', 'member name', 'name' ),
			'email'            => array( 'email', 'email address' ),
			'start_date'       => array( 'start_date', 'start date', 'membership start date' ),
			'end_date'         => array( 'end_date', 'end date', 'expiration date', 'renewal date', 'next renewal date' ),
			'status'           => array( 'status', 'membership status' ),
			'paypal_reference' => array( 'paypal_reference', 'paypal reference', 'reference' ),
			'notes'            => array( 'notes', 'note' ),
		);
		$lookup = array();
		foreach ( $aliases as $canonical => $names ) {
			foreach ( $names as $name ) {
				$lookup[ $name ] = $canonical;
			}
		}
		$mapped = array();
		$found = array();
		foreach ( $headers as $index => $header ) {
			$normalized = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header ) ) );
			$canonical = $lookup[ $normalized ] ?? '';
			if ( '' !== $canonical && ! isset( $found[ $canonical ] ) ) {
				$mapped[ (int) $index ] = $canonical;
				$found[ $canonical ] = true;
			}
		}
		if ( ! isset( $found['member_name'], $found['end_date'] ) ) {
			return new \WP_Error( 'legacy_import_required_headers', __( 'The CSV must include Member Name and End Date columns.', 'oras-tickets' ) );
		}

		return $mapped;
	}

	/** @param array<int,string> $columns @param string[] $values @return array<string,string> */
	private function extract_row( array $columns, array $values ): array {
		$row = array();
		foreach ( $columns as $index => $field ) {
			$row[ $field ] = isset( $values[ $index ] ) ? (string) $values[ $index ] : '';
		}

		return $row;
	}

	/**
	 * @param array<string,string> $raw
	 * @param array<string,bool> $website_emails
	 * @param array<string,bool> $website_names
	 * @param array<string,bool> $legacy_emails
	 * @param array<string,bool> $legacy_references
	 * @return array<string,mixed>
	 */
	private function build_preview_row( array $raw, int $row_number, array $website_emails, array $website_names, array $legacy_emails, array $legacy_references ): array {
		$candidate = array(
			'member_name'      => sanitize_text_field( (string) ( $raw['member_name'] ?? '' ) ),
			'email'            => strtolower( sanitize_email( (string) ( $raw['email'] ?? '' ) ) ),
			'start_date'       => sanitize_text_field( (string) ( $raw['start_date'] ?? '' ) ),
			'end_date'         => sanitize_text_field( (string) ( $raw['end_date'] ?? '' ) ),
			'status'           => sanitize_key( (string) ( $raw['status'] ?? 'active' ) ),
			'paypal_reference' => sanitize_text_field( (string) ( $raw['paypal_reference'] ?? '' ) ),
			'linked_user_id'   => 0,
			'transitioned'     => false,
			'notes'            => sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ),
		);
		if ( '' === $candidate['status'] ) {
			$candidate['status'] = 'active';
		}
		$sanitized = Legacy_Membership_Store::sanitize_record( $candidate );
		$classification = self::CLASS_REVIEW;
		$message = '';
		$importable = false;
		$default_approved = false;
		$record = $candidate;
		if ( is_wp_error( $sanitized ) ) {
			$message = $sanitized->get_error_message();
		} else {
			$record = $sanitized;
			$email = $this->normalize_email( (string) $record['email'] );
			$name = $this->normalize_text( (string) $record['member_name'] );
			$reference = $this->normalize_text( (string) $record['paypal_reference'] );
			if ( ( '' !== $email && isset( $legacy_emails[ $email ] ) ) || ( '' !== $reference && isset( $legacy_references[ $reference ] ) ) ) {
				$classification = self::CLASS_DUPLICATE;
				$message = __( 'An existing legacy membership has the same email or PayPal reference.', 'oras-tickets' );
			} elseif ( '' !== $email && isset( $website_emails[ $email ] ) ) {
				$classification = self::CLASS_EXACT_EMAIL;
				$message = __( 'Website account/membership match found. Review before importing.', 'oras-tickets' );
				$importable = true;
			} elseif ( '' !== $name && isset( $website_names[ $name ] ) ) {
				$classification = self::CLASS_POSSIBLE_NAME;
				$message = __( 'Possible name match found. Review before importing.', 'oras-tickets' );
				$importable = true;
			} else {
				$classification = self::CLASS_NEW;
				$message = __( 'Valid new legacy membership.', 'oras-tickets' );
				$importable = true;
				$default_approved = true;
			}
		}
		$display = CsvSafety::row(
			array(
				'member_name'      => (string) $record['member_name'],
				'email'            => (string) $record['email'],
				'start_date'       => (string) $record['start_date'],
				'end_date'         => (string) $record['end_date'],
				'status'           => (string) $record['status'],
				'paypal_reference' => (string) $record['paypal_reference'],
				'notes'            => (string) $record['notes'],
			)
		);
		$row_token = hash( 'sha256', $row_number . '|' . wp_json_encode( $record ) );

		return array(
			'row_number'       => $row_number,
			'row_token'        => $row_token,
			'record'           => $record,
			'display'          => $display,
			'classification'   => $classification,
			'message'          => sanitize_text_field( $message ),
			'importable'       => $importable,
			'default_approved' => $default_approved,
		);
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,int> */
	private function count_classifications( array $rows ): array {
		$counts = array();
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) ( $row['classification'] ?? self::CLASS_REVIEW ) );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/** @param string[] $values */
	private function row_is_empty( array $values ): bool {
		return '' === trim( implode( '', array_map( 'strval', $values ) ) );
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,bool> */
	private function index_values( array $rows, string $field, bool $email ): array {
		return self::static_index( $rows, $field, $email );
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,bool> */
	private static function static_index( array $rows, string $field, bool $email ): array {
		$index = array();
		foreach ( $rows as $row ) {
			$value = $email ? strtolower( trim( (string) ( $row[ $field ] ?? '' ) ) ) : self::normalize_static_text( (string) ( $row[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$index[ $value ] = true;
			}
		}

		return $index;
	}

	private function normalize_email( string $value ): string {
		return strtolower( trim( $value ) );
	}

	private function normalize_text( string $value ): string {
		return self::normalize_static_text( $value );
	}

	private static function normalize_static_text( string $value ): string {
		$value = strtolower( trim( $value ) );

		return (string) preg_replace( '/\s+/', ' ', $value );
	}

	private static function valid_token( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	private static function transient_key( int $user_id, string $token ): string {
		return 'oras_legacy_import_' . max( 0, $user_id ) . '_' . $token;
	}
}
