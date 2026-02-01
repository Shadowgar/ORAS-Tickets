<?php
namespace ORAS\Tickets\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	private static ?Logger $instance = null;

	public static function instance(): Logger {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function log( string $message ): void {
		if ( ! defined( 'ORAS_TICKETS_DEBUG' ) || ! ORAS_TICKETS_DEBUG ) {
			return;
		}
		error_log( '[ORAS-Tickets] ' . $message );
	}
}
