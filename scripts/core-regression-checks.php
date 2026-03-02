<?php
/**
 * Wrapper entry point for Phase 0-2 core regression checks.
 *
 * Primary executable path in wp-env:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/core-regression-checks.php
 */

if (! defined('ABSPATH')) {
    exit(1);
}

if (! defined('ORAS_TICKETS_DIR')) {
    fwrite(STDERR, "Core regression checks failed: ORAS_TICKETS_DIR not defined.\n");
    exit(1);
}

require_once ORAS_TICKETS_DIR . 'tools/core-regression-checks.php';
