<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Access {
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest_pre_dispatch' ), 1, 3 );
		add_action( 'admin_init', array( self::class, 'guard_admin' ), 1 );
		add_action( 'template_redirect', array( self::class, 'guard_frontend' ), -100 );
	}

	public static function is_restricted_account( ?\WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();

		return $user instanceof \WP_User && in_array( Capabilities::REGISTRATION_DESK_ROLE, (array) $user->roles, true );
	}

	/** @param mixed $result @param mixed $server @return mixed */
	public static function rest_pre_dispatch( $result, $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( ! self::is_restricted_account() ) {
			return $result;
		}
		$route = $request->get_route();
		if ( str_starts_with( $route, '/oras-tickets/v1/registration-desk/' ) ) {
			return $result;
		}

		return new \WP_Error( 'oras_desk_route_forbidden', 'This shared account is restricted to the Registration Desk.', array( 'status' => 403 ) );
	}

	public static function guard_admin(): void {
		if ( ! self::is_restricted_account() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			self::deny_ajax();
		}
		wp_safe_redirect( Landing_Page::url() );
		exit;
	}

	public static function guard_frontend(): void {
		if ( ! self::is_restricted_account() ) {
			return;
		}
		if ( wp_doing_ajax() || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Rejects the request before dispatcher execution.
			self::deny_ajax();
		}
		if ( Landing_Page::is_request() ) {
			return;
		}
		wp_safe_redirect( Landing_Page::url() );
		exit;
	}

	private static function deny_ajax(): void {
		wp_die( 'oras_desk_ajax_forbidden', 'Registration Desk', array( 'response' => 403 ) );
	}
}
