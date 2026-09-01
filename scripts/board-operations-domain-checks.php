<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_Error {
    public function __construct(public string $code = '', public string $message = '') {}
}

/** @var array<string,array<string,mixed>> $registered_post_types */
$registered_post_types = array();

function register_post_type(string $post_type, array $args): void
{
    global $registered_post_types;
    $registered_post_types[$post_type] = $args;
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_textarea_field(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_email(string $value): string
{
    return filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? strtolower(trim($value)) : '';
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function __(string $value, string $domain = ''): string
{
    unset($domain);
    return $value;
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function oras_domain_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$required = array(
    __DIR__ . '/../oras-tickets/includes/Domain/Annual_Pass_Validity.php',
    __DIR__ . '/../oras-tickets/includes/Storage/Manual_Observer_Pass_Store.php',
    __DIR__ . '/../oras-tickets/includes/Storage/Legacy_Membership_Store.php',
);
foreach ($required as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}
require_once __DIR__ . '/../oras-tickets/includes/Capabilities.php';

oras_domain_assert(class_exists(\ORAS\Tickets\Domain\Annual_Pass_Validity::class), 'Annual validity service exists');
oras_domain_assert(class_exists(\ORAS\Tickets\Storage\Manual_Observer_Pass_Store::class), 'Manual Observer Pass store exists');
oras_domain_assert(class_exists(\ORAS\Tickets\Storage\Legacy_Membership_Store::class), 'Legacy membership store exists');

use ORAS\Tickets\Capabilities;
use ORAS\Tickets\Domain\Annual_Pass_Validity;
use ORAS\Tickets\Storage\Legacy_Membership_Store;
use ORAS\Tickets\Storage\Manual_Observer_Pass_Store;

$timezone = new DateTimeZone('America/New_York');
$expiration = Annual_Pass_Validity::expiration_for(new DateTimeImmutable('2025-09-10', $timezone));
oras_domain_assert('2026-09-10' === $expiration->format('Y-m-d'), 'Annual expiration preserves purchase anniversary');

$leap_expiration = Annual_Pass_Validity::expiration_for(new DateTimeImmutable('2024-02-29', $timezone));
oras_domain_assert('2025-03-01' === $leap_expiration->format('Y-m-d'), 'Annual leap-day expiration advances to March 1');
oras_domain_assert(
    Annual_Pass_Validity::STATUS_EXPIRING_SOON === Annual_Pass_Validity::state_for($expiration, new DateTimeImmutable('2026-08-11', $timezone))['status'],
    'Annual pass enters expiring-soon state 30 days before expiration'
);
oras_domain_assert(
    Annual_Pass_Validity::STATUS_EXPIRED === Annual_Pass_Validity::state_for($expiration, new DateTimeImmutable('2026-09-10', $timezone))['status'],
    'Annual pass expires on the stored anniversary boundary'
);

Manual_Observer_Pass_Store::register_post_type();
Legacy_Membership_Store::register_post_type();

foreach (array(Manual_Observer_Pass_Store::POST_TYPE, Legacy_Membership_Store::POST_TYPE) as $post_type) {
    $args = $registered_post_types[$post_type] ?? array();
    foreach (array('public', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_rest', 'has_archive', 'rewrite', 'query_var') as $key) {
        oras_domain_assert(false === ($args[$key] ?? null), "{$post_type} disables {$key}");
    }
    oras_domain_assert(true === ($args['exclude_from_search'] ?? false), "{$post_type} is excluded from search");
}

$manual = Manual_Observer_Pass_Store::sanitize_record(
    array(
        'holder_names'  => array('John O’Hara', 'Kelly O’Hara'),
        'quantity'      => 1,
        'email'         => ' Family@Example.org ',
        'start_date'    => '2026-05-25',
        'source'        => 'cash',
        'linked_user_id'=> 42,
        'notes'         => "Paid offline\nConfirmed",
        'record_state'  => 'active',
    )
);
oras_domain_assert(!is_wp_error($manual), 'Valid manual Annual record is accepted');
oras_domain_assert(2 === count($manual['holder_names']), 'Manual record preserves multiple holder names');
oras_domain_assert(1 === $manual['quantity'], 'Manual quantity remains explicit and independent of holder count');
oras_domain_assert('2027-05-25' === $manual['expiration_date'], 'Manual expiration uses shared Annual validity logic');
oras_domain_assert('family@example.org' === $manual['email'], 'Manual email is normalized');

$invalid_manual = Manual_Observer_Pass_Store::sanitize_record(array('holder_names' => array(), 'start_date' => '05/25/26'));
oras_domain_assert(is_wp_error($invalid_manual), 'Manual record rejects missing holders and non-ISO date');

$legacy = Legacy_Membership_Store::sanitize_record(
    array(
        'member_name'     => 'Legacy Member',
        'email'           => ' LEGACY@example.org ',
        'start_date'      => '2025-10-01',
        'end_date'        => '2026-10-01',
        'status'          => 'active',
        'paypal_reference'=> 'I-ABC123',
        'linked_user_id'  => 0,
        'transitioned'    => false,
        'notes'           => 'Legacy subscription',
    )
);
oras_domain_assert(!is_wp_error($legacy), 'Valid legacy membership record is accepted');
oras_domain_assert('legacy@example.org' === $legacy['email'], 'Legacy email is normalized');
oras_domain_assert('legacy_paypal' === $legacy['source'], 'Legacy source is fixed');

$invalid_legacy = Legacy_Membership_Store::sanitize_record(array('member_name' => 'Missing Date', 'end_date' => ''));
oras_domain_assert(is_wp_error($invalid_legacy), 'Legacy record requires a valid expiration or renewal date');

oras_domain_assert(in_array('oras_tickets_manage_observer_passes', Capabilities::CAPS, true), 'Manual Observer Pass management capability is managed');
oras_domain_assert(in_array('oras_tickets_manage_memberships', Capabilities::CAPS, true), 'Membership management capability is managed');
oras_domain_assert(!in_array('oras_tickets_manage_observer_passes', Capabilities::BOARD_CAPS, true), 'Board viewers do not automatically manage Observer Passes');
oras_domain_assert(!in_array('oras_tickets_manage_memberships', Capabilities::BOARD_CAPS, true), 'Board viewers do not automatically manage memberships');

echo "Board operations domain checks passed.\n";
