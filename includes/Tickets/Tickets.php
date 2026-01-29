<?php
namespace ORAS\Tickets\Tickets;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Tickets {
  private static ?Tickets $instance = null;
  public static function instance(): Tickets { return self::$instance ??= new self(); }
  private function __construct() {}

  public function init(): void {
    // Ticket definitions & syncing will go here.
  }
}
