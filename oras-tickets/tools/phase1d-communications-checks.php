<?php
/**
 * Phase 1D communications checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase1d-communications-checks.php
 */

use ORAS\Tickets\Communication_Log_Store;
use ORAS\Tickets\Communication_Recipients;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Frontend\Event_RSVP;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasPhase1dCommunicationsException extends RuntimeException {}

function orasPhase1dFail(string $message): void
{
    throw new OrasPhase1dCommunicationsException($message);
}

function orasPhase1dAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasPhase1dFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1dAssertSame($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        orasPhase1dFail(
            sprintf(
                '%s. Expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1dRunChecks(): void
{
    Communication_Log_Store::install_schema();
    orasPhase1dAssert(Communication_Log_Store::table_exists(), 'Communication log table exists');

    $suffix = wp_generate_password(8, false);
    $event_id = wp_insert_post(
        array(
            'post_type'   => 'tribe_events',
            'post_status' => 'publish',
            'post_title'  => 'ORAS Phase1D Communications ' . $suffix,
        ),
        true
    );
    orasPhase1dAssert(is_int($event_id) && $event_id > 0, 'Fixture event created');

    $shared_email = 'phase1d_shared_' . $suffix . '@example.org';
    $onsite_user_id = wp_create_user('phase1d_onsite_' . $suffix, wp_generate_password(20, true, true), $shared_email);
    $virtual_user_id = wp_create_user('phase1d_virtual_' . $suffix, wp_generate_password(20, true, true), 'phase1d_virtual_' . $suffix . '@example.org');
    $sender_user_id = wp_create_user('phase1d_sender_' . $suffix, wp_generate_password(20, true, true), 'phase1d_sender_' . $suffix . '@example.org');
    orasPhase1dAssert(is_int($onsite_user_id) && $onsite_user_id > 0, 'Onsite RSVP user created');
    orasPhase1dAssert(is_int($virtual_user_id) && $virtual_user_id > 0, 'Virtual RSVP user created');
    orasPhase1dAssert(is_int($sender_user_id) && $sender_user_id > 0, 'Sender user created');

    update_user_meta($onsite_user_id, '_oras_rsvp_event_' . $event_id, 'yes');
    update_user_meta($onsite_user_id, '_oras_rsvp_event_' . $event_id . '_attendance_mode', Ticket::ATTENDANCE_MODE_ONSITE);
    update_user_meta($onsite_user_id, '_oras_rsvp_event_' . $event_id . '_contact', array('email' => $shared_email));

    update_user_meta($virtual_user_id, '_oras_rsvp_event_' . $event_id, 'yes');
    update_user_meta($virtual_user_id, '_oras_rsvp_event_' . $event_id . '_attendance_mode', Ticket::ATTENDANCE_MODE_VIRTUAL);
    update_user_meta($virtual_user_id, '_oras_rsvp_event_' . $event_id . '_approval_status', Event_RSVP::APPROVAL_STATUS_PENDING);

    $resolver = new Communication_Recipients();
    $all = $resolver->resolve($event_id, Communication_Recipients::SEGMENT_ALL_ATTENDEES);
    $pending_virtual = $resolver->resolve($event_id, Communication_Recipients::SEGMENT_PENDING_VIRTUAL);
    $onsite = $resolver->resolve($event_id, Communication_Recipients::SEGMENT_ONSITE);

    orasPhase1dAssertSame(count($all), 2, 'All attendees segment deduplicates valid emails');
    orasPhase1dAssertSame(count($pending_virtual), 1, 'Pending virtual segment resolves one recipient');
    orasPhase1dAssertSame((string) ($pending_virtual[0]['approval_status'] ?? ''), Event_RSVP::APPROVAL_STATUS_PENDING, 'Pending virtual recipient preserves approval status');
    orasPhase1dAssertSame(count($onsite), 1, 'On-site segment resolves one recipient');

    $sender = get_user_by('id', $sender_user_id);
    orasPhase1dAssert($sender instanceof WP_User, 'Sender user loaded');
    wp_set_current_user($sender_user_id);

    $log_id = Communication_Log_Store::insert(
        array(
            'event_id'               => $event_id,
            'sender_user_id'         => get_current_user_id(),
            'sender_display_name'    => $sender->display_name,
            'sender_email'           => $sender->user_email,
            'recipient_segment'      => Communication_Recipients::SEGMENT_PENDING_VIRTUAL,
            'recipient_count'        => count($pending_virtual),
            'email_subject'          => 'Phase 1D subject',
            'email_body_snapshot'    => 'Phase 1D body',
            'send_status'            => 'sent',
            'failed_recipient_count' => 0,
            'related_action_type'    => 'mass_email',
        )
    );
    orasPhase1dAssert($log_id > 0, 'Communication log insert succeeds');

    $log = Communication_Log_Store::get($log_id);
    orasPhase1dAssert(is_array($log), 'Communication log detail loads');
    orasPhase1dAssertSame((int) $log['sender_user_id'], (int) $sender_user_id, 'Communication log captures sender user id from current user');
    orasPhase1dAssertSame((string) $log['sender_email'], (string) $sender->user_email, 'Communication log captures sender email');

    wp_delete_user((int) $onsite_user_id);
    wp_delete_user((int) $virtual_user_id);
    wp_delete_user((int) $sender_user_id);
    wp_delete_post((int) $event_id, true);
    wp_set_current_user(0);
}

try {
    orasPhase1dRunChecks();
    echo "Phase 1D communications checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 1D communications checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
