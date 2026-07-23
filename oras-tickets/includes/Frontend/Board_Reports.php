<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Communication_Queue;
use ORAS\Tickets\Communication_Recipients;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Event_Question_Attention_Store;
use ORAS\Tickets\Reporting\Board_Report_Exporter;
use ORAS\Tickets\Reporting\Board_Report_Service;
use ORAS\Tickets\Waitlist_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Board_Reports {

	private const NONCE_ACTION = 'oras_board_reports_export';
	private const COMMUNICATION_NONCE_ACTION = 'oras_board_reports_communication';
	private const COMMUNICATION_ACTION = 'oras_board_reports_send_communication';
	private const APPROVAL_NONCE_ACTION = 'oras_board_reports_rsvp_approval';
	private const APPROVAL_ACTION = 'oras_board_reports_update_rsvp_approval';
	private const WAITLIST_NONCE_ACTION = 'oras_board_reports_waitlist';
	private const WAITLIST_ACTION = 'oras_board_reports_update_waitlist';
	private const ATTENTION_NONCE_ACTION = 'oras_board_reports_attention';
	private const ATTENTION_ACTION = 'oras_board_reports_update_attention_status';
	private const TAB_OVERVIEW = 'overview';
	private const TAB_TICKET_SALES = 'ticket_sales';
	private const TAB_RSVPS = 'rsvps';
	private const TAB_ATTENTION = 'attention';
	private const TAB_COMMUNICATIONS = 'communications';
	private const TAB_ATTENDEES = 'attendees';
	private const TAB_STATISTICS = 'statistics';

	public static function register(): void {
		add_shortcode( 'oras_board_reports', array( self::class, 'render_shortcode' ) );
		add_action( 'admin_post_oras_board_reports_export_csv', array( self::class, 'handle_export_csv' ) );
		add_action( 'admin_post_oras_board_reports_export_spreadsheet', array( self::class, 'handle_export_spreadsheet' ) );
		add_action( 'admin_post_oras_board_reports_export_pdf', array( self::class, 'handle_export_pdf' ) );
		add_action( 'admin_post_' . self::COMMUNICATION_ACTION, array( self::class, 'handle_send_communication' ) );
		add_action( 'admin_post_' . self::APPROVAL_ACTION, array( self::class, 'handle_update_rsvp_approval' ) );
		add_action( 'admin_post_' . self::WAITLIST_ACTION, array( self::class, 'handle_update_waitlist' ) );
		add_action( 'admin_post_' . self::ATTENTION_ACTION, array( self::class, 'handle_update_attention_status' ) );
	}

	/**
	 * @param array<string,mixed> $atts
	 */
	public static function render_shortcode( array $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( (string) get_permalink() );
			return '<p>' . esc_html__( 'Please sign in to view board reports.', 'oras-tickets' ) . ' <a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Sign in', 'oras-tickets' ) . '</a></p>';
		}

		if ( ! current_user_can( 'oras_tickets_view_board_dashboard' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return '<p>' . esc_html__( 'You do not have permission to view board reports.', 'oras-tickets' ) . '</p>';
		}

		$active_tab = self::get_active_tab();
		$page_id = self::get_context_page_id();
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		ob_start();
		?>
		<div class="oras-board-reports">
			<style>
				.oras-board-reports {
					box-sizing: border-box;
					max-width: min(1600px, calc(100vw - 40px));
					margin: 24px auto;
					color: #111827;
					font-size: 15px;
					line-height: 1.45;
				}
				.oras-board-reports *,
				.oras-board-reports *::before,
				.oras-board-reports *::after {
					box-sizing: border-box;
				}
				.oras-board-reports .oras-board-reports__notice {
					background: rgba(10, 16, 28, 0.75);
					border-left: 4px solid #2d7dbf;
					margin: 0 0 16px;
					padding: 10px 12px;
					color: #e5ecf5;
				}
				.oras-board-reports .oras-board-reports__tabs {
					display: flex;
					flex-wrap: wrap;
					gap: 8px;
					margin: 18px 0 16px;
					border-bottom: 1px solid #dcdcde;
				}
				.oras-board-reports .oras-board-reports__tab {
					display: inline-flex;
					align-items: center;
					min-height: 40px;
					margin-bottom: -1px;
					padding: 0 14px;
					border: 1px solid #dcdcde;
					border-radius: 8px 8px 0 0;
					background: #f6f7f7;
					color: #1d2327;
					text-decoration: none;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__tab:hover,
				.oras-board-reports .oras-board-reports__tab:focus {
					background: #ffffff;
					color: #0a4b78;
				}
				.oras-board-reports .oras-board-reports__tab[aria-current="page"] {
					background: #ffffff;
					border-bottom-color: #ffffff;
					color: #0a4b78;
				}
				.oras-board-reports .oras-board-reports__placeholder {
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
					padding: 18px;
				}
				.oras-board-reports .oras-board-reports__placeholder h3 {
					margin-top: 0;
				}
				.oras-board-reports .oras-board-reports__event-shell {
					display: grid;
					grid-template-columns: minmax(240px, 1fr) auto;
					gap: 14px;
					align-items: end;
					margin: 18px 0;
					padding: 16px;
					border: 1px solid #dcdcde;
					border-radius: 12px;
					background: rgba(255, 255, 255, 0.94);
					box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
				}
				.oras-board-reports .oras-board-reports__event-shell label {
					margin: 0;
				}
				.oras-board-reports .oras-board-reports__overview-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
					gap: 12px;
					margin: 16px 0;
				}
				.oras-board-reports .oras-board-reports__metric {
					padding: 16px;
					border: 1px solid #d8dee9;
					border-radius: 14px;
					background: #ffffff;
					box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
				}
				.oras-board-reports .oras-board-reports__metric-label {
					margin: 0 0 6px;
					color: #475569;
					font-size: 0.82rem;
					font-weight: 800;
					letter-spacing: 0.04em;
					text-transform: uppercase;
				}
				.oras-board-reports .oras-board-reports__metric-value {
					margin: 0;
					color: #0f172a;
					font-size: 1.65rem;
					font-weight: 850;
					line-height: 1.1;
				}
				.oras-board-reports .oras-board-reports__attention-notice {
					display: grid;
					gap: 10px;
					margin: 16px 0;
					padding: 16px;
					border: 1px solid #f59e0b;
					border-left-width: 5px;
					border-radius: 12px;
					background: #fffbeb;
					color: #78350f;
				}
				.oras-board-reports .oras-board-reports__attention-notice h3 {
					margin: 0;
					color: #78350f;
				}
				.oras-board-reports .oras-board-reports__attention-notice p {
					margin: 0;
				}
				.oras-board-reports .oras-board-reports__filters {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
					gap: 12px;
					align-items: end;
					margin: 16px 0;
					padding: 14px;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
				}
				.oras-board-reports label {
					display: grid;
					gap: 5px;
					font-weight: 600;
					color: #1f2937;
				}
				.oras-board-reports input,
				.oras-board-reports select {
					max-width: 100%;
					min-height: 36px;
					padding: 6px 10px;
					color: #111827;
					background: #ffffff;
					font-size: 0.95rem;
				}
				.oras-board-reports .oras-board-reports__actions {
					grid-column: 1 / -1;
					display: flex;
					gap: 8px;
					flex-wrap: wrap;
					justify-content: flex-end;
					align-items: center;
				}
				.oras-board-reports .oras-board-reports__actions .button {
					display: inline-flex;
					align-items: center;
					justify-content: center;
					min-height: 38px;
					padding: 0 14px;
					border: 1px solid #35516e;
					border-radius: 6px;
					background: #1f3953;
					color: #f5f8fc;
					text-decoration: none;
					font-weight: 600;
					font-size: 0.95rem;
					line-height: 1;
					white-space: nowrap;
					text-align: center;
					box-shadow: none;
				}
				.oras-board-reports .oras-board-reports__actions .button:hover,
				.oras-board-reports .oras-board-reports__actions .button:focus {
					background: #2a4d70;
					border-color: #466a8e;
					color: #ffffff;
				}
				.oras-board-reports .oras-board-reports__actions .button.button-primary {
					background: #2d7dbf;
					border-color: #2d7dbf;
					color: #ffffff;
				}
				.oras-board-reports .oras-board-reports__actions .button.button-primary:hover,
				.oras-board-reports .oras-board-reports__actions .button.button-primary:focus {
					background: #3892d8;
					border-color: #3892d8;
				}
				.oras-board-reports .oras-board-reports__table-wrap {
					overflow-x: auto;
					max-height: min(72vh, 780px);
					border: 1px solid #dcdcde;
					border-radius: 12px;
					background: #fff;
					box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
				}
				.oras-board-reports table {
					width: max-content;
					min-width: 100%;
					border-collapse: separate;
					border-spacing: 0;
					font-size: 0.92rem;
					line-height: 1.38;
				}
				.oras-board-reports th,
				.oras-board-reports td {
					border-top: 1px solid #e5e7eb;
					border-right: 1px solid #edf0f4;
					padding: 11px 10px;
					text-align: left;
					vertical-align: top;
					overflow-wrap: anywhere;
				}
				.oras-board-reports th {
					position: sticky;
					top: 0;
					z-index: 2;
					border-top: 0;
					background: #f8fafc;
					color: #1d2327;
					font-weight: 700;
					white-space: nowrap;
				}
				.oras-board-reports tr:nth-child(even) > td {
					background: #fbfdff;
				}
				.oras-board-reports tbody tr:hover > td {
					background: #f1f7ff;
				}
				.oras-board-reports .oras-board-reports__cell--name {
					min-width: 170px;
					max-width: 220px;
					font-weight: 650;
				}
				.oras-board-reports .oras-board-reports__cell--email {
					min-width: 230px;
					max-width: 280px;
				}
				.oras-board-reports .oras-board-reports__cell--phone {
					min-width: 135px;
					max-width: 160px;
					white-space: nowrap;
				}
				.oras-board-reports .oras-board-reports__cell--address_summary,
				.oras-board-reports .oras-board-reports__cell--address {
					min-width: 230px;
					max-width: 300px;
				}
				.oras-board-reports .oras-board-reports__cell--item_label {
					min-width: 210px;
					max-width: 280px;
				}
				.oras-board-reports .oras-board-reports__cell--quantity,
				.oras-board-reports .oras-board-reports__cell--qty {
					min-width: 82px;
					max-width: 95px;
					text-align: center;
				}
				.oras-board-reports .oras-board-reports__cell--order_status,
				.oras-board-reports .oras-board-reports__cell--approval_status,
				.oras-board-reports .oras-board-reports__cell--rsvp_status,
				.oras-board-reports .oras-board-reports__cell--attendance_type,
				.oras-board-reports .oras-board-reports__cell--source {
					min-width: 125px;
					max-width: 170px;
				}
				.oras-board-reports .oras-board-reports__cell--order_date,
				.oras-board-reports .oras-board-reports__cell--approved_date {
					min-width: 145px;
					max-width: 170px;
				}
				.oras-board-reports .oras-board-reports__cell--note {
					min-width: 170px;
					max-width: 260px;
				}
				.oras-board-reports .oras-board-reports__cell--question_answers,
				.oras-board-reports .oras-board-reports__cell--details,
				.oras-board-reports .oras-board-reports__cell--actions {
					min-width: 145px;
					max-width: 190px;
				}
				.oras-board-reports .oras-board-reports__empty {
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
					padding: 18px;
				}
				.oras-board-reports .oras-board-reports__inline-actions {
					display: flex;
					flex-wrap: wrap;
					gap: 6px;
				}
				.oras-board-reports .oras-board-reports__inline-actions form {
					margin: 0;
				}
				.oras-board-reports .oras-board-reports__inline-actions .button {
					min-height: 32px;
					padding: 0 10px;
				}
				.oras-board-reports .oras-board-reports__rsvp-summary {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
					gap: 10px;
					margin: 0 0 14px;
				}
				.oras-board-reports .oras-board-reports__rsvp-summary-item {
					padding: 12px 14px;
					border: 1px solid #d8dee9;
					border-radius: 12px;
					background: #ffffff;
					box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
				}
				.oras-board-reports .oras-board-reports__rsvp-summary-label {
					display: block;
					color: #475569;
					font-size: 0.78rem;
					font-weight: 800;
					letter-spacing: 0.04em;
					text-transform: uppercase;
				}
				.oras-board-reports .oras-board-reports__rsvp-summary-value {
					display: block;
					margin-top: 4px;
					color: #0f172a;
					font-size: 1.5rem;
					font-weight: 850;
					line-height: 1;
				}
				.oras-board-reports .oras-board-reports__rsvp-list {
					display: grid;
					gap: 12px;
					margin: 0 0 18px;
				}
				.oras-board-reports .oras-board-reports__report-list {
					display: grid;
					gap: 12px;
					margin: 0 0 18px;
				}
				.oras-board-reports .oras-board-reports__pagination {
					display: flex;
					align-items: center;
					justify-content: flex-end;
					gap: 10px;
					flex-wrap: wrap;
					margin-top: 16px;
				}
				.oras-board-reports .oras-board-reports__pagination span {
					margin-right: auto;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__rsvp-card {
					display: grid;
					grid-template-columns: minmax(0, 1fr) minmax(220px, auto);
					gap: 16px;
					padding: 16px;
					border: 1px solid #d8dee9;
					border-radius: 14px;
					background: #ffffff;
					box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
				}
				.oras-board-reports .oras-board-reports__report-card {
					display: grid;
					grid-template-columns: minmax(0, 1fr) minmax(190px, auto);
					gap: 16px;
					padding: 16px;
					border: 1px solid #d8dee9;
					border-radius: 14px;
					background: #ffffff;
					box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
				}
				.oras-board-reports .oras-board-reports__rsvp-card-main {
					min-width: 0;
				}
				.oras-board-reports .oras-board-reports__report-card-main {
					min-width: 0;
				}
				.oras-board-reports .oras-board-reports__rsvp-card-name {
					margin: 0 0 6px;
					color: #0f172a;
					font-size: 1.14rem;
					font-weight: 850;
				}
				.oras-board-reports .oras-board-reports__report-card-title {
					margin: 0 0 6px;
					color: #0f172a;
					font-size: 1.14rem;
					font-weight: 850;
				}
				.oras-board-reports .oras-board-reports__rsvp-card-contact,
				.oras-board-reports .oras-board-reports__rsvp-card-meta,
				.oras-board-reports .oras-board-reports__rsvp-card-note {
					margin: 0;
					color: #334155;
				}
				.oras-board-reports .oras-board-reports__report-card-contact {
					display: flex;
					flex-wrap: wrap;
					gap: 8px 14px;
					margin: 0;
					color: #334155;
				}
				.oras-board-reports .oras-board-reports__rsvp-card-contact {
					display: flex;
					flex-wrap: wrap;
					gap: 8px 14px;
				}
				.oras-board-reports .oras-board-reports__rsvp-card-badges {
					display: flex;
					flex-wrap: wrap;
					gap: 8px;
					margin: 12px 0;
				}
				.oras-board-reports .oras-board-reports__report-card-badges {
					display: flex;
					flex-wrap: wrap;
					gap: 8px;
					margin: 12px 0;
				}
				.oras-board-reports .oras-board-reports__rsvp-badge {
					display: inline-flex;
					align-items: center;
					min-height: 28px;
					padding: 4px 9px;
					border: 1px solid #cbd5e1;
					border-radius: 999px;
					background: #f8fafc;
					color: #1f2937;
					font-size: 0.84rem;
					font-weight: 800;
				}
				.oras-board-reports .oras-board-reports__rsvp-badge--pending {
					background: #fffbeb;
					border-color: #fbbf24;
					color: #78350f;
				}
				.oras-board-reports .oras-board-reports__rsvp-badge--approved {
					background: #ecfdf5;
					border-color: #6ee7b7;
					color: #065f46;
				}
				.oras-board-reports .oras-board-reports__rsvp-badge--rejected {
					background: #fef2f2;
					border-color: #fca5a5;
					color: #991b1b;
				}
				.oras-board-reports .oras-board-reports__report-card-fields {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
					gap: 10px;
					margin: 12px 0 0;
				}
				.oras-board-reports .oras-board-reports__report-card-fields div {
					min-width: 0;
					padding: 10px 12px;
					border: 1px solid #e5e7eb;
					border-radius: 10px;
					background: #f8fafc;
				}
				.oras-board-reports .oras-board-reports__report-card-fields dt {
					margin: 0 0 4px;
					color: #475569;
					font-size: 0.76rem;
					font-weight: 800;
					letter-spacing: 0.04em;
					text-transform: uppercase;
				}
				.oras-board-reports .oras-board-reports__report-card-fields dd {
					margin: 0;
					color: #0f172a;
					font-weight: 650;
					overflow-wrap: anywhere;
					white-space: pre-wrap;
				}
				.oras-board-reports .oras-board-reports__rsvp-card-actions {
					display: flex;
					flex-direction: column;
					gap: 8px;
					align-items: stretch;
					justify-content: flex-start;
				}
				.oras-board-reports .oras-board-reports__report-card-actions {
					display: flex;
					flex-direction: column;
					gap: 8px;
					align-items: stretch;
					justify-content: flex-start;
				}
				.oras-board-reports .oras-board-reports__rsvp-card-actions .oras-board-reports__inline-actions {
					display: grid;
					grid-template-columns: 1fr;
					gap: 8px;
				}
				.oras-board-reports .oras-board-reports__report-card-actions .oras-board-reports__inline-actions {
					display: grid;
					grid-template-columns: 1fr;
					gap: 8px;
				}
				.oras-board-reports .oras-board-reports__rsvp-card-actions .button {
					width: 100%;
					justify-content: center;
				}
				.oras-board-reports .oras-board-reports__report-card-actions .button {
					width: 100%;
					justify-content: center;
				}
				.oras-board-reports details summary {
					cursor: pointer;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__answer-row > td {
					padding: 0 8px 14px;
					background: #f8fafc;
				}
				.oras-board-reports .oras-board-reports__question-answers {
					margin: 10px 0 0;
					padding: 14px;
					border: 1px solid #d8dee9;
					border-radius: 10px;
					background: #ffffff;
					color: #111827;
				}
				.oras-board-reports .oras-board-reports__question-answers-summary {
					cursor: pointer;
					font-weight: 800;
					color: #0f172a;
				}
				.oras-board-reports .oras-board-reports__question-answers[open] .oras-board-reports__question-answers-summary {
					margin-bottom: 12px;
				}
				.oras-board-reports .oras-board-reports__question-answers-title {
					margin-bottom: 10px;
					font-size: 0.95rem;
					font-weight: 800;
					color: #0f172a;
				}
				.oras-board-reports .oras-board-reports__question-answers dl {
					display: grid;
					grid-template-columns: minmax(180px, 0.35fr) minmax(220px, 1fr);
					gap: 8px 14px;
					margin: 0;
				}
				.oras-board-reports .oras-board-reports__question-answers dt,
				.oras-board-reports .oras-board-reports__question-answers dd {
					margin: 0;
					padding: 9px 10px;
					border-radius: 8px;
				}
				.oras-board-reports .oras-board-reports__question-answers dt {
					background: #eef2f7;
					color: #1f2937;
					font-weight: 700;
				}
				.oras-board-reports .oras-board-reports__question-answers dd {
					background: #ffffff;
					border: 1px solid #e5e7eb;
					color: #111827;
					white-space: pre-wrap;
				}
				html.oras-dark-on .oras-board-reports label,
				html[data-wp-dark-mode-active] .oras-board-reports label,
				body.wp-dark-mode-active .oras-board-reports label {
					color: #e6edf7;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__table-wrap,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__table-wrap,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__table-wrap {
					background: #050a12;
					border-color: rgba(148, 163, 184, 0.32);
					box-shadow: 0 20px 44px rgba(0, 0, 0, 0.35);
				}
				html.oras-dark-on .oras-board-reports th,
				html[data-wp-dark-mode-active] .oras-board-reports th,
				body.wp-dark-mode-active .oras-board-reports th {
					background: #101827;
					border-color: rgba(148, 163, 184, 0.24);
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports td,
				html[data-wp-dark-mode-active] .oras-board-reports td,
				body.wp-dark-mode-active .oras-board-reports td {
					background: rgba(2, 6, 23, 0.7);
					border-color: rgba(148, 163, 184, 0.22);
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports tr:nth-child(even) > td,
				html[data-wp-dark-mode-active] .oras-board-reports tr:nth-child(even) > td,
				body.wp-dark-mode-active .oras-board-reports tr:nth-child(even) > td {
					background: rgba(15, 23, 42, 0.76);
				}
				html.oras-dark-on .oras-board-reports tbody tr:hover > td,
				html[data-wp-dark-mode-active] .oras-board-reports tbody tr:hover > td,
				body.wp-dark-mode-active .oras-board-reports tbody tr:hover > td {
					background: rgba(30, 64, 111, 0.62);
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__tab,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__tab,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__tab {
					background: #142238;
					border-color: #3a4f68;
					color: #e6edf7;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__tab[aria-current="page"],
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__tab[aria-current="page"],
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__tab[aria-current="page"] {
					background: #0c1624;
					border-bottom-color: #0c1624;
					color: #ffffff;
				}
				html.oras-dark-on .oras-board-reports input,
				html.oras-dark-on .oras-board-reports select,
				html[data-wp-dark-mode-active] .oras-board-reports input,
				html[data-wp-dark-mode-active] .oras-board-reports select,
				body.wp-dark-mode-active .oras-board-reports input,
				body.wp-dark-mode-active .oras-board-reports select {
					color: #f4f7fc;
					background: #0c1624;
					border-color: #3a4f68;
				}
				html.oras-dark-on .oras-board-reports input::placeholder,
				html[data-wp-dark-mode-active] .oras-board-reports input::placeholder,
				body.wp-dark-mode-active .oras-board-reports input::placeholder {
					color: #c0cfdf;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__answer-row > td,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__answer-row > td,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__answer-row > td {
					background: rgba(2, 6, 23, 0.36);
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers {
					background: rgba(15, 23, 42, 0.9);
					border-color: rgba(148, 163, 184, 0.35);
					color: #e6edf7;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers-summary,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers-summary,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers-summary {
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers-title,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers-title,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers-title {
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers dt,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers dt,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers dt {
					background: rgba(51, 65, 85, 0.78);
					color: #e2e8f0;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__question-answers dd,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__question-answers dd,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__question-answers dd {
					background: rgba(2, 6, 23, 0.72);
					border-color: rgba(148, 163, 184, 0.28);
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__event-shell,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__event-shell,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__event-shell,
				html.oras-dark-on .oras-board-reports .oras-board-reports__metric,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__metric,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__metric {
					background: rgba(15, 23, 42, 0.9);
					border-color: rgba(148, 163, 184, 0.35);
					color: #e6edf7;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-summary-item,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-summary-item,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-summary-item,
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-card,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-card,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-card,
				html.oras-dark-on .oras-board-reports .oras-board-reports__report-card,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__report-card,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__report-card {
					background: rgba(15, 23, 42, 0.92);
					border-color: rgba(148, 163, 184, 0.35);
					color: #e6edf7;
					box-shadow: 0 20px 44px rgba(0, 0, 0, 0.35);
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-card-name,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-card-name,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-card-name,
				html.oras-dark-on .oras-board-reports .oras-board-reports__report-card-title,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__report-card-title,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__report-card-title,
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-summary-value,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-summary-value,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-summary-value {
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-card-contact,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-card-contact,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-card-contact,
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-card-meta,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-card-meta,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-card-meta,
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-card-note,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-card-note,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-card-note,
				html.oras-dark-on .oras-board-reports .oras-board-reports__report-card-contact,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__report-card-contact,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__report-card-contact,
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-summary-label,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-summary-label,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-summary-label {
					color: #cbd5e1;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__report-card-fields div,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__report-card-fields div,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__report-card-fields div {
					background: rgba(2, 6, 23, 0.72);
					border-color: rgba(148, 163, 184, 0.28);
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__report-card-fields dt,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__report-card-fields dt,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__report-card-fields dt {
					color: #cbd5e1;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__report-card-fields dd,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__report-card-fields dd,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__report-card-fields dd {
					color: #f8fafc;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__rsvp-badge,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__rsvp-badge,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__rsvp-badge {
					background: rgba(51, 65, 85, 0.78);
					border-color: rgba(148, 163, 184, 0.35);
					color: #e2e8f0;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__metric-label,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__metric-label,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__metric-label {
					color: #cbd5e1;
				}
				html.oras-dark-on .oras-board-reports .oras-board-reports__metric-value,
				html[data-wp-dark-mode-active] .oras-board-reports .oras-board-reports__metric-value,
				body.wp-dark-mode-active .oras-board-reports .oras-board-reports__metric-value {
					color: #f8fafc;
				}
				html:not(.oras-dark-on) .oras-board-reports label {
					color: #1f2937;
				}
				html:not(.oras-dark-on) .oras-board-reports input,
				html:not(.oras-dark-on) .oras-board-reports select {
					color: #111827;
					background: #ffffff;
				}
				@media (max-width: 900px) {
					.oras-board-reports .oras-board-reports__actions {
						justify-content: stretch;
					}
					.oras-board-reports .oras-board-reports__actions .button {
						flex: 1 1 100%;
						text-align: center;
					}
					.oras-board-reports .oras-board-reports__question-answers dl {
						grid-template-columns: 1fr;
					}
					.oras-board-reports .oras-board-reports__event-shell {
						grid-template-columns: 1fr;
					}
					.oras-board-reports .oras-board-reports__rsvp-card {
						grid-template-columns: 1fr;
					}
					.oras-board-reports .oras-board-reports__report-card {
						grid-template-columns: 1fr;
					}
				}
			</style>

			<h2><?php echo esc_html__( 'Board Reports / Event Management Dashboard', 'oras-tickets' ); ?></h2>
			<p class="oras-board-reports__notice"><?php echo esc_html__( 'This report excludes payment method, transaction, card, and accounting details.', 'oras-tickets' ); ?></p>
			<?php self::render_rsvp_approval_notice(); ?>
			<?php self::render_waitlist_notice(); ?>
			<?php self::render_attention_notice(); ?>
			<?php self::render_event_selector_shell( $page_id, $events, $filters['event_id'], $active_tab ); ?>
			<?php self::render_overview_cards( $service, $filters['event_id'] ); ?>
			<?php self::render_attention_notification_center( $filters['event_id'] ); ?>
			<?php self::render_tabs( $active_tab ); ?>
			<?php if ( self::TAB_OVERVIEW === $active_tab ) : ?>
				<?php self::render_overview_tab( $filters['event_id'] ); ?>
			<?php elseif ( self::TAB_TICKET_SALES === $active_tab ) : ?>
				<?php self::render_ticket_sales_tab( $page_id ); ?>
			<?php elseif ( self::TAB_RSVPS === $active_tab ) : ?>
				<?php self::render_rsvps_tab( $page_id ); ?>
			<?php elseif ( self::TAB_ATTENTION === $active_tab ) : ?>
				<?php self::render_attention_tab( $page_id ); ?>
			<?php elseif ( self::TAB_COMMUNICATIONS === $active_tab ) : ?>
				<?php self::render_communications_tab( $page_id ); ?>
			<?php elseif ( self::TAB_ATTENDEES === $active_tab ) : ?>
				<?php self::render_attendees_tab( $page_id ); ?>
			<?php else : ?>
				<?php self::render_placeholder_tab( $active_tab ); ?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private static function render_event_selector_shell( int $page_id, array $events, int $event_id, string $active_tab ): void {
		?>
		<form class="oras-board-reports__event-shell" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
			<?php if ( $page_id > 0 ) : ?>
				<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
			<?php endif; ?>
			<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
			<?php self::render_event_filter( $events, $event_id ); ?>
			<div class="oras-board-reports__actions">
				<button class="button button-primary" type="submit"><?php echo esc_html__( 'Load Event Dashboard', 'oras-tickets' ); ?></button>
			</div>
		</form>
		<?php
	}

	private static function render_overview_cards( Board_Report_Service $service, int $event_id ): void {
		if ( $event_id <= 0 ) {
			echo '<div class="oras-board-reports__empty">' . esc_html__( 'Select an event to load the dashboard overview.', 'oras-tickets' ) . '</div>';
			return;
		}

		$stats = $service->get_event_statistics( $event_id );
		$approval_counts = isset( $stats['rsvp_approval_counts'] ) && is_array( $stats['rsvp_approval_counts'] )
			? $stats['rsvp_approval_counts']
			: array();
		$metrics = array(
			__( 'Ticket Quantity', 'oras-tickets' )       => (string) absint( $stats['ticket_quantity'] ?? 0 ),
			__( 'Ticket Orders', 'oras-tickets' )         => (string) absint( $stats['ticket_order_count'] ?? 0 ),
			__( 'RSVP Yes', 'oras-tickets' )              => (string) absint( $stats['rsvp_yes_count'] ?? 0 ),
			__( 'Pending Virtual', 'oras-tickets' )       => (string) absint( $approval_counts[ Event_RSVP::APPROVAL_STATUS_PENDING ] ?? 0 ),
			__( 'Approved Virtual', 'oras-tickets' )      => (string) absint( $stats['rsvp_virtual_approved_count'] ?? 0 ),
			__( 'On-site Attendance', 'oras-tickets' )    => (string) absint( $stats['onsite_attendance_count'] ?? 0 ),
			__( 'Virtual Attendance', 'oras-tickets' )    => (string) absint( $stats['virtual_attendance_count'] ?? 0 ),
			__( 'Waitlist', 'oras-tickets' )              => (string) absint( $stats['rsvp_waitlist_count'] ?? 0 ),
			__( 'Open Attention Items', 'oras-tickets' )  => (string) Event_Question_Attention_Store::count_open( $event_id ),
			__( 'Expected Attendance', 'oras-tickets' )   => (string) ( absint( $stats['onsite_attendance_count'] ?? 0 ) + absint( $stats['virtual_attendance_count'] ?? 0 ) ),
			__( 'Communications Sent', 'oras-tickets' )   => (string) self::get_communication_count_for_event( $event_id ),
		);
		?>
		<section class="oras-board-reports__overview-grid" aria-label="<?php echo esc_attr__( 'Event Overview', 'oras-tickets' ); ?>">
			<?php foreach ( $metrics as $label => $value ) : ?>
				<div class="oras-board-reports__metric">
					<p class="oras-board-reports__metric-label"><?php echo esc_html( $label ); ?></p>
					<p class="oras-board-reports__metric-value"><?php echo esc_html( $value ); ?></p>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private static function render_attention_notification_center( int $event_id ): void {
		if ( $event_id <= 0 ) {
			return;
		}

		$count = Event_Question_Attention_Store::count_open( $event_id );
		if ( $count <= 0 ) {
			return;
		}

		$url = add_query_arg(
			array(
				'oras_board_tab'      => self::TAB_ATTENTION,
				'oras_board_event_id' => $event_id,
				'oras_attention_status' => Event_Question_Attention_Store::STATUS_OPEN,
			),
			self::get_form_action_url()
		);
		?>
		<section class="oras-board-reports__attention-notice" aria-live="polite">
			<h3><?php echo esc_html__( 'Open event coordination items need review', 'oras-tickets' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: open attention item count */
						_n( '%d question answer matched an attention rule.', '%d question answers matched attention rules.', $count, 'oras-tickets' ),
						$count
					)
				);
				?>
			</p>
			<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'Review Attention Items', 'oras-tickets' ); ?></a></p>
		</section>
		<?php
	}

	private static function render_overview_tab( int $event_id ): void {
		?>
		<section class="oras-board-reports__placeholder">
			<h3><?php echo esc_html__( 'Event Overview', 'oras-tickets' ); ?></h3>
			<p><?php echo esc_html__( 'Use Sales for paid ticket orders, RSVP Management for approvals and waitlists, Roster for the combined attendee list, and Communications for event emails.', 'oras-tickets' ); ?></p>
			<?php if ( $event_id > 0 ) : ?>
				<p><strong><?php echo esc_html__( 'Selected event:', 'oras-tickets' ); ?></strong> <?php echo esc_html( get_the_title( $event_id ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_ticket_sales_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$filters['type'] = Board_Report_Service::TYPE_TICKETS;

		$events = $service->get_events();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$rows = $service->get_rows( $filters['type'], $filters );
		$summary_rows = $rows;
		$page_data = self::paginate_rows( $rows, $filters );
		$rows = $page_data['rows'];
		$spreadsheet_export_url = self::build_export_url( $filters, 'spreadsheet' );
		$pdf_export_url = self::build_export_url( $filters, 'pdf' );
		?>

			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_TICKET_SALES ); ?>" />
				<?php self::render_page_size_field( $filters['per_page'] ); ?>

				<?php self::render_event_hidden_input( $filters['event_id'] ); ?>

				<label>
					<?php echo esc_html__( 'After', 'oras-tickets' ); ?>
					<input type="date" name="oras_board_after" value="<?php echo esc_attr( $filters['after'] ); ?>" />
				</label>

				<label>
					<?php echo esc_html__( 'Before', 'oras-tickets' ); ?>
					<input type="date" name="oras_board_before" value="<?php echo esc_attr( $filters['before'] ); ?>" />
				</label>

				<label>
					<?php echo esc_html__( 'Status', 'oras-tickets' ); ?>
					<select name="oras_board_status">
						<?php foreach ( self::get_status_options( $filters['type'] ) as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php echo esc_html__( 'Search', 'oras-tickets' ); ?>
					<input type="search" name="oras_board_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone, item', 'oras-tickets' ); ?>" />
				</label>

				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show Sales', 'oras-tickets' ); ?></button>
					<?php if ( current_user_can( 'oras_tickets_export_reports' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
						<a class="button button-secondary" href="<?php echo esc_url( $spreadsheet_export_url ); ?>"><?php echo esc_html__( 'Create Spreadsheet', 'oras-tickets' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $pdf_export_url ); ?>"><?php echo esc_html__( 'Create PDF', 'oras-tickets' ); ?></a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No matching rows found for this report.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<?php self::render_sales_summary_bar( $summary_rows ); ?>
				<div class="oras-board-reports__report-list oras-board-reports__sales-list">
					<?php foreach ( $rows as $row ) : ?>
						<?php self::render_sales_card( $row ); ?>
					<?php endforeach; ?>
				</div>
				<?php self::render_pagination( $page_data ); ?>
				<?php endif; ?>
			<?php
		}

	private static function render_waitlist_section( int $event_id ): void {
		$rows = Waitlist_Store::get_event_rows( $event_id, array( 'waiting' ), 250, 'joined_asc' );
		$position = 1;
		?>
		<section class="oras-board-reports__placeholder">
			<h3><?php echo esc_html__( 'Waitlist Queue', 'oras-tickets' ); ?></h3>
			<p><?php echo esc_html__( 'Board users can promote waitlisted RSVPs when capacity opens, open one additional RSVP seat, or remove a waitlist entry.', 'oras-tickets' ); ?></p>
			<?php if ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No one is currently waiting for this event.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<div class="oras-board-reports__report-list oras-board-reports__waitlist-list">
					<?php foreach ( $rows as $row ) : ?>
						<?php self::render_waitlist_card( $event_id, $row, $position ); ?>
						<?php ++$position; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @return array{name:string,email:string,phone:string,note:string}
	 */
	private static function get_waitlist_contact( int $event_id, int $user_id ): array {
		$contact = array(
			'name'  => '',
			'email' => '',
			'phone' => '',
			'note'  => '',
		);
		if ( $event_id <= 0 || $user_id <= 0 ) {
			return $contact;
		}

		$stored = get_user_meta( $user_id, '_oras_rsvp_event_' . $event_id . '_contact', true );
		if ( is_array( $stored ) ) {
			$first = isset( $stored['first_name'] ) && is_scalar( $stored['first_name'] ) ? sanitize_text_field( (string) $stored['first_name'] ) : '';
			$last = isset( $stored['last_name'] ) && is_scalar( $stored['last_name'] ) ? sanitize_text_field( (string) $stored['last_name'] ) : '';
			$contact['name'] = trim( $first . ' ' . $last );
			$contact['email'] = isset( $stored['email'] ) && is_scalar( $stored['email'] ) ? sanitize_email( (string) $stored['email'] ) : '';
			$contact['phone'] = isset( $stored['phone'] ) && is_scalar( $stored['phone'] ) ? sanitize_text_field( (string) $stored['phone'] ) : '';
			$contact['note'] = isset( $stored['note'] ) && is_scalar( $stored['note'] ) ? sanitize_textarea_field( (string) $stored['note'] ) : '';
		}

		return $contact;
	}

	/**
	 * @param object               $row
	 * @param array<string,string> $contact
	 */
	private static function render_waitlist_row_actions( int $event_id, int $user_id, object $row, array $contact, string $attendance_mode, string $approval_status ): void {
		?>
		<div class="oras-board-reports__inline-actions">
			<details>
				<summary><?php echo esc_html__( 'View Details', 'oras-tickets' ); ?></summary>
				<p><strong><?php echo esc_html__( 'User ID:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $user_id ); ?></p>
				<p><strong><?php echo esc_html__( 'Phone:', 'oras-tickets' ); ?></strong> <?php echo esc_html( $contact['phone'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Attendance mode:', 'oras-tickets' ); ?></strong> <?php echo esc_html( Event_RSVP::get_attendance_mode_label( $attendance_mode ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approval status:', 'oras-tickets' ); ?></strong> <?php echo esc_html( Event_RSVP::get_approval_status_label( $approval_status ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Source:', 'oras-tickets' ); ?></strong> <?php echo esc_html( isset( $row->source ) ? (string) $row->source : '' ); ?></p>
			</details>
			<?php if ( current_user_can( 'oras_tickets_manage_rsvps' ) && $event_id > 0 && $user_id > 0 ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
				<?php self::render_waitlist_action_form( $event_id, $user_id, 'promote', __( 'Promote', 'oras-tickets' ) ); ?>
				<?php self::render_waitlist_action_form( $event_id, $user_id, 'open_seat_promote', __( 'Open Seat + Promote', 'oras-tickets' ) ); ?>
				<?php self::render_waitlist_action_form( $event_id, $user_id, 'remove', __( 'Remove from Waitlist', 'oras-tickets' ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_waitlist_action_form( int $event_id, int $user_id, string $waitlist_action, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::WAITLIST_NONCE_ACTION, 'oras_board_waitlist_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::WAITLIST_ACTION ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<input type="hidden" name="waitlist_action" value="<?php echo esc_attr( $waitlist_action ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_current_url() ); ?>" />
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_communications_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$segment = self::get_communication_segment_from_request();
		$recipients = ( new Communication_Recipients( $service ) )->resolve( $filters['event_id'], $segment );
		$recipient_count = count( $recipients );
		$history_filters = self::get_communication_history_filters( $filters['event_id'] );
		$history_rows = Communication_Log_Store::query( $history_filters );
		$detail = self::get_communication_detail();
		?>
			<?php self::render_communication_notice(); ?>
			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_COMMUNICATIONS ); ?>" />
				<?php self::render_event_hidden_input( $filters['event_id'] ); ?>
				<label>
					<?php echo esc_html__( 'Recipient Segment', 'oras-tickets' ); ?>
					<select name="oras_comm_segment">
						<?php foreach ( Communication_Recipients::get_segments() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $segment, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Preview Recipients', 'oras-tickets' ); ?></button>
				</div>
			</form>

			<div class="oras-board-reports__placeholder">
				<h3><?php echo esc_html__( 'Recipient Count Preview', 'oras-tickets' ); ?></h3>
				<p><strong><?php echo esc_html( (string) $recipient_count ); ?></strong> <?php echo esc_html__( 'valid deduplicated recipient(s) found for the selected segment.', 'oras-tickets' ); ?></p>
			</div>

			<?php if ( current_user_can( 'oras_tickets_send_notifications' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
				<form class="oras-board-reports__filters" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::COMMUNICATION_ACTION ); ?>" />
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $filters['event_id'] ); ?>" />
					<input type="hidden" name="recipient_segment" value="<?php echo esc_attr( $segment ); ?>" />
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_current_dashboard_url( self::TAB_COMMUNICATIONS, $filters['event_id'], $segment ) ); ?>" />
					<?php wp_nonce_field( self::COMMUNICATION_NONCE_ACTION, 'oras_board_communication_nonce' ); ?>
					<label>
						<?php echo esc_html__( 'Subject', 'oras-tickets' ); ?>
						<input type="text" name="email_subject" required maxlength="200" />
					</label>
					<label style="grid-column:1 / -1;">
						<?php echo esc_html__( 'Message', 'oras-tickets' ); ?>
						<textarea name="email_message" rows="8" required></textarea>
					</label>
					<label style="grid-column:1 / -1;">
						<input type="checkbox" name="confirm_send" value="1" required />
						<?php echo esc_html__( 'I confirm this message should be sent to the selected recipient segment.', 'oras-tickets' ); ?>
					</label>
					<div class="oras-board-reports__actions">
						<button class="button button-primary" type="submit" <?php disabled( 0, $recipient_count ); ?>><?php echo esc_html__( 'Send Email', 'oras-tickets' ); ?></button>
					</div>
				</form>
			<?php else : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'You do not have permission to send event communications.', 'oras-tickets' ); ?></div>
			<?php endif; ?>

			<h3><?php echo esc_html__( 'Communication History', 'oras-tickets' ); ?></h3>
			<?php self::render_communication_history_filters( $page_id, $events, $history_filters ); ?>
			<?php self::render_communication_history_table( $history_rows ); ?>
			<?php if ( is_array( $detail ) ) : ?>
				<section class="oras-board-reports__placeholder">
					<h3><?php echo esc_html__( 'Communication Details', 'oras-tickets' ); ?></h3>
					<p><strong><?php echo esc_html__( 'Subject:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) ( $detail['email_subject'] ?? '' ) ); ?></p>
					<p><strong><?php echo esc_html__( 'Body Snapshot:', 'oras-tickets' ); ?></strong></p>
					<pre><?php echo esc_html( (string) ( $detail['email_body_snapshot'] ?? '' ) ); ?></pre>
				</section>
			<?php endif; ?>
		<?php
	}

	private static function render_attendees_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$rows = $service->get_unified_attendees( $filters['event_id'], $filters );
		$summary_rows = $rows;
		$page_data = self::paginate_rows( $rows, $filters );
		$rows = $page_data['rows'];
		$ticket_export_filters = array_merge(
			$filters,
			array(
				'type'   => Board_Report_Service::TYPE_TICKETS,
				'status' => $filters['ticket_status'],
			)
		);
		$spreadsheet_export_url = self::build_export_url(
			$ticket_export_filters,
			'spreadsheet'
		);
		$pdf_export_url = self::build_export_url(
			$ticket_export_filters,
			'pdf'
		);
		?>
			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_ATTENDEES ); ?>" />
				<?php self::render_page_size_field( $filters['per_page'] ); ?>
				<?php self::render_event_hidden_input( $filters['event_id'] ); ?>

				<label>
					<?php echo esc_html__( 'Source', 'oras-tickets' ); ?>
					<select name="oras_board_attendee_source">
						<?php foreach ( self::get_attendee_source_options() as $source => $label ) : ?>
							<option value="<?php echo esc_attr( $source ); ?>" <?php selected( $filters['attendee_source'], $source ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Ticket Status', 'oras-tickets' ); ?>
					<select name="oras_board_ticket_status">
						<?php foreach ( self::get_status_options( Board_Report_Service::TYPE_TICKETS ) as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['ticket_status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Attendance Type', 'oras-tickets' ); ?>
					<select name="oras_board_attendance_type">
						<?php foreach ( self::get_attendance_type_options() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['attendance_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Approval Status', 'oras-tickets' ); ?>
					<select name="oras_board_approval_status">
						<?php foreach ( self::get_approval_status_options() as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['approval_status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Search', 'oras-tickets' ); ?>
					<input type="search" name="oras_board_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone, item', 'oras-tickets' ); ?>" />
				</label>
				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show Roster', 'oras-tickets' ); ?></button>
					<?php if ( current_user_can( 'oras_tickets_export_reports' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
						<a class="button button-secondary" href="<?php echo esc_url( $spreadsheet_export_url ); ?>"><?php echo esc_html__( 'Export Sales Spreadsheet', 'oras-tickets' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $pdf_export_url ); ?>"><?php echo esc_html__( 'Export Sales PDF', 'oras-tickets' ); ?></a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( empty( $events ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No events are available for roster reporting.', 'oras-tickets' ); ?></div>
			<?php elseif ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No matching roster rows found for this event.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<?php self::render_roster_summary_bar( $summary_rows ); ?>
				<div class="oras-board-reports__report-list oras-board-reports__roster-list">
					<?php foreach ( $rows as $row ) : ?>
						<?php self::render_roster_card( $row ); ?>
					<?php endforeach; ?>
				</div>
				<?php self::render_pagination( $page_data ); ?>
				<?php endif; ?>
			<?php
		}

	private static function render_rsvps_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		$filters['type'] = Board_Report_Service::TYPE_RSVP;
		$filters['status'] = 'all';

		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$rows = $service->get_rows( Board_Report_Service::TYPE_RSVP, $filters );
		$summary_rows = $rows;
		$page_data = self::paginate_rows( $rows, $filters );
		$rows = $page_data['rows'];
		$spreadsheet_export_url = self::build_export_url( $filters, 'spreadsheet' );
		$pdf_export_url = self::build_export_url( $filters, 'pdf' );
		?>

			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_RSVPS ); ?>" />
				<?php self::render_page_size_field( $filters['per_page'] ); ?>
				<?php self::render_event_hidden_input( $filters['event_id'] ); ?>

				<label>
					<?php echo esc_html__( 'Attendance Type', 'oras-tickets' ); ?>
					<select name="oras_board_attendance_type">
						<?php foreach ( self::get_attendance_type_options() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['attendance_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php echo esc_html__( 'Approval Status', 'oras-tickets' ); ?>
					<select name="oras_board_approval_status">
						<?php foreach ( self::get_approval_status_options() as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['approval_status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php echo esc_html__( 'Search', 'oras-tickets' ); ?>
					<input type="search" name="oras_board_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone, note', 'oras-tickets' ); ?>" />
				</label>

				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show RSVPs', 'oras-tickets' ); ?></button>
					<?php if ( current_user_can( 'oras_tickets_export_reports' ) ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
						<a class="button button-secondary" href="<?php echo esc_url( $spreadsheet_export_url ); ?>"><?php echo esc_html__( 'Create Spreadsheet', 'oras-tickets' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $pdf_export_url ); ?>"><?php echo esc_html__( 'Create PDF', 'oras-tickets' ); ?></a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( empty( $events ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No events are available for RSVP reporting.', 'oras-tickets' ); ?></div>
			<?php elseif ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No matching RSVP rows found for this event.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<?php self::render_rsvp_summary_bar( $summary_rows ); ?>
				<div class="oras-board-reports__rsvp-list">
					<?php foreach ( $rows as $row ) : ?>
						<?php self::render_rsvp_card( $row ); ?>
					<?php endforeach; ?>
				</div>
				<?php self::render_pagination( $page_data ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $events ) && $filters['event_id'] > 0 ) : ?>
					<?php self::render_waitlist_section( $filters['event_id'] ); ?>
				<?php endif; ?>
			<?php
		}

	private static function render_attention_tab( int $page_id ): void {
		$service = new Board_Report_Service();
		$events = $service->get_events();
		$filters = self::get_filters_from_request();
		if ( $filters['event_id'] <= 0 && ! empty( $events ) ) {
			$filters['event_id'] = (int) $events[0]->ID;
		}

		$attention_filters = self::get_attention_filters( $filters['event_id'] );
		$rows = $filters['event_id'] > 0 ? Event_Question_Attention_Store::query( $attention_filters ) : array();
		?>
			<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
				<?php if ( $page_id > 0 ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_ATTENTION ); ?>" />
				<?php self::render_event_hidden_input( $filters['event_id'] ); ?>

				<label>
					<?php echo esc_html__( 'Status', 'oras-tickets' ); ?>
					<select name="oras_attention_status">
						<?php foreach ( self::get_attention_status_options() as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( (string) $attention_filters['status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Severity', 'oras-tickets' ); ?>
					<select name="oras_attention_severity">
						<option value=""><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
						<?php foreach ( \ORAS\Tickets\Event_Questions::attention_severity_options() as $severity => $label ) : ?>
							<option value="<?php echo esc_attr( $severity ); ?>" <?php selected( (string) $attention_filters['severity'], $severity ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Source', 'oras-tickets' ); ?>
					<select name="oras_attention_source_type">
						<option value=""><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
						<option value="rsvp" <?php selected( (string) $attention_filters['source_type'], 'rsvp' ); ?>><?php echo esc_html__( 'RSVP', 'oras-tickets' ); ?></option>
						<option value="ticket" <?php selected( (string) $attention_filters['source_type'], 'ticket' ); ?>><?php echo esc_html__( 'Ticket', 'oras-tickets' ); ?></option>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Search', 'oras-tickets' ); ?>
					<input type="search" name="oras_attention_search" value="<?php echo esc_attr( (string) $attention_filters['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, question, answer, reason', 'oras-tickets' ); ?>" />
				</label>
				<div class="oras-board-reports__actions">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Show Attention Items', 'oras-tickets' ); ?></button>
				</div>
			</form>

			<?php if ( empty( $events ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No events are available for attention review.', 'oras-tickets' ); ?></div>
			<?php elseif ( empty( $rows ) ) : ?>
				<div class="oras-board-reports__empty"><?php echo esc_html__( 'No matching attention items found.', 'oras-tickets' ); ?></div>
			<?php else : ?>
				<div class="oras-board-reports__report-list oras-board-reports__attention-list">
					<?php foreach ( $rows as $row ) : ?>
						<?php self::render_attention_card( $row ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_attention_row_actions( array $row ): void {
		if ( ! current_user_can( 'oras_tickets_manage_rsvps' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			echo esc_html__( 'View only', 'oras-tickets' );
			return;
		}

		$id = absint( $row['id'] ?? 0 );
		if ( $id <= 0 ) {
			return;
		}

		echo '<div class="oras-board-reports__inline-actions">';
		self::render_attention_action_form( $id, Event_Question_Attention_Store::STATUS_REVIEWED, __( 'Mark Reviewed', 'oras-tickets' ) );
		self::render_attention_action_form( $id, Event_Question_Attention_Store::STATUS_RESOLVED, __( 'Resolve', 'oras-tickets' ) );
		self::render_attention_action_form( $id, Event_Question_Attention_Store::STATUS_DISMISSED, __( 'Dismiss', 'oras-tickets' ) );
		if ( Event_Question_Attention_Store::STATUS_OPEN !== self::row_scalar( $row, 'status' ) ) {
			self::render_attention_action_form( $id, Event_Question_Attention_Store::STATUS_OPEN, __( 'Reopen', 'oras-tickets' ) );
		}
		echo '</div>';
	}

	private static function render_attention_action_form( int $id, string $status, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ATTENTION_NONCE_ACTION, 'oras_board_attention_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ATTENTION_ACTION ); ?>" />
			<input type="hidden" name="attention_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="attention_status" value="<?php echo esc_attr( $status ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_current_url() ); ?>" />
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function render_sales_summary_bar( array $rows ): void {
		$order_ids = array();
		$quantity = 0;
		foreach ( $rows as $row ) {
			$order_id = absint( $row['order_id'] ?? 0 );
			if ( $order_id > 0 ) {
				$order_ids[ $order_id ] = true;
			}
			$quantity += absint( $row['quantity'] ?? 0 );
		}

		self::render_compact_summary_bar(
			array(
				__( 'Rows Shown', 'oras-tickets' )     => count( $rows ),
				__( 'Unique Orders', 'oras-tickets' )  => count( $order_ids ),
				__( 'Ticket Quantity', 'oras-tickets' ) => $quantity,
			),
			__( 'Sales result summary', 'oras-tickets' )
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_sales_card( array $row ): void {
		$name = self::row_scalar( $row, 'name' );
		$email = self::row_scalar( $row, 'email' );
		$phone = self::row_scalar( $row, 'phone' );
		$status = self::row_scalar( $row, 'order_status' );
		$source = self::row_scalar( $row, 'source' );
		$quantity = self::row_scalar( $row, 'quantity' );
		$item = self::row_scalar( $row, 'item_label' );
		?>
		<article class="oras-board-reports__report-card oras-board-reports__sales-card">
			<div class="oras-board-reports__report-card-main">
				<h3 class="oras-board-reports__report-card-title"><?php echo esc_html( '' !== $name ? $name : __( 'Unnamed purchaser', 'oras-tickets' ) ); ?></h3>
				<?php self::render_report_card_contact( $email, $phone ); ?>
				<?php self::render_report_card_badges( array_filter( array( $status, $source, '' !== $quantity ? sprintf( __( 'Qty %s', 'oras-tickets' ), $quantity ) : '' ) ) ); ?>
				<?php
				self::render_report_card_fields(
					array(
						__( 'Item / Ticket / Pass', 'oras-tickets' ) => $item,
						__( 'Order Date', 'oras-tickets' ) => self::row_scalar( $row, 'order_date' ),
						__( 'Address', 'oras-tickets' ) => self::row_scalar( $row, 'address_summary' ),
						__( 'Note', 'oras-tickets' ) => self::row_scalar( $row, 'note' ),
					)
				);
				?>
				<?php self::render_question_answers_summary( $row ); ?>
			</div>
		</article>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function render_roster_summary_bar( array $rows ): void {
		$ticket_rows = 0;
		$rsvp_rows = 0;
		$virtual_rows = 0;
		foreach ( $rows as $row ) {
			$source = strtolower( self::row_scalar( $row, 'source' ) );
			if ( str_contains( $source, 'ticket' ) ) {
				++$ticket_rows;
			}
			if ( str_contains( $source, 'rsvp' ) ) {
				++$rsvp_rows;
			}
			if ( Ticket::ATTENDANCE_MODE_VIRTUAL === strtolower( self::row_scalar( $row, 'attendance_type' ) ) ) {
				++$virtual_rows;
			}
		}

		self::render_compact_summary_bar(
			array(
				__( 'Roster Rows', 'oras-tickets' ) => count( $rows ),
				__( 'Ticket Rows', 'oras-tickets' ) => $ticket_rows,
				__( 'RSVP Rows', 'oras-tickets' )   => $rsvp_rows,
				__( 'Virtual', 'oras-tickets' )     => $virtual_rows,
			),
			__( 'Roster result summary', 'oras-tickets' )
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_roster_card( array $row ): void {
		$name = self::row_scalar( $row, 'name' );
		$email = self::row_scalar( $row, 'email' );
		$phone = self::row_scalar( $row, 'phone' );
		$item = trim( self::row_scalar( $row, 'item_label' ) . self::format_status_suffix( self::row_scalar( $row, 'order_status' ) ) );
		?>
		<article class="oras-board-reports__report-card oras-board-reports__roster-card">
			<div class="oras-board-reports__report-card-main">
				<h3 class="oras-board-reports__report-card-title"><?php echo esc_html( '' !== $name ? $name : __( 'Unnamed attendee', 'oras-tickets' ) ); ?></h3>
				<?php self::render_report_card_contact( $email, $phone ); ?>
				<?php self::render_report_card_badges( array_filter( array( self::row_scalar( $row, 'source' ), self::row_scalar( $row, 'attendance_label' ), self::row_scalar( $row, 'approval_label' ) ) ) ); ?>
				<?php
				self::render_report_card_fields(
					array(
						__( 'Item / Status', 'oras-tickets' ) => $item,
						__( 'Quantity', 'oras-tickets' ) => self::row_scalar( $row, 'quantity' ),
						__( 'Note', 'oras-tickets' )     => self::row_scalar( $row, 'note' ),
					)
				);
				?>
				<?php self::render_question_answers_summary( $row ); ?>
			</div>
		</article>
		<?php
	}

	private static function render_waitlist_card( int $event_id, object $row, int $position ): void {
		$user_id = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		$contact = self::get_waitlist_contact( $event_id, $user_id );
		$name = $contact['name'] !== '' ? $contact['name'] : ( $user instanceof \WP_User ? (string) $user->display_name : 'User #' . $user_id );
		$email = $contact['email'] !== '' ? $contact['email'] : ( $user instanceof \WP_User ? (string) $user->user_email : '' );
		$attendance_mode = Event_RSVP::get_user_attendance_type_for_report( $event_id, $user_id );
		$approval_status = Event_RSVP::get_user_approval_status( $event_id, $user_id );
		?>
		<article class="oras-board-reports__report-card oras-board-reports__waitlist-card">
			<div class="oras-board-reports__report-card-main">
				<h3 class="oras-board-reports__report-card-title"><?php echo esc_html( $name ); ?></h3>
				<?php self::render_report_card_contact( $email, $contact['phone'] ); ?>
				<?php self::render_report_card_badges( array( sprintf( __( 'Position %d', 'oras-tickets' ), $position ), Event_RSVP::get_attendance_mode_label( $attendance_mode ), Event_RSVP::get_approval_status_label( $approval_status ) ) ); ?>
				<?php
				self::render_report_card_fields(
					array(
						__( 'Joined Date', 'oras-tickets' )  => isset( $row->joined_at ) ? (string) $row->joined_at : '',
						__( 'Contact Note', 'oras-tickets' ) => $contact['note'],
					)
				);
				?>
			</div>
			<div class="oras-board-reports__report-card-actions">
				<?php self::render_waitlist_row_actions( $event_id, $user_id, $row, $contact, $attendance_mode, $approval_status ); ?>
			</div>
		</article>
		<?php
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_attention_card( array $row ): void {
		$question = self::row_scalar( $row, 'question_label' );
		$answer = self::row_scalar( $row, 'answer_value' );
		$severity = self::get_attention_severity_label( self::row_scalar( $row, 'severity' ) );
		$status = self::get_attention_status_label( self::row_scalar( $row, 'status' ) );
		?>
		<article class="oras-board-reports__report-card oras-board-reports__attention-card">
			<div class="oras-board-reports__report-card-main">
				<h3 class="oras-board-reports__report-card-title"><?php echo esc_html( '' !== $question ? $question : __( 'Attention item', 'oras-tickets' ) ); ?></h3>
				<?php self::render_report_card_contact( self::row_scalar( $row, 'email' ), '' ); ?>
				<?php self::render_report_card_badges( array_filter( array( ucfirst( self::row_scalar( $row, 'source_type' ) ), $severity, $status ) ) ); ?>
				<?php
				self::render_report_card_fields(
					array(
						__( 'Person', 'oras-tickets' )  => self::row_scalar( $row, 'attendee_name' ),
						__( 'Answer', 'oras-tickets' )  => $answer,
						__( 'Reason', 'oras-tickets' )  => self::row_scalar( $row, 'rule_label' ),
						__( 'Created', 'oras-tickets' ) => self::row_scalar( $row, 'created_at' ),
					)
				);
				?>
			</div>
			<div class="oras-board-reports__report-card-actions">
				<?php self::render_attention_row_actions( $row ); ?>
			</div>
		</article>
		<?php
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_communication_history_card( array $row ): void {
		$detail_url = self::build_communication_detail_url( absint( $row['id'] ?? 0 ) );
		?>
		<article class="oras-board-reports__report-card oras-board-reports__communication-card">
			<div class="oras-board-reports__report-card-main">
				<h3 class="oras-board-reports__report-card-title"><?php echo esc_html( self::row_scalar( $row, 'email_subject' ) ); ?></h3>
				<?php self::render_report_card_badges( array_filter( array( Communication_Recipients::get_segment_label( self::row_scalar( $row, 'recipient_segment' ) ), self::row_scalar( $row, 'send_status' ) ) ) ); ?>
				<?php
				self::render_report_card_fields(
					array(
						__( 'Date Sent', 'oras-tickets' )       => self::row_scalar( $row, 'sent_at' ),
						__( 'Sent By', 'oras-tickets' )         => trim( self::row_scalar( $row, 'sender_display_name' ) . ' <' . self::row_scalar( $row, 'sender_email' ) . '>' ),
						__( 'Recipient Count', 'oras-tickets' ) => self::row_scalar( $row, 'recipient_count' ),
					)
				);
				?>
			</div>
			<div class="oras-board-reports__report-card-actions">
				<a class="button button-secondary" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html__( 'View Details', 'oras-tickets' ); ?></a>
			</div>
		</article>
		<?php
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_rsvp_card( array $row ): void {
		$name = self::row_scalar( $row, 'name' );
		$email = self::row_scalar( $row, 'email' );
		$phone = self::row_scalar( $row, 'phone' );
		$rsvp_status = self::get_rsvp_status_label( self::row_scalar( $row, 'order_status' ) );
		$attendance_label = self::row_scalar( $row, 'attendance_label' );
		$approval_status = Event_RSVP::normalize_approval_status( self::row_scalar( $row, 'approval_status' ), Event_RSVP::APPROVAL_STATUS_APPROVED );
		$approval_label = self::row_scalar( $row, 'approval_label' );
		$approved_by = self::row_scalar( $row, 'approved_by' );
		$approved_at = self::row_scalar( $row, 'approved_at' );
		$source = self::row_scalar( $row, 'source' );
		$note = self::row_scalar( $row, 'note' );
		?>
		<article class="oras-board-reports__rsvp-card">
			<div class="oras-board-reports__rsvp-card-main">
				<h3 class="oras-board-reports__rsvp-card-name"><?php echo esc_html( '' !== $name ? $name : __( 'Unnamed attendee', 'oras-tickets' ) ); ?></h3>
				<p class="oras-board-reports__rsvp-card-contact">
					<?php if ( '' !== $email ) : ?>
						<span><?php echo esc_html( $email ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $phone ) : ?>
						<span><?php echo esc_html( $phone ); ?></span>
					<?php endif; ?>
				</p>
				<div class="oras-board-reports__rsvp-card-badges" aria-label="<?php echo esc_attr__( 'RSVP status summary', 'oras-tickets' ); ?>">
					<span class="oras-board-reports__rsvp-badge"><?php echo esc_html( $rsvp_status ); ?></span>
					<span class="oras-board-reports__rsvp-badge"><?php echo esc_html( $attendance_label ); ?></span>
					<span class="oras-board-reports__rsvp-badge oras-board-reports__rsvp-badge--<?php echo esc_attr( sanitize_html_class( $approval_status ) ); ?>"><?php echo esc_html( $approval_label ); ?></span>
					<?php if ( '' !== $source ) : ?>
						<span class="oras-board-reports__rsvp-badge"><?php echo esc_html( $source ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $approved_by || '' !== $approved_at ) : ?>
					<p class="oras-board-reports__rsvp-card-meta">
						<?php if ( '' !== $approved_at ) : ?>
							<?php
							printf(
								/* translators: 1: approver name, 2: approved date. */
								esc_html__( 'Approved by %1$s on %2$s', 'oras-tickets' ),
								esc_html( '' !== $approved_by ? $approved_by : __( 'Unknown', 'oras-tickets' ) ),
								esc_html( $approved_at )
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: approver name. */
								esc_html__( 'Approved by %s', 'oras-tickets' ),
								esc_html( $approved_by )
							);
							?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $note ) : ?>
					<details class="oras-board-reports__rsvp-card-note">
						<summary><?php echo esc_html__( 'View note', 'oras-tickets' ); ?></summary>
						<p><?php echo esc_html( $note ); ?></p>
					</details>
				<?php endif; ?>
				<?php self::render_question_answers_summary( $row ); ?>
			</div>
			<div class="oras-board-reports__rsvp-card-actions" aria-label="<?php echo esc_attr__( 'RSVP approval actions', 'oras-tickets' ); ?>">
				<?php self::render_rsvp_row_actions( $row ); ?>
			</div>
		</article>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function render_rsvp_summary_bar( array $rows ): void {
		$counts = array(
			'pending_virtual'  => 0,
			'approved_virtual' => 0,
			'onsite'           => 0,
			'rejected'         => 0,
			'total'            => count( $rows ),
		);

		foreach ( $rows as $row ) {
			$attendance_type = strtolower( self::row_scalar( $row, 'attendance_type' ) );
			$approval_status = Event_RSVP::normalize_approval_status( self::row_scalar( $row, 'approval_status' ), Event_RSVP::APPROVAL_STATUS_APPROVED );

			if ( Ticket::ATTENDANCE_MODE_ONSITE === $attendance_type ) {
				++$counts['onsite'];
			}
			if ( Ticket::ATTENDANCE_MODE_VIRTUAL === $attendance_type && Event_RSVP::APPROVAL_STATUS_PENDING === $approval_status ) {
				++$counts['pending_virtual'];
			}
			if ( Ticket::ATTENDANCE_MODE_VIRTUAL === $attendance_type && Event_RSVP::APPROVAL_STATUS_APPROVED === $approval_status ) {
				++$counts['approved_virtual'];
			}
			if ( Event_RSVP::APPROVAL_STATUS_REJECTED === $approval_status ) {
				++$counts['rejected'];
			}
		}

		$items = array(
			__( 'Total Shown', 'oras-tickets' )      => $counts['total'],
			__( 'Pending Virtual', 'oras-tickets' )  => $counts['pending_virtual'],
			__( 'Approved Virtual', 'oras-tickets' ) => $counts['approved_virtual'],
			__( 'On-site', 'oras-tickets' )          => $counts['onsite'],
			__( 'Rejected', 'oras-tickets' )         => $counts['rejected'],
		);
		?>
		<div class="oras-board-reports__rsvp-summary" aria-label="<?php echo esc_attr__( 'RSVP result summary', 'oras-tickets' ); ?>">
			<?php foreach ( $items as $label => $value ) : ?>
				<div class="oras-board-reports__rsvp-summary-item">
					<span class="oras-board-reports__rsvp-summary-label"><?php echo esc_html( $label ); ?></span>
					<span class="oras-board-reports__rsvp-summary-value"><?php echo esc_html( (string) $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_rsvp_row_actions( array $row ): void {
		$event_id = absint( $row['event_id'] ?? 0 );
		$user_id = absint( $row['user_id'] ?? 0 );
		$approval_status = Event_RSVP::normalize_approval_status( self::row_scalar( $row, 'approval_status' ), Event_RSVP::APPROVAL_STATUS_APPROVED );
		$rejection_reason = self::row_scalar( $row, 'rejection_reason' );

		?>
		<div class="oras-board-reports__inline-actions">
			<details>
				<summary><?php echo esc_html__( 'View Details', 'oras-tickets' ); ?></summary>
				<p><strong><?php echo esc_html__( 'Attendance mode:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'attendance_label' ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approval status:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'approval_label' ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approved by:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'approved_by' ) ); ?></p>
				<p><strong><?php echo esc_html__( 'Approved date:', 'oras-tickets' ); ?></strong> <?php echo esc_html( self::row_scalar( $row, 'approved_at' ) ); ?></p>
					<?php if ( '' !== $rejection_reason ) : ?>
						<p><strong><?php echo esc_html__( 'Rejection reason:', 'oras-tickets' ); ?></strong> <?php echo esc_html( $rejection_reason ); ?></p>
					<?php endif; ?>
					<p><strong><?php echo esc_html__( 'User ID:', 'oras-tickets' ); ?></strong> <?php echo esc_html( (string) $user_id ); ?></p>
				</details>
			<?php if ( current_user_can( 'oras_tickets_manage_rsvps' ) && $event_id > 0 && $user_id > 0 ) : // phpcs:ignore WordPress.WP.Capabilities.Unknown ?>
				<?php if ( Event_RSVP::APPROVAL_STATUS_APPROVED !== $approval_status ) : ?>
					<?php self::render_rsvp_approval_action_form( $event_id, $user_id, Event_RSVP::APPROVAL_STATUS_APPROVED, __( 'Approve', 'oras-tickets' ) ); ?>
				<?php endif; ?>
				<?php if ( Event_RSVP::APPROVAL_STATUS_REJECTED !== $approval_status ) : ?>
					<?php self::render_rsvp_approval_action_form( $event_id, $user_id, Event_RSVP::APPROVAL_STATUS_REJECTED, __( 'Reject', 'oras-tickets' ), true ); ?>
				<?php endif; ?>
				<?php if ( Event_RSVP::APPROVAL_STATUS_PENDING !== $approval_status ) : ?>
					<?php self::render_rsvp_approval_action_form( $event_id, $user_id, Event_RSVP::APPROVAL_STATUS_PENDING, __( 'Return to Pending', 'oras-tickets' ) ); ?>
				<?php else : ?>
					<button type="button" class="button button-secondary" disabled><?php echo esc_html__( 'Return to Pending', 'oras-tickets' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_rsvp_approval_action_form( int $event_id, int $user_id, string $approval_status, string $label, bool $include_reason = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::APPROVAL_NONCE_ACTION, 'oras_board_rsvp_approval_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::APPROVAL_ACTION ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<input type="hidden" name="approval_status" value="<?php echo esc_attr( $approval_status ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_current_url() ); ?>" />
			<?php if ( $include_reason ) : ?>
				<input type="hidden" name="rejection_reason" value="" />
			<?php endif; ?>
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_rsvp_approval_notice(): void {
		$status = isset( $_GET['oras_rsvp_approval_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_rsvp_approval_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$message = 'updated' === $status
			? __( 'RSVP approval status updated.', 'oras-tickets' )
			: __( 'Unable to update RSVP approval status.', 'oras-tickets' );
		?>
		<p class="oras-board-reports__notice"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	private static function render_waitlist_notice(): void {
		$status = isset( $_GET['oras_waitlist_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_waitlist_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$message = 'updated' === $status
			? __( 'Waitlist action completed.', 'oras-tickets' )
			: __( 'Unable to complete waitlist action.', 'oras-tickets' );
		?>
		<p class="oras-board-reports__notice"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	private static function render_attention_notice(): void {
		$status = isset( $_GET['oras_attention_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_attention_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$message = 'updated' === $status
			? __( 'Attention item updated.', 'oras-tickets' )
			: __( 'Unable to update attention item.', 'oras-tickets' );
		?>
		<p class="oras-board-reports__notice"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	public static function handle_update_rsvp_approval(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_manage_rsvps' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if (
			! isset( $_POST['oras_board_rsvp_approval_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_board_rsvp_approval_nonce'] ) ), self::APPROVAL_NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$approval_status = isset( $_POST['approval_status'] ) ? sanitize_key( wp_unslash( $_POST['approval_status'] ) ) : '';
		$rejection_reason = isset( $_POST['rejection_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rejection_reason'] ) ) : '';
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::get_current_dashboard_url( self::TAB_RSVPS, $event_id );

		$result = Event_RSVP::update_approval_status( $event_id, $user_id, $approval_status, $rejection_reason );
		$status = is_wp_error( $result ) ? 'failed' : 'updated';

		wp_safe_redirect( add_query_arg( 'oras_rsvp_approval_status', $status, $redirect ) );
		exit;
	}

	public static function handle_update_waitlist(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_manage_rsvps' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if (
			! isset( $_POST['oras_board_waitlist_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_board_waitlist_nonce'] ) ), self::WAITLIST_NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$waitlist_action = isset( $_POST['waitlist_action'] ) && is_scalar( $_POST['waitlist_action'] ) ? sanitize_key( wp_unslash( $_POST['waitlist_action'] ) ) : '';
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::get_current_dashboard_url( self::TAB_RSVPS, $event_id );
		$actor_user_id = get_current_user_id();

		if ( 'promote' === $waitlist_action ) {
			$result = Event_RSVP::promote_waitlist_user( $event_id, $user_id, $actor_user_id, 'board-waitlist', true );
		} elseif ( 'open_seat_promote' === $waitlist_action ) {
			$result = Event_RSVP::open_seat_and_promote_waitlist_user( $event_id, $user_id, $actor_user_id, 'board-open-seat' );
		} elseif ( 'remove' === $waitlist_action ) {
			$result = Event_RSVP::cancel_rsvp_attendee( $event_id, $user_id, $actor_user_id, 'board-waitlist-remove', false );
		} else {
			$result = new \WP_Error( 'oras_waitlist_invalid_action', __( 'Invalid waitlist action.', 'oras-tickets' ) );
		}

		$status = is_wp_error( $result ) ? 'failed' : 'updated';

		wp_safe_redirect( add_query_arg( 'oras_waitlist_status', $status, $redirect ) );
		exit;
	}

	public static function handle_update_attention_status(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_manage_rsvps' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if (
			! isset( $_POST['oras_board_attention_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_board_attention_nonce'] ) ), self::ATTENTION_NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$id = isset( $_POST['attention_id'] ) ? absint( wp_unslash( $_POST['attention_id'] ) ) : 0;
		$status = isset( $_POST['attention_status'] ) && is_scalar( $_POST['attention_status'] ) ? sanitize_key( wp_unslash( $_POST['attention_status'] ) ) : '';
		$note = isset( $_POST['attention_note'] ) && is_scalar( $_POST['attention_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attention_note'] ) ) : '';
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::get_current_dashboard_url( self::TAB_ATTENTION, 0 );

		$updated = Event_Question_Attention_Store::update_status( $id, $status, get_current_user_id(), $note );

		wp_safe_redirect( add_query_arg( 'oras_attention_status', $updated ? 'updated' : 'failed', $redirect ) );
		exit;
	}

	public static function handle_export_csv(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_export_reports' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$rows = $service->get_rows( $filters['type'], $filters );
		$filename = 'oras-board-' . sanitize_key( $filters['type'] ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		( new Board_Report_Exporter() )->output_csv( $rows, $filename );
		exit;
	}

	public static function handle_export_spreadsheet(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_export_reports' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$rows = $service->get_rows( $filters['type'], $filters );
		$filename = 'oras-board-' . sanitize_key( $filters['type'] ) . '-' . gmdate( 'Y-m-d' ) . '.xls';

		( new Board_Report_Exporter() )->output_spreadsheet( $rows, $filename );
		exit;
	}

	public static function handle_export_pdf(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_export_reports' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$service = new Board_Report_Service();
		$filters = self::get_filters_from_request();
		$rows = $service->get_rows( $filters['type'], $filters );
		$filename = 'oras-board-' . sanitize_key( $filters['type'] ) . '-' . gmdate( 'Y-m-d' ) . '.pdf';

		( new Board_Report_Exporter() )->output_pdf( $rows, $filename );
		exit;
	}

	public static function handle_send_communication(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_send_notifications' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Not allowed.', 'oras-tickets' ), '', array( 'response' => 403 ) );
		}

		if (
			! isset( $_POST['oras_board_communication_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_board_communication_nonce'] ) ), self::COMMUNICATION_NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Invalid request.', 'oras-tickets' ), '', array( 'response' => 400 ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$segment = isset( $_POST['recipient_segment'] ) ? Communication_Recipients::normalize_segment( sanitize_key( wp_unslash( $_POST['recipient_segment'] ) ) ) : Communication_Recipients::SEGMENT_ALL_ATTENDEES;
		$subject = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		$message = isset( $_POST['email_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_message'] ) ) : '';
		$confirmed = isset( $_POST['confirm_send'] ) && '1' === (string) wp_unslash( $_POST['confirm_send'] );
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::get_current_dashboard_url( self::TAB_COMMUNICATIONS, $event_id, $segment );

		if ( $event_id <= 0 || '' === $subject || '' === $message || ! $confirmed ) {
			self::redirect_communication_result( $redirect, 'failed', 0 );
		}

		$sender = wp_get_current_user();
		$sender_user_id = get_current_user_id();
		$sender_display_name = (string) $sender->display_name;
		$sender_email = (string) $sender->user_email;
		$recipients = ( new Communication_Recipients() )->resolve( $event_id, $segment );
		$recipient_count = count( $recipients );

		$body = self::build_communication_email_body( $message, $sender, $event_id, $segment );
		if ( $recipient_count <= 0 ) {
			$status = 'failed';
		} else {
			$status = 'queued';
		}

		$log_id = Communication_Log_Store::insert(
			array(
				'event_id'               => $event_id,
				'sender_user_id'         => $sender_user_id,
				'sender_display_name'    => $sender_display_name,
				'sender_email'           => $sender_email,
				'recipient_segment'      => $segment,
				'recipient_count'        => $recipient_count,
				'email_subject'          => $subject,
				'email_body_snapshot'    => $message,
				'sent_at'                => current_time( 'mysql', true ),
				'send_status'            => $status,
				'failed_recipient_count' => 0,
				'related_action_type'    => self::get_related_action_type( $segment ),
			)
		);
		if ( $log_id > 0 && 'queued' === $status && ! Communication_Queue::enqueue( $log_id, $recipients, $subject, $body ) ) {
			Communication_Log_Store::update_delivery( $log_id, 'failed', 0, $recipient_count );
			$status = 'failed';
		}

		self::redirect_communication_result( $redirect, $status, $log_id );
	}

	private static function get_active_tab(): string {
		$tab = isset( $_GET['oras_board_tab'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_tab'] ) ) : self::TAB_OVERVIEW;
		if ( self::TAB_STATISTICS === $tab ) {
			return self::TAB_OVERVIEW;
		}

		$tabs = self::get_dashboard_tabs();

		return isset( $tabs[ $tab ] ) ? $tab : self::TAB_OVERVIEW;
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_dashboard_tabs(): array {
		return array(
			self::TAB_OVERVIEW      => __( 'Event Overview', 'oras-tickets' ),
			self::TAB_TICKET_SALES  => __( 'Sales', 'oras-tickets' ),
			self::TAB_RSVPS         => __( 'RSVP Management', 'oras-tickets' ),
			self::TAB_ATTENTION     => __( 'Attention Needed', 'oras-tickets' ),
			self::TAB_COMMUNICATIONS => __( 'Communications', 'oras-tickets' ),
			self::TAB_ATTENDEES     => __( 'Roster', 'oras-tickets' ),
		);
	}

	private static function render_tabs( string $active_tab ): void {
		?>
		<nav class="oras-board-reports__tabs" aria-label="<?php echo esc_attr__( 'Event Management Dashboard sections', 'oras-tickets' ); ?>">
			<?php foreach ( self::get_dashboard_tabs() as $tab => $label ) : ?>
				<a
					class="oras-board-reports__tab"
					href="<?php echo esc_url( self::build_tab_url( $tab ) ); ?>"
					<?php if ( $active_tab === $tab ) : ?>
						aria-current="page"
					<?php endif; ?>
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private static function render_placeholder_tab( string $active_tab ): void {
		$placeholders = array(
			self::TAB_OVERVIEW       => array(
				'title' => __( 'Event Overview', 'oras-tickets' ),
				'body'  => __( 'Select an event to review sales, RSVP status, roster, and communications from one place.', 'oras-tickets' ),
			),
			self::TAB_RSVPS          => array(
				'title' => __( 'RSVP Management', 'oras-tickets' ),
				'body'  => __( 'RSVP management will be added in Phase 1C.', 'oras-tickets' ),
			),
			self::TAB_COMMUNICATIONS => array(
				'title' => __( 'Communications', 'oras-tickets' ),
				'body'  => __( 'Communications tools will be added in Phase 1D.', 'oras-tickets' ),
			),
			self::TAB_ATTENTION      => array(
				'title' => __( 'Attention Needed', 'oras-tickets' ),
				'body'  => __( 'Review event question answers that matched configured attention rules.', 'oras-tickets' ),
			),
			self::TAB_ATTENDEES      => array(
				'title' => __( 'Roster', 'oras-tickets' ),
				'body'  => __( 'Roster management will be added in Phase 1C.', 'oras-tickets' ),
			),
		);
		$placeholder = $placeholders[ $active_tab ] ?? $placeholders[ self::TAB_OVERVIEW ];
		?>
		<section class="oras-board-reports__placeholder" aria-live="polite">
			<h3><?php echo esc_html( $placeholder['title'] ); ?></h3>
			<p><?php echo esc_html( $placeholder['body'] ); ?></p>
		</section>
		<?php
	}

	private static function build_tab_url( string $tab ): string {
		$args = $_GET;
		$args['oras_board_tab'] = $tab;

		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				unset( $args[ $key ] );
				continue;
			}

			$args[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		return add_query_arg( $args, self::get_form_action_url() );
	}

	private static function get_communication_segment_from_request(): string {
		$segment = isset( $_GET['oras_comm_segment'] ) ? sanitize_key( wp_unslash( $_GET['oras_comm_segment'] ) ) : Communication_Recipients::SEGMENT_ALL_ATTENDEES;

		return Communication_Recipients::normalize_segment( $segment );
	}

	/**
	 * @return array{event_id:int,status:string,severity:string,source_type:string,search:string,limit:int}
	 */
	private static function get_attention_filters( int $event_id ): array {
		$status = isset( $_GET['oras_attention_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_attention_status'] ) ) : Event_Question_Attention_Store::STATUS_OPEN;
		if ( ! isset( self::get_attention_status_options()[ $status ] ) ) {
			$status = Event_Question_Attention_Store::STATUS_OPEN;
		}

		$severity = isset( $_GET['oras_attention_severity'] ) ? sanitize_key( wp_unslash( $_GET['oras_attention_severity'] ) ) : '';
		if ( '' !== $severity && ! array_key_exists( $severity, \ORAS\Tickets\Event_Questions::attention_severity_options() ) ) {
			$severity = '';
		}

		$source_type = isset( $_GET['oras_attention_source_type'] ) ? sanitize_key( wp_unslash( $_GET['oras_attention_source_type'] ) ) : '';
		if ( ! in_array( $source_type, array( 'rsvp', 'ticket' ), true ) ) {
			$source_type = '';
		}

		return array(
			'event_id'     => max( 0, $event_id ),
			'status'       => $status,
			'severity'     => $severity,
			'source_type'  => $source_type,
			'search'       => isset( $_GET['oras_attention_search'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_attention_search'] ) ) : '',
			'limit'        => 250,
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_attention_status_options(): array {
		return array(
			Event_Question_Attention_Store::STATUS_OPEN      => __( 'Open', 'oras-tickets' ),
			Event_Question_Attention_Store::STATUS_REVIEWED  => __( 'Reviewed', 'oras-tickets' ),
			Event_Question_Attention_Store::STATUS_RESOLVED  => __( 'Resolved', 'oras-tickets' ),
			Event_Question_Attention_Store::STATUS_DISMISSED => __( 'Dismissed', 'oras-tickets' ),
		);
	}

	private static function get_attention_status_label( string $status ): string {
		$options = self::get_attention_status_options();
		return $options[ $status ] ?? $status;
	}

	private static function get_attention_severity_label( string $severity ): string {
		$options = \ORAS\Tickets\Event_Questions::attention_severity_options();
		return $options[ $severity ] ?? $severity;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_communication_history_filters( int $event_id ): array {
		$segment = isset( $_GET['oras_comm_history_segment'] ) ? sanitize_key( wp_unslash( $_GET['oras_comm_history_segment'] ) ) : '';
		$after = isset( $_GET['oras_comm_after'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_comm_after'] ) ) : '';
		$before = isset( $_GET['oras_comm_before'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_comm_before'] ) ) : '';

		return array(
			'event_id'           => $event_id,
			'sender_user_id'     => isset( $_GET['oras_comm_sender_id'] ) ? absint( $_GET['oras_comm_sender_id'] ) : 0,
			'recipient_segment'  => isset( Communication_Recipients::get_segments()[ $segment ] ) ? $segment : '',
			'search'             => isset( $_GET['oras_comm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_comm_search'] ) ) : '',
			'after'              => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ? $after : '',
			'before'             => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ? $before : '',
			'limit'              => 50,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function get_communication_detail(): ?array {
		$detail_id = isset( $_GET['oras_comm_detail'] ) ? absint( $_GET['oras_comm_detail'] ) : 0;
		if ( $detail_id <= 0 ) {
			return null;
		}

		return Communication_Log_Store::get( $detail_id );
	}

	private static function get_communication_count_for_event( int $event_id ): int {
		if ( $event_id <= 0 ) {
			return 0;
		}

		return count(
			Communication_Log_Store::query(
				array(
					'event_id' => $event_id,
					'limit'    => 500,
				)
			)
		);
	}

	private static function render_communication_notice(): void {
		$status = isset( $_GET['oras_comm_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_comm_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$message = __( 'Communication send attempt was recorded.', 'oras-tickets' );
		if ( 'sent' === $status ) {
			$message = __( 'Communication sent and logged.', 'oras-tickets' );
		} elseif ( 'queued' === $status ) {
			$message = __( 'Communication queued for background delivery. Progress is shown in Communication History.', 'oras-tickets' );
		} elseif ( 'partial' === $status ) {
			$message = __( 'Communication partially sent; failures were logged.', 'oras-tickets' );
		} elseif ( 'failed' === $status ) {
			$message = __( 'Communication was not sent; the failed attempt was logged when possible.', 'oras-tickets' );
		}

		echo '<div class="oras-board-reports__notice">' . esc_html( $message ) . '</div>';
	}

	/**
	 * @param array<int,\WP_Post> $events
	 * @param array<string,mixed> $filters
	 */
	private static function render_communication_history_filters( int $page_id, array $events, array $filters ): void {
		?>
		<form class="oras-board-reports__filters" method="get" action="<?php echo esc_url( self::get_form_action_url() ); ?>">
			<?php if ( $page_id > 0 ) : ?>
				<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>" />
			<?php endif; ?>
			<input type="hidden" name="oras_board_tab" value="<?php echo esc_attr( self::TAB_COMMUNICATIONS ); ?>" />
			<?php self::render_event_hidden_input( absint( $filters['event_id'] ?? 0 ) ); ?>
			<label>
				<?php echo esc_html__( 'Sender User ID', 'oras-tickets' ); ?>
				<input type="number" min="1" name="oras_comm_sender_id" value="<?php echo esc_attr( (string) absint( $filters['sender_user_id'] ?? 0 ) ); ?>" />
			</label>
			<label>
				<?php echo esc_html__( 'Subject Search', 'oras-tickets' ); ?>
				<input type="search" name="oras_comm_search" value="<?php echo esc_attr( (string) ( $filters['search'] ?? '' ) ); ?>" />
			</label>
			<label>
				<?php echo esc_html__( 'Recipient Segment', 'oras-tickets' ); ?>
				<select name="oras_comm_history_segment">
					<option value=""><?php echo esc_html__( 'All', 'oras-tickets' ); ?></option>
					<?php foreach ( Communication_Recipients::get_segments() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) ( $filters['recipient_segment'] ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<?php echo esc_html__( 'After', 'oras-tickets' ); ?>
				<input type="date" name="oras_comm_after" value="<?php echo esc_attr( (string) ( $filters['after'] ?? '' ) ); ?>" />
			</label>
			<label>
				<?php echo esc_html__( 'Before', 'oras-tickets' ); ?>
				<input type="date" name="oras_comm_before" value="<?php echo esc_attr( (string) ( $filters['before'] ?? '' ) ); ?>" />
			</label>
			<div class="oras-board-reports__actions">
				<button class="button button-primary" type="submit"><?php echo esc_html__( 'Filter History', 'oras-tickets' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function render_communication_history_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<div class="oras-board-reports__empty">' . esc_html__( 'No communication history found.', 'oras-tickets' ) . '</div>';
			return;
		}
		?>
		<div class="oras-board-reports__report-list oras-board-reports__communication-history-list">
			<?php foreach ( $rows as $row ) : ?>
				<?php self::render_communication_history_card( $row ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function get_current_dashboard_url( string $tab, int $event_id, string $segment = '' ): string {
		$args = array(
			'oras_board_tab'      => $tab,
			'oras_board_event_id' => $event_id,
		);
		if ( '' !== $segment ) {
			$args['oras_comm_segment'] = $segment;
		}

		return add_query_arg( $args, self::get_form_action_url() );
	}

	private static function build_communication_detail_url( int $detail_id ): string {
		$args = $_GET;
		$args['oras_board_tab'] = self::TAB_COMMUNICATIONS;
		$args['oras_comm_detail'] = $detail_id;

		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				unset( $args[ $key ] );
				continue;
			}

			$args[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		return add_query_arg( $args, self::get_form_action_url() );
	}

	private static function redirect_communication_result( string $redirect, string $status, int $log_id ): void {
		$args = array(
			'oras_comm_status' => sanitize_key( $status ),
		);
		if ( $log_id > 0 ) {
			$args['oras_comm_log'] = $log_id;
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	private static function build_communication_email_body( string $message, \WP_User $sender, int $event_id, string $segment ): string {
		$event_title = get_the_title( $event_id );
		if ( ! is_string( $event_title ) || '' === trim( $event_title ) ) {
			$event_title = __( 'ORAS Event', 'oras-tickets' );
		}
		$event_title = wp_specialchars_decode( trim( $event_title ), ENT_QUOTES );

		$event_url = get_permalink( $event_id );
		if ( ! is_string( $event_url ) || '' === $event_url ) {
			$event_url = '';
		}

		$segment_label = Communication_Recipients::get_segment_label( $segment );
		$brand = __( 'Oil Region Astronomical Society', 'oras-tickets' );
		$intro = sprintf(
			/* translators: %s: event title */
			__( 'You are receiving this message because you are connected to %s.', 'oras-tickets' ),
			$event_title
		);

		$show_sender = (bool) apply_filters( 'oras_tickets_show_sender_name_in_email_footer', false, $sender, $event_id );
		$sender_note = '';
		if ( $show_sender && '' !== (string) $sender->display_name ) {
			$sender_note = sprintf(
				/* translators: %s: sender display name. */
				__( 'Sent by: %s', 'oras-tickets' ),
				(string) $sender->display_name
			);
		}

		$html = '<!doctype html><html><body style="margin:0;padding:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif;line-height:1.5;">';
		$html .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html( wp_strip_all_tags( $intro ) ) . '</div>';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7;margin:0;padding:28px 12px;"><tr><td align="center">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d8dee8;box-shadow:0 12px 36px rgba(15,23,42,0.12);">';
		$html .= '<tr><td style="background:#0b1220;padding:28px 32px;color:#ffffff;">';
		$html .= '<div style="font-size:28px;font-weight:800;letter-spacing:0.18em;line-height:1;">ORAS</div>';
		$html .= '<div style="font-size:14px;color:#cbd5e1;margin-top:8px;">' . esc_html( $brand ) . '</div>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:32px;">';
		$html .= '<h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;color:#0f172a;">' . esc_html__( 'Event communication', 'oras-tickets' ) . '</h1>';
		$html .= '<p style="margin:0 0 24px;font-size:16px;color:#334155;">' . esc_html( $intro ) . '</p>';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">';
		$html .= self::communication_email_detail_row( __( 'Event', 'oras-tickets' ), $event_title, $event_url );
		$html .= self::communication_email_detail_row( __( 'Audience', 'oras-tickets' ), $segment_label );
		$html .= '</table>';
		$html .= '<div style="margin:0 0 22px;padding:18px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;">';
		$html .= '<h2 style="margin:0 0 10px;font-size:16px;color:#0f172a;">' . esc_html__( 'Message from ORAS', 'oras-tickets' ) . '</h2>';
		$html .= '<div style="font-size:15px;color:#334155;">' . nl2br( esc_html( trim( $message ) ) ) . '</div>';
		$html .= '</div>';

		if ( '' !== $event_url ) {
			$html .= '<div style="margin:28px 0 8px;">';
			$html .= '<a href="' . esc_url( $event_url ) . '" style="display:inline-block;margin:0 10px 10px 0;padding:13px 18px;border-radius:10px;background:#1e3a8a;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">' . esc_html__( 'View Event', 'oras-tickets' ) . '</a>';
			$html .= '</div>';
		}

		if ( '' !== $sender_note ) {
			$html .= '<p style="margin:22px 0 0;font-size:13px;color:#64748b;">' . esc_html( $sender_note ) . '</p>';
		}

		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;">';
		$html .= esc_html__( 'This message was sent by Oil Region Astronomical Society regarding an ORAS event.', 'oras-tickets' );
		$html .= '</td></tr>';
		$html .= '</table></td></tr></table></body></html>';

		return $html;
	}

	private static function communication_email_detail_row( string $label, string $value, string $url = '' ): string {
		if ( '' === trim( $label ) || '' === trim( $value ) ) {
			return '';
		}

		$html = '<tr>';
		$html .= '<td style="width:34%;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">' . esc_html( $label ) . '</td>';
		$html .= '<td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:15px;">';
		if ( '' !== $url ) {
			$html .= '<a href="' . esc_url( $url ) . '" style="color:#1d4ed8;text-decoration:underline;word-break:break-word;">' . esc_html( $value ) . '</a>';
		} else {
			$html .= esc_html( $value );
		}
		$html .= '</td></tr>';

		return $html;
	}

	private static function get_related_action_type( string $segment ): string {
		if ( Communication_Recipients::SEGMENT_EVENT_CANCELLATION === $segment ) {
			return 'event_cancellation';
		}

		if ( Communication_Recipients::SEGMENT_EVENT_UPDATE === $segment ) {
			return 'event_update';
		}

		return 'mass_email';
	}

	/**
	 * @return array{type:string,event_id:int,after:string,before:string,search:string,status:string,attendance_type:string,approval_status:string,attendee_source:string,ticket_status:string,rsvp_status:string,page:int,per_page:int}
	 */
	private static function get_filters_from_request(): array {
		$type = isset( $_GET['oras_board_report_type'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_report_type'] ) ) : Board_Report_Service::TYPE_TICKETS;
		$after = isset( $_GET['oras_board_after'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_after'] ) ) : '';
		$before = isset( $_GET['oras_board_before'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_before'] ) ) : '';
		$attendance_type = isset( $_GET['oras_board_attendance_type'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_attendance_type'] ) ) : 'all';
		if ( ! isset( self::get_attendance_type_options()[ $attendance_type ] ) ) {
			$attendance_type = 'all';
		}
		$approval_status = isset( $_GET['oras_board_approval_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_approval_status'] ) ) : 'all';
		if ( ! isset( self::get_approval_status_options()[ $approval_status ] ) ) {
			$approval_status = 'all';
		}
		$attendee_source = isset( $_GET['oras_board_attendee_source'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_attendee_source'] ) ) : 'all';
		if ( ! isset( self::get_attendee_source_options()[ $attendee_source ] ) ) {
			$attendee_source = 'all';
		}
		$ticket_status = isset( $_GET['oras_board_ticket_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_ticket_status'] ) ) : 'all';
		if ( ! isset( self::get_status_options( Board_Report_Service::TYPE_TICKETS )[ $ticket_status ] ) ) {
			$ticket_status = 'all';
		}
		$rsvp_status = isset( $_GET['oras_board_rsvp_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_rsvp_status'] ) ) : 'all';
		if ( ! isset( self::get_status_options( Board_Report_Service::TYPE_RSVP )[ $rsvp_status ] ) ) {
			$rsvp_status = 'all';
		}

		return array(
			'type'            => $type,
			'event_id'        => isset( $_GET['oras_board_event_id'] ) ? absint( $_GET['oras_board_event_id'] ) : 0,
			'after'           => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ? $after : '',
			'before'          => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ? $before : '',
			'search'          => isset( $_GET['oras_board_search'] ) ? sanitize_text_field( wp_unslash( $_GET['oras_board_search'] ) ) : '',
			'status'          => isset( $_GET['oras_board_status'] ) ? sanitize_key( wp_unslash( $_GET['oras_board_status'] ) ) : 'all',
			'attendance_type' => $attendance_type,
			'approval_status' => $approval_status,
			'attendee_source' => $attendee_source,
			'ticket_status'   => $ticket_status,
			'rsvp_status'     => $rsvp_status,
			'page'            => isset( $_GET['oras_board_page'] ) ? max( 1, absint( $_GET['oras_board_page'] ) ) : 1,
			'per_page'        => isset( $_GET['oras_board_per_page'] ) && in_array( absint( $_GET['oras_board_per_page'] ), array( 25, 50, 100 ), true ) ? absint( $_GET['oras_board_per_page'] ) : 25,
		);
	}

	/**
	 * @param array<int,mixed>     $rows
	 * @param array<string,mixed> $filters
	 * @return array{rows:array<int,mixed>,page:int,per_page:int,total:int,total_pages:int}
	 */
	private static function paginate_rows( array $rows, array $filters ): array {
		$per_page = isset( $filters['per_page'] ) ? absint( $filters['per_page'] ) : 25;
		$per_page = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 25;
		$total = count( $rows );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( max( 1, absint( $filters['page'] ?? 1 ) ), $total_pages );

		return array(
			'rows'        => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	private static function render_page_size_field( int $per_page ): void {
		?>
		<label>
			<?php echo esc_html__( 'Rows', 'oras-tickets' ); ?>
			<select name="oras_board_per_page">
				<?php foreach ( array( 25, 50, 100 ) as $size ) : ?>
					<option value="<?php echo esc_attr( (string) $size ); ?>" <?php selected( $per_page, $size ); ?>><?php echo esc_html( (string) $size ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	/**
	 * @param array{page:int,per_page:int,total:int,total_pages:int} $page_data
	 */
	private static function render_pagination( array $page_data ): void {
		if ( $page_data['total'] <= 0 ) {
			return;
		}
		?>
		<nav class="oras-board-reports__pagination" aria-label="<?php echo esc_attr__( 'Report pages', 'oras-tickets' ); ?>">
			<span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d (%3$d records)', 'oras-tickets' ), $page_data['page'], $page_data['total_pages'], $page_data['total'] ) ); ?></span>
			<?php if ( $page_data['page'] > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'oras_board_page', $page_data['page'] - 1 ) ); ?>"><?php echo esc_html__( 'Previous', 'oras-tickets' ); ?></a>
			<?php endif; ?>
			<?php if ( $page_data['page'] < $page_data['total_pages'] ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'oras_board_page', $page_data['page'] + 1 ) ); ?>"><?php echo esc_html__( 'Next', 'oras-tickets' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private static function build_export_url( array $filters, string $format = 'csv' ): string {
		$action = 'oras_board_reports_export_csv';
		if ( 'spreadsheet' === $format ) {
			$action = 'oras_board_reports_export_spreadsheet';
		} elseif ( 'pdf' === $format ) {
			$action = 'oras_board_reports_export_pdf';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'                 => $action,
					'oras_board_report_type' => $filters['type'],
					'oras_board_event_id'    => $filters['event_id'],
					'oras_board_after'       => $filters['after'],
					'oras_board_before'      => $filters['before'],
					'oras_board_search'      => $filters['search'],
					'oras_board_status'      => $filters['status'],
					'oras_board_attendance_type' => $filters['attendance_type'],
					'oras_board_approval_status' => $filters['approval_status'],
					'oras_board_attendee_source' => $filters['attendee_source'],
					'oras_board_ticket_status' => $filters['ticket_status'],
					'oras_board_rsvp_status'  => $filters['rsvp_status'],
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_status_options( string $type ): array {
		if ( Board_Report_Service::TYPE_RSVP === $type ) {
			return array(
				'all'      => __( 'All RSVP rows', 'oras-tickets' ),
				'yes'      => __( 'RSVP Yes', 'oras-tickets' ),
				'waitlist' => __( 'Waitlist', 'oras-tickets' ),
			);
		}

		return array(
			'all'        => __( 'All order statuses', 'oras-tickets' ),
			'completed'  => __( 'Completed', 'oras-tickets' ),
			'processing' => __( 'Processing', 'oras-tickets' ),
			'on-hold'    => __( 'On hold', 'oras-tickets' ),
			'pending'    => __( 'Pending', 'oras-tickets' ),
			'refunded'   => __( 'Refunded', 'oras-tickets' ),
			'cancelled'  => __( 'Cancelled', 'oras-tickets' ),
			'failed'     => __( 'Failed', 'oras-tickets' ),
		);
	}

	/**
	 * @param array<int,\WP_Post> $events
	 */
	private static function render_event_filter( array $events, int $selected_event_id ): void {
		?>
		<label>
			<?php echo esc_html__( 'Event', 'oras-tickets' ); ?>
			<select name="oras_board_event_id">
				<?php foreach ( $events as $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php selected( $selected_event_id, (int) $event->ID ); ?>><?php echo esc_html( get_the_title( $event ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	private static function render_event_hidden_input( int $event_id ): void {
		?>
		<input type="hidden" name="oras_board_event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_attendee_source_options(): array {
		return array(
			'all'     => __( 'All', 'oras-tickets' ),
			'tickets' => __( 'Ticket Holders', 'oras-tickets' ),
			'rsvps'   => __( 'RSVPs', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_attendance_type_options(): array {
		return array(
			'all'                         => __( 'All', 'oras-tickets' ),
			Ticket::ATTENDANCE_MODE_ONSITE => __( 'On Site', 'oras-tickets' ),
			Ticket::ATTENDANCE_MODE_VIRTUAL => __( 'Virtual', 'oras-tickets' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_approval_status_options(): array {
		return array(
			'all'                             => __( 'All', 'oras-tickets' ),
			Event_RSVP::APPROVAL_STATUS_PENDING => __( 'Pending', 'oras-tickets' ),
			Event_RSVP::APPROVAL_STATUS_APPROVED => __( 'Approved', 'oras-tickets' ),
			Event_RSVP::APPROVAL_STATUS_REJECTED => __( 'Rejected', 'oras-tickets' ),
		);
	}

	private static function get_rsvp_status_label( string $status ): string {
		if ( 'waitlist' === $status ) {
			return __( 'Waitlist', 'oras-tickets' );
		}

		if ( 'yes' === $status ) {
			return __( 'RSVP Yes', 'oras-tickets' );
		}

		return '' !== $status ? $status : __( 'Unknown', 'oras-tickets' );
	}

	private static function format_status_suffix( string $status ): string {
		if ( '' === $status ) {
			return '';
		}

		return ' (' . $status . ')';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_question_answers_summary( array $row ): void {
		$answers = isset( $row['question_answers'] ) && is_array( $row['question_answers'] ) ? $row['question_answers'] : array();
		if ( empty( $answers ) ) {
			return;
		}

		echo '<details class="oras-board-reports__question-answers">';
		echo '<summary class="oras-board-reports__question-answers-summary">' . esc_html( self::format_question_answer_count( $answers ) ) . '</summary>';
		echo '<div class="oras-board-reports__question-answers-title">' . esc_html__( 'Event question answers', 'oras-tickets' ) . '</div>';
		echo '<dl>';
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}

			$label = isset( $answer['label'] ) && is_scalar( $answer['label'] ) ? sanitize_text_field( (string) $answer['label'] ) : '';
			$value = isset( $answer['display_value'] ) && is_scalar( $answer['display_value'] ) ? sanitize_text_field( (string) $answer['display_value'] ) : '';
			if ( '' === $label || '' === $value ) {
				continue;
			}

			echo '<dt>' . esc_html( $label ) . '</dt>';
			echo '<dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
		echo '</details>';
	}

	private static function render_report_card_contact( string $email, string $phone ): void {
		if ( '' === $email && '' === $phone ) {
			return;
		}

		echo '<p class="oras-board-reports__report-card-contact">';
		if ( '' !== $email ) {
			echo '<span>' . esc_html( $email ) . '</span>';
		}
		if ( '' !== $phone ) {
			echo '<span>' . esc_html( $phone ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * @param array<int,string> $badges
	 */
	private static function render_report_card_badges( array $badges ): void {
		$badges = array_values(
			array_filter(
				array_map( 'strval', $badges ),
				static function ( string $badge ): bool {
					return '' !== trim( $badge );
				}
			)
		);
		if ( empty( $badges ) ) {
			return;
		}

		echo '<div class="oras-board-reports__report-card-badges" aria-label="' . esc_attr__( 'Report row summary', 'oras-tickets' ) . '">';
		foreach ( $badges as $badge ) {
			echo '<span class="oras-board-reports__rsvp-badge">' . esc_html( $badge ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string,string> $fields
	 */
	private static function render_report_card_fields( array $fields ): void {
		$fields = array_filter(
			$fields,
			static function ( string $value ): bool {
				return '' !== trim( $value );
			}
		);
		if ( empty( $fields ) ) {
			return;
		}

		echo '<dl class="oras-board-reports__report-card-fields">';
		foreach ( $fields as $label => $value ) {
			echo '<div>';
			echo '<dt>' . esc_html( (string) $label ) . '</dt>';
			echo '<dd>' . esc_html( $value ) . '</dd>';
			echo '</div>';
		}
		echo '</dl>';
	}

	/**
	 * @param array<string,int> $items
	 */
	private static function render_compact_summary_bar( array $items, string $aria_label ): void {
		?>
		<div class="oras-board-reports__rsvp-summary" aria-label="<?php echo esc_attr( $aria_label ); ?>">
			<?php foreach ( $items as $label => $value ) : ?>
				<div class="oras-board-reports__rsvp-summary-item">
					<span class="oras-board-reports__rsvp-summary-label"><?php echo esc_html( $label ); ?></span>
					<span class="oras-board-reports__rsvp-summary-value"><?php echo esc_html( (string) $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param mixed $answers
	 */
	private static function format_question_answer_count( $answers ): string {
		if ( ! is_array( $answers ) ) {
			return '';
		}

		$count = 0;
		foreach ( $answers as $answer ) {
			if ( is_array( $answer ) ) {
				++$count;
			}
		}

		if ( 0 === $count ) {
			return '';
		}

		return sprintf(
			/* translators: %d: event question answer count */
			_n( '%d event question answer', '%d event question answers', $count, 'oras-tickets' ),
			$count
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function row_scalar( array $row, string $key ): string {
		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}

	private static function get_form_action_url(): string {
		$permalink = get_permalink( get_queried_object_id() );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			return $permalink;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';
		$path = strtok( $request_uri, '?' );
		if ( false === $path ) {
			$path = '/';
		}

		return home_url( $path );
	}

	private static function get_current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';

		if ( '' === $request_uri ) {
			return self::get_form_action_url();
		}

		return home_url( esc_url_raw( $request_uri ) );
	}

	private static function get_context_page_id(): int {
		$page_id = get_queried_object_id();
		if ( $page_id > 0 ) {
			return (int) $page_id;
		}

		global $post;
		if ( $post instanceof \WP_Post && $post->ID > 0 ) {
			return (int) $post->ID;
		}

		return 0;
	}
}
