<?php

declare(strict_types=1);

if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function pass(string $message): void
{
    fwrite(STDOUT, "PASS: {$message}\n");
}

define('ABSPATH', __DIR__ . '/');

require_once dirname(__DIR__) . '/oras-tickets/includes/Admin/Tickets_Metabox.php';

$result = \ORAS\Tickets\Admin\Tickets_Metabox::normalize_legacy_phase_datetime_value('2026-06-08 14:30');
if (! is_array($result) || ($result['value'] ?? null) !== '2026-06-08 14:30' || ! ($result['safe'] ?? false) || ($result['normalized'] ?? true)) {
    fail('Existing Y-m-d H:i phase datetimes must stay unchanged and be treated as safe.');
}
pass('Existing phase datetimes remain unchanged.');

$result = \ORAS\Tickets\Admin\Tickets_Metabox::normalize_legacy_phase_datetime_value('2026-06-08T14:30');
if (($result['value'] ?? null) !== '2026-06-08 14:30' || ! ($result['safe'] ?? false) || ! ($result['normalized'] ?? false)) {
    fail('Datetime-local phase values must normalize to Y-m-d H:i.');
}
pass('Datetime-local phase values normalize correctly.');

$result = \ORAS\Tickets\Admin\Tickets_Metabox::normalize_legacy_phase_datetime_value('2026-06-08');
if (($result['value'] ?? null) !== '2026-06-08 00:00' || ! ($result['safe'] ?? false) || ! ($result['normalized'] ?? false)) {
    fail('Date-only phase values must normalize to midnight Y-m-d H:i.');
}
pass('Date-only phase values normalize correctly.');

$result = \ORAS\Tickets\Admin\Tickets_Metabox::normalize_legacy_phase_datetime_value('06/08/2026 2:30pm');
if (($result['value'] ?? null) !== '06/08/2026 2:30pm' || ($result['safe'] ?? true) || ($result['normalized'] ?? true)) {
    fail('Ambiguous legacy phase values must be left untouched and marked unsafe.');
}
pass('Ambiguous phase values are skipped.');

$adminMenuPhp = file_get_contents(dirname(__DIR__) . '/oras-tickets/includes/Admin/Admin_Menu.php');
if (! is_string($adminMenuPhp) || strpos($adminMenuPhp, 'admin_post_oras_tickets_repair_phase_datetimes') === false) {
    fail('Admin menu must register the pricing phase datetime repair action.');
}
pass('Admin repair action is registered.');

