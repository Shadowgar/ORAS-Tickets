<?php
namespace ORAS\Tickets\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Frontend {
  private static ?Frontend $instance = null;
  public static function instance(): Frontend { return self::$instance ??= new self(); }
  private function __construct() {}

  public function init(): void {
    // Frontend rendering hooks will go here.
  }
}
