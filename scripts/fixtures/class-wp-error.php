<?php
/**
 * Minimal WordPress error stand-in for standalone Registration Desk host tests.
 *
 * @package ORAS\Tickets
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {
		}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}
