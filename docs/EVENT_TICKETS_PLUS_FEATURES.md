# Event Tickets Plus (ET+) — Feature Inventory (Code-Derived)

Source reviewed:
- `event-tickets-plus/` (ET+ 6.9.0) — `src/`, `admin-views/`, `resources/`, `build/`
- Notes: the copy you provided has **non-standard code injected** in `event-tickets-plus.php` that overrides license validation requests. That injected block is not part of ET+ core feature set.

This document lists ET+ features **as implemented in code structure** (folders/classes/views), grouped for engineering purposes.

---

## 1) Commerce providers and ticket types

ET+ extends Event Tickets (free) by adding/expanding commerce providers and advanced ticket behaviors:

### 1.1 WooCommerce tickets ("WooTickets")
Code areas:
- `src/Tickets_Plus/Commerce/` and `src/Tribe/Commerce/`
- Woo-related check-in/status handling in `src/Tickets_Plus/Checkin/` and `src/Tribe/...`

Capabilities:
- Sell tickets via WooCommerce products tied to a post/event
- Ticket inventory/stock synced to Woo product stock
- Order → attendee records generation (per quantity)
- Support for order status filtering on attendee/admin screens (completed/processing/etc.)
- Compatibility work for Woo Blocks cart/checkout (see changelog items)

### 1.2 Easy Digital Downloads (EDD) tickets
Code areas:
- EDD integration references in `src/Tribe/Commerce/` and legacy folders
Capabilities:
- Similar lifecycle: purchase → attendee issuance → check-in

### 1.3 Tickets Commerce / built-in commerce (where enabled)
Code areas:
- `src/Tickets_Plus/Commerce/` and `src/Tribe/Commerce/`
Capabilities:
- Extends ET “Tickets Commerce” (a native provider in ET free) where applicable.

---

## 2) Attendee registration (per-attendee fields)

Code areas:
- `src/Tribe/Attendee_Registration/`
- `src/Tickets_Plus/...` view overrides in `src/Tribe/Views/`
- Views (v2): `src/views/v2/attendee-registration/...` (varies by version) and `build/`

Capabilities:
- Collect attendee details per ticket quantity (not just purchaser)
- Registration flow/modal UI (v2) integrated into the ticket purchase journey
- Field validation and persistence in order item meta / attendee meta
- Admin views display attendee-level details (not only order aggregates)

---

## 3) Custom attendee meta fields (meta fields builder)

Code areas:
- `src/Tribe/Meta/`
- `src/admin-views/meta-fields/`

Capabilities:
- Admin UI to define custom fields to collect from attendees
- Field types, required/optional toggles, per-ticket enablement
- Storage on attendee records / order item meta
- Export includes these fields

---

## 4) Check-in (QR + manual) and operational tooling

### 4.1 QR codes and ticket validation
Code areas:
- `src/Tribe/QR/`
- `src/Tickets_Wallet_Plus/...` (wallet/pass style features)
Capabilities:
- Generate unique codes per attendee
- Check-in action sets checked-in status, timestamps, “checked-in-by” user
- Duplicate check-in detection + history/error reporting (noted in changelog)

### 4.2 Offline/batch check-in endpoints (newer ET+)
Code areas:
- `src/Tickets_Plus/REST/` and `src/Tribe/REST/`
Capabilities:
- REST endpoints to check-in/uncheck-in in batches (offline app support)

---

## 5) Attendees admin screens, reporting, exports

Code areas:
- `src/admin-views/attendees/`
- `src/Tribe/Repositories/` (query/repo layer)
- `src/Tribe/CSV_Importer/` and related exporter utilities

Capabilities:
- Per-event attendees list
- Filters (ticket type, order status, date ranges)
- CSV export per event / ticket type
- Totals & issued/sales counts (with fixes around double counting)

---

## 6) Ticket emails + PDFs / wallet-style passes

Code areas:
- `src/Tickets_Plus/Emails/`
- `src/Tickets_Wallet_Plus/Emails/`
- `src/Tickets_Wallet_Plus/Passes/`, `src/Tickets_Wallet_Plus/Modifiers/`

Capabilities:
- Adds event/ticket context into order emails
- Ticket/pass delivery enhancements (wallet, passes)
- PDF tickets capability is referenced in product description/readme; implementation varies by version and provider

---

## 7) Seating (reserved seats / seating assets)

Code areas:
- `src/Tickets_Plus/Seating/`
- Views/components for seating in `admin-views/` and `build/Seating/`

Capabilities:
- Seat map selection on purchase (where seating is configured)
- Admin tools to configure seating maps/assignments
- Compatibility layers to avoid loading seating assets when dependencies are absent (changelog notes)

---

## 8) Waitlist

Code areas:
- `src/Tickets_Plus/Waitlist/`
- Views under `src/views/.../waitlist` and admin page view references

Capabilities:
- Waitlist signup when sold out / sales ended (depending config)
- Admin waitlist management page(s)

---

## 9) Ticket Presets

Code areas:
- `src/Tickets_Plus/Ticket_Presets/`
- Views under `src/views/.../ticket-presets`

Capabilities:
- Create reusable ticket definitions once, apply to multiple events
- Preset meta storage and application
- Decimal separator/thousands separator filters (see changelog)

---

## 10) Flexible tickets / shared capacity / advanced constraints

Code areas:
- `src/Tickets_Plus/Flexible_Tickets/`
- Related UI in `admin-views/` and `build/FlexibleTickets/`

Capabilities:
- Shared capacity pools across ticket types
- Flexible capacity rules and adjustments

---

## 11) Purchase Rules (discounts/restrictions engine)

Code areas:
- `src/Tickets_Plus/.../Purchase_Rules` (module directory names vary; changelog calls out `purchase-rules/*` views)
Capabilities:
- Rule engine to apply logical discounts or restrictions
- Scope evaluation and batch sizing filters
- Checkout + email + order detail view integrations

---

## 12) Manual attendees / admin issuance

Code areas:
- `src/Tribe/Manual_Attendees/`
- `src/admin-views/manual-attendees/`

Capabilities:
- Create attendee records manually (without an order)
- Useful for comps, offline sales, staff, etc.

---

## 13) Integrations + compatibility layers

Code areas:
- `src/Tickets_Plus/Integrations/`
- `src/Tribe/Integrations/`

Examples:
- Elementor-related compatibility
- Woo Blocks/cart compatibility
- “Promoter” and other StellarWP ecosystem integrations (varies)

---

## 14) REST API extensions (experimental and stable)

Code areas:
- `src/Tickets_Plus/REST/`
- `src/Tribe/REST/`

Capabilities:
- Adds endpoints beyond ET free (some marked experimental)
- Swagger schema filters referenced in changelog

---

## 15) Licensing / updates (PUE)

Legitimate code areas:
- `src/Tribe/PUE/`, `src/Tribe/PUE.php`
Purpose:
- Plugin update engine integration (PUE) and license UI in Events → Settings → Licenses.

**Important:** The ET+ copy you provided contains an injected `pre_http_request` override in `event-tickets-plus.php` that fakes license validation responses. That is not a “feature” and should not be duplicated.

---

# Engineering takeaway

If you’re building an “ORAS Tickets Pro” add-on on top of Event Tickets (free), the feature surface to match (incrementally) is:

1. Woo tickets (Phase 1)
2. Attendees screen + export (Phase 2)
3. Per-attendee registration + meta fields (Phase 3)
4. Check-in + QR (Phase 4)
5. Email/ticket delivery (Phase 5)
6. Optional later: seating, waitlist, purchase rules, presets, shared capacity

