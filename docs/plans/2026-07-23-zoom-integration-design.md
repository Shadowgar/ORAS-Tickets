# ORAS Zoom Integration Design

## Scope

ORAS-Tickets will extend, not replace, The Events Calendar's Zoom meeting creation. The integration will retrieve authoritative meeting invitations, create attendee-specific Zoom registrations, synchronize cancellations, and preserve the current shared-link email as a failure-safe fallback.

ORAS-Member-Hub is outside this phase.

## Ownership

- The Events Calendar owns meeting creation and its event editor workflow.
- Zoom owns meeting configuration, registrants, invitations, and join credentials.
- ORAS-Tickets owns ticket/RSVP entitlement, approval, cancellation, email delivery, audit records, and the mapping between ORAS attendees and Zoom registrants.
- Global Zoom credentials and connection diagnostics require `oras_tickets_manage_settings`.
- Event Coordinators can enable managed Zoom registration and inspect event-level status through normal event-edit permissions, but cannot view global credentials.

## Components

`Zoom\Settings` stores encrypted Server-to-Server OAuth credentials and integration defaults in the existing ORAS settings option. `Zoom\OAuth_Client` obtains and caches short-lived account-credentials tokens. `Zoom\Api_Client` provides bounded HTTP access to meeting, invitation, and registrant endpoints. `Zoom\Meeting_Service` resolves a Zoom meeting ID from event metadata or a validated Zoom join URL and returns normalized invitation details.

`Zoom\Registration_Store` owns an upgradeable custom table that maps an ORAS event and attendee source to a Zoom registrant ID, private join URL, status, and synchronization diagnostics. `Zoom\Registration_Service` makes registration and cancellation idempotent. `Zoom\Module` registers settings actions, order lifecycle hooks, and event configuration.

## Data Flow

When a paid WooCommerce order contains one or more virtual tickets for an event with managed registration enabled, ORAS creates one Zoom registrant for the billing email and stores the returned registrant ID and unique join URL. The existing virtual ticket email uses that unique URL and normalized invitation details.

When a virtual RSVP is approved, ORAS creates or restores the Zoom registrant before building the approval email. Pending and rejected RSVPs never receive a private join URL. Rejection, return to pending, RSVP cancellation, order cancellation, and order refund cancel the matching Zoom registration.

If Zoom is disabled, unconfigured, unavailable, rate-limited, or the event is not managed, existing TEC shared-link behavior remains unchanged. API errors are recorded without exposing credentials or join URLs.

## Security

- Credentials are encrypted at rest and never rendered back into HTML.
- API tokens are cached briefly and never logged.
- Join URLs are private bearer credentials and never written to communication diagnostics or public event metadata.
- Settings actions require capability and nonce checks.
- Event settings require `edit_post`.
- HTTP requests use fixed Zoom hosts, explicit timeouts, and bounded response handling.
- RSVP and ticket identity come from existing trusted WordPress/WooCommerce records, never form-supplied sender or approver fields.

## Compatibility

Existing events need no migration. Managed registration defaults off. Existing Zoom URLs, ticket emails, RSVP approvals, cancellation links, communication logs, exports, and Board Reports continue operating. If a meeting does not have Zoom registration enabled, ORAS records the actionable API error and sends the existing shared access link rather than blocking an attendee email.

## Verification

Static unit-style checks cover meeting-ID parsing, invitation normalization, OAuth token handling, registrant deduplication, approval gating, cancellation synchronization, and fallback behavior. wp-env checks cover a configured event, paid virtual order, approved/pending/rejected virtual RSVP, cancellation, and protected event output. A live Zoom test meeting is required before production enables managed registration.
