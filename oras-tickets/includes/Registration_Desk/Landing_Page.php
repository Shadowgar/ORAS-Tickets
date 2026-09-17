<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Landing_Page {
	private const QUERY_VAR = 'oras_registration_desk';

	public static function register(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'render' ), 0 );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^registration-desk/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/** @param string[] $vars @return string[] */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public static function is_request(): bool {
		return '1' === (string) get_query_var( self::QUERY_VAR, '' );
	}

	public static function url(): string {
		return home_url( '/registration-desk/' );
	}

	public static function render(): void {
		if ( ! self::is_request() ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_use_registration_desk' ) ) {
			auth_redirect();
			exit;
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		echo '<!doctype html><html><head><meta name="robots" content="noindex,nofollow"><title>' . esc_html__( 'Registration Desk', 'oras-tickets' ) . '</title></head><body>';
		echo '<main><h1>' . esc_html__( 'Registration Desk', 'oras-tickets' ) . '</h1><p>' . esc_html__( 'Backend foundation placeholder. Final volunteer screens are not yet approved.', 'oras-tickets' ) . '</p></main>';
		echo '</body></html>';
		exit;
	}
}
