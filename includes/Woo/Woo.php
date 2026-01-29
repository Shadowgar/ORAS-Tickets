<?php
namespace ORAS\Tickets\Woo;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Woo {
  private static ?Woo $instance = null;
  public static function instance(): Woo { return self::$instance ??= new self(); }
  private function __construct() {}

  public function init(): void {
    // Woo integration hooks will go here.
  }
}
