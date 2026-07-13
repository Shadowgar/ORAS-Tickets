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

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $display = true ) {
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $display = true ) {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
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
oras_phase1h_assert( 'text' === $definitions[0]['type'], 'Question answer type is forced to short text' );
oras_phase1h_assert( array() === $definitions[0]['options'], 'Question options are discarded because answers are short text only' );

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

$yes_no_definitions = $class::normalize_definitions(
	array(
		array(
			'label' => 'Do you have a telescope?',
			'type'  => 'yes_no',
		),
	)
);

oras_phase1h_assert( 'text' === $yes_no_definitions[0]['type'], 'Legacy yes/no questions normalize to short text' );

ob_start();
$class::render_fields( $yes_no_definitions );
$rendered = (string) ob_get_clean();
oras_phase1h_assert( false !== strpos( $rendered, 'type="text"' ), 'Event questions render short text inputs' );
oras_phase1h_assert( false === strpos( $rendered, 'type="radio"' ), 'Event questions do not render radio controls' );

$frontend_css_file = dirname( __DIR__ ) . '/assets/css/tickets-frontend.css';
$frontend_css      = file_exists( $frontend_css_file ) ? (string) file_get_contents( $frontend_css_file ) : '';
oras_phase1h_assert( false !== strpos( $frontend_css, '--oras-rsvp-label-text: #111827;' ), 'RSVP labels use readable light-mode text color' );
oras_phase1h_assert( false !== strpos( $frontend_css, '--oras-rsvp-description-text: #4b5563;' ), 'RSVP descriptions use readable light-mode text color' );
oras_phase1h_assert( false !== strpos( $frontend_css, '.oras-rsvp-event-questions legend' ), 'Event Questions fieldset legend inherits RSVP color handling' );
oras_phase1h_assert( false === strpos( $frontend_css, 'wp-dark-mode-loading' ), 'WP Dark Mode loading state does not force dark RSVP colors' );
oras_phase1h_assert( false !== strpos( $frontend_css, '--oras-rsvp-modal-bg: #0f172a;' ), 'RSVP email modal has dark-mode dialog color variables' );
oras_phase1h_assert( false !== strpos( $frontend_css, 'color: var(--oras-rsvp-modal-label);' ), 'RSVP email modal labels use theme-aware colors' );

$board_reports_file = dirname( __DIR__ ) . '/includes/Frontend/Board_Reports.php';
$board_reports      = file_exists( $board_reports_file ) ? (string) file_get_contents( $board_reports_file ) : '';
oras_phase1h_assert( false !== strpos( $board_reports, '<details class="oras-board-reports__question-answers">' ), 'Board report question answers render collapsed by default' );
oras_phase1h_assert( false !== strpos( $board_reports, 'oras-board-reports__question-answers-summary' ), 'Board report question answers include an expandable summary' );

$rsvp_frontend_file = dirname( __DIR__ ) . '/includes/Frontend/Event_RSVP.php';
$rsvp_frontend      = file_exists( $rsvp_frontend_file ) ? (string) file_get_contents( $rsvp_frontend_file ) : '';
oras_phase1h_assert( false !== strpos( $rsvp_frontend, "'Submit RSVP'" ), 'Frontend RSVP submit button uses updated label' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, "'Remove RSVP'" ), 'Frontend RSVP removal button uses updated label' );

echo "Event question checks passed.\n";
