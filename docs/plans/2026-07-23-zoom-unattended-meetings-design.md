# Zoom Unattended Meetings Design

## Goal

Allow approved ORAS virtual attendees to enter an event's Zoom meeting at any
time without requiring a person to sign in as the Zoom host.

## Design

ORAS-Tickets will manage this policy per event. The Zoom Automation section
will include an `Allow approved attendees to join anytime without a host`
option. It will default to enabled when Zoom attendee automation is first
enabled for an event.

When the option is enabled, ORAS-Tickets will update the resolved Zoom meeting
through the Zoom Meetings API with:

- `join_before_host: true`
- `jbh_time: 0`
- `waiting_room: false`

When disabled, ORAS-Tickets will not overwrite the meeting's host-access
settings. Registration approval, passcodes, registrant-specific join URLs, and
existing RSVP/ticket entitlement rules remain unchanged. ORAS-Tickets will
never expose or email Zoom's host `start_url`.

## Synchronization

Saving an event will synchronize the selected unattended-access policy after
The Events Calendar has supplied a resolvable meeting ID. The event editor will
also provide an explicit `Sync Zoom Settings` action for existing meetings.

The event configuration will retain the last synchronization time and status.
Zoom API or account-policy failures will be shown to authorized event editors
without exposing credentials or private meeting URLs. A successful update will
be verified by reading the meeting settings back from Zoom.

## Security

Unattended meetings intentionally disable the Zoom Waiting Room because Zoom
does not permit waiting-room admission without a host. Access remains limited
by Zoom registration, passcodes, and ORAS eligibility rules. The editor will
warn that host controls are unavailable until an authorized host joins.

Only users who can edit the event may change or synchronize this setting.
Synchronization requests require a WordPress nonce.

## Compatibility

Existing events retain their current Zoom policy until an authorized editor
enables unattended access and synchronizes the meeting. Existing Zoom
registration records, ticket emails, RSVP approval emails, and fallback links
are not changed.

## Verification

Automated checks will cover API payloads, meeting verification, event
configuration persistence, capability and nonce enforcement, successful sync
feedback, and Zoom policy failures. Static checks will include PHP syntax,
`git diff --check`, PHPCS, PHPStan, and the existing Zoom integration harness.
