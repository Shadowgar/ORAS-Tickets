```markdown
# CHANGELOG (Append-Only)

## 2026-02-12 — Agenda + Speaker Modal (Completed)
- Delivered multi-day agenda management via event meta `_oras_agenda_v1` with settings/day/slot envelope.
- Added admin agenda metabox with nested repeaters (days -> slots -> speakers) and native date/time pickers.
- Added frontend agenda renderer for TEC single event pages, including slot metadata and multi-day display.
- Added current-slot highlighting and optional autoscroll behavior via `assets/js/agenda-now.js`.
- Added slot speaker rendering under agenda items using per-slot speaker rows (`speaker_id`, `role`, optional `label`).
- Added speaker modal popup UX from agenda clicks with abbreviated speaker info and "View full profile" permalink.
- Speaker modal headshot uses `_oras_speaker_headshot_id` with fallback to Featured Image when meta is absent.

Status: COMPLETE

## Phase 3.0 — COMPLETED
- Woo product sync finalized
- Stock = capacity logic implemented
- Refund handling verified
- Reporting and CSV exports completed

## Phase 3.1 — COMPLETED
- Frontend ticket filtering by sale window: tickets render only during `sale_start` / `sale_end`.
- Inventory visibility: in-badge notes show "X left" or "Unlimited".
- Qty input layout fixes: resilient to long names and browser spinner overlay (Firefox/Edge).
- Robust cart revalidation: malformed or off-sale ORAS ticket items are removed; valid items are preserved during checkout.
- Product purchasability fix: Woo products saved with `post_status = publish` and `catalog_visibility = hidden`.
- Improved add-to-cart success notice includes a cart link.

Status: COMPLETE (LOCKED)

Notes: Phase 3.1 behaviors (sale-window filtering, add-to-cart revalidation, frontend UX fixes) are locked; changes require a design review.

## Phase 3.2 — COMPLETED
- Implemented server-side `price_phases` resolver for time-based pricing.
- Frontend ticket display shows active phase badge and server-rendered countdown.
- Cart and checkout totals apply resolved phase pricing server-side.
- Order item metadata now includes active `price_phase` snapshot keys for audits and reporting.

Status: COMPLETE (LOCKED)

## Phase 3.3 — COMPLETED
- Admin tickets editor now uses WooCommerce-style vertical tabs within each ticket (General, Inventory, Sale window, Pricing, Pricing phases).
- Pricing phases UI redesigned into a card/grid layout with Advanced expand/collapse.
- Left ticket rail shows title + meta (price + status) and updates live while editing.
- Add Ticket / Remove Ticket update the UI immediately (no refresh required).
- Initialization fixes prevent blank/ghost rows on refresh.
- Inline styles reduced/moved to CSS for maintainability.

Status: COMPLETE (UI-only)

## Phase 3.4 — COMPLETED
- Treasurer reporting finalized (pricing aggregates, KPI correctness, multi-event summaries).
- Filtering and CSV export verified.

Status: COMPLETE (LOCKED)

## Phase 3.5-C — COMPLETED
- Member Hub UI renders purchased tickets via ORAS-Tickets REST API.
- Member-facing display only; no ticket logic in Member Hub.

Status: COMPLETE (LOCKED)

## Phase 3.5-D — COMPLETED
- Secure printable ticket pages delivered with ticket-card layout.
- One card per ticket quantity with ownership validation.
- No QR codes or check-in functionality.

Status: COMPLETE (LOCKED)

## Phase 4.1 — DEFINED (Planning Only)
- Speaker Management (MVP, internal only) defined as the next phase.
- No implementation work started.

## Phase 4.1 — PLANNING DETAILS (Not Implemented)
- Phase 4.1 defined: Speaker CPT + event meta assignments (`_oras_speakers_v1`).
- Optional WP user link for PMPro fulfillment (planning only).

## 2026-02-14 — Phase 4.1 + 4.1-B Implemented
- Implemented Speaker CPT and event speaker assignments.
- Implemented public speaker profiles and event speaker linkage.
- Phase 4.1 and Phase 4.1-B are now implemented.

## 2026-02-14 — Woo Ticket-Only Order Auto-Completion
- Added `includes/Commerce/Woo/Order_Autocomplete.php`.
- Hooks into `woocommerce_order_status_processing`.
- Uses meta marker `_oras_autocompleted` to avoid duplicate processing.
- Mixed merch orders remain in `processing` (no auto-complete).

## 2026-02-15 — Virtual Events access controls (ORAS-only)
- Added admin "Show to" options for virtual events: `Everyone`, `Logged in`, `People who RSVP’d`, `People who purchased tickets`, and `People with free tickets`.
- Persisted selection in `_oras_virtual_access_v1` as a versioned envelope (`version` => 1, `show_to` => value).
- Admin options are surfaced conditionally when RSVP is enabled or ORAS ticket mappings exist for the event.
- Frontend enforcement implemented server-side: join links/details are hidden when access is denied; denial message filter: `oras_tickets_virtual_access_denied_message`.
- Added helper `ORAS\\Tickets\\Frontend\\Virtual_Access::current_user_can_access_virtual_link()` for programmatic checks.

## 2026-02-15 — RSVP REST Endpoints (Phase 5.1-C)
- Added REST API endpoints for Member Hub RSVP data consumption.
- GET `/oras/v1/rsvp/my`: Returns authenticated user's RSVP status for all events (scans usermeta `_oras_rsvp_event_{EVENT_ID}`).
- GET `/oras/v1/rsvp/event/{event_id}`: Returns RSVP stats for a specific event (yes_count, capacity, waitlist_enabled).
- Implemented in `includes/Api/Rsvp.php` with WP_User_Query for efficient yes_count calculation.
- Endpoints require authentication and validate event existence.

## 2026-02-15 — RSVP Admin Management Panel (Phase 5.2)
- Added “RSVP Attendees” metabox in event editor for RSVP-enabled events.
- Displays RSVP stats: yes_count, waitlist_count, capacity, is_full.
- Shows list table of attendees: name, email, status.
- CSV export button for YES attendees (name, email, status columns).
- “Promote from Waitlist” button: promotes oldest waitlist user to yes if capacity allows.
- Implemented in `includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php` with admin-post handlers.

```
