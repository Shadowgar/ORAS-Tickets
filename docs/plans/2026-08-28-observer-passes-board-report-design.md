# Observer Passes Board Report Design

## Goal

Add a read-only **Observer Passes** section to the existing ORAS Board Reports
interface. It must let authorized board members verify Annual and Daily Observer
Pass purchasers, see who may observe today or on upcoming dates, inspect pass
validity, and print today's observer list without granting WooCommerce
administrative access.

## Existing System Findings

Board Reports is a shortcode-driven frontend dashboard implemented by
`Frontend\Board_Reports`, with reporting logic in `Reporting\Board_Report_Service`.
Its tabs, cards, filters, expandable details, and pagination are rendered on the
server. Access is controlled by the existing
`oras_tickets_view_board_dashboard` capability. Board roles do not receive
WooCommerce order-editing, refund, or deletion capabilities.

WooCommerce is the source of truth for the two production passes:

- Annual Observer Pass: simple virtual product, production product ID `3219`,
  slug `annual-observer-pass`.
- Daily Observer Pass: bookable simple virtual product, production product ID
  `2494`, slug `daily-observer-pass`.
- Both products are in the `observer-passes` category.

Daily bookings store their authoritative dates on the WooCommerce order line
item:

- `_wapbk_booking_date`: inclusive first observing date in `Y-m-d` format.
- `_wapbk_checkout_date`: exclusive checkout/end date in `Y-m-d` format.
- `_wapbk_booking_status`: booking state, with `confirmed` and `paid` observed
  on valid production purchases.

Annual purchases do not store a separate expiration field. Their expiration
must be calculated from the order purchase date. Existing purchases contain
purchaser billing details but no separate attendee or pass-holder identity, so
the report will label the purchaser as **Purchaser/Passholder** rather than
fabricating attendee data.

WooCommerce HPOS is enabled in production. All order access must therefore use
WooCommerce CRUD APIs rather than direct queries against posts or order tables.
The WordPress site timezone is `America/New_York` and is authoritative for
operational date boundaries.

## Architecture

Introduce a dedicated `Observer_Pass_Report_Service` under the reporting
namespace. It will read WooCommerce orders through `wc_get_orders()`, inspect
matching line items, and return normalized report rows. WooCommerce remains the
only source of truth; the feature will not add a reporting table, copy pass
records, or mutate orders.

The service is separate from the event-focused `Board_Report_Service` because
Observer Pass classification, booking dates, expiry calculations, and status
rules are independent of Event Tickets and selected ORAS events. It may reuse
existing contact normalization and product classification helpers where their
contracts fit.

Pass products will be identified by their exact portable slugs, with the known
production IDs available as filterable fast paths. The shared
`observer-passes` category is supporting validation, not sufficient on its own
to distinguish Annual from Daily. Product identifiers must be filterable so a
different environment can supply fixture or replacement product IDs without
editing the service.

Each normalized row will contain, where available:

- purchaser/passholder name, email, phone, and address;
- pass type and product identity;
- order ID, displayed order number, order status, and purchase date;
- line-item ID, original quantity, refunded quantity, and valid quantity;
- Daily start date, exclusive checkout date, and booking status;
- Annual expiration date;
- calculated operational status and sortable operational date;
- diagnostic flags for missing or malformed source data.

No normalized field will expose payment tokens, gateway metadata, accounting
metadata, or unrelated order items.

## Validity And Status Rules

All comparisons use the WordPress site timezone and calendar dates rather than
server UTC dates.

### Annual Observer Passes

The purchase date is the WooCommerce order creation date. Expiration is the
same local calendar date one year later. February 29 purchases explicitly
expire at the start of March 1 in the following non-leap year, so they remain
valid through the end of February 28. The calculation must not rely on PHP's
implicit date rollover. A pass is:

1. **Active** until the final 30-day window.
2. **Expiring Soon** from 30 days before expiration through the day before
   expiration.
3. **Expired** on the expiration date and afterward.

Only valid paid quantity contributes to Active or Expiring Soon totals.

### Daily Observer Passes

The booking start is inclusive and checkout is exclusive. A multi-night pass
is valid when:

`start_date <= local_today < checkout_date`

Daily status priority is:

1. **Today** when the range contains today.
2. **Upcoming** when the booking starts after today.
3. **Past** when checkout is on or before today.

Only the observed `confirmed` and `paid` booking states are eligible. An
unknown booking status retains its date classification and source status for
audit display but has `is_valid=false`; it must never enter Today or Upcoming
operational lists or counts.

The visible valid-date range will communicate observing nights. For multi-night
bookings, the final valid night is the calendar day before the exclusive
checkout date.

### Invalid And Historical Purchases

Cancelled, failed, and fully refunded orders are invalid regardless of their
calculated dates. A cancelled booking status also invalidates its Daily pass.
These rows remain visible for audit searches and display their specific status,
but never count as active Annual passes or Daily attendance.

Valid quantity is reduced only when WooCommerce records an explicit refunded
quantity for the Observer Pass line item. Other refund records do not change
operational validity because they do not identify a refunded pass quantity.

## Board Reports Integration

Add `Observer Passes` to the existing top-level Board Reports navigation. It
will use the established dashboard wrapper, visual language, capability check,
server-rendered controls, and expandable-detail pattern.

Observer Passes are global products rather than event-specific tickets. On this
tab, omit the selected-event control, event summary cards, and event attention
center. Other tabs and their event selection behavior remain unchanged.

The page contains four unfiltered operational summary cards:

- **Active Annual Passes**: all valid Annual quantity, including Expiring Soon.
- **Daily Passes Today**: valid Daily quantity whose booked range contains
  today.
- **Upcoming Daily Passes — Next 7 Days**: valid quantity for future bookings
  that occur during the next seven local calendar dates, excluding passes
  already counted in Today. Each matching purchase is counted once.
- **Upcoming Daily Passes — This Month**: valid quantity for future Daily
  bookings whose start date falls after today and on or before the final local
  calendar date of the current month. Each matching line is counted once.

The cards describe the complete current snapshot and do not change when the
table is filtered.

## Filters And Sorting

Provide server-side GET filters consistent with the current dashboard:

- Pass type: All, Annual, or Daily.
- Search: purchaser/passholder name, email, or displayed order number.
- Status: the Annual and Daily statuses plus Refunded and Cancelled historical
  states.
- Operational date: Today, Next 7 Days, This Month, This Year, or the existing
  reusable custom after/before range.

Operational date filtering applies to Daily observing-night ranges and Annual
expiration dates. A Daily booking matches a range when its valid nights overlap
that range. Annual passes match by expiration date.

Daily rows sort as Today, Upcoming by start date ascending, then Past/history.
Annual rows sort as Active, Expiring Soon by expiration ascending, then
Expired. When All Passes is selected, currently valid operational rows precede
future and historical rows, with stable purchase/order tie-breakers.

## Main Pass Table And Details

Render a responsive main table with these columns:

| Purchaser | Pass Type | Pass Holder(s) | Purchase Date | Valid Date / Expiration | Qty | Order | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |

The table uses actual source data only. Because no separate holder identity is
currently collected, the holder column shows the purchaser with the explicit
`Purchaser/Passholder` label. If future line-item holder data is supported, the
normalizer may present it without changing the table contract.

Each row has an expandable, read-only detail area following the existing Board
Reports pattern. It may show purchaser contact information, pass type, dates,
quantity, order number, WooCommerce order status, and Daily booking status.
Order references are plain text. The report will not link board users into
WooCommerce admin or expose edit, refund, cancel, or delete actions.

## Today's Daily Observers

A prominent section above the historical table shows valid Daily passes for
the current local date. Each entry displays:

- purchaser/passholder name;
- quantity;
- separately collected holders if they become available;
- relevant order number.

`Print Today's Observer List` submits to a dedicated authenticated WordPress
handler. The handler requires `oras_tickets_view_board_dashboard` and a valid
nonce, rebuilds the current list from WooCommerce, and returns standalone,
escaped print HTML. It includes the report title, local date, names, quantity,
known holders, and order references, but excludes the WordPress theme,
navigation, filters, financial data, and unrelated customer or order data.

## Active Annual Verification List

A compact **Active Annual Observer Passes** list shows the current count,
purchaser/passholder name, and expiration date. It includes Active and Expiring
Soon passes and excludes invalid or expired passes.

The list has a dedicated fast name/email search and a phone-friendly stacked
layout so a board member can verify someone at the observatory without using
the larger historical table.

## Permissions And Security

The tab and print route reuse `oras_tickets_view_board_dashboard`. No new
WooCommerce or WordPress administrator capabilities will be granted to Board
roles. All filters are sanitized, all output is escaped for its context, and
the print endpoint enforces both authorization and CSRF protection.

The feature is read-only. It does not modify products, checkout, customers,
orders, bookings, refunds, roles, or capabilities.

## Error Handling

- If WooCommerce is unavailable or the order query fails, show a clear
  report-unavailable state instead of misleading zero cards.
- If a Daily booking date is missing or malformed, retain the row with `Date
  unavailable`, flag it in details, and exclude it from Today and Upcoming
  calculations.
- If purchaser or contact data is missing, display `Not recorded`; do not
  invent holder data.
- If an individual order or item cannot be normalized, preserve an auditable
  row where possible and exclude it only from calculations its source data
  cannot support.

## Performance

The service will page through orders using WooCommerce APIs with a stable order
ID tie-breaker so equal creation timestamps cannot duplicate or omit rows at a
page boundary. The current
production order volume is small enough that a fresh read is preferable to a
persistent cache that could make today's list stale. The normalized result is
reused within the request for cards, lists, filters, and the table.

If order volume later makes this slow, a short-lived cache with explicit order
change invalidation can be added without changing the report contract. A
separate ledger is out of scope for this implementation.

## Verification

Extend the existing Board Reports integration harness with local WooCommerce
fixtures covering:

- Annual Active, 30-day Expiring Soon, and Expired boundaries;
- Daily Today, multi-night overlap, Upcoming, and Past classification;
- site-timezone date boundaries;
- paid, failed, cancelled, fully refunded, and attributable partial-refund
  behavior;
- explicit line-item refunded quantity and valid-quantity calculations;
- February 29 expiration through February 28 with expiration on March 1;
- unknown Daily booking statuses remaining visible but invalid;
- more than 50 matching orders and multiple Observer lines in one order;
- missing dates/contact fields and purchaser-as-passholder fallback;
- search by name, email, and order number;
- type, status, and operational-date filters;
- required Annual and Daily sorting;
- Board capability access and unauthorized denial;
- print capability and nonce enforcement;
- escaped output and absence of administrative controls;
- regression coverage for all existing Board Reports tabs.

Browser-level validation will cover desktop and phone layouts, expandable
details, the Annual verification search, and clean print output. Before
completion, run the repository's syntax, formatting, static-analysis,
integration, version-consistency, and full quality commands.

Production verification remains read-only unless deployment is separately
authorized.

## Out Of Scope

- Changing either Observer Pass product or its checkout experience.
- Collecting or editing separate pass-holder identities.
- Modifying existing orders or booking metadata.
- Adding WooCommerce administration access for Board members.
- Introducing an Observer Pass database ledger or settings application.
- Expanding the report beyond operational pass validity and attendance.
- Changing unrelated Event Tickets, RSVP, Communications, or Board Reports
  behavior.
