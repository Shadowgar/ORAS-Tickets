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
oras_phase1h_assert( 'select' === $definitions[0]['type'], 'Single-choice question type is preserved' );
oras_phase1h_assert( array( 'Dobsonian', 'Refractor' ) === $definitions[0]['options'], 'Choice options are normalized and preserved' );

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

oras_phase1h_assert( 'yes_no' === $yes_no_definitions[0]['type'], 'Yes/no questions preserve controlled question type' );

ob_start();
$class::render_fields( $yes_no_definitions );
$rendered = (string) ob_get_clean();
oras_phase1h_assert( false !== strpos( $rendered, 'type="radio"' ), 'Yes/no questions render radio controls' );

$multi_choice_definitions = $class::normalize_definitions(
	array(
		array(
			'label'   => 'Which workshops interest you?',
			'type'    => 'checkbox',
			'options' => "Solar\nImaging\nSolar",
		),
	)
);
oras_phase1h_assert( 'checkbox' === $multi_choice_definitions[0]['type'], 'Multiple-choice question type is preserved' );
oras_phase1h_assert( array( 'Solar', 'Imaging' ) === $multi_choice_definitions[0]['options'], 'Multiple-choice options are normalized from newline text' );

$choice_validation = $class::build_answer_snapshots(
	$multi_choice_definitions,
	array(
		$multi_choice_definitions[0]['id'] => array( 'Solar', 'Bad Option', 'Imaging' ),
	)
);
oras_phase1h_assert( 'Solar, Imaging' === $choice_validation[0]['display_value'], 'Multiple-choice answers only keep configured options' );

$attention_definitions = $class::normalize_definitions(
	array(
		array(
			'label'           => 'Will you need accommodations?',
			'type'            => 'yes_no',
			'attention_rules' => array(
				array(
					'operator' => 'equals',
					'value'    => 'Yes',
					'label'    => 'Accommodation request',
					'severity' => 'urgent',
				),
			),
		),
		array(
			'label'           => 'How many guests?',
			'type'            => 'number',
			'attention_rules' => array(
				array(
					'operator' => 'greater_than',
					'value'    => '4',
					'label'    => 'Large group',
					'severity' => 'review',
				),
			),
		),
	)
);
oras_phase1h_assert( isset( $attention_definitions[0]['attention_rules'] ) && 1 === count( $attention_definitions[0]['attention_rules'] ), 'Attention rules are normalized with question definitions' );
oras_phase1h_assert( 'Accommodation request' === $attention_definitions[0]['attention_rules'][0]['label'], 'Attention rule label is preserved' );
oras_phase1h_assert( 'urgent' === $attention_definitions[0]['attention_rules'][0]['severity'], 'Attention rule severity is preserved' );

oras_phase1h_assert( is_callable( array( $class, 'match_attention_rules' ) ), 'Attention rule matcher exists' );
$attention_matches = $class::match_attention_rules( $attention_definitions[0], 'Yes' );
oras_phase1h_assert( 1 === count( $attention_matches ), 'Equals attention rule matches controlled yes answer' );
oras_phase1h_assert( 'Accommodation request' === $attention_matches[0]['label'], 'Matched attention rule keeps board-facing label' );
oras_phase1h_assert( 0 === count( $class::match_attention_rules( $attention_definitions[0], 'No' ) ), 'Equals attention rule ignores nonmatching answer' );
oras_phase1h_assert( 1 === count( $class::match_attention_rules( $attention_definitions[1], '5' ) ), 'Greater-than attention rule matches numeric answers' );

$frontend_css_file = dirname( __DIR__ ) . '/assets/css/tickets-frontend.css';
$frontend_css      = file_exists( $frontend_css_file ) ? (string) file_get_contents( $frontend_css_file ) : '';
oras_phase1h_assert( false !== strpos( $frontend_css, '--oras-rsvp-label-text: #111827;' ), 'RSVP labels use readable light-mode text color' );
oras_phase1h_assert( false !== strpos( $frontend_css, '--oras-rsvp-description-text: #4b5563;' ), 'RSVP descriptions use readable light-mode text color' );
oras_phase1h_assert( false !== strpos( $frontend_css, '.oras-rsvp-event-questions legend' ), 'Event Questions fieldset legend inherits RSVP color handling' );
oras_phase1h_assert( false === strpos( $frontend_css, 'wp-dark-mode-loading' ), 'WP Dark Mode loading state does not force dark RSVP colors' );
oras_phase1h_assert( false !== strpos( $frontend_css, '--oras-rsvp-modal-bg: #0f172a;' ), 'RSVP email modal has dark-mode dialog color variables' );
oras_phase1h_assert( false !== strpos( $frontend_css, 'color: var(--oras-rsvp-modal-label);' ), 'RSVP email modal labels use theme-aware colors' );
oras_phase1h_assert( false !== strpos( $frontend_css, '.oras-rsvp-question-wizard' ), 'RSVP event questions have wizard styling' );
oras_phase1h_assert( false !== strpos( $frontend_css, '@keyframes oras-rsvp-question-slide-in' ), 'RSVP question wizard has slide movement styling' );

$board_reports_file = dirname( __DIR__ ) . '/includes/Frontend/Board_Reports.php';
$board_reports      = file_exists( $board_reports_file ) ? (string) file_get_contents( $board_reports_file ) : '';
oras_phase1h_assert( false !== strpos( $board_reports, '<details class="oras-board-reports__question-answers">' ), 'Board report question answers render collapsed by default' );
oras_phase1h_assert( false !== strpos( $board_reports, 'oras-board-reports__question-answers-summary' ), 'Board report question answers include an expandable summary' );
oras_phase1h_assert( false !== strpos( $board_reports, 'TAB_OVERVIEW' ), 'Board reports include event overview tab' );
oras_phase1h_assert( false !== strpos( $board_reports, "'Event Overview'" ), 'Board reports expose Event Overview label' );
oras_phase1h_assert( false !== strpos( $board_reports, "'Sales'" ), 'Board reports expose Sales label' );
oras_phase1h_assert( false !== strpos( $board_reports, "'RSVP Management'" ), 'Board reports expose RSVP Management label' );
oras_phase1h_assert( false !== strpos( $board_reports, "'Roster'" ), 'Board reports expose Roster label' );
oras_phase1h_assert( false !== strpos( $board_reports, '$filters[\'type\'] = Board_Report_Service::TYPE_TICKETS;' ), 'Ticket Sales tab is locked to ticket rows' );
oras_phase1h_assert( false === strpos( $board_reports, '<select name="oras_board_report_type">' ), 'Ticket Sales tab no longer exposes generic report type dropdown' );
oras_phase1h_assert( false !== strpos( $board_reports, 'Content-Type: text/html; charset=UTF-8' ), 'Board communications emails use HTML content type' );
oras_phase1h_assert( false !== strpos( $board_reports, 'build_communication_email_body' ), 'Board communications emails use a styled ORAS email body' );
oras_phase1h_assert( false !== strpos( $board_reports, 'oras-board-reports__rsvp-list' ), 'RSVP Management renders a card list instead of a wide action table' );
oras_phase1h_assert( false !== strpos( $board_reports, 'oras-board-reports__rsvp-card-actions' ), 'RSVP Management keeps approval actions visible in each card' );
oras_phase1h_assert( false !== strpos( $board_reports, 'render_rsvp_card' ), 'RSVP Management rows are rendered through dedicated card markup' );
oras_phase1h_assert( false !== strpos( $board_reports, 'render_rsvp_summary_bar' ), 'RSVP Management includes a compact count summary for large groups' );
oras_phase1h_assert( false !== strpos( $board_reports, 'render_sales_card' ), 'Sales tab rows render as board report cards' );
oras_phase1h_assert( false !== strpos( $board_reports, 'render_roster_card' ), 'Roster tab rows render as board report cards' );
oras_phase1h_assert( false !== strpos( $board_reports, 'render_waitlist_card' ), 'Waitlist rows render as board report cards' );
oras_phase1h_assert( false !== strpos( $board_reports, 'render_attention_card' ), 'Attention items render as board report cards' );
oras_phase1h_assert( false !== strpos( $board_reports, 'render_communication_history_card' ), 'Communication history rows render as board report cards' );
oras_phase1h_assert( false !== strpos( $board_reports, 'oras-board-reports__report-list' ), 'Board Reports uses reusable card lists outside RSVP Management' );

$agenda_css_file    = dirname( __DIR__ ) . '/assets/css/agenda.css';
$agenda_css         = file_exists( $agenda_css_file ) ? (string) file_get_contents( $agenda_css_file ) : '';
$agenda_colors_file = dirname( __DIR__ ) . '/assets/css/oras-agenda-colors.css';
$agenda_colors      = file_exists( $agenda_colors_file ) ? (string) file_get_contents( $agenda_colors_file ) : '';
$agenda_render_file = dirname( __DIR__ ) . '/includes/Frontend/Event_Agenda_Render.php';
$agenda_render      = file_exists( $agenda_render_file ) ? (string) file_get_contents( $agenda_render_file ) : '';
oras_phase1h_assert( false !== strpos( $agenda_css, '--oras-agenda-surface:' ), 'Agenda frontend defines a readable surface color' );
oras_phase1h_assert( false !== strpos( $agenda_css, 'clear: both;' ), 'Agenda frontend clears preceding floated event content' );
oras_phase1h_assert( false !== strpos( $agenda_css, 'display: flow-root;' ), 'Agenda frontend creates an independent layout context' );
oras_phase1h_assert( false !== strpos( $agenda_css, '.oras-agenda__timecell' ), 'Agenda frontend renders a dedicated time column' );
oras_phase1h_assert( false !== strpos( $agenda_css, 'box-shadow: 0 18px 48px' ), 'Agenda frontend uses card depth for readability' );
oras_phase1h_assert( false !== strpos( $agenda_css, '.oras-agenda__timeline::before' ) && false !== strpos( $agenda_css, "display: none;\n}" ), 'Agenda frontend suppresses decorative timeline rail' );
oras_phase1h_assert( false !== strpos( $agenda_colors, '--oras-agenda-surface:' ), 'Agenda dark mode defines a readable surface color' );
oras_phase1h_assert( false !== strpos( $agenda_render, 'oras-agenda__timecell' ), 'Agenda renderer outputs time column markup' );

$rsvp_frontend_file = dirname( __DIR__ ) . '/includes/Frontend/Event_RSVP.php';
$rsvp_frontend      = file_exists( $rsvp_frontend_file ) ? (string) file_get_contents( $rsvp_frontend_file ) : '';
$rsvp_js_file       = dirname( __DIR__ ) . '/assets/js/oras-rsvp-frontend.js';
$rsvp_js            = file_exists( $rsvp_js_file ) ? (string) file_get_contents( $rsvp_js_file ) : '';
oras_phase1h_assert( false !== strpos( $rsvp_frontend, "'Submit RSVP'" ), 'Frontend RSVP submit button uses updated label' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, "'Remove RSVP'" ), 'Frontend RSVP removal button uses updated label' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'oras-rsvp-guidance' ), 'Frontend RSVP form renders a clear start-here guidance panel' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, '$show_remove_action = \'yes\' === $status;' ), 'Frontend Remove RSVP controls are only rendered for confirmed RSVP state' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'value="no" class="oras-rsvp-button oras-rsvp-button-secondary" formnovalidate' ), 'Remove RSVP bypasses required event-question browser validation' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'value="leave_waitlist" class="oras-rsvp-button oras-rsvp-button-secondary" formnovalidate' ), 'Leave Waitlist bypasses required event-question browser validation' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'build_rsvp_cancellation_email' ), 'RSVP cancellation email uses the shared ORAS email template' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'render_cancellation_result_notice' ), 'Signed cancellation redirects render visible success or failure feedback' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'initQuestionWizard' ), 'RSVP frontend initializes one-question-at-a-time wizard' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'oras-rsvp-question-next' ), 'RSVP question wizard renders Next control' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'setPrimaryActionsVisible' ), 'RSVP question wizard hides submit actions until final question' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'validateCurrentQuestion' ), 'RSVP question wizard validates each question before moving next' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'oras-rsvp-question-final-prompt' ), 'RSVP question wizard shows a clear final-step completion prompt' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'Answer the questions to Submit' ), 'RSVP question wizard labels incomplete final submit state' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'You are done.' ), 'RSVP question wizard clearly announces completion when ready to submit' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'setFinalSubmitState' ), 'RSVP question wizard updates final submit state as answers change' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'getContactEmailForSubmission' ), 'RSVP frontend reuses entered contact email before asking again' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'Saving your RSVP and sending your confirmation email' ), 'RSVP frontend gives immediate saving feedback during AJAX submission' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'Are you sure you want to remove your RSVP?' ), 'RSVP removal asks for an explicit browser confirmation' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'Removing your RSVP and sending your cancellation email' ), 'RSVP removal gives immediate removal-specific progress feedback' );
oras_phase1h_assert( false !== strpos( $rsvp_js, 'scrollIntoView' ), 'RSVP frontend returns users to the inline result after submission' );
oras_phase1h_assert( false === strpos( $rsvp_js, 'window.location.reload();' ), 'RSVP frontend does not force a page reload after AJAX success' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'Content-Type: text/html; charset=UTF-8' ), 'RSVP attendee emails use HTML content type' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'build_oras_email_template' ), 'RSVP attendee emails use shared ORAS email template' );
oras_phase1h_assert( false !== strpos( $rsvp_frontend, 'html_entity_decode' ), 'RSVP email subjects decode numeric title entities for email headers' );

$bootstrap_file = dirname( __DIR__ ) . '/includes/Bootstrap.php';
$bootstrap      = file_exists( $bootstrap_file ) ? (string) file_get_contents( $bootstrap_file ) : '';
$virtual_ticket_email_file = dirname( __DIR__ ) . '/includes/Commerce/Woo/Virtual_Ticket_Access_Email.php';
$virtual_ticket_email      = file_exists( $virtual_ticket_email_file ) ? (string) file_get_contents( $virtual_ticket_email_file ) : '';
oras_phase1h_assert( false !== strpos( $bootstrap, 'Virtual_Ticket_Access_Email' ), 'Bootstrap registers virtual ticket access email service' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, 'woocommerce_order_status_processing' ), 'Virtual ticket access email runs on processing orders' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, 'woocommerce_order_status_completed' ), 'Virtual ticket access email runs on completed orders' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, '_oras_virtual_access_email_sent_' ), 'Virtual ticket access email prevents duplicate sends per event' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, '_oras_ticket_attendance_mode' ), 'Virtual ticket access email reads ticket attendance mode' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, 'Ticket::ATTENDANCE_MODE_VIRTUAL' ), 'Virtual ticket access email only targets virtual ticket rows' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, 'Event_RSVP::get_virtual_join_link' ), 'Virtual ticket access email reuses ORAS virtual join link resolver' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, 'virtual_ticket_access' ), 'Virtual ticket access email logs its action type' );
oras_phase1h_assert( false !== strpos( $virtual_ticket_email, 'html_entity_decode' ), 'Virtual ticket access email subjects decode numeric title entities for email headers' );

echo "Event question checks passed.\n";
