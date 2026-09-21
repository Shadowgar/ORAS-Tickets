# Registration Desk Training Mode Design

## Goal

Add a manager-controlled, station-specific Training Mode that demonstrates the
Registration Desk with a real event's current canonical configuration and a
server-authorized simulated event date, while making it structurally impossible
for training operations to write to or appear in live operational data.

## Non-negotiable boundaries

- Live Registration Desk date, event-range, offering-validity, and station-ended
  validation remain unchanged.
- Training Mode never changes TEC event dates, Woo sale dates, ticket
  configuration, the WordPress clock, or the site timezone.
- Training writes never use the live registration, attendee, attendance, audit,
  offline-membership, WooCommerce, PMPro, email, QuickBooks, Stripe, RSVP, user,
  or Board Reports paths.
- Training state is scoped to one signed station and WordPress session. A second
  station remains live unless its manager independently starts Training Mode.
- No production event, product, or order identifier is embedded in source.

## Isolation model

Use one dedicated InnoDB table named from the WordPress prefix plus
`oras_registration_desk_training_sessions`. The table is not included in any
live store or reporting query. Each row contains:

- an opaque training-session UUID;
- a unique station UUID;
- the WordPress user and session binding;
- the selected event ID;
- the event configuration revision captured when training starts;
- the mutable simulated local date;
- a bounded JSON state document;
- a row revision for optimistic/transactional concurrency;
- creation, update, and expiry timestamps.

The row identity is the training session/station, never the simulated date.
Changing the training date updates a field on the same row. Registrations and
attendance history remain in the same state document, with attendance keyed by
simulated local date. Reset is the only operation that replaces the dataset
with deterministic seeds.

All mutations acquire a database transaction and row lock, compare the row
revision, apply one bounded state transition, and commit a new revision. The
state document contains only synthetic training registrations, attendees,
date-partitioned attendance, training-only request/idempotency results,
simulated membership records, and a bounded diagnostic history.

Expired sessions are rejected and removed opportunistically. Ending Training
Mode deletes only the matching training row.

## Authorization and station lifecycle

Starting Training Mode requires the normal manager PIN token on an existing
station. The server, not JavaScript, resolves the selected event, configuration
revision, inclusive event date range, and default start date. A successful start
creates a new event-bound station token and training row, then clears Manager
Mode so a volunteer can practice without continued manager access.

Every training request validates all of the following:

1. the signed Registration Desk station token;
2. the current WordPress user and session digest;
3. the matching training row for that station UUID;
4. the selected event ID;
5. the captured configuration revision against the current revision;
6. the simulated date against the event's current inclusive date range;
7. the training-session expiry.

If event configuration changes, the session fails closed with a specific
restart-required response. It does not reseed, translate, or silently combine
old training data with new configuration.

Changing the date, resetting data, and ending Training Mode each require a
freshly valid manager authorization for the training station. Date changes are
limited to the selected event's inclusive site-local dates. Ending deletes the
training row and returns the browser to normal event selection, where the real
site-local clock applies immediately.

## REST separation and fail-closed routing

Add a separate Training Mode controller under explicit
`/registration-desk/training/*` routes. It owns training context, roster,
registration detail, check-in, walk-in finalization, membership simulation,
statistics, date change, reset, and end operations. It never calls the live
Registration Desk write services.

Normal operational endpoints use a central live-context guard. If a training
row exists for the station, live roster, detail, projection, recovery,
statistics, membership, check-in, and registration-write routes reject the
request even when a modified client omits any training marker. PIN unlock and
the minimum station/bootstrap operations remain available so managers can
authorize training controls.

Training endpoints require the server-side row and reject stations without one.
There is no `ignore_date` parameter, simulated-date parameter on an operational
write, or client-controlled mode switch.

## Canonical read-only configuration

Training Mode uses existing event and membership offering resolvers to read:

- event identity and inclusive dates;
- ticket names, descriptions, prices, classifications, attendee limits, and
  one-day/full-event validity;
- supplemental Registration Desk coverage;
- included/cross-event labels that can be derived from configuration alone;
- current PMPro membership level names, prices, terms, and display URLs.

Training Mode does not call website-order projection, source reconciliation,
real member lookup, RSVP mutation, credit generation, or mail delivery.

## Synthetic dataset

Starting or resetting a session deterministically seeds obviously synthetic
records from the selected event's meaningful canonical offerings. Seed names,
emails, and phones use explicit `DEMO` labels and reserved example values. A
family-capable offering receives multiple attendees; a Student-like offering
receives a student example. When configuration contains cross-event coverage,
one synthetic included-access example may be derived without reading an order.

Seed identifiers are deterministically derived from the training-session UUID
and semantic seed key. Reset therefore restores the same records for that
session. Manager diagnostics label every source as synthetic and contain no
real order identifier.

## Training operations

Roster search, filters, details, attendee selection, and check-in operate only
on the JSON state. The current simulated date determines `Here Today` and
whether an attendee is currently checked in. Repeating a request UUID returns
the stored training result and never adds a second attendance entry.

Changing the simulated date preserves registrations and prior attendance.
Prior-date attendance remains available to training history and statistics but
does not count as checked in on the new date.

Walk-ins reuse the existing client wizard and canonical offering display, but
post to training routes. AlfaPOS and payment steps use explicit practice-only
copy. Card, Cash, Check, and Unpaid are stored only as training assertions.

Training membership lookup returns deterministic synthetic members. Recording
a membership stores a simulated result in the training row and displays an
explicit statement that no payment, email, credit, user, or membership was
created.

Training Event Stats are calculated solely from the training state document.
They never call or merge with live Event Stats or Board Reports.

## Browser presentation

The kiosk shell renders a persistent textual warning whenever the validated
server context is training:

`TRAINING MODE — NO LIVE EVENT DATA WILL BE CHANGED`

It also shows the selected event and formatted simulated date. Because the
banner is part of the shell rather than an individual screen, it remains on the
home, roster, detail, wizard, payment, success, manager, membership, and
statistics views. Training errors replace real-payment recovery language with
practice-only wording.

On page refresh, the browser validates its stored station token against the
server and restores the authoritative training context. Local storage may cache
presentation state and unfinished drafts, but it never authorizes mode or date.

## Failure behavior

- Missing, expired, mismatched, or stale training state fails closed.
- Configuration-revision mismatch instructs the manager to end/restart
  Training Mode and performs no state transition.
- Training write failures never invoke a live fallback.
- Training failures state that no live data changed and never imply that a real
  payment was taken.
- Bounded state and input limits prevent an unbounded JSON row.

## Verification strategy

Test-first host checks cover schema, token/context binding, date validation,
seeding, state transitions, routing separation, client copy, and responsive
banner placement.

Guarded disposable WordPress tests run in both legacy and HPOS modes. Before
training actions they snapshot live Registration Desk tables, Woo orders and
items, PMPro membership state, pending activation state, transport logs, and
Board Reports totals. They then start training, check in seeded people, create
individual and family walk-ins, exercise every payment assertion, simulate a
membership, inspect stats, change dates, reset, and end. Every live snapshot
must remain identical.

Two simultaneous station tokens prove that one training station cannot affect
another live station. Crafted requests prove that an active training station
cannot call live writes by omitting client mode fields and that training context
cannot be used by another station.

Real-browser qualification covers iPad landscape, iPad portrait, iPhone
portrait, and desktop demo layouts with console, overflow, banner, workflow,
and requested-screenshot evidence.

The final release gate includes focused checks, guarded legacy and HPOS suites,
PHP/JavaScript syntax, PHPStan, differential PHPCS, version consistency,
Phase5, CodeQL, exact committed-tree packaging, checksum, tag, and downloaded
release-asset identity verification.
