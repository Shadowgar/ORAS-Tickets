<?php

/**
 * PHPStan bootstrap for plugin constants.
 *
 * @package ORAS\Tickets
 */

declare(strict_types=1);

if (! defined('ORAS_TICKETS_VERSION')) {
  define('ORAS_TICKETS_VERSION', '0.1.0');
}

if (! defined('ORAS_TICKETS_FILE')) {
  define('ORAS_TICKETS_FILE', __DIR__ . '/../plugin/oras-tickets.php');
}

if (! defined('ORAS_TICKETS_DIR')) {
  define('ORAS_TICKETS_DIR', __DIR__ . '/../plugin/');
}

if (! defined('ORAS_TICKETS_URL')) {
  define('ORAS_TICKETS_URL', 'https://example.test/wp-content/plugins/oras-tickets/');
}

if (! defined('DAY_IN_SECONDS')) {
  define('DAY_IN_SECONDS', 86400);
}
