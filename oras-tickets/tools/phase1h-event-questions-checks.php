<?php
/**
 * Targeted checks for event-specific questions.
 *
 * Run:
 *   php oras-tickets/tools/phase1h-event-questions-checks.php
 */

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

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $value ) {
		return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = array();

		public function __construct( string $code = '', string $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
			}
		}

		public function add( string $code, string $message ): void {
			$this->errors[ $code ][] = $message;
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}

		public function get_error_message(): string {
			foreach ( $this->errors as $messages ) {
				return (string) reset( $messages );
			}

			return '';
		}
	}
}

$service_file = dirname( __DIR__ ) . '/includes/Event_Questions.php';
if ( file_exists( $service_file ) ) {
	require_once $service_file;
}

function oras_phase1h_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	echo "PASS: {$message}\n";
}

oras_phase1h_assert( class_exists( '\ORAS\Tickets\Event_Questions' ), 'Event_Questions service exists' );

$class = '\ORAS\Tickets\Event_Questions';

$definitions = $class::normalize_definitions(
	array(
		array(
			'id'               => 'telescope-type',
			'label'            => ' What telescope are you bringing? ',
			'type'             => 'select',
			'required'         => '1',
			'applies_to'       => 'both',
			'attendance_scope' => 'all',
			'options'          => array( 'Dobsonian', '', 'Refractor' ),
		),
		array(
			'label'      => '',
			'type'       => 'text',
			'applies_to' => 'tickets',
		),
	)
);

oras_phase1h_assert( 1 === count( $definitions ), 'Empty labels are discarded during definition normalization' );
oras_phase1h_assert( 'telescope-type' === $definitions[0]['id'], 'Question IDs are preserved when safe' );
oras_phase1h_assert( true === $definitions[0]['required'], 'Required flag normalizes to boolean true' );
oras_phase1h_assert( array( 'Dobsonian', 'Refractor' ) === $definitions[0]['options'], 'Question options are sanitized and empty values removed' );

$ticket_questions = $class::filter_questions( $definitions, 'tickets', 'onsite' );
oras_phase1h_assert( 1 === count( $ticket_questions ), 'Questions applying to both are shown for ticket buyers' );

$missing = $class::validate_answers( $ticket_questions, array() );
oras_phase1h_assert( $missing instanceof \WP_Error && $missing->has_errors(), 'Missing required answers fail validation' );

$snapshots = $class::build_answer_snapshots(
	$ticket_questions,
	array(
		'telescope-type' => 'Dobsonian',
	)
);

oras_phase1h_assert( 1 === count( $snapshots ), 'Answer snapshot is created for submitted answer' );
oras_phase1h_assert( 'What telescope are you bringing?' === $snapshots[0]['label'], 'Answer snapshot stores original question label' );
oras_phase1h_assert( 'Dobsonian' === $snapshots[0]['value'], 'Answer snapshot stores sanitized answer' );

$definitions[0]['label'] = 'Changed later';
oras_phase1h_assert( 'What telescope are you bringing?' === $snapshots[0]['label'], 'Historical answer snapshot is not changed when definition changes' );

echo "Event question checks passed.\n";
