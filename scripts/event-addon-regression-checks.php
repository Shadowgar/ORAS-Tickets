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

$base = dirname(__DIR__) . '/oras-tickets';

$eventAddonJs = file_get_contents($base . '/assets/admin/event-addon-metabox.js');
if (! is_string($eventAddonJs) || $eventAddonJs === '') {
    fail('Unable to read event addon metabox script.');
}

if (strpos($eventAddonJs, "document.getElementById('oras-events-addon-root')") === false) {
    fail('Event addon script must bind to a unique inner root element.');
}
pass('Event addon script targets a unique inner root element.');

if (! preg_match('/focusTarget\.focus\(\{\s*preventScroll:\s*true\s*\}\)/', $eventAddonJs)) {
    fail('Event addon viewport restore must focus with preventScroll enabled.');
}
pass('Event addon viewport restore uses non-scrolling focus.');

$ticketsMetaboxPhp = file_get_contents($base . '/includes/Admin/Tickets_Metabox.php');
if (! is_string($ticketsMetaboxPhp) || $ticketsMetaboxPhp === '') {
    fail('Unable to read tickets metabox source.');
}

if (! preg_match('/price_phases.*?\[.*?\]\[start\].*?type="datetime-local"/s', $ticketsMetaboxPhp)) {
    fail('Pricing phase start field must use datetime-local.');
}
if (! preg_match('/price_phases.*?\[.*?\]\[end\].*?type="datetime-local"/s', $ticketsMetaboxPhp)) {
    fail('Pricing phase end field must use datetime-local.');
}
pass('Pricing phase fields use datetime-local inputs.');

$phaseNormalizationCount = preg_match_all(
    "/\\\$phase_start\\s*=\\s*str_replace\\(\\s*'T',\\s*' ',\\s*\\\$phase_start\\s*\\);/m",
    $ticketsMetaboxPhp
);
if ($phaseNormalizationCount < 2) {
    fail('Pricing phase start values must normalize datetime-local input before saving in both save paths.');
}

$phaseEndNormalizationCount = preg_match_all(
    "/\\\$phase_end\\s*=\\s*str_replace\\(\\s*'T',\\s*' ',\\s*\\\$phase_end\\s*\\);/m",
    $ticketsMetaboxPhp
);
if ($phaseEndNormalizationCount < 2) {
    fail('Pricing phase end values must normalize datetime-local input before saving in both save paths.');
}
pass('Pricing phase save path normalizes datetime-local values.');

$eventAddonPhp = file_get_contents($base . '/includes/Admin/Event_Addon_Metabox.php');
if (! is_string($eventAddonPhp) || $eventAddonPhp === '') {
    fail('Unable to read event addon metabox source.');
}

if (! preg_match("/private const META_BOX_ID = 'oras-events-addon-metabox';/", $eventAddonPhp)) {
    fail('Event addon metabox wrapper id must not reuse the inner app root id.');
}
if (! preg_match('/<div id="oras-events-addon-root" class="oras-events-addon"/', $eventAddonPhp)) {
    fail('Event addon markup must render a dedicated inner root element.');
}
pass('Event addon markup uses distinct wrapper and inner root ids.');
