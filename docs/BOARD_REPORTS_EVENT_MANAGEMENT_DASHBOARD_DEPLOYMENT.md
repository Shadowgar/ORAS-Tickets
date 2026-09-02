# Board Reports Event Management Dashboard Deployment

## Scope

This release keeps the Board Reports / Event Management Dashboard inside ORAS-Tickets.
ORAS-Member-Hub only links board-facing users to the ORAS-Tickets dashboard page.

Release commits:

- ORAS-Tickets: `7a172e0 Add virtual RSVP approval workflow`
- ORAS-Member-Hub: `8dabdf5 Route board reports link to tickets dashboard`

## Page Requirement

Production and local test environments must have a published WordPress page containing:

```text
[oras_board_reports]
```

The expected public path is `/board-reports/`.

ORAS-Member-Hub resolves the first published page containing `[oras_board_reports]`.
If no matching page is found, it falls back to `home_url( '/board-reports/' )`.

Authenticated production preflight:

```bash
wp db query "SELECT ID, post_title, post_name, post_status FROM $(wp db prefix)posts WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[oras_board_reports]%' ORDER BY ID"
```

## Roles And Capabilities

ORAS-Tickets reconciles distinct capability sets for `administrator`, `board`, `board_member`, `treasurer`, and the existing `event_creator` role displayed as Event Coordinator.
The role must already exist for capabilities to be assigned.

Required capabilities for this release:

- `oras_tickets_view_board_dashboard`
- `oras_tickets_send_notifications`
- `oras_tickets_manage_rsvps`, when board users are expected to approve or reject RSVPs
- `oras_tickets_view_attendees`

Board and Board Member do not receive event editing, export, attendee-record editing, or settings capabilities. Event Coordinator receives event operations, reports, exports, tickets, RSVP, communications, agenda, questions, speakers, media, and virtual-event capabilities, but not ORAS Tickets or Events Calendar settings.

Authenticated production preflight:

```bash
wp eval 'foreach (array("board", "board_member") as $slug) { $role = get_role($slug); echo $slug . ":" . ($role ? "exists" : "missing") . PHP_EOL; if ($role) { foreach (array("oras_tickets_view_board_dashboard", "oras_tickets_send_notifications", "oras_tickets_manage_rsvps") as $cap) { echo "  {$cap}:" . ($role->has_cap($cap) ? "yes" : "no") . PHP_EOL; } } }'
```

If a required role exists but is missing a capability, run the plugin capability bootstrap:

```bash
wp eval '\ORAS\Tickets\Capabilities::add_caps(); \ORAS\Tickets\Capabilities::ensure_board_communication_caps();'
```

## Database And Schema

This release adds the ORAS-Tickets communication log table:

```text
{$wpdb->prefix}oras_ticket_communications
```

The schema is installed or upgraded by `ORAS\Tickets\Communication_Log_Store::maybe_upgrade()` and tracked with the `oras_tickets_communications_schema_version` option. Schema version 2 adds queued-delivery payload and progress fields. Payloads are cleared after delivery.

Authenticated production preflight:

```bash
wp eval '\ORAS\Tickets\Communication_Log_Store::maybe_upgrade(); global $wpdb; $table = $wpdb->prefix . "oras_ticket_communications"; echo $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table ? "communication log table exists\n" : "communication log table missing\n";'
```

RSVP approval metadata is stored in existing RSVP user-meta keys:

- `_oras_rsvp_event_{event_id}_approval_status`
- `_oras_rsvp_event_{event_id}_approved_by`
- `_oras_rsvp_event_{event_id}_approved_at`
- `_oras_rsvp_event_{event_id}_rejection_reason`

Existing RSVP records without attendance mode default to `onsite`.
Existing RSVP records without approval status default to `approved`.

## Admin-Post Actions

ORAS-Tickets owns these dashboard actions:

- `oras_board_reports_export_csv`
- `oras_board_reports_export_spreadsheet`
- `oras_board_reports_export_pdf`
- `oras_board_reports_send_communication`
- `oras_board_reports_update_rsvp_approval`

ORAS-Member-Hub does not add event reporting routes, AJAX handlers, or duplicate reporting logic.

## Virtual Access

Virtual meeting settings continue to use existing ORAS-Tickets / The Events Calendar virtual access storage, including `_oras_virtual_access_v1` and provider join URL metadata such as Zoom join URLs.

The virtual meeting link must not be public. It is included only in approved virtual RSVP emails. Pending and rejected virtual RSVP messages do not include the meeting link.

## Deployment Steps

1. Back up production files and database.
2. Deploy ORAS-Tickets at `7a172e0` or the approved release commit that contains it.
3. Deploy ORAS-Member-Hub at `8dabdf5` or the approved release commit that contains it.
4. Confirm both plugins are active.
5. Run the capability and communication-log preflight commands above.
6. Confirm a published `/board-reports/` page contains `[oras_board_reports]`.
7. Clear page, object, and CDN caches.
8. Smoke-test as administrator, board, board_member, treasurer, and unauthorized/basic member.
9. Send one controlled test communication and confirm the communication log captures the current WordPress sender user ID, display name, and email.
10. Confirm the message first shows `queued`, then reaches `sent` or `partial` through Action Scheduler.
11. Approve and reject controlled virtual RSVPs and confirm only the approved email contains the virtual meeting link.

## Rollback Steps

1. Put the site in a maintenance or deployment-safe state if needed.
2. Restore the previous ORAS-Tickets plugin version.
3. Restore the previous ORAS-Member-Hub plugin version.
4. Clear page, object, and CDN caches.
5. Verify the Board Reports page still renders under the previous release behavior.
6. Do not drop `{$wpdb->prefix}oras_ticket_communications` during a normal rollback; it is an audit log. Drop it only after an explicit operations/legal decision.
7. Restore the database backup only if rollback must remove communication records or RSVP approval metadata created during the release.

## ORAS Tickets 0.4.51 Observer Pass Verification

The Observer Passes release is a read-only reporting update. It requires no database schema migration, no order-data migration, and introduces no production order writes or other persistent data. It grants no new WordPress or WooCommerce administration capabilities; access continues to use the existing `oras_tickets_view_board_dashboard` capability.

The report depends on the existing Annual and Daily Observer Pass product identifiers and the existing Daily booking metadata contract (`_wapbk_booking_date`, `_wapbk_checkout_date`, and `_wapbk_booking_status`). Confirm those identifiers and metadata remain populated before deployment.

After updating and activating ORAS Tickets 0.4.51:

1. Open Board Reports as a Board user and select `Observer Passes`.
2. Verify the four summary cards: `Active Annual Passes`, `Daily Passes Today`, `Upcoming Daily Passes — Next 7 Days`, and `Upcoming Daily Passes — This Month` against known orders.
3. Verify active and expiring Annual passes, including the purchaser/passholder search.
4. Verify a multi-night Daily pass remains valid through the night before its exclusive checkout date.
5. Verify refunded, cancelled, failed, unpaid, and unconfirmed rows remain historical or invalid and do not enter valid operational counts.
6. Exercise filtering, search, pagination, expandable details, and the secure printable Today list on desktop and mobile widths.

Rollback is accomplished by restoring the previous ORAS Tickets plugin version and clearing application/CDN caches. No data rollback is required because Observer Pass reporting adds no schema and writes no order data.

## ORAS Tickets 0.4.52 Membership and Manual Pass Verification

This release adds unified PMPro and Legacy PayPal membership reporting plus frontend management of manual Annual Observer Passes. It adds no custom database tables and does not modify PMPro memberships or WooCommerce orders. Manual passes and imported legacy memberships are stored as private, non-public plugin post types with namespaced metadata.

Before deployment, retain the normal database and plugin backups. After updating and activating ORAS Tickets 0.4.52:

1. Open Board Reports as an authorized Board user and confirm the `Memberships` and `Observer Passes` tabs load.
2. Confirm PMPro rows show website account, level, source status, start date, end date, and operational status; ordinary WordPress users without PMPro history must not appear.
3. Confirm existing Annual and Daily WooCommerce Observer rows remain labeled `Website`, while manual Annual rows remain labeled `Manual / Offline` and contribute to the shared Active Annual total.
4. Preview the native PayPal Active Subscriptions CSV and verify metadata/footer rows are ignored, duplicate-email profiles are held for review, exact-email PMPro matches are identified, and `Next Bill Date` is labeled `Next Renewal`.
5. Commit only approved import rows, then repeat the preview to confirm the same PayPal Profile IDs are treated as existing records instead of duplicates.
6. Confirm users with view-only Board access cannot see membership or manual-pass management controls, and that managers receive no WooCommerce or PMPro administration permissions.

Rollback is accomplished by restoring the previous ORAS Tickets plugin version and clearing application/CDN caches. Do not delete `oras_manual_pass` or `oras_legacy_member` records during a code rollback; the previous release ignores them and a later 0.4.52 restoration can read them again.

## Remaining Production Checks

These cannot be proven from public HTTP and must be verified with authenticated production WordPress access:

- The production `/board-reports/` page raw content contains `[oras_board_reports]`.
- `board` and `board_member` roles exist in production.
- `board` and `board_member` roles have the required ORAS-Tickets capabilities.
- The communication log table exists after deployment.
- Production mail delivery works through the configured mailer.
