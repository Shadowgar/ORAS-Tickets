<?php
/**
 * Targeted checks for event-question attention workflow.
 *
 * Run:
 *   php oras-tickets/tools/event-question-attention-checks.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $value ) {
		return trim( filter_var( (string) $value, FILTER_SANITIZE_EMAIL ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		unset( $type, $gmt );
		return '2026-07-13 12:00:00';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

require_once dirname( __DIR__ ) . '/includes/Event_Questions.php';

function oras_attention_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	echo "PASS: {$message}\n";
}

oras_attention_assert( file_exists( dirname( __DIR__ ) . '/includes/Event_Question_Attention_Store.php' ), 'Attention store file exists' );
require_once dirname( __DIR__ ) . '/includes/Event_Question_Attention_Store.php';

oras_attention_assert( class_exists( '\ORAS\Tickets\Event_Question_Attention_Store' ), 'Attention store class exists' );

$questions = \ORAS\Tickets\Event_Questions::normalize_definitions(
	array(
		array(
			'label'           => 'Will you need accommodations?',
			'type'            => 'yes_no',
			'attention_rules' => array(
				array(
					'id'       => 'needs-accommodation',
					'operator' => 'equals',
					'value'    => 'Yes',
					'label'    => 'Accommodation request',
					'severity' => 'urgent',
				),
			),
		),
	)
);

$snapshots = \ORAS\Tickets\Event_Questions::build_answer_snapshots(
	$questions,
	array(
		$questions[0]['id'] => 'Yes',
	)
);

$items = \ORAS\Tickets\Event_Question_Attention_Store::build_items_for_answer_snapshots(
	5870,
	'rsvp',
	'user:123',
	array(
		'user_id'       => 123,
		'attendee_name' => 'Paul Rocco',
		'email'         => 'rocco.paul@gmail.com',
	),
	$questions,
	$snapshots
);

oras_attention_assert( 1 === count( $items ), 'Matching answer snapshot creates one attention item payload' );
oras_attention_assert( 5870 === $items[0]['event_id'], 'Attention item includes event ID' );
oras_attention_assert( 'rsvp' === $items[0]['source_type'], 'Attention item includes source type' );
oras_attention_assert( 'user:123' === $items[0]['source_id'], 'Attention item includes source ID' );
oras_attention_assert( 123 === $items[0]['user_id'], 'Attention item includes user context' );
oras_attention_assert( 'Accommodation request' === $items[0]['rule_label'], 'Attention item includes rule label' );
oras_attention_assert( 'urgent' === $items[0]['severity'], 'Attention item includes severity' );
oras_attention_assert( 'open' === $items[0]['status'], 'Attention item defaults to open status' );

$nonmatching_snapshots = \ORAS\Tickets\Event_Questions::build_answer_snapshots(
	$questions,
	array(
		$questions[0]['id'] => 'No',
	)
);
$nonmatching_items = \ORAS\Tickets\Event_Question_Attention_Store::build_items_for_answer_snapshots( 5870, 'rsvp', 'user:123', array(), $questions, $nonmatching_snapshots );
oras_attention_assert( array() === $nonmatching_items, 'Nonmatching answer snapshot creates no attention item payload' );

echo "Event question attention checks passed.\n";
