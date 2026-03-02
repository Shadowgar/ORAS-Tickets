<?php
/**
 * Core regression checks for Phase 0-2 hardening.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/core-regression-checks.php
 */

use ORAS\Tickets\Capabilities;
use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasCoreRegressionException extends RuntimeException {}

/**
 * @throws OrasCoreRegressionException
 */
function orasCoreFail(string $message): void
{
    throw new OrasCoreRegressionException($message);
}

/**
 * @throws OrasCoreRegressionException
 */
function orasCoreAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasCoreFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

/**
 * @param mixed $actual
 * @param mixed $expected
 *
 * @throws OrasCoreRegressionException
 */
function orasCoreAssertSame($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        orasCoreFail(
            sprintf(
                '%s (expected=%s actual=%s)',
                $message,
                wp_json_encode($expected),
                wp_json_encode($actual)
            )
        );
    }

    echo 'PASS: ' . $message . "\n";
}

/**
 * @throws OrasCoreRegressionException
 */
function orasCoreCreateEvent(string $suffix): int
{
    $event_id = wp_insert_post(
        array(
            'post_type'   => post_type_exists(Meta::EVENT_POST_TYPE) ? Meta::EVENT_POST_TYPE : 'post',
            'post_status' => 'publish',
            'post_title'  => 'Core Regression Event ' . $suffix,
        ),
        true
    );

    if (is_wp_error($event_id) || ! is_int($event_id) || $event_id <= 0) {
        orasCoreFail('Failed to create event post for core regression checks.');
    }

    return $event_id;
}

/**
 * @throws OrasCoreRegressionException
 */
function orasCoreCreateUser(string $prefix, string $suffix, string $role): int
{
    $login = sanitize_user($prefix . '_' . $suffix . '_' . wp_generate_password(4, false));
    $email = $login . '@example.org';
    $pass  = wp_generate_password(20, true, true);
    $id    = wp_create_user($login, $pass, $email);

    if (! is_int($id) || $id <= 0) {
        orasCoreFail('Unable to create user: ' . $prefix);
    }

    $user = new WP_User($id);
    $user->set_role($role);

    return $id;
}

/**
 * @throws OrasCoreRegressionException
 */
function orasCoreRunPhase1EnvelopeChecks(int $event_id): void
{
    update_post_meta(
        $event_id,
        Meta::META_KEY_TICKETS,
        array(
            'schema'  => 2,
            'tickets' => array(
                'legacy_ticket' => array(
                    'ticket_key' => 'legacy_ticket',
                    'name'       => 'Legacy Ticket',
                    'price'      => '10.00',
                ),
            ),
        )
    );

    $unsupported_schema_collection = Ticket_Collection::load_for_event($event_id);
    orasCoreAssertSame(
        $unsupported_schema_collection->count(),
        0,
        'Unsupported schema envelope loads as empty collection'
    );

    update_post_meta(
        $event_id,
        Meta::META_KEY_TICKETS,
        array(
            'schema'  => 1,
            'tickets' => array(
                'fallback_ticket_key' => array(
                    'name'  => 'Fallback Ticket',
                    'price' => '15.00',
                ),
            ),
        )
    );

    $fallback_collection = Ticket_Collection::load_for_event($event_id);
    orasCoreAssertSame(
        $fallback_collection->count(),
        1,
        'Schema 1 envelope with one row loads one ticket object'
    );

    $fallback_ticket = $fallback_collection->all()[0] ?? null;
    orasCoreAssert(
        $fallback_ticket instanceof \ORAS\Tickets\Domain\Ticket,
        'Loaded fallback ticket is a Ticket object'
    );
    orasCoreAssertSame(
        $fallback_ticket instanceof \ORAS\Tickets\Domain\Ticket ? $fallback_ticket->ticket_key : null,
        'fallback_ticket_key',
        'Missing ticket_key falls back to envelope row key'
    );

    $generated_key_a = Ticket_Collection::generate_ticket_key();
    $generated_key_b = Ticket_Collection::generate_ticket_key();
    orasCoreAssert(
        is_string($generated_key_a) && strlen($generated_key_a) === 12,
        'Generated ticket key uses expected 12-character length'
    );
    orasCoreAssert(
        $generated_key_a !== $generated_key_b,
        'Generated ticket keys are unique across consecutive calls'
    );
}

/**
 * @throws OrasCoreRegressionException
 */
function orasCoreRunChecks(): void
{
    if (! defined('ORAS_TICKETS_DIR')) {
        orasCoreFail('ORAS_TICKETS_DIR not defined. Ensure oras-tickets plugin is active.');
    }

    Capabilities::add_caps();

    $admin_ids = get_users(
        array(
            'role'   => 'administrator',
            'fields' => 'ids',
            'number' => 1,
        )
    );
    orasCoreAssert(! empty($admin_ids), 'Administrator user exists');

    $admin_id = (int) $admin_ids[0];

    foreach (Capabilities::CAPS as $capability) {
        orasCoreAssert(
            user_can($admin_id, (string) $capability),
            'Administrator has ORAS capability: ' . (string) $capability
        );
    }

    foreach (Capabilities::TREASURER_ONLY_CAPS as $capability) {
        orasCoreAssert(
            user_can($admin_id, (string) $capability),
            'Administrator has treasurer capability: ' . (string) $capability
        );
    }

    $suffix        = gmdate('YmdHis') . '_' . wp_rand(1000, 9999);
    $subscriber_id = orasCoreCreateUser('oras_core_sub', $suffix, 'subscriber');
    $event_id      = orasCoreCreateEvent($suffix);

    try {
        foreach (Capabilities::CAPS as $capability) {
            orasCoreAssert(
                ! user_can($subscriber_id, (string) $capability),
                'Subscriber does not inherit ORAS capability: ' . (string) $capability
            );
        }

        foreach (Capabilities::TREASURER_ONLY_CAPS as $capability) {
            orasCoreAssert(
                ! user_can($subscriber_id, (string) $capability),
                'Subscriber does not inherit treasurer capability: ' . (string) $capability
            );
        }

        delete_post_meta($event_id, Meta::META_KEY_TICKETS);
        $missing_envelope = Ticket_Collection::load_envelope_for_event($event_id);
        orasCoreAssertSame($missing_envelope['schema'] ?? null, 1, 'Missing ticket envelope defaults schema to 1');
        orasCoreAssertSame($missing_envelope['tickets'] ?? null, array(), 'Missing ticket envelope defaults tickets to empty array');

        update_post_meta(
            $event_id,
            Meta::META_KEY_TICKETS,
            array(
                'schema'  => 1,
                'tickets' => array(
                    'ticket_a' => array(
                        'ticket_key'    => 'ticket_a',
                        'label'         => 'Ticket A',
                        'capacity'      => 10,
                        'price'         => '25',
                        'price_phases'  => array(
                            array(
                                'label' => 'Early',
                                'price' => '20',
                            ),
                        ),
                    ),
                ),
            )
        );

        Ticket_Collection::save_for_event(
            $event_id,
            array(
                'schema'  => 1,
                'tickets' => array(
                    'ticket_a' => array(
                        'ticket_key' => 'ticket_a',
                        'label'      => 'Ticket A Updated',
                        'capacity'   => 12,
                        'price'      => '30',
                    ),
                ),
            )
        );

        $saved_envelope = Ticket_Collection::load_envelope_for_event($event_id);
        $saved_ticket   = is_array($saved_envelope['tickets'] ?? null) ? ($saved_envelope['tickets']['ticket_a'] ?? array()) : array();

        orasCoreAssert(
            isset($saved_ticket['price_phases']) && is_array($saved_ticket['price_phases']) && count($saved_ticket['price_phases']) === 1,
            'Price phases are preserved when omitted from save payload'
        );

        Ticket_Collection::save_for_event(
            $event_id,
            array(
                'schema'  => 1,
                'tickets' => array(
                    'ticket_a' => array(
                        'ticket_key'   => 'ticket_a',
                        'label'        => 'Ticket A Invalid Phases',
                        'capacity'     => 8,
                        'price'        => '22',
                        'price_phases' => array('invalid_phase_shape'),
                    ),
                ),
            )
        );

        $saved_envelope = Ticket_Collection::load_envelope_for_event($event_id);
        $saved_ticket   = is_array($saved_envelope['tickets'] ?? null) ? ($saved_envelope['tickets']['ticket_a'] ?? array()) : array();

        orasCoreAssertSame(
            isset($saved_ticket['price_phases']) ? $saved_ticket['price_phases'] : null,
            array(),
            'Invalid price phase shapes are normalized to empty array'
        );

        orasCoreRunPhase1EnvelopeChecks($event_id);
    } finally {
        if ($subscriber_id > 0) {
            wp_delete_user($subscriber_id);
        }
        if ($event_id > 0) {
            wp_delete_post($event_id, true);
        }
    }
}

try {
    orasCoreRunChecks();
    echo "Core regression checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Core regression checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
