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

ORAS-Tickets grants its event-management capabilities to existing `administrator`, `board`, and `board_member` roles.
The role must already exist for capabilities to be assigned.

Required capabilities for this release:

- `oras_tickets_view_board_dashboard`
- `oras_tickets_send_notifications`
- `oras_tickets_manage_rsvps`, when board users are expected to approve or reject RSVPs

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

The schema is installed or upgraded by `ORAS\Tickets\Communication_Log_Store::maybe_upgrade()` and tracked with the `oras_tickets_communications_schema_version` option.

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
10. Approve and reject controlled virtual RSVPs and confirm only the approved email contains the virtual meeting link.

## Rollback Steps

1. Put the site in a maintenance or deployment-safe state if needed.
2. Restore the previous ORAS-Tickets plugin version.
3. Restore the previous ORAS-Member-Hub plugin version.
4. Clear page, object, and CDN caches.
5. Verify the Board Reports page still renders under the previous release behavior.
6. Do not drop `{$wpdb->prefix}oras_ticket_communications` during a normal rollback; it is an audit log. Drop it only after an explicit operations/legal decision.
7. Restore the database backup only if rollback must remove communication records or RSVP approval metadata created during the release.

## Remaining Production Checks

These cannot be proven from public HTTP and must be verified with authenticated production WordPress access:

- The production `/board-reports/` page raw content contains `[oras_board_reports]`.
- `board` and `board_member` roles exist in production.
- `board` and `board_member` roles have the required ORAS-Tickets capabilities.
- The communication log table exists after deployment.
- Production mail delivery works through the configured mailer.
