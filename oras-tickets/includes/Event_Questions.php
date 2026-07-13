<?php

namespace ORAS\Tickets;

if ( ! defined( 'ABSPATH' ) && ! defined( 'STDIN' ) ) {
	exit;
}

final class Event_Questions {
	public const META_KEY = '_oras_event_questions_v1';
	public const CART_ITEM_KEY = '_oras_event_question_answers';
	public const ORDER_ITEM_KEY = '_oras_event_question_answers';
	public const RSVP_CONTACT_KEY = 'event_questions';

	public const APPLIES_TICKETS = 'tickets';
	public const APPLIES_RSVP = 'rsvp';
	public const APPLIES_BOTH = 'both';

	public const ATTENDANCE_ALL = 'all';
	public const ATTENDANCE_ONSITE = 'onsite';
	public const ATTENDANCE_VIRTUAL = 'virtual';

	public const ATTENTION_EQUALS = 'equals';
	public const ATTENTION_CONTAINS = 'contains';
	public const ATTENTION_IS_BLANK = 'is_blank';
	public const ATTENTION_IS_NOT_BLANK = 'is_not_blank';
	public const ATTENTION_GREATER_THAN = 'greater_than';
	public const ATTENTION_LESS_THAN = 'less_than';
	public const ATTENTION_ALWAYS = 'always';

	public const SEVERITY_REVIEW = 'review';
	public const SEVERITY_URGENT = 'urgent';

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function load_definitions( int $event_id ): array {
		if ( $event_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$raw = get_post_meta( $event_id, self::META_KEY, true );
		return self::normalize_definitions( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * @param array<int|string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_definitions( array $raw ): array {
		$definitions = array();
		$used_ids = array();

		foreach ( $raw as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = self::clean_text( $row['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}

			$id = self::normalize_id( $row['id'] ?? '', $label, (int) $index, $used_ids );
			$used_ids[ $id ] = true;

			$definitions[] = array(
				'id'               => $id,
				'label'            => $label,
				'type'             => self::normalize_type( $row['type'] ?? 'text' ),
				'required'         => ! empty( $row['required'] ),
				'applies_to'       => self::normalize_applies_to( $row['applies_to'] ?? self::APPLIES_BOTH ),
				'attendance_scope' => self::normalize_attendance_scope( $row['attendance_scope'] ?? self::ATTENDANCE_ALL ),
				'options'          => self::normalize_options( $row['options'] ?? array() ),
				'attention_rules'  => self::normalize_attention_rules( $row['attention_rules'] ?? array() ),
			);
		}

		return $definitions;
	}

	/**
	 * @param array<int,array<string,mixed>> $questions
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter_questions( array $questions, string $applies_to, string $attendance_scope = self::ATTENDANCE_ALL ): array {
		$applies_to = self::normalize_applies_to( $applies_to );
		$attendance_scope = self::normalize_attendance_scope( $attendance_scope );

		$filtered = array();
		foreach ( self::normalize_definitions( $questions ) as $question ) {
			$question_applies = (string) $question['applies_to'];
			if ( self::APPLIES_BOTH !== $question_applies && $question_applies !== $applies_to ) {
				continue;
			}

			$question_attendance = (string) $question['attendance_scope'];
			if ( self::ATTENDANCE_ALL !== $question_attendance && self::ATTENDANCE_ALL !== $attendance_scope && $question_attendance !== $attendance_scope ) {
				continue;
			}

			$filtered[] = $question;
		}

		return $filtered;
	}

	/**
	 * @param array<int,array<string,mixed>> $questions
	 * @param array<string,mixed> $raw_answers
	 * @return true|\WP_Error
	 */
	public static function validate_answers( array $questions, array $raw_answers ) {
		$error = new \WP_Error();

		foreach ( self::normalize_definitions( $questions ) as $question ) {
			$id = (string) $question['id'];
			$value = $raw_answers[ $id ] ?? '';
			$clean = self::sanitize_answer_value( $question, $value );

			if ( ! empty( $question['required'] ) && self::is_empty_answer( $clean ) ) {
				$error->add(
					'oras_event_question_required_' . $id,
					sprintf(
						/* translators: %s: question label */
						__( 'Please answer: %s', 'oras-tickets' ),
						(string) $question['label']
					)
				);
				continue;
			}

			if ( 'email' === (string) $question['type'] && '' !== (string) $clean && function_exists( 'is_email' ) && ! is_email( (string) $clean ) ) {
				$error->add(
					'oras_event_question_email_' . $id,
					sprintf(
						/* translators: %s: question label */
						__( 'Please enter a valid email for: %s', 'oras-tickets' ),
						(string) $question['label']
					)
				);
			}
		}

		return $error->has_errors() ? $error : true;
	}

	/**
	 * @param array<int,array<string,mixed>> $questions
	 * @param array<string,mixed> $raw_answers
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_answer_snapshots( array $questions, array $raw_answers ): array {
		$snapshots = array();

		foreach ( self::normalize_definitions( $questions ) as $question ) {
			$id = (string) $question['id'];
			$value = self::sanitize_answer_value( $question, $raw_answers[ $id ] ?? '' );
			if ( self::is_empty_answer( $value ) ) {
				continue;
			}

			$snapshots[] = array(
				'id'               => $id,
				'label'            => (string) $question['label'],
				'type'             => (string) $question['type'],
				'value'            => $value,
				'display_value'    => self::format_answer_value( $value ),
				'applies_to'       => (string) $question['applies_to'],
				'attendance_scope' => (string) $question['attendance_scope'],
			);
		}

		return $snapshots;
	}

	/**
	 * @param array<int,array<string,mixed>> $snapshots
	 * @return array<string,string>
	 */
	public static function snapshots_to_label_map( array $snapshots ): array {
		$map = array();
		foreach ( $snapshots as $snapshot ) {
			if ( ! is_array( $snapshot ) ) {
				continue;
			}

			$label = self::clean_text( $snapshot['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}

			$map[ $label ] = self::format_answer_value( $snapshot['value'] ?? ( $snapshot['display_value'] ?? '' ) );
		}

		return $map;
	}

	/**
	 * @param array<int,array<string,mixed>> $snapshots
	 * @return array<string,mixed>
	 */
	public static function snapshots_to_answer_values( array $snapshots ): array {
		$values = array();
		foreach ( $snapshots as $snapshot ) {
			if ( ! is_array( $snapshot ) || empty( $snapshot['id'] ) ) {
				continue;
			}

			$values[ (string) $snapshot['id'] ] = $snapshot['value'] ?? '';
		}

		return $values;
	}

	/**
	 * @return array<string,string>
	 */
	public static function field_type_options(): array {
		return array(
			'text'     => __( 'Short Text', 'oras-tickets' ),
			'textarea' => __( 'Long Text', 'oras-tickets' ),
			'yes_no'   => __( 'Yes / No', 'oras-tickets' ),
			'select'   => __( 'Single Choice', 'oras-tickets' ),
			'checkbox' => __( 'Multiple Choice', 'oras-tickets' ),
			'number'   => __( 'Number', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function applies_to_options(): array {
		return array(
			self::APPLIES_BOTH    => __( 'Tickets and RSVP', 'oras-tickets' ),
			self::APPLIES_TICKETS => __( 'Tickets only', 'oras-tickets' ),
			self::APPLIES_RSVP    => __( 'RSVP only', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function attendance_scope_options(): array {
		return array(
			self::ATTENDANCE_ALL     => __( 'All attendees', 'oras-tickets' ),
			self::ATTENDANCE_ONSITE  => __( 'On-site only', 'oras-tickets' ),
			self::ATTENDANCE_VIRTUAL => __( 'Virtual only', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function attention_operator_options(): array {
		return array(
			self::ATTENTION_EQUALS       => __( 'Answer equals', 'oras-tickets' ),
			self::ATTENTION_CONTAINS     => __( 'Answer contains', 'oras-tickets' ),
			self::ATTENTION_IS_BLANK     => __( 'Answer is blank', 'oras-tickets' ),
			self::ATTENTION_IS_NOT_BLANK => __( 'Answer is not blank', 'oras-tickets' ),
			self::ATTENTION_GREATER_THAN => __( 'Number is greater than', 'oras-tickets' ),
			self::ATTENTION_LESS_THAN    => __( 'Number is less than', 'oras-tickets' ),
			self::ATTENTION_ALWAYS       => __( 'Always flag when answered', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function attention_severity_options(): array {
		return array(
			self::SEVERITY_REVIEW => __( 'Needs Review', 'oras-tickets' ),
			self::SEVERITY_URGENT => __( 'Urgent', 'oras-tickets' ),
		);
	}

	/**
	 * @param array<string,mixed> $question
	 * @param mixed $answer
	 * @return array<int,array<string,string>>
	 */
	public static function match_attention_rules( array $question, $answer ): array {
		$rules = self::normalize_attention_rules( $question['attention_rules'] ?? array() );
		if ( empty( $rules ) ) {
			return array();
		}

		$matches = array();
		foreach ( $rules as $rule ) {
			if ( self::attention_rule_matches( $rule, $answer ) ) {
				$matches[] = $rule;
			}
		}

		return $matches;
	}

	/**
	 * @param array<int,array<string,mixed>> $questions
	 * @param array<string,mixed> $answers
	 */
	public static function render_fields( array $questions, array $answers = array(), string $field_name = 'oras_event_question_answers' ): void {
		$questions = self::normalize_definitions( $questions );
		if ( empty( $questions ) ) {
			return;
		}

		echo '<div class="oras-event-questions">';
		foreach ( $questions as $question ) {
			$id = (string) $question['id'];
			$type = (string) $question['type'];
			$value = $answers[ $id ] ?? '';
			$input_name = $field_name . '[' . $id . ']';
			$input_id = 'oras-event-question-' . esc_attr( $id );
			$required = ! empty( $question['required'] );

			echo '<div class="oras-event-question-field oras-event-question-field--' . esc_attr( $type ) . '">';
			echo '<label class="oras-event-question-field__label" for="' . esc_attr( $input_id ) . '">' . esc_html( (string) $question['label'] );
			if ( $required ) {
				echo ' <span class="required">*</span>';
			}
			echo '</label>';

			self::render_field_control( $question, $input_name, $input_id, $value, $required );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $question
	 * @param mixed $value
	 */
	private static function render_field_control( array $question, string $input_name, string $input_id, $value, bool $required ): void {
		$type = (string) $question['type'];
		$required_attr = $required ? ' required' : '';
		$string_value = is_scalar( $value ) ? (string) $value : '';

		if ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" rows="4"' . $required_attr . '>' . esc_textarea( $string_value ) . '</textarea>';
			return;
		}

		if ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '"' . $required_attr . '>';
			echo '<option value="">' . esc_html__( 'Select an option', 'oras-tickets' ) . '</option>';
			foreach ( (array) $question['options'] as $option ) {
				echo '<option value="' . esc_attr( (string) $option ) . '" ' . selected( $string_value, (string) $option, false ) . '>' . esc_html( (string) $option ) . '</option>';
			}
			echo '</select>';
			return;
		}

		if ( 'radio' === $type || 'yes_no' === $type ) {
			$options = 'yes_no' === $type ? array( __( 'Yes', 'oras-tickets' ), __( 'No', 'oras-tickets' ) ) : (array) $question['options'];
			echo '<div class="oras-event-question-field__choices">';
			foreach ( $options as $option ) {
				$option = (string) $option;
				$choice_id = $input_id . '-' . sanitize_key( $option );
				echo '<label for="' . esc_attr( $choice_id ) . '"><input id="' . esc_attr( $choice_id ) . '" type="radio" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $option ) . '" ' . checked( $string_value, $option, false ) . $required_attr . ' /> ' . esc_html( $option ) . '</label>';
			}
			echo '</div>';
			return;
		}

		if ( 'checkbox' === $type ) {
			$values = is_array( $value ) ? array_map( 'strval', $value ) : array();
			echo '<div class="oras-event-question-field__choices">';
			foreach ( (array) $question['options'] as $option ) {
				$option = (string) $option;
				$choice_id = $input_id . '-' . sanitize_key( $option );
				echo '<label for="' . esc_attr( $choice_id ) . '"><input id="' . esc_attr( $choice_id ) . '" type="checkbox" name="' . esc_attr( $input_name ) . '[]" value="' . esc_attr( $option ) . '" ' . checked( in_array( $option, $values, true ), true, false ) . ' /> ' . esc_html( $option ) . '</label>';
			}
			echo '</div>';
			return;
		}

		$html_type = in_array( $type, array( 'number', 'email' ), true ) ? $type : 'text';
		if ( 'phone' === $type ) {
			$html_type = 'tel';
		}

		echo '<input id="' . esc_attr( $input_id ) . '" type="' . esc_attr( $html_type ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $string_value ) . '"' . $required_attr . ' />';
	}

	/**
	 * @param array<string,mixed> $question
	 * @param mixed $value
	 * @return string|array<int,string>
	 */
	private static function sanitize_answer_value( array $question, $value ) {
		$type = self::normalize_type( $question['type'] ?? 'text' );
		$options = self::normalize_options( $question['options'] ?? array() );

		if ( 'checkbox' === $type ) {
			$values = is_array( $value ) ? $value : array();
			$clean = array();
			foreach ( $values as $item ) {
				$item = self::clean_text( $item );
				if ( '' !== $item && ( empty( $options ) || in_array( $item, $options, true ) ) ) {
					$clean[] = $item;
				}
			}
			return array_values( array_unique( $clean ) );
		}

		$value = is_scalar( $value ) ? (string) $value : '';
		if ( 'textarea' === $type ) {
			return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : trim( strip_tags( $value ) );
		}
		if ( 'email' === $type ) {
			return function_exists( 'sanitize_email' ) ? sanitize_email( $value ) : trim( $value );
		}
		if ( 'number' === $type ) {
			return preg_replace( '/[^0-9.\-]/', '', $value );
		}
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$clean = self::clean_text( $value );
			return in_array( $clean, $options, true ) ? $clean : '';
		}
		if ( 'yes_no' === $type ) {
			$clean = self::clean_text( $value );
			return in_array( $clean, array( 'Yes', 'No' ), true ) ? $clean : '';
		}

		return self::clean_text( $value );
	}

	/**
	 * @param mixed $value
	 */
	private static function is_empty_answer( $value ): bool {
		if ( is_array( $value ) ) {
			return empty( $value );
		}

		return '' === trim( (string) $value );
	}

	/**
	 * @param mixed $value
	 */
	private static function format_answer_value( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * @param mixed $value
	 */
	private static function clean_text( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}

	/**
	 * @param mixed $value
	 * @param array<string,bool> $used_ids
	 */
	private static function normalize_id( $value, string $label, int $index, array $used_ids ): string {
		$id = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
		if ( '' === $id ) {
			$id = 'question_' . ( $index + 1 ) . '_' . substr( md5( $label . '|' . $index ), 0, 8 );
		}

		$base = $id;
		$suffix = 2;
		while ( isset( $used_ids[ $id ] ) ) {
			$id = $base . '_' . $suffix;
			++$suffix;
		}

		return $id;
	}

	/**
	 * @param mixed $value
	 */
	private static function normalize_type( $value ): string {
		$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : strtolower( (string) $value );
		return array_key_exists( $value, self::field_type_options() ) ? $value : 'text';
	}

	/**
	 * @param mixed $value
	 */
	private static function normalize_applies_to( $value ): string {
		$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : strtolower( (string) $value );
		return array_key_exists( $value, self::applies_to_options() ) ? $value : self::APPLIES_BOTH;
	}

	/**
	 * @param mixed $value
	 */
	private static function normalize_attendance_scope( $value ): string {
		$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : strtolower( (string) $value );
		return array_key_exists( $value, self::attendance_scope_options() ) ? $value : self::ATTENDANCE_ALL;
	}

	/**
	 * @param mixed $value
	 * @return array<int,array<string,string>>
	 */
	private static function normalize_attention_rules( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rules = array();
		foreach ( $value as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$operator = self::normalize_attention_operator( $row['operator'] ?? self::ATTENTION_EQUALS );
			$match_value = self::clean_text( $row['value'] ?? '' );
			if ( in_array( $operator, array( self::ATTENTION_EQUALS, self::ATTENTION_CONTAINS, self::ATTENTION_GREATER_THAN, self::ATTENTION_LESS_THAN ), true ) && '' === $match_value ) {
				continue;
			}

			$label = self::clean_text( $row['label'] ?? '' );
			if ( '' === $label ) {
				$label = __( 'Needs review', 'oras-tickets' );
			}

			$rules[] = array(
				'id'       => self::normalize_id( $row['id'] ?? '', $label, (int) $index, array() ),
				'operator' => $operator,
				'value'    => $match_value,
				'label'    => $label,
				'severity' => self::normalize_attention_severity( $row['severity'] ?? self::SEVERITY_REVIEW ),
			);
		}

		return $rules;
	}

	/**
	 * @param mixed $value
	 */
	private static function normalize_attention_operator( $value ): string {
		$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : strtolower( (string) $value );
		return array_key_exists( $value, self::attention_operator_options() ) ? $value : self::ATTENTION_EQUALS;
	}

	/**
	 * @param mixed $value
	 */
	private static function normalize_attention_severity( $value ): string {
		$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : strtolower( (string) $value );
		return array_key_exists( $value, self::attention_severity_options() ) ? $value : self::SEVERITY_REVIEW;
	}

	/**
	 * @param array<string,string> $rule
	 * @param mixed $answer
	 */
	private static function attention_rule_matches( array $rule, $answer ): bool {
		$answer_text = self::format_answer_value( $answer );
		$operator = $rule['operator'] ?? self::ATTENTION_EQUALS;
		$match_value = $rule['value'] ?? '';

		if ( self::ATTENTION_IS_BLANK === $operator ) {
			return self::is_empty_answer( $answer );
		}

		if ( self::is_empty_answer( $answer ) ) {
			return false;
		}

		if ( self::ATTENTION_ALWAYS === $operator || self::ATTENTION_IS_NOT_BLANK === $operator ) {
			return true;
		}

		if ( self::ATTENTION_CONTAINS === $operator ) {
			return false !== stripos( $answer_text, $match_value );
		}

		if ( self::ATTENTION_GREATER_THAN === $operator ) {
			return is_numeric( $answer_text ) && is_numeric( $match_value ) && (float) $answer_text > (float) $match_value;
		}

		if ( self::ATTENTION_LESS_THAN === $operator ) {
			return is_numeric( $answer_text ) && is_numeric( $match_value ) && (float) $answer_text < (float) $match_value;
		}

		return 0 === strcasecmp( $answer_text, $match_value );
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function normalize_options( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n|,/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$options = array();
		foreach ( $value as $option ) {
			$option = self::clean_text( $option );
			if ( '' !== $option ) {
				$options[] = $option;
			}
		}

		return array_values( array_unique( $options ) );
	}
}
