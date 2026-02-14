<?php
/**
 * Workspace-only WordPress/WooCommerce signatures for editor analysis.
 *
 * @package ORAS\Tickets
 */

declare(strict_types=1);

namespace {
	if (! function_exists('add_action')) {
		function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool { return true; }
	}
	if (! function_exists('add_filter')) {
		function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool { return true; }
	}
	if (! function_exists('apply_filters')) {
		function apply_filters($hook_name, $value, ...$args) { return $value; }
	}
	if (! function_exists('is_admin')) {
		function is_admin(): bool { return false; }
	}
	if (! function_exists('is_singular')) {
		function is_singular($post_types = ''): bool { return false; }
	}
	if (! function_exists('in_the_loop')) {
		function in_the_loop(): bool { return false; }
	}
	if (! function_exists('is_main_query')) {
		function is_main_query(): bool { return true; }
	}
	if (! function_exists('get_the_ID')) {
		function get_the_ID(): int { return 0; }
	}
	if (! function_exists('get_queried_object_id')) {
		function get_queried_object_id(): int { return 0; }
	}
	if (! function_exists('get_post_meta')) {
		function get_post_meta($post_id, $key = '', $single = false) { return $single ? '' : array(); }
	}
	if (! function_exists('update_post_meta')) {
		function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = ''): bool { return true; }
	}
	if (! function_exists('get_permalink')) {
		function get_permalink($post = 0, $leavename = false): string { return ''; }
	}
	if (! function_exists('get_the_title')) {
		function get_the_title($post = 0): string { return ''; }
	}
	if (! function_exists('wp_enqueue_style')) {
		function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all'): bool { return true; }
	}
	if (! function_exists('wp_enqueue_script')) {
		function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false): bool { return true; }
	}
	if (! function_exists('wp_localize_script')) {
		function wp_localize_script($handle, $object_name, $l10n): bool { return true; }
	}
	if (! function_exists('wp_unslash')) {
		function wp_unslash($value) { return $value; }
	}
	if (! function_exists('wp_verify_nonce')) {
		function wp_verify_nonce($nonce, $action = -1): bool { return true; }
	}
	if (! function_exists('wp_nonce_field')) {
		function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true): string { return ''; }
	}
	if (! function_exists('wp_safe_redirect')) {
		function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress'): bool { return true; }
	}
	if (! function_exists('wp_kses_post')) {
		function wp_kses_post($data) { return $data; }
	}
	if (! function_exists('wp_kses')) {
		function wp_kses($string, $allowed_html, $allowed_protocols = array()) { return $string; }
	}
	if (! function_exists('wp_strip_all_tags')) {
		function wp_strip_all_tags($text, $remove_breaks = false): string { return (string) $text; }
	}
	if (! function_exists('wp_generate_uuid4')) {
		function wp_generate_uuid4(): string { return ''; }
	}
	if (! function_exists('wp_date')) {
		function wp_date($format, $timestamp = null, $timezone = null): string { return ''; }
	}
	if (! function_exists('wp_timezone_string')) {
		function wp_timezone_string(): string { return 'UTC'; }
	}
	if (! function_exists('esc_html')) {
		function esc_html($text): string { return (string) $text; }
	}
	if (! function_exists('esc_attr')) {
		function esc_attr($text): string { return (string) $text; }
	}
	if (! function_exists('esc_url')) {
		function esc_url($url, $protocols = null, $_context = 'display'): string { return (string) $url; }
	}
	if (! function_exists('__')) {
		function __($text, $domain = 'default'): string { return (string) $text; }
	}
	if (! function_exists('esc_html__')) {
		function esc_html__($text, $domain = 'default'): string { return (string) $text; }
	}
	if (! function_exists('absint')) {
		function absint($maybeint): int { return abs((int) $maybeint); }
	}
	if (! function_exists('sanitize_key')) {
		function sanitize_key($key): string { return strtolower((string) $key); }
	}
	if (! function_exists('sanitize_text_field')) {
		function sanitize_text_field($str): string { return (string) $str; }
	}
	if (! function_exists('register_rest_route')) {
		function register_rest_route($namespace, $route, $args = array(), $override = false): bool { return true; }
	}
	if (! function_exists('rest_ensure_response')) {
		function rest_ensure_response($response) { return $response; }
	}
	if (! function_exists('get_current_user_id')) {
		function get_current_user_id(): int { return 0; }
	}
	if (! function_exists('is_user_logged_in')) {
		function is_user_logged_in(): bool { return false; }
	}
	if (! function_exists('get_option')) {
		function get_option($option, $default = false) { return $default; }
	}
	if (! function_exists('set_transient')) {
		function set_transient($transient, $value, $expiration = 0): bool { return true; }
	}
	if (! function_exists('get_transient')) {
		function get_transient($transient) { return false; }
	}
	if (! function_exists('wc_add_notice')) {
		function wc_add_notice($message, $notice_type = 'success', $data = array()): void {}
	}
	if (! function_exists('wc_print_notices')) {
		function wc_print_notices($return = false): string { return ''; }
	}
	if (! function_exists('wc_get_cart_url')) {
		function wc_get_cart_url(): string { return ''; }
	}
	if (! function_exists('wc_get_product')) {
		function wc_get_product($the_product = false) { return null; }
	}
	if (! function_exists('wc_get_orders')) {
		function wc_get_orders($args = array()) { return array(); }
	}
	if (! function_exists('wc_get_order')) {
		function wc_get_order($the_order = false) { return null; }
	}
	if (! function_exists('wc_get_endpoint_url')) {
		function wc_get_endpoint_url($endpoint, $value = '', $permalink = ''): string { return ''; }
	}
	if (! function_exists('wc_get_page_permalink')) {
		function wc_get_page_permalink($page): string { return ''; }
	}
	if (! function_exists('wc_price')) {
		function wc_price($price, $args = array()): string { return (string) $price; }
	}
	if (! function_exists('wc_format_decimal')) {
		function wc_format_decimal($number, $dp = false, $trim_zeros = false): string { return (string) $number; }
	}
	if (! function_exists('wc_get_price_decimals')) {
		function wc_get_price_decimals(): int { return 2; }
	}
	if (! function_exists('get_woocommerce_currency')) {
		function get_woocommerce_currency(): string { return 'USD'; }
	}
	if (! function_exists('WC')) {
		function WC() { return null; }
	}
}

namespace ORAS\Tickets\Frontend {
	function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool { return true; }
	function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool { return true; }
	function apply_filters($hook_name, $value, ...$args) { return $value; }
	function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all'): bool { return true; }
	function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false): bool { return true; }
	function wp_localize_script($handle, $object_name, $l10n): bool { return true; }
	function wp_unslash($value) { return $value; }
	function wp_verify_nonce($nonce, $action = -1): bool { return true; }
	function wp_kses_post($data) { return $data; }
	function __($text, $domain = 'default'): string { return (string) $text; }
	function esc_url($url, $protocols = null, $_context = 'display'): string { return (string) $url; }
	function esc_attr($text): string { return (string) $text; }
	function esc_html($text): string { return (string) $text; }
	function is_admin(): bool { return false; }
	function is_singular($post_types = ''): bool { return false; }
	function in_the_loop(): bool { return false; }
	function is_main_query(): bool { return true; }
	function get_the_ID(): int { return 0; }
	function get_permalink($post = 0, $leavename = false): string { return ''; }
	function get_post_meta($post_id, $key = '', $single = false) { return $single ? '' : array(); }
	function absint($maybeint): int { return abs((int) $maybeint); }
	function wc_add_notice($message, $notice_type = 'success', $data = array()): void {}
	function wc_print_notices($return = false): string { return ''; }
	function wc_get_cart_url(): string { return ''; }
	function wc_get_product($the_product = false) { return null; }
	function WC() { return null; }
}
