<?php
/**
 * Phase 1F virtual access and RSVP UI checks.
 *
 * Runs inside wp-env via:
 *   wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase1f-virtual-access-rsvp-ui-checks.php
 */

use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Frontend\Virtual_Access;

if (! defined('ABSPATH')) {
    exit(1);
}

final class OrasPhase1fVirtualAccessException extends RuntimeException {}

final class OrasPhase1fTemplateStub
{
    private WP_Post $post;

    public function __construct(WP_Post $post)
    {
        $this->post = $post;
    }

    public function get(string $key)
    {
        if ('post' === $key || 'event' === $key) {
            return $this->post;
        }

        return '';
    }
}

function orasPhase1fFail(string $message): void
{
    throw new OrasPhase1fVirtualAccessException($message);
}

function orasPhase1fAssert(bool $condition, string $message): void
{
    if (! $condition) {
        orasPhase1fFail($message);
    }

    echo 'PASS: ' . $message . "\n";
}

function orasPhase1fCreateUser(string $prefix, string $suffix): int
{
    $user_id = wp_create_user(
        $prefix . '_' . $suffix,
        wp_generate_password(20, true, true),
        $prefix . '_' . $suffix . '@example.org'
    );

    if (! is_int($user_id) || $user_id <= 0) {
        orasPhase1fFail('Unable to create user: ' . $prefix);
    }

    return $user_id;
}

function orasPhase1fStoreRsvp(int $event_id, int $user_id, string $attendance_mode, string $approval_status): void
{
    update_user_meta($user_id, '_oras_rsvp_event_' . $event_id, 'yes');
    update_user_meta($user_id, '_oras_rsvp_event_' . $event_id . '_attendance_mode', $attendance_mode);
    update_user_meta($user_id, '_oras_rsvp_event_' . $event_id . '_approval_status', $approval_status);
    update_user_meta(
        $user_id,
        '_oras_rsvp_event_' . $event_id . '_contact',
        array(
            'first_name' => 'Phase1F',
            'last_name'  => 'Attendee',
            'email'      => 'phase1f_' . $user_id . '@example.org',
        )
    );
}

function orasPhase1fCanSeeVirtualDetails(int $event_id, int $user_id): bool
{
    wp_set_current_user($user_id);
    $post = get_post($event_id);
    if (! $post instanceof WP_Post) {
        orasPhase1fFail('Fixture event was not found');
    }

    $html = Virtual_Access::filter_virtual_details_html(
        '<div class="tribe-events-virtual-single-zoom-details">PRIVATE-ZOOM-DETAILS</div>',
        'events-pro/zoom/zoom-details',
        'zoom-details',
        new OrasPhase1fTemplateStub($post),
        array('event' => $post)
    );

    return is_string($html) && false !== strpos($html, 'PRIVATE-ZOOM-DETAILS');
}

function orasPhase1fCreateVirtualTicketOrder(int $event_id, int $user_id, string $suffix): void
{
    if (! function_exists('wc_create_order')) {
        orasPhase1fFail('WooCommerce is required for virtual ticket purchaser check');
    }

    $product_id = wp_insert_post(
        array(
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_title'  => 'Phase 1F Virtual Ticket ' . $suffix,
        ),
        true
    );
    if (! is_int($product_id) || $product_id <= 0) {
        orasPhase1fFail('Unable to create virtual ticket product');
    }

    update_post_meta($product_id, '_oras_ticket_event_id', (string) $event_id);
    update_post_meta($product_id, '_oras_ticket_attendance_mode', Ticket::ATTENDANCE_MODE_VIRTUAL);
    update_post_meta($product_id, '_price', '0');
    update_post_meta($product_id, '_regular_price', '0');
    update_post_meta($event_id, '_oras_tickets_woo_map_v1', array($product_id));

    $order = wc_create_order(array('customer_id' => $user_id));
    if (! $order instanceof WC_Order) {
        orasPhase1fFail('Unable to create Woo order');
    }

    $order->add_product(wc_get_product($product_id), 1);
    $order->set_status('completed');
    $order->save();
}

function orasPhase1fRenderRsvpBlock(int $event_id, int $user_id): string
{
    global $post, $wp_query, $wp_the_query;

    wp_set_current_user($user_id);
    $post = get_post($event_id);
    if (! $post instanceof WP_Post) {
        orasPhase1fFail('Fixture event was not found for RSVP render');
    }

    $previous_query = $wp_query;
    $previous_the_query = $wp_the_query;
    $wp_query = new WP_Query();
    $wp_query->is_singular = true;
    $wp_query->is_single = true;
    $wp_query->queried_object = $post;
    $wp_query->queried_object_id = $event_id;
    $wp_query->post = $post;
    $wp_query->posts = array($post);
    $wp_query->post_count = 1;
    $wp_query->current_post = 0;
    $wp_query->in_the_loop = true;
    $wp_the_query = $wp_query;

    setup_postdata($post);
    $html = Event_RSVP::render_rsvp_block('EVENT CONTENT');
    wp_reset_postdata();
    $wp_query = $previous_query;
    $wp_the_query = $previous_the_query;

    return $html;
}

function orasPhase1fRunChecks(): void
{
    $suffix = wp_generate_password(8, false);
    $event_id = wp_insert_post(
        array(
            'post_type'    => 'tribe_events',
            'post_status'  => 'publish',
            'post_title'   => 'ORAS Phase1F Virtual Access ' . $suffix,
            'post_content' => 'Phase 1F event details.',
        ),
        true
    );
    orasPhase1fAssert(is_int($event_id) && $event_id > 0, 'Fixture event created');

    update_post_meta($event_id, '_oras_rsvp_v1', array('enabled' => true, 'capacity' => 0, 'waitlist_enabled' => true));
    update_post_meta($event_id, '_oras_virtual_access_v1', array('version' => 1, 'show_to' => 'approved_virtual_attendees'));

    $approved_virtual_id = orasPhase1fCreateUser('phase1f_approved_virtual', $suffix);
    $pending_virtual_id = orasPhase1fCreateUser('phase1f_pending_virtual', $suffix);
    $rejected_virtual_id = orasPhase1fCreateUser('phase1f_rejected_virtual', $suffix);
    $onsite_id = orasPhase1fCreateUser('phase1f_onsite', $suffix);
    $ticket_id = orasPhase1fCreateUser('phase1f_ticket', $suffix);
    $plain_id = orasPhase1fCreateUser('phase1f_plain', $suffix);

    orasPhase1fStoreRsvp($event_id, $approved_virtual_id, Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_APPROVED);
    orasPhase1fStoreRsvp($event_id, $pending_virtual_id, Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_PENDING);
    orasPhase1fStoreRsvp($event_id, $rejected_virtual_id, Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_REJECTED);
    orasPhase1fStoreRsvp($event_id, $onsite_id, Ticket::ATTENDANCE_MODE_ONSITE, Event_RSVP::APPROVAL_STATUS_APPROVED);
    orasPhase1fCreateVirtualTicketOrder($event_id, $ticket_id, $suffix);

    orasPhase1fAssert(orasPhase1fCanSeeVirtualDetails($event_id, $approved_virtual_id), 'Approved virtual RSVP user can see virtual details');
    orasPhase1fAssert(! orasPhase1fCanSeeVirtualDetails($event_id, $pending_virtual_id), 'Pending virtual RSVP user cannot see virtual details');
    orasPhase1fAssert(! orasPhase1fCanSeeVirtualDetails($event_id, $rejected_virtual_id), 'Rejected virtual RSVP user cannot see virtual details');
    orasPhase1fAssert(! orasPhase1fCanSeeVirtualDetails($event_id, $onsite_id), 'On-site RSVP user cannot see virtual details');
    orasPhase1fAssert(orasPhase1fCanSeeVirtualDetails($event_id, $ticket_id), 'Virtual ticket purchaser can see virtual details');
    orasPhase1fAssert(! orasPhase1fCanSeeVirtualDetails($event_id, $plain_id), 'Logged-in non-attendee cannot see virtual details');
    orasPhase1fAssert(! orasPhase1fCanSeeVirtualDetails($event_id, 0), 'Logged-out visitor cannot see virtual details');

    $approved_html = orasPhase1fRenderRsvpBlock($event_id, $approved_virtual_id);
    orasPhase1fAssert(false !== strpos($approved_html, 'Status: RSVPed for Virtual'), 'Existing RSVP yes renders status badge');
    orasPhase1fAssert(false !== strpos($approved_html, '<details class="oras-rsvp-details"'), 'Existing RSVP yes renders collapsed details wrapper');
    orasPhase1fAssert(false !== strpos($approved_html, 'Change RSVP details'), 'Existing RSVP yes renders change details summary');
    orasPhase1fAssert(false === strpos($approved_html, '<details class="oras-rsvp-details" open'), 'Existing RSVP yes details are collapsed by default');

    $plain_html = orasPhase1fRenderRsvpBlock($event_id, $plain_id);
    orasPhase1fAssert(false === strpos($plain_html, '<details class="oras-rsvp-details"'), 'No RSVP renders normal expanded form');
    orasPhase1fAssert(false !== strpos($plain_html, 'class="oras-rsvp-form"'), 'No RSVP still renders RSVP form');

    update_user_meta($plain_id, '_oras_rsvp_event_' . $event_id, 'no');
    $no_html = orasPhase1fRenderRsvpBlock($event_id, $plain_id);
    orasPhase1fAssert(false === strpos($no_html, '<details class="oras-rsvp-details"'), 'No response renders normal expanded form');

    foreach (array($approved_virtual_id, $pending_virtual_id, $rejected_virtual_id, $onsite_id, $ticket_id, $plain_id) as $user_id) {
        wp_delete_user((int) $user_id);
    }
    wp_delete_post((int) $event_id, true);
    wp_set_current_user(0);
}

try {
    orasPhase1fRunChecks();
    echo "Phase 1F virtual access and RSVP UI checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 1F virtual access and RSVP UI checks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
