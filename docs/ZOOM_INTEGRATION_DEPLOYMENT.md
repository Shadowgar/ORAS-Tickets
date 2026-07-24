# ORAS Zoom Integration Deployment

## Production Prerequisites

1. Create a Zoom Server-to-Server OAuth app in the ORAS-owned Zoom account.
2. Grant these granular Server-to-Server OAuth scopes:
   - `meeting:read:meeting:admin`
   - `meeting:read:invitation:admin`
   - `meeting:write:registrant:admin`
   - `meeting:update:registrant_status:admin`
   - `meeting:update:meeting:admin`
3. Add a separate high-entropy `ORAS_TICKETS_ZOOM_AES_KEY` constant to production `wp-config.php`.
4. Deploy ORAS-Tickets 0.4.45 and reactivate it, or load one normal request, so the registration schema upgrade runs.
5. Open **ORAS Tickets > Zoom**, enter the account ID, client ID, and client secret, enable the integration, save, and run **Test Zoom Connection**.

## Event Setup

1. Create or connect the Zoom meeting through The Events Calendar Virtual Event controls.
2. Open **ORAS Events Addon > Zoom Automation** on the event.
3. Confirm the detected meeting ID. Use the override only when automatic detection cannot resolve the TEC Zoom meeting.
4. Enable **Manage virtual attendees through Zoom registration**.
5. Enable **Allow approved attendees to join anytime without a host** when the meeting must operate without a person signing into the ORAS host account.
6. Save the event and allow the queued synchronization to run, or use **Sync Zoom Settings** for immediate verification. Confirm the Zoom Automation panel reports that unattended access synchronized.
7. Confirm the Zoom meeting uses automatic registration approval. Zoom must return a registrant-specific join URL.

Paid virtual ticket buyers are registered after the WooCommerce order reaches processing or completed. Virtual RSVP attendees are registered only after board approval. Pending, rejected, and cancelled RSVPs do not receive a private Zoom link.

## Unattended Meetings

When unattended access is enabled, ORAS-Tickets updates and verifies these
meeting settings through the Zoom Meetings API:

- `join_before_host: true`
- `jbh_time: 0` (participants may join at any time)
- `waiting_room: false`
- `audio: both` (Telephone and Computer Audio)

Zoom does not allow join-before-host to operate while Waiting Room is enabled.
Registration, meeting passcodes, and ORAS ticket/RSVP eligibility remain the
access controls. No host controls are available until an authorized Zoom host
joins the meeting, and ORAS-Tickets never distributes Zoom's host start URL.

The Server-to-Server OAuth app must have permission to update meetings in
addition to reading meeting details, reading invitations, and managing
registrants. If account-level Zoom policy locks Waiting Room or disables
join-before-host, the event editor reports that synchronization failed.

Existing events are not silently changed during plugin upgrade. Open the event,
enable unattended access in **Zoom Automation**, and save or select
**Sync Zoom Settings**.

Queued synchronization retries temporary Zoom rate-limit and server failures
three times. Synchronization revisions prevent an older queued job from
overwriting a newer event configuration.

## Schema

The plugin installs and upgrades:

- `{$wpdb->prefix}oras_zoom_registrations`
- `{$wpdb->prefix}oras_zoom_registration_sources`

The first table stores one Zoom registrant per event, meeting, and email. The second tracks independent ticket and RSVP entitlements so cancelling one source does not revoke another valid source. Private join URLs are encrypted at rest.

## Verification

Use a non-production Zoom test meeting to verify:

1. A paid virtual ticket order receives a unique join URL plus meeting ID, passcode, and dial-in details.
2. An approved virtual RSVP receives a unique join URL.
3. Pending and rejected virtual RSVP emails contain no join URL.
4. Cancelling or refunding the final ticket entitlement cancels the Zoom registrant.
5. Cancelling or rejecting the final RSVP entitlement cancels the Zoom registrant.
6. A user with both a paid ticket and an approved RSVP retains access when only one entitlement is removed.

## Rollback

1. Disable managed registration on affected events, or disable the global Zoom integration.
2. Existing ORAS shared-link email behavior will remain available as the fallback.
3. Roll back the plugin files to 0.4.44 if required.
4. Do not drop the Zoom registration tables during an application rollback. They contain the entitlement audit needed to avoid duplicate registrants when 0.4.44 is restored.
5. Revoke the Zoom Server-to-Server OAuth app credentials if credential exposure is suspected.
