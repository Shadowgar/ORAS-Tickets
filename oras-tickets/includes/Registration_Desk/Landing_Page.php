<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Landing_Page {
	private const QUERY_VAR = 'oras_registration_desk';
	private const REWRITE_VERSION_OPTION = 'oras_registration_desk_rewrite_version';
	private const REWRITE_VERSION = 1;

	public static function register(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'render' ), 0 );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^registration-desk/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		if ( self::REWRITE_VERSION !== (int) get_option( self::REWRITE_VERSION_OPTION, 0 ) ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false );
		}
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
		return '' !== (string) get_option( 'permalink_structure', '' )
			? home_url( '/registration-desk/' )
			: add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
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
		wp_enqueue_style( 'oras-registration-desk', ORAS_TICKETS_URL . 'assets/registration-desk/desk.css', array(), ORAS_TICKETS_VERSION );
		wp_enqueue_script( 'oras-registration-desk', ORAS_TICKETS_URL . 'assets/registration-desk/desk.js', array(), ORAS_TICKETS_VERSION, true );
		wp_localize_script(
			'oras-registration-desk',
			'ORASRegistrationDesk',
			array(
				'restUrl' => untrailingslashit( rest_url( 'oras-tickets/v1/registration-desk' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'appUrl'  => self::url(),
			)
		);
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		echo '<!doctype html><html ';
		language_attributes();
		echo '><head>';
		echo '<meta charset="' . esc_attr( get_option( 'blog_charset', 'UTF-8' ) ) . '"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html__( 'Registration Desk', 'oras-tickets' ) . '</title>';
		wp_head();
		echo '</head><body class="oras-registration-desk-page">';
		echo '<div id="oras-registration-desk-root" class="desk-shell" aria-live="polite">';
		echo '<main class="desk-loading"><p>' . esc_html__( 'Loading Registration Desk…', 'oras-tickets' ) . '</p></main>';
		echo '</div><noscript><p>' . esc_html__( 'Registration Desk requires JavaScript.', 'oras-tickets' ) . '</p></noscript>';
		wp_footer();
		echo '</body></html>';
		exit;
	}
}
