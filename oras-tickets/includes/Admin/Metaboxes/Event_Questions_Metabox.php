<?php

namespace ORAS\Tickets\Admin\Metaboxes;

use ORAS\Tickets\Event_Questions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Event_Questions_Metabox {
	private const NONCE_ACTION = 'oras_event_questions_metabox';
	private const NONCE_NAME = 'oras_event_questions_metabox_nonce';

	public static function register(): void {
		add_action( 'save_post_tribe_events', array( self::class, 'save' ), 10, 1 );
	}

	public static function render( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$questions = Event_Questions::load_definitions( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div id="oras-event-questions-metabox" class="oras-event-questions-admin">
			<div class="oras-event-questions-admin__intro">
				<h3><?php echo esc_html__( 'Event Questions', 'oras-tickets' ); ?></h3>
				<p class="description"><?php echo esc_html__( 'Create event-specific questions for ticket buyers and RSVP attendees. Saved answers keep the original question label for historical reporting even if you edit questions later.', 'oras-tickets' ); ?></p>
			</div>

			<div class="oras-event-questions-admin__rows" data-oras-question-rows>
				<?php
				if ( empty( $questions ) ) {
					self::render_question_row( array(), 0 );
				} else {
					foreach ( $questions as $index => $question ) {
						self::render_question_row( $question, (int) $index );
					}
				}
				?>
			</div>

			<p>
				<button type="button" class="button" data-oras-add-question><?php echo esc_html__( 'Add Question', 'oras-tickets' ); ?></button>
			</p>

			<template data-oras-question-template>
				<?php self::render_question_row( array(), '__INDEX__' ); ?>
			</template>
		</div>

		<script>
			(function () {
				var root = document.getElementById('oras-event-questions-metabox');
				if (!root || root.dataset.ready === '1') {
					return;
				}
				root.dataset.ready = '1';

				var rows = root.querySelector('[data-oras-question-rows]');
				var template = root.querySelector('template[data-oras-question-template]');
				var addButton = root.querySelector('[data-oras-add-question]');

				function nextIndex() {
					return rows ? rows.querySelectorAll('.oras-event-question-admin-row').length : 0;
				}

				if (addButton && rows && template) {
					addButton.addEventListener('click', function () {
						var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex()));
						var wrapper = document.createElement('div');
						wrapper.innerHTML = html;
						rows.appendChild(wrapper.firstElementChild);
					});
				}

				root.addEventListener('click', function (event) {
					var remove = event.target.closest('[data-oras-remove-question]');
					if (remove && rows) {
						var row = remove.closest('.oras-event-question-admin-row');
						if (row) {
							row.remove();
						}
						return;
					}

					var addRule = event.target.closest('[data-oras-add-attention-rule]');
					if (addRule) {
						var questionRow = addRule.closest('.oras-event-question-admin-row');
						var ruleRows = questionRow ? questionRow.querySelector('[data-oras-attention-rule-rows]') : null;
						var ruleTemplate = questionRow ? questionRow.querySelector('template[data-oras-attention-rule-template]') : null;
						if (!ruleRows || !ruleTemplate) {
							return;
						}

						var ruleIndex = ruleRows.querySelectorAll('.oras-event-question-attention-rule').length;
						var html = ruleTemplate.innerHTML.replace(/__RULE_INDEX__/g, String(ruleIndex));
						var wrapper = document.createElement('div');
						wrapper.innerHTML = html;
						ruleRows.appendChild(wrapper.firstElementChild);
						return;
					}

					var removeRule = event.target.closest('[data-oras-remove-attention-rule]');
					if (removeRule) {
						var rule = removeRule.closest('.oras-event-question-attention-rule');
						if (rule) {
							rule.remove();
						}
					}
				});
			})();
		</script>
		<?php
	}

	/**
	 * @param array<string,mixed> $question
	 * @param int|string $index
	 */
	private static function render_question_row( array $question, $index ): void {
		$id = isset( $question['id'] ) ? (string) $question['id'] : '';
		$label = isset( $question['label'] ) ? (string) $question['label'] : '';
		$type = isset( $question['type'] ) ? (string) $question['type'] : 'text';
		$required = ! empty( $question['required'] );
		$applies_to = isset( $question['applies_to'] ) ? (string) $question['applies_to'] : Event_Questions::APPLIES_BOTH;
		$attendance_scope = isset( $question['attendance_scope'] ) ? (string) $question['attendance_scope'] : Event_Questions::ATTENDANCE_ALL;
		$options = isset( $question['options'] ) && is_array( $question['options'] ) ? implode( "\n", array_map( 'strval', $question['options'] ) ) : '';
		$attention_rules = isset( $question['attention_rules'] ) && is_array( $question['attention_rules'] ) ? $question['attention_rules'] : array();
		$name = 'oras_event_questions[questions][' . $index . ']';
		?>
		<div class="oras-event-question-admin-row">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
			<div class="oras-event-question-admin-row__header">
				<strong><?php echo esc_html__( 'Question', 'oras-tickets' ); ?></strong>
				<button type="button" class="button-link-delete" data-oras-remove-question><?php echo esc_html__( 'Remove', 'oras-tickets' ); ?></button>
			</div>
			<div class="oras-event-question-admin-row__grid">
				<label>
					<span><?php echo esc_html__( 'Question Label', 'oras-tickets' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr__( 'Example: Are you bringing a telescope?', 'oras-tickets' ); ?>" />
				</label>
				<label>
					<span><?php echo esc_html__( 'Answer Type', 'oras-tickets' ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>[type]">
						<?php foreach ( Event_Questions::field_type_options() as $value => $text ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php echo esc_html__( 'Ask During', 'oras-tickets' ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>[applies_to]">
						<?php foreach ( Event_Questions::applies_to_options() as $value => $text ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $applies_to, $value ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php echo esc_html__( 'Attendance Scope', 'oras-tickets' ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>[attendance_scope]">
						<?php foreach ( Event_Questions::attendance_scope_options() as $value => $text ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $attendance_scope, $value ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="oras-event-question-admin-row__required">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( $required ); ?> />
					<?php echo esc_html__( 'Required', 'oras-tickets' ); ?>
				</label>
				<label class="oras-event-question-admin-row__options">
					<span><?php echo esc_html__( 'Choice Options', 'oras-tickets' ); ?></span>
					<textarea name="<?php echo esc_attr( $name ); ?>[options]" rows="4" placeholder="<?php echo esc_attr__( "One option per line. Used for Single Choice and Multiple Choice questions.", 'oras-tickets' ); ?>"><?php echo esc_textarea( $options ); ?></textarea>
				</label>
			</div>
			<div class="oras-event-question-admin-row__attention">
				<h4><?php echo esc_html__( 'Attention Rules', 'oras-tickets' ); ?></h4>
				<p class="description"><?php echo esc_html__( 'Create dashboard review items when an answer needs coordinator attention.', 'oras-tickets' ); ?></p>
				<div data-oras-attention-rule-rows>
					<?php foreach ( $attention_rules as $rule_index => $rule ) : ?>
						<?php self::render_attention_rule_row( is_array( $rule ) ? $rule : array(), $name, (int) $rule_index ); ?>
					<?php endforeach; ?>
				</div>
				<p>
					<button type="button" class="button" data-oras-add-attention-rule><?php echo esc_html__( 'Add Attention Rule', 'oras-tickets' ); ?></button>
				</p>
				<template data-oras-attention-rule-template>
					<?php self::render_attention_rule_row( array(), $name, '__RULE_INDEX__' ); ?>
				</template>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param int|string $rule_index
	 */
	private static function render_attention_rule_row( array $rule, string $question_name, $rule_index ): void {
		$name = $question_name . '[attention_rules][' . $rule_index . ']';
		$id = isset( $rule['id'] ) ? (string) $rule['id'] : '';
		$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : Event_Questions::ATTENTION_EQUALS;
		$value = isset( $rule['value'] ) ? (string) $rule['value'] : '';
		$label = isset( $rule['label'] ) ? (string) $rule['label'] : '';
		$severity = isset( $rule['severity'] ) ? (string) $rule['severity'] : Event_Questions::SEVERITY_REVIEW;
		?>
		<div class="oras-event-question-attention-rule">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
			<label>
				<span><?php echo esc_html__( 'When', 'oras-tickets' ); ?></span>
				<select name="<?php echo esc_attr( $name ); ?>[operator]">
					<?php foreach ( Event_Questions::attention_operator_options() as $option_value => $text ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $operator, $option_value ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php echo esc_html__( 'Match Value', 'oras-tickets' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr__( 'Example: Yes', 'oras-tickets' ); ?>" />
			</label>
			<label>
				<span><?php echo esc_html__( 'Flag Label', 'oras-tickets' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr__( 'Example: Accommodation request', 'oras-tickets' ); ?>" />
			</label>
			<label>
				<span><?php echo esc_html__( 'Severity', 'oras-tickets' ); ?></span>
				<select name="<?php echo esc_attr( $name ); ?>[severity]">
					<?php foreach ( Event_Questions::attention_severity_options() as $option_value => $text ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $severity, $option_value ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="button" class="button-link-delete" data-oras-remove-attention-rule><?php echo esc_html__( 'Remove Rule', 'oras-tickets' ); ?></button>
		</div>
		<?php
	}

	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = isset( $_POST['oras_event_questions'] ) && is_array( $_POST['oras_event_questions'] )
			? wp_unslash( $_POST['oras_event_questions'] )
			: array();
		$rows = isset( $input['questions'] ) && is_array( $input['questions'] ) ? $input['questions'] : array();
		$questions = Event_Questions::normalize_definitions( $rows );

		if ( empty( $questions ) ) {
			delete_post_meta( $post_id, Event_Questions::META_KEY );
			return;
		}

		update_post_meta( $post_id, Event_Questions::META_KEY, $questions );
	}
}
