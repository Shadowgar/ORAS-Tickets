<?php

/**
 * Workspace-only WordPress/WooCommerce signatures for editor analysis.
 *
 * @package ORAS\Tickets
 */

declare(strict_types=1);

namespace {
	if (! defined('DOING_AJAX')) {
		define('DOING_AJAX', false);
	}
	if (! defined('DOING_AUTOSAVE')) {
		define('DOING_AUTOSAVE', false);
	}

	if (! class_exists('WP_Post')) {
		class WP_Post
		{
			/** @var int */
			public $ID = 0;
			/** @var string */
			public $post_title = '';
			/** @var string */
			public $post_type = '';
			/** @var string */
			public $post_status = 'publish';
		}
	}
	if (! class_exists('WP_REST_Request')) {
		class WP_REST_Request
		{
			public function get_param($key)
			{
				return null;
			}
		}
	}
	if (! class_exists('WP_REST_Response')) {
		class WP_REST_Response {}
	}
	if (! class_exists('WP_User')) {
		class WP_User
		{
			/** @var int */
			public $ID = 0;
			/** @var string */
			public $display_name = '';
		}
	}
	if (! class_exists('WC_Product')) {
		class WC_Product
		{
			public function get_manage_stock(): bool
			{
				return false;
			}
			public function get_meta($key = '', $single = true)
			{
				return '';
			}
			public function get_name(): string
			{
				return '';
			}
			public function is_purchasable(): bool
			{
				return true;
			}
			public function managing_stock(): bool
			{
				return false;
			}
			public function is_in_stock(): bool
			{
				return true;
			}
			public function backorders_allowed(): bool
			{
				return false;
			}
			public function get_stock_quantity(): int
			{
				return 0;
			}
			public function get_price()
			{
				return '0';
			}
			public function set_price($price): void {}
			public function set_regular_price($price): void {}
			public function set_description($description): void {}
			public function set_name($name): void {}
			public function set_date_on_sale_from($date): void {}
			public function set_date_on_sale_to($date): void {}
			public function set_virtual($virtual): void {}
			public function set_catalog_visibility($visibility): void {}
			public function set_status($status): void {}
			public function set_manage_stock($manage): void {}
			public function set_stock_quantity($quantity): void {}
			public function set_stock_status($status): void {}
			public function set_backorders($value): void {}
			public function save(): int
			{
				return 1;
			}
		}
	}
	if (! class_exists('WC_Product_Simple')) {
		class WC_Product_Simple extends WC_Product {}
	}
	if (! class_exists('WC_Cart')) {
		class WC_Cart
		{
			public function get_cart(): array
			{
				return array();
			}
			public function remove_cart_item($cart_item_key): bool
			{
				return true;
			}
			public function set_quantity($cart_item_key, $quantity, $refresh_totals = true): bool
			{
				return true;
			}
			public function calculate_totals(): void {}
			public function add_to_cart($product_id, $quantity = 1): bool
			{
				return true;
			}
		}
	}
	if (! class_exists('WC_Global')) {
		class WC_Global
		{
			/** @var WC_Cart */
			public $cart;

			public function __construct()
			{
				$this->cart = new WC_Cart();
			}
		}
	}
	if (! class_exists('WC_Order')) {
		class WC_Order
		{
			public function update_meta_data($key, $value): void {}
			public function save(): int
			{
				return 1;
			}
			public function get_id(): int
			{
				return 0;
			}
			public function get_status(): string
			{
				return '';
			}
			public function get_total(): string
			{
				return '0';
			}
			public function get_refunds(): array
			{
				return array();
			}
			public function get_item($item_id)
			{
				return null;
			}
			public function get_user_id(): int
			{
				return 0;
			}
			public function get_items($type = 'line_item'): array
			{
				return array();
			}
			public function get_meta($key = '', $single = true)
			{
				return '';
			}
			public function get_date_created()
			{
				return null;
			}
			public function get_currency(): string
			{
				return 'USD';
			}
			public function get_user()
			{
				return null;
			}
			public function get_billing_first_name(): string
			{
				return '';
			}
			public function get_billing_last_name(): string
			{
				return '';
			}
		}
	}
	if (! class_exists('WC_Order_Item_Product')) {
		class WC_Order_Item
		{
			public function get_meta($key = '', $single = true)
			{
				return '';
			}
			public function get_product_id(): int
			{
				return 0;
			}
			public function get_name(): string
			{
				return '';
			}
			public function get_quantity(): int
			{
				return 1;
			}
			public function get_subtotal()
			{
				return 0;
			}
			public function get_order()
			{
				return new WC_Order();
			}
			public function add_meta_data($key, $value, $unique = false): void {}
		}
	}
	if (! class_exists('WC_Order_Item_Product')) {
		class WC_Order_Item_Product extends WC_Order_Item
		{
			public function get_total()
			{
				return 0;
			}
		}
	}

	if (! function_exists('add_action')) {
		function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
		{
			return true;
		}
	}
	if (! function_exists('add_filter')) {
		function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
		{
			return true;
		}
	}
	if (! function_exists('apply_filters')) {
		function apply_filters($hook_name, $value, ...$args)
		{
			return $value;
		}
	}
	if (! function_exists('is_admin')) {
		function is_admin(): bool
		{
			return false;
		}
	}
	if (! function_exists('is_singular')) {
		function is_singular($post_types = ''): bool
		{
			return false;
		}
	}
	if (! function_exists('in_the_loop')) {
		function in_the_loop(...$args): bool
		{
			return false;
		}
	}
	if (! function_exists('is_main_query')) {
		function is_main_query(...$args): bool
		{
			return true;
		}
	}
	if (! function_exists('get_the_ID')) {
		function get_the_ID(...$args): int
		{
			return 0;
		}
	}
	if (! function_exists('get_queried_object_id')) {
		function get_queried_object_id(...$args): int
		{
			return 0;
		}
	}
	if (! function_exists('get_post_meta')) {
		function get_post_meta($post_id, $key = '', $single = false)
		{
			return $single ? '' : array();
		}
	}
	if (! function_exists('update_post_meta')) {
		function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = ''): bool
		{
			return true;
		}
	}
	if (! function_exists('delete_post_meta')) {
		function delete_post_meta($post_id, $meta_key, $meta_value = ''): bool
		{
			return true;
		}
	}
	if (! function_exists('get_posts')) {
		function get_posts($args = null): array
		{
			return array();
		}
	}
	if (! function_exists('get_permalink')) {
		function get_permalink($post = 0, $leavename = false): string
		{
			return '';
		}
	}
	if (! function_exists('plugin_dir_path')) {
		function plugin_dir_path($file): string
		{
			return '';
		}
	}
	if (! function_exists('plugin_dir_url')) {
		function plugin_dir_url($file): string
		{
			return '';
		}
	}
	if (! function_exists('get_the_title')) {
		function get_the_title($post = 0): string
		{
			return '';
		}
	}
	if (! function_exists('did_action')) {
		function did_action($hook_name): int
		{
			return 0;
		}
	}
	if (! function_exists('add_rewrite_rule')) {
		function add_rewrite_rule($regex, $query, $after = 'bottom'): void {}
	}
	if (! function_exists('get_post')) {
		function get_post($post = null)
		{
			return (object) array(
				'ID'          => 0,
				'post_title'  => '',
				'post_type'   => '',
				'post_status' => 'publish',
			);
		}
	}
	if (! function_exists('current_user_can')) {
		function current_user_can($capability, ...$args): bool
		{
			return true;
		}
	}
	if (! function_exists('current_time')) {
		function current_time($type, $gmt = 0)
		{
			return $type === 'timestamp' ? time() : '';
		}
	}
	if (! function_exists('wp_timezone')) {
		function wp_timezone(): \DateTimeZone
		{
			return new \DateTimeZone('UTC');
		}
	}
	if (! function_exists('sanitize_textarea_field')) {
		function sanitize_textarea_field($str): string
		{
			return (string) $str;
		}
	}
	if (! function_exists('esc_textarea')) {
		function esc_textarea($text): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('checked')) {
		function checked($checked, $current = true, $display = true): string
		{
			return '';
		}
	}
	if (! function_exists('wp_is_post_autosave')) {
		function wp_is_post_autosave($post): bool
		{
			return false;
		}
	}
	if (! function_exists('wp_is_post_revision')) {
		function wp_is_post_revision($post): bool
		{
			return false;
		}
	}
	if (! function_exists('wp_update_post')) {
		function wp_update_post($postarr = array(), $wp_error = false, $fire_after_hooks = true)
		{
			return 0;
		}
	}
	if (! function_exists('add_meta_box')) {
		function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null): void {}
	}
	if (! function_exists('get_current_screen')) {
		function get_current_screen()
		{
			return (object) array('id' => '');
		}
	}
	if (! function_exists('add_query_arg')) {
		function add_query_arg($key, $value = null, $url = null): string
		{
			return '';
		}
	}
	if (! function_exists('get_query_var')) {
		function get_query_var($query_var, $default = '')
		{
			return $default;
		}
	}
	if (! function_exists('wp_parse_url')) {
		function wp_parse_url($url, $component = -1)
		{
			return '';
		}
	}
	if (! function_exists('wp_json_encode')) {
		function wp_json_encode($value, $flags = 0, $depth = 512): string
		{
			return '';
		}
	}
	if (! function_exists('wp_die')) {
		function wp_die($message = '', $title = '', $args = array()): void {}
	}
	if (! function_exists('status_header')) {
		function status_header($code, $description = ''): void {}
	}
	if (! function_exists('nocache_headers')) {
		function nocache_headers(): void {}
	}
	if (! function_exists('tribe_get_start_date')) {
		function tribe_get_start_date($postId = null, $withTime = false, $format = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_venue_id')) {
		function tribe_get_venue_id($eventId = null): int
		{
			return 0;
		}
	}
	if (! function_exists('tribe_get_venue')) {
		function tribe_get_venue($eventId = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_address')) {
		function tribe_get_address($venueId = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_city')) {
		function tribe_get_city($venueId = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_state')) {
		function tribe_get_state($venueId = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_province')) {
		function tribe_get_province($venueId = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_zip')) {
		function tribe_get_zip($venueId = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_country')) {
		function tribe_get_country($venueId = null): string
		{
			return '';
		}
	}
	if (! function_exists('tribe_get_full_address')) {
		function tribe_get_full_address($eventId = null): string
		{
			return '';
		}
	}
	if (! function_exists('get_header')) {
		function get_header($name = null, $args = array()): void {}
	}
	if (! function_exists('get_footer')) {
		function get_footer($name = null, $args = array()): void {}
	}
	if (! function_exists('have_posts')) {
		function have_posts(): bool
		{
			return false;
		}
	}
	if (! function_exists('the_post')) {
		function the_post(): void {}
	}
	if (! function_exists('the_ID')) {
		function the_ID(): void {}
	}
	if (! function_exists('post_class')) {
		function post_class($class = '', $post_id = null): void {}
	}
	if (! function_exists('the_title')) {
		function the_title($before = '', $after = '', $display = true): void {}
	}
	if (! function_exists('has_post_thumbnail')) {
		function has_post_thumbnail($post = null): bool
		{
			return false;
		}
	}
	if (! function_exists('the_post_thumbnail')) {
		function the_post_thumbnail($size = 'post-thumbnail', $attr = ''): void {}
	}
	if (! function_exists('get_the_content')) {
		function get_the_content($more_link_text = null, $strip_teaser = false, $post = null): string
		{
			return '';
		}
	}
	if (! function_exists('wp_enqueue_style')) {
		function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all'): bool
		{
			return true;
		}
	}
	if (! function_exists('wp_enqueue_script')) {
		function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false): bool
		{
			return true;
		}
	}
	if (! function_exists('wp_localize_script')) {
		function wp_localize_script($handle, $object_name, $l10n): bool
		{
			return true;
		}
	}
	if (! function_exists('wp_unslash')) {
		function wp_unslash($value)
		{
			return $value;
		}
	}
	if (! function_exists('wp_verify_nonce')) {
		function wp_verify_nonce($nonce, $action = -1): bool
		{
			return true;
		}
	}
	if (! function_exists('wp_nonce_field')) {
		function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true): string
		{
			return '';
		}
	}
	if (! function_exists('wp_safe_redirect')) {
		function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress'): bool
		{
			return true;
		}
	}
	if (! function_exists('wp_kses_post')) {
		function wp_kses_post($data)
		{
			return $data;
		}
	}
	if (! function_exists('wp_kses')) {
		function wp_kses($string, $allowed_html, $allowed_protocols = array())
		{
			return $string;
		}
	}
	if (! function_exists('wp_strip_all_tags')) {
		function wp_strip_all_tags($text, $remove_breaks = false): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('wp_generate_uuid4')) {
		function wp_generate_uuid4(): string
		{
			return '';
		}
	}
	if (! function_exists('wp_generate_password')) {
		function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false): string
		{
			return '';
		}
	}
	if (! function_exists('wp_date')) {
		function wp_date($format, $timestamp = null, $timezone = null): string
		{
			return '';
		}
	}
	if (! function_exists('wp_timezone_string')) {
		function wp_timezone_string(): string
		{
			return 'UTC';
		}
	}
	if (! function_exists('esc_html')) {
		function esc_html($text): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('esc_attr')) {
		function esc_attr($text): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('esc_attr__')) {
		function esc_attr__($text, $domain = 'default'): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('esc_js')) {
		function esc_js($text): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('esc_url')) {
		function esc_url($url, $protocols = null, $_context = 'display'): string
		{
			return (string) $url;
		}
	}
	if (! function_exists('__')) {
		function __($text, $domain = 'default'): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('esc_html__')) {
		function esc_html__($text, $domain = 'default'): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('absint')) {
		function absint($maybeint): int
		{
			return abs((int) $maybeint);
		}
	}
	if (! function_exists('selected')) {
		function selected($selected, $current = true, $display = true): string
		{
			return '';
		}
	}
	if (! function_exists('sanitize_key')) {
		function sanitize_key($key): string
		{
			return strtolower((string) $key);
		}
	}
	if (! function_exists('sanitize_text_field')) {
		function sanitize_text_field($str): string
		{
			return (string) $str;
		}
	}
	if (! function_exists('number_format_i18n')) {
		function number_format_i18n($number, $decimals = 0): string
		{
			return (string) $number;
		}
	}
	if (! function_exists('register_rest_route')) {
		function register_rest_route($namespace, $route, $args = array(), $override = false): bool
		{
			return true;
		}
	}
	if (! function_exists('rest_ensure_response')) {
		function rest_ensure_response($response)
		{
			return $response;
		}
	}
	if (! function_exists('get_current_user_id')) {
		function get_current_user_id(): int
		{
			return 0;
		}
	}
	if (! function_exists('is_user_logged_in')) {
		function is_user_logged_in(): bool
		{
			return false;
		}
	}
	if (! function_exists('get_option')) {
		function get_option($option, $default = false)
		{
			return $default;
		}
	}
	if (! function_exists('admin_url')) {
		function admin_url($path = '', $scheme = 'admin'): string
		{
			return '';
		}
	}
	if (! function_exists('submit_button')) {
		function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null): void {}
	}
	if (! function_exists('set_transient')) {
		function set_transient($transient, $value, $expiration = 0): bool
		{
			return true;
		}
	}
	if (! function_exists('get_transient')) {
		function get_transient($transient)
		{
			return false;
		}
	}
	if (! function_exists('wc_add_notice')) {
		function wc_add_notice($message, $notice_type = 'success', $data = array()): void {}
	}
	if (! function_exists('wc_print_notices')) {
		function wc_print_notices($return = false): string
		{
			return '';
		}
	}
	if (! function_exists('wc_get_cart_url')) {
		function wc_get_cart_url(...$args): string
		{
			return '';
		}
	}
	if (! function_exists('wc_get_product')) {
		function wc_get_product($the_product = false): WC_Product
		{
			return new WC_Product();
		}
	}
	if (! function_exists('wc_get_orders')) {
		function wc_get_orders($args = array())
		{
			return array();
		}
	}
	if (! function_exists('wc_get_order')) {
		function wc_get_order($the_order = false): WC_Order
		{
			return new WC_Order();
		}
	}
	if (! function_exists('wc_get_endpoint_url')) {
		function wc_get_endpoint_url($endpoint, $value = '', $permalink = ''): string
		{
			return '';
		}
	}
	if (! function_exists('wc_get_page_permalink')) {
		function wc_get_page_permalink($page): string
		{
			return '';
		}
	}
	if (! function_exists('get_woocommerce_currency_symbol')) {
		function get_woocommerce_currency_symbol($currency = ''): string
		{
			return '$';
		}
	}
	if (! function_exists('wc_price')) {
		function wc_price($price, $args = array()): string
		{
			return (string) $price;
		}
	}
	if (! function_exists('wc_format_decimal')) {
		function wc_format_decimal($number, $dp = false, $trim_zeros = false): string
		{
			return (string) $number;
		}
	}
	if (! function_exists('wc_get_price_decimals')) {
		function wc_get_price_decimals(): int
		{
			return 2;
		}
	}
	if (! function_exists('get_woocommerce_currency')) {
		function get_woocommerce_currency(): string
		{
			return 'USD';
		}
	}
	if (! function_exists('WC')) {
		function WC(): WC_Global
		{
			return new WC_Global();
		}
	}
	if (! function_exists('register_post_type')) {
		function register_post_type($post_type, $args = array())
		{
			return null;
		}
	}
	if (! function_exists('get_edit_post_link')) {
		function get_edit_post_link($post = 0, $context = 'display')
		{
			return '';
		}
	}
	if (! function_exists('remove_query_arg')) {
		function remove_query_arg($key, $query = false): string
		{
			return '';
		}
	}
	if (! function_exists('sanitize_email')) {
		function sanitize_email($email): string
		{
			return (string) $email;
		}
	}
	if (! function_exists('esc_url_raw')) {
		function esc_url_raw($url, $protocols = null): string
		{
			return (string) $url;
		}
	}
	if (! function_exists('get_post_thumbnail_id')) {
		function get_post_thumbnail_id($post = null): int
		{
			return 0;
		}
	}
	if (! function_exists('wp_get_attachment_image_url')) {
		function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail', $icon = false): string
		{
			return '';
		}
	}
	if (! function_exists('get_post_field')) {
		function get_post_field($field, $post = null, $context = 'display')
		{
			return '';
		}
	}
	if (! function_exists('wp_trim_words')) {
		function wp_trim_words($text, $num_words = 55, $more = null): string
		{
			return (string) $text;
		}
	}
	if (! function_exists('get_user_by')) {
		function get_user_by($field, $value)
		{
			return new WP_User();
		}
	}
	if (! function_exists('sanitize_user')) {
		function sanitize_user($username, $strict = false): string
		{
			return (string) $username;
		}
	}
	if (! function_exists('username_exists')) {
		function username_exists($username)
		{
			return false;
		}
	}
	if (! function_exists('wp_insert_user')) {
		function wp_insert_user($userdata)
		{
			return 1;
		}
	}
	if (! function_exists('is_wp_error')) {
		function is_wp_error($thing): bool
		{
			return false;
		}
	}
	if (! function_exists('wp_nonce_url')) {
		function wp_nonce_url($actionurl, $action = -1, $name = '_wpnonce'): string
		{
			return (string) $actionurl;
		}
	}
	if (! function_exists('is_email')) {
		function is_email($email, $deprecated = false)
		{
			return (string) $email;
		}
	}
	if (! function_exists('wp_get_current_user')) {
		function wp_get_current_user(): WP_User
		{
			return new WP_User();
		}
	}
	if (! function_exists('wp_mail')) {
		function wp_mail($to, $subject, $message, $headers = '', $attachments = array()): bool
		{
			return true;
		}
	}
	if (! function_exists('pmpro_getMembershipLevelForUser')) {
		function pmpro_getMembershipLevelForUser($user_id = null, $post_id = null)
		{
			return null;
		}
	}
}

namespace ORAS\Tickets\Frontend {
	function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
	{
		return true;
	}
	function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
	{
		return true;
	}
	function apply_filters($hook_name, $value, ...$args)
	{
		return $value;
	}
	function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all'): bool
	{
		return true;
	}
	function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false): bool
	{
		return true;
	}
	function wp_localize_script($handle, $object_name, $l10n): bool
	{
		return true;
	}
	function wp_unslash($value)
	{
		return $value;
	}
	function wp_verify_nonce($nonce, $action = -1): bool
	{
		return true;
	}
	function wp_kses_post($data)
	{
		return $data;
	}
	function __($text, $domain = 'default'): string
	{
		return (string) $text;
	}
	function esc_url($url, $protocols = null, $_context = 'display'): string
	{
		return (string) $url;
	}
	function esc_attr($text): string
	{
		return (string) $text;
	}
	function esc_html($text): string
	{
		return (string) $text;
	}
	function is_admin(): bool
	{
		return false;
	}
	function is_singular($post_types = ''): bool
	{
		return false;
	}
	function in_the_loop(...$args): bool
	{
		return false;
	}
	function is_main_query(...$args): bool
	{
		return true;
	}
	function get_the_ID(...$args): int
	{
		return 0;
	}
	function get_permalink($post = 0, $leavename = false): string
	{
		return '';
	}
	function did_action($hook_name): int
	{
		return 0;
	}
	function add_rewrite_rule($regex, $query, $after = 'bottom'): void {}
	function get_post($post = null)
	{
		return \get_post($post);
	}
	function get_query_var($query_var, $default = '')
	{
		return $default;
	}
	function get_queried_object_id(...$args): int
	{
		return \get_queried_object_id(...$args);
	}
	function wp_parse_url($url, $component = -1)
	{
		return '';
	}
	function wp_die($message = '', $title = '', $args = array()): void {}
	function status_header($code, $description = ''): void {}
	function nocache_headers(): void {}
	function tribe_get_start_date($postId = null, $withTime = false, $format = null): string
	{
		return '';
	}
	function get_post_meta($post_id, $key = '', $single = false)
	{
		return $single ? '' : array();
	}
	function absint($maybeint): int
	{
		return abs((int) $maybeint);
	}
	function wc_add_notice($message, $notice_type = 'success', $data = array()): void {}
	function wc_print_notices($return = false): string
	{
		return '';
	}
	function wc_get_cart_url(...$args): string
	{
		return '';
	}
	function wc_get_product($the_product = false)
	{
		return null;
	}
	function get_post_thumbnail_id($post = null): int
	{
		return \get_post_thumbnail_id($post);
	}
	function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail', $icon = false): string
	{
		return \wp_get_attachment_image_url($attachment_id, $size, $icon);
	}
	function get_post_field($field, $post = null, $context = 'display')
	{
		return \get_post_field($field, $post, $context);
	}
	function wp_trim_words($text, $num_words = 55, $more = null): string
	{
		return \wp_trim_words($text, $num_words, $more);
	}
	function WC(): \WC_Global
	{
		return \WC();
	}
}

namespace ORAS\Tickets\Commerce\Woo {
	function is_admin(): bool
	{
		return \is_admin();
	}
	function wp_is_post_revision($post): bool
	{
		return \wp_is_post_revision($post);
	}
	function wp_is_post_autosave($post): bool
	{
		return \wp_is_post_autosave($post);
	}
	function current_user_can($capability, ...$args): bool
	{
		return \current_user_can($capability, ...$args);
	}
	function wp_update_post($postarr = array(), $wp_error = false, $fire_after_hooks = true)
	{
		return \wp_update_post($postarr, $wp_error, $fire_after_hooks);
	}
}

namespace ORAS\Tickets\Admin {
	function register_post_type($post_type, $args = array())
	{
		return \register_post_type($post_type, $args);
	}
	function get_edit_post_link($post = 0, $context = 'display')
	{
		return \get_edit_post_link($post, $context);
	}
	function remove_query_arg($key, $query = false): string
	{
		return \remove_query_arg($key, $query);
	}
	function sanitize_email($email): string
	{
		return \sanitize_email($email);
	}
	function esc_url_raw($url, $protocols = null): string
	{
		return \esc_url_raw($url, $protocols);
	}
	function get_post_thumbnail_id($post = null): int
	{
		return \get_post_thumbnail_id($post);
	}
	function get_woocommerce_currency_symbol($currency = ''): string
	{
		return \get_woocommerce_currency_symbol($currency);
	}
	function get_current_screen()
	{
		return \get_current_screen();
	}
	function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null): void
	{
		\add_meta_box($id, $title, $callback, $screen, $context, $priority, $callback_args);
	}
	function current_user_can($capability, ...$args): bool
	{
		return \current_user_can($capability, ...$args);
	}
	function current_time($type, $gmt = 0)
	{
		return \current_time($type, $gmt);
	}
	function wp_timezone(): \DateTimeZone
	{
		return \wp_timezone();
	}
	function esc_textarea($text): string
	{
		return \esc_textarea($text);
	}
	function checked($checked, $current = true, $display = true): string
	{
		return \checked($checked, $current, $display);
	}
	function wp_is_post_autosave($post): bool
	{
		return \wp_is_post_autosave($post);
	}
	function wp_is_post_revision($post): bool
	{
		return \wp_is_post_revision($post);
	}
	function sanitize_textarea_field($str): string
	{
		return \sanitize_textarea_field($str);
	}
	function add_query_arg($key, $value = null, $url = null): string
	{
		return \add_query_arg($key, $value, $url);
	}
	function get_posts($args = null): array
	{
		return \get_posts($args);
	}
	function selected($selected, $current = true, $display = true): string
	{
		return \selected($selected, $current, $display);
	}
	function delete_post_meta($post_id, $meta_key, $meta_value = ''): bool
	{
		return \delete_post_meta($post_id, $meta_key, $meta_value);
	}
	function wp_json_encode($value, $flags = 0, $depth = 512): string
	{
		return \wp_json_encode($value, $flags, $depth);
	}
}

namespace ORAS\Tickets\Admin\Metaboxes {
	function get_posts($args = null): array
	{
		return \get_posts($args);
	}
	function selected($selected, $current = true, $display = true): string
	{
		return \selected($selected, $current, $display);
	}
	function esc_attr__($text, $domain = 'default'): string
	{
		return \esc_attr__($text, $domain);
	}
	function esc_js($text): string
	{
		return \esc_js($text);
	}
	function delete_post_meta($post_id, $meta_key, $meta_value = ''): bool
	{
		return \delete_post_meta($post_id, $meta_key, $meta_value);
	}
}

namespace ORAS\Tickets\Admin\Pages {
	function get_post($post = null)
	{
		return \get_post($post);
	}
	function get_posts($args = null): array
	{
		return \get_posts($args);
	}
	function get_edit_post_link($post = 0, $context = 'display')
	{
		return \get_edit_post_link($post, $context);
	}
	function get_woocommerce_currency_symbol($currency = ''): string
	{
		return \get_woocommerce_currency_symbol($currency);
	}
	function pmpro_getMembershipLevelForUser($user_id = null, $post_id = null)
	{
		return \pmpro_getMembershipLevelForUser($user_id, $post_id);
	}
	function get_user_by($field, $value)
	{
		return \get_user_by($field, $value);
	}
	function sanitize_user($username, $strict = false): string
	{
		return \sanitize_user($username, $strict);
	}
	function username_exists($username)
	{
		return \username_exists($username);
	}
	function wp_insert_user($userdata)
	{
		return \wp_insert_user($userdata);
	}
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false): string
	{
		return \wp_generate_password($length, $special_chars, $extra_special_chars);
	}
	function is_wp_error($thing): bool
	{
		return \is_wp_error($thing);
	}
	function wp_nonce_url($actionurl, $action = -1, $name = '_wpnonce'): string
	{
		return \wp_nonce_url($actionurl, $action, $name);
	}
	function is_email($email, $deprecated = false)
	{
		return \is_email($email, $deprecated);
	}
	function wp_get_current_user(): \WP_User
	{
		return \wp_get_current_user();
	}
	function wp_mail($to, $subject, $message, $headers = '', $attachments = array()): bool
	{
		return \wp_mail($to, $subject, $message, $headers, $attachments);
	}
	function admin_url($path = '', $scheme = 'admin'): string
	{
		return \admin_url($path, $scheme);
	}
	function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null): void
	{
		\submit_button($text, $type, $name, $wrap, $other_attributes);
	}
	function selected($selected, $current = true, $display = true): string
	{
		return \selected($selected, $current, $display);
	}
	function number_format_i18n($number, $decimals = 0): string
	{
		return \number_format_i18n($number, $decimals);
	}
}
