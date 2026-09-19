# Registration Desk Canonical Offerings Design

**Approved:** 2026-09-19

## Goal

Make the public event ticket form and Registration Desk consume one event-scoped offering source, remove manually duplicated desk ticket names and prices, and support accountless RSVP-only walk-ins with the event's existing capacity and waitlist policy.

## Canonical ticket offerings

Add a shared event offering resolver above `Ticket_Collection`, `Price_Resolver`, and the event's WooCommerce product mapping. The resolver returns stable ticket identity, product identity, current name and description, effective price and pricing phase, attendance mode, sale-window state, stock state, and visibility/selectability. Both `Tickets_Display` and Registration Desk consume these normalized offerings.

The resolver is strictly event-scoped. Tickets from another event are never returned. Registration Desk refreshes offerings when the walk-in flow opens and resolves the submitted identity again before writing, so removed, renamed, closed, or sold-out tickets cannot be accepted from stale client state.

Desk option UUIDs are deterministic projections of the event ID and stable canonical ticket key. Historical desk records also snapshot the canonical identity and display facts in source evidence so later ticket edits do not rewrite history.

## Supplemental manager configuration

Event desk configuration stores only facts absent from the canonical ticket definition: individual/family classification, family maximum, full-event/one-day validity, and the applicable one-day date. Supplemental ticket rules are keyed by canonical ticket key.

Explicit cross-event entitlements are stored separately from selectable ticket rules. Same-event website orders resolve automatically through the canonical product mapping. A cross-event mapping can make an existing purchaser admissible at the target event but cannot create a target-event walk-in offering.

Legacy manual options are never selectable unless they can be matched to a current canonical ticket. Existing records retain their snapshots and remain readable.

## RSVP-only fallback

When an event has no ORAS ticket products and RSVP is enabled, the resolver exposes an RSVP workflow instead of synthesizing ticket products. Accountless attendees are stored only in Registration Desk records; no WordPress user is created.

The public and desk RSVP paths share an effective capacity service that counts approved public on-site RSVPs plus active accountless desk RSVP admissions. Under the event lock:

- available capacity creates an `rsvp_walk_in` registration and immediately checks in the attendee;
- full capacity with waitlist enabled creates an accountless `rsvp_waitlist` registration without attendance and reports that outcome clearly;
- full capacity without waitlist returns a plain-language refusal.

Public RSVP submissions use the same effective capacity calculation, so concurrent public and desk registration cannot overbook the event. Events containing ORAS ticket products always use canonical ticket offerings even if RSVP is also enabled.

## Nonfinancial and safety boundaries

Registration Desk records operational payment statements only; it does not create orders, collect payments, mutate QuickBooks, create attendee accounts, create customers, or create memberships. Testing uses only the verified disposable test services owned by `/home/rocco/projects/oras-wp-env`. No production deployment or release is part of this change.

## Verification

Regression coverage compares the public and desk views after ticket add, rename, pricing-phase, stock, sale-window, removal/unavailability, and unrelated-event mutations. RSVP coverage exercises admission, waitlisting, refusal, shared capacity in both directions, account non-creation, ticket precedence, and cross-event isolation.
