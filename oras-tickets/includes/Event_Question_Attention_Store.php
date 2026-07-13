<?php

namespace ORAS\Tickets;

if ( ! defined( 'ABSPATH' ) && ! defined( 'STDIN' ) ) {
	exit;
}

final class Event_Question_Attention_Store {
	private const OPTION_SCHEMA_VERSION = 'oras_tickets_event_question_attention_schema_version';
	private const SCHEMA_VERSION = 1;

	public const STATUS_OPEN = 'open';
	public const STATUS_REVIEWED = 'reviewed';
	public const STATUS_RESOLVED = 'resolved';
	public const STATUS_DISMISSED = 'dismissed';

	/**
	 * @var array<int,string>
	 */
	private const ALLOWED_STATUSES = array(
		self::STATUS_OPEN,
		self::STATUS_REVIEWED,
		self::STATUS_RESOLVED,
		self::STATUS_DISMISSED,
	);

	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );
		if ( $installed >= self::SCHEMA_VERSION && self::table_exists() ) {
			return;
		}

		self::install_schema();
	}

	public static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			source_type varchar(32) NOT NULL DEFAULT '',
			source_id varchar(191) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attendee_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			question_id varchar(191) NOT NULL DEFAULT '',
			question_label text NOT NULL,
			answer_value mediumtext NOT NULL,
			rule_id varchar(191) NOT NULL DEFAULT '',
			rule_label varchar(191) NOT NULL DEFAULT '',
			severity varchar(20) NOT NULL DEFAULT 'review',
			status varchar(20) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewed_at datetime NULL DEFAULT NULL,
			internal_note text NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attention_unique (event_id,source_type,source_id,question_id,rule_id),
			KEY event_status (event_id,status,updated_at,id),
			KEY event_severity (event_id,severity,status,id),
			KEY user_status (user_id,status,updated_at),
			KEY order_status (order_id,status,updated_at)
		) {$charset_collate};";

		dbDelta( $sql );

		if ( self::table_exists() ) {
			update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<int,array<string,mixed>> $questions
	 * @param array<int,array<string,mixed>> $snapshots
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_items_for_answer_snapshots( int $event_id, string $source_type, string $source_id, array $context, array $questions, array $snapshots ): array {
		if ( $event_id <= 0 || '' === trim( $source_type ) || '' === trim( $source_id ) ) {
			return array();
		}

		$question_map = self::question_map( $questions );
		$items = array();

		foreach ( $snapshots as $snapshot ) {
			if ( ! is_array( $snapshot ) || empty( $snapshot['id'] ) ) {
				continue;
			}

			$question_id = (string) $snapshot['id'];
			if ( ! isset( $question_map[ $question_id ] ) ) {
				continue;
			}

			$question = $question_map[ $question_id ];
			$answer = $snapshot['value'] ?? ( $snapshot['display_value'] ?? '' );
			$matches = Event_Questions::match_attention_rules( $question, $answer );
			foreach ( $matches as $rule ) {
				$items[] = self::prepare_attention_item(
					$event_id,
					$source_type,
					$source_id,
					$context,
					$question,
					$snapshot,
					$rule
				);
			}
		}

		return $items;
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<int,array<string,mixed>> $questions
	 * @param array<int,array<string,mixed>> $snapshots
	 * @return array<int,int>
	 */
	public static function upsert_for_answer_snapshots( int $event_id, string $source_type, string $source_id, array $context, array $questions, array $snapshots ): array {
		$items = self::build_items_for_answer_snapshots( $event_id, $source_type, $source_id, $context, $questions, $snapshots );
		$ids = array();

		foreach ( $items as $item ) {
			$id = self::upsert( $item );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function upsert( array $data ): int {
		self::maybe_upgrade();

		global $wpdb;

		$row = self::prepare_row( $data );
		$existing_id = self::find_existing_id( $row );
		if ( $existing_id > 0 ) {
			$updated = $wpdb->update(
				self::table_name(),
				$row,
				array( 'id' => $existing_id ),
				self::row_formats(),
				array( '%d' )
			);

			return false === $updated ? 0 : $existing_id;
		}

		$inserted = $wpdb->insert( self::table_name(), $row, self::row_formats() );
		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $filters = array() ): array {
		self::maybe_upgrade();

		global $wpdb;

		$where = array( '1=1' );
		$args = array();

		$event_id = isset( $filters['event_id'] ) ? absint( $filters['event_id'] ) : 0;
		if ( $event_id > 0 ) {
			$where[] = 'event_id = %d';
			$args[] = $event_id;
		}

		$status = isset( $filters['status'] ) ? self::sanitize_status( (string) $filters['status'] ) : '';
		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[] = $status;
		}

		$severity = isset( $filters['severity'] ) ? self::sanitize_severity( (string) $filters['severity'] ) : '';
		if ( '' !== $severity ) {
			$where[] = 'severity = %s';
			$args[] = $severity;
		}

		$source_type = isset( $filters['source_type'] ) ? self::sanitize_source_type( (string) $filters['source_type'] ) : '';
		if ( '' !== $source_type ) {
			$where[] = 'source_type = %s';
			$args[] = $source_type;
		}

		$search = isset( $filters['search'] ) ? sanitize_text_field( (string) $filters['search'] ) : '';
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(attendee_name LIKE %s OR email LIKE %s OR question_label LIKE %s OR answer_value LIKE %s OR rule_label LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}

		$limit = isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 100;
		$limit = min( 500, max( 1, $limit ) );
		$offset = isset( $filters['offset'] ) ? absint( $filters['offset'] ) : 0;

		$sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), 'ARRAY_A' );
		return is_array( $rows ) ? $rows : array();
	}

	public static function count_open( int $event_id = 0 ): int {
		self::maybe_upgrade();

		global $wpdb;

		$where = 'status = %s';
		$args = array( self::STATUS_OPEN );
		if ( $event_id > 0 ) {
			$where .= ' AND event_id = %d';
			$args[] = $event_id;
		}

		return max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . $where, $args ) ) );
	}

	public static function table_exists(): bool {
		global $wpdb;

		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) && $found === $table;
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'oras_event_question_attention';
	}

	/**
	 * @param array<int,array<string,mixed>> $questions
	 * @return array<string,array<string,mixed>>
	 */
	private static function question_map( array $questions ): array {
		$map = array();
		foreach ( Event_Questions::normalize_definitions( $questions ) as $question ) {
			$map[ (string) $question['id'] ] = $question;
		}

		return $map;
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $question
	 * @param array<string,mixed> $snapshot
	 * @param array<string,string> $rule
	 * @return array<string,mixed>
	 */
	private static function prepare_attention_item( int $event_id, string $source_type, string $source_id, array $context, array $question, array $snapshot, array $rule ): array {
		return array(
			'event_id'       => $event_id,
			'source_type'    => $source_type,
			'source_id'      => $source_id,
			'user_id'        => isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0,
			'order_id'       => isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0,
			'order_item_id'  => isset( $context['order_item_id'] ) ? absint( $context['order_item_id'] ) : 0,
			'attendee_name'  => (string) ( $context['attendee_name'] ?? '' ),
			'email'          => (string) ( $context['email'] ?? '' ),
			'question_id'    => (string) $question['id'],
			'question_label' => (string) $snapshot['label'],
			'answer_value'   => Event_Questions::snapshots_to_label_map( array( $snapshot ) )[ (string) $snapshot['label'] ] ?? '',
			'rule_id'        => (string) $rule['id'],
			'rule_label'     => (string) $rule['label'],
			'severity'       => (string) $rule['severity'],
			'status'         => self::STATUS_OPEN,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function prepare_row( array $data ): array {
		$now = current_time( 'mysql', true );
		$created_at = self::sanitize_mysql_datetime( (string) ( $data['created_at'] ?? '' ) );
		if ( '' === $created_at ) {
			$created_at = $now;
		}

		return array(
			'event_id'       => isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0,
			'source_type'    => self::sanitize_source_type( (string) ( $data['source_type'] ?? '' ) ),
			'source_id'      => self::sanitize_text_snapshot( (string) ( $data['source_id'] ?? '' ), 191 ),
			'user_id'        => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0,
			'order_id'       => isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0,
			'order_item_id'  => isset( $data['order_item_id'] ) ? absint( $data['order_item_id'] ) : 0,
			'attendee_name'  => self::sanitize_text_snapshot( (string) ( $data['attendee_name'] ?? '' ), 191 ),
			'email'          => sanitize_email( (string) ( $data['email'] ?? '' ) ),
			'question_id'    => self::sanitize_text_snapshot( (string) ( $data['question_id'] ?? '' ), 191 ),
			'question_label' => self::sanitize_text_snapshot( (string) ( $data['question_label'] ?? '' ), 1000 ),
			'answer_value'   => self::sanitize_body_snapshot( (string) ( $data['answer_value'] ?? '' ) ),
			'rule_id'        => self::sanitize_text_snapshot( (string) ( $data['rule_id'] ?? '' ), 191 ),
			'rule_label'     => self::sanitize_text_snapshot( (string) ( $data['rule_label'] ?? '' ), 191 ),
			'severity'       => self::sanitize_severity( (string) ( $data['severity'] ?? '' ) ) ?: Event_Questions::SEVERITY_REVIEW,
			'status'         => self::sanitize_status( (string) ( $data['status'] ?? '' ) ) ?: self::STATUS_OPEN,
			'created_at'     => $created_at,
			'updated_at'     => $now,
			'reviewed_by'    => isset( $data['reviewed_by'] ) ? absint( $data['reviewed_by'] ) : 0,
			'reviewed_at'    => self::sanitize_mysql_datetime( (string) ( $data['reviewed_at'] ?? '' ) ) ?: null,
			'internal_note'  => self::sanitize_body_snapshot( (string) ( $data['internal_note'] ?? '' ) ),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function row_formats(): array {
		return array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function find_existing_id( array $row ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table_name() . ' WHERE event_id = %d AND source_type = %s AND source_id = %s AND question_id = %s AND rule_id = %s LIMIT 1',
				$row['event_id'],
				$row['source_type'],
				$row['source_id'],
				$row['question_id'],
				$row['rule_id']
			)
		);
	}

	private static function sanitize_source_type( string $value ): string {
		$clean = sanitize_key( $value );
		return in_array( $clean, array( 'rsvp', 'ticket' ), true ) ? $clean : '';
	}

	private static function sanitize_status( string $value ): string {
		$clean = sanitize_key( $value );
		return in_array( $clean, self::ALLOWED_STATUSES, true ) ? $clean : '';
	}

	private static function sanitize_severity( string $value ): string {
		$clean = sanitize_key( $value );
		return array_key_exists( $clean, Event_Questions::attention_severity_options() ) ? $clean : '';
	}

	private static function sanitize_text_snapshot( string $value, int $max_length ): string {
		$clean = sanitize_text_field( $value );
		if ( $max_length > 0 && strlen( $clean ) > $max_length ) {
			return substr( $clean, 0, $max_length );
		}

		return $clean;
	}

	private static function sanitize_body_snapshot( string $value ): string {
		$clean = sanitize_textarea_field( $value );
		if ( strlen( $clean ) > 65535 ) {
			return substr( $clean, 0, 65535 );
		}

		return $clean;
	}

	private static function sanitize_mysql_datetime( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
