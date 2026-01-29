<?php
namespace ORAS\Tickets\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Admin {
  private static ?Admin $instance = null;
  public static function instance(): Admin { return self::$instance ??= new self(); }
  private function __construct() {}

  public function init(): void {
    // Admin hooks will go here.
  }
}
