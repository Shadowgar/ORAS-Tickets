# ORAS Events Add-On — Current State (Authoritative)

## Identity (Locked)
Logical name: ORAS Events Add-On  
Repository name: ORAS-Tickets (unchanged)

This plugin is a WordPress add-on for The Events Calendar (TEC).
Tickets are one module within a broader event-enhancement platform.

No completed functionality is removed or reset.

---

## Hard Rules (Non-Negotiable)
- Add-on only (no forks of TEC, Event Tickets, or WooCommerce)
- WooCommerce is the only commerce engine
- No external services, license servers, or SaaS dependencies
- WordPress Coding Standards
- Deterministic, auditable behavior

---

## Current System State (Authoritative)
- ORAS-Tickets provides full backend ticketing, reporting, REST API, and printable tickets.
- ORAS Member Hub provides member-facing display only and consumes the ORAS-Tickets REST API.
- System is operational end-to-end.
- Treasurer reporting is complete and reliable.
- Printable tickets are complete and internal (secure direct URLs with ownership validation).
 
## Recent Additions (2026-02-15)
- RSVP + Waitlist frontend: non-commerce RSVP UI now renders on single events when `_oras_rsvp_v1.enabled` is true; per-user state stored in usermeta `_oras_rsvp_event_{EVENT_ID}` with values `yes|no|waitlist` (code: `plugin/includes/Frontend/Event_RSVP.php`).
- Virtual access gating: ORAS-only "Show to" options for TEC Virtual Events persisted in `_oras_virtual_access_v1` and enforced server-side when rendering virtual join links (code: `plugin/includes/Frontend/Virtual_Access.php`).
- RSVP REST endpoints: Added GET `/oras/v1/rsvp/my` and `/oras/v1/rsvp/event/{event_id}` for Member Hub consumption (code: `plugin/includes/Api/Rsvp.php`).

### Phase 6 (Attendees Management) — Recent work (2026-02-15)
- Phase 6.2: Attendees Dashboard MVP — Admin Dashboard with event selector, attendees list with deduplication across RSVP and ticket sources, AJAX loading, filters, and CSV export (code: `plugin/includes/Admin/Pages/Dashboard_Page.php`, `plugin/includes/Bootstrap.php`, `plugin/assets/admin/dashboard-rsvp.js`).
- Phase 6.3: Attendee Operations MVP — Row actions (View User / View Order / Mailto), advanced filters (ticket status, guests-only), and improved CSV export plus minor URL bugfixes.
- Phase 6.4: Attendee Messaging MVP — Admin compose panel, BCC-based bulk sending with chunking, per-chunk failure handling, and CC-to-admin option; handler `oras_attendees_send_email` now validates and normalizes recipient emails and returns accurate recipient/chunk counts (code: `plugin/includes/Bootstrap.php`, `plugin/assets/admin/dashboard-rsvp.js`).
- Phase 6.5: Attendee Notes MVP — Inline note editor in attendees table, storage in post meta envelope `_oras_attendee_notes_v1`, filtering by notes, and CSV column for notes (code: `plugin/includes/Bootstrap.php`, `plugin/assets/admin/dashboard-rsvp.js`).

All Phase 6 changes were implemented with minimal diffs, no new DB tables, and focused on admin-only workflows. Verification was performed via WP-CLI simulations for AJAX handlers and CSV export.


## Locked & Completed Phases
- Phase 0: Foundations (repo structure, bootstrapping, tooling, namespaces).
- Phase 1: Ticket core model (event ticket envelope meta, deterministic identity, safe admin save paths).
- Phase 2: WooCommerce integration (one hidden Woo product per ticket, cart/checkout validation and revalidation).
- Phase 3.1: Frontend ticket rendering (TEC single event pages, on-sale logic, sold-out behavior, add-to-cart flow).
- Phase 3.2: Time-based pricing (pricing phases, price resolver, snapshot meta stored on order items).
- Phase 3.3: Admin UX redesign (tickets editor improvements, UI-only).
- Phase 3.4: Treasurer reporting (pricing aggregates, KPI correctness, multi-event summaries, filtering, CSV export).
- Phase 3.5-A: REST API.
- Phase 3.5-B: Grouped-by-event API.
- Phase 3.5-C: Member Hub UI rendering.
- Phase 3.5-D: Secure printable ticket pages (ticket-card layout, one card per ticket quantity, logged-in ownership validation, no QR or check-in).
- Phase 4.1: Speaker Management MVP (CPT + event speaker assignments + obligations workflows).
- Phase 4.1-B: Public Speaker Profiles & Event Display.
- Phase 4.5.1: Agenda MVP (admin metabox + frontend agenda rendering).
- Phase 4.5.3: Agenda "currently happening" highlight + autoscroll.
- Phase 4.5.4: Agenda slot speaker modal UX.
- Phase 4.6.1: Speaker historical index (_oras_speaker_history_v1 envelope).
- Phase 4.6.2: Speaker resource archive (slot-level resources, speaker page rendering).
- Phase 4.7: Recurrence guardrail (prevents ORAS ticketing on recurring TEC events).
- Phase 5.1-B: Frontend RSVP + Waitlist (UI, capacity enforcement, usermeta persistence).
- Phase 5.1-C: RSVP REST endpoints (Member Hub API consumption).
- Phase 5.2: RSVP Admin Management Panel (stats, attendees list, CSV export, waitlist promotion).

Planned next steps (Phase 5.3-5.5):
 - Phase 5.3: Unified ORAS Event Metabox — create a single `ORAS EVENTS ADDON` master metabox in the event editor that contains vertical tabs for Tickets, Agenda, RSVP, Speakers, and Virtual Access. This is a UI container refactor only (reuse existing forms and save handlers; no meta schema changes).
 - Phase 5.4: RSVP Management Dashboard — move RSVP attendee lists, CSV export, and waitlist promotion out of the event edit screen and into the plugin Dashboard with an event selector and AJAX-driven lists.
 - Phase 5.5: Settings Page Expansion — centralize global defaults and feature toggles in `ORAS-Tickets → Settings` (default capacity, waitlist behavior, virtual access defaults, ticket auto-complete toggles).

---

## Not Implemented Yet
- Attendance tracking, QR codes, or check-in.
- Member-only gating beyond existing PMPro membership usage.
- Zoom or external integrations.

## Agenda (Implemented)
- Event agenda data is stored in event meta `_oras_agenda_v1` using a versioned envelope.
- Envelope shape: `settings` + `days[]`; each day contains `slots[]`.
- Slot fields: `start`, `end`, `title`, `desc`, `type`, `location`, `visibility`, and `speakers[]`.
- Admin metabox supports multi-day nested repeaters (days -> slots -> speakers).
- Admin date/time inputs use native pickers; dates are stored as `YYYY-MM-DD` (browser UI may display locale forms such as `MM/DD/YYYY`), times stored as `HH:MM`.
- Frontend agenda renders on single `tribe_events` pages.
- "Highlight current" and optional autoscroll are handled by `assets/js/agenda-now.js`.

## Speaker Integration (Implemented)
- Slot speakers are stored as `slot['speakers'][]` rows with `speaker_id`, `role`, and optional `label`.
- Frontend agenda renders speakers beneath the slot title.
- Clicking a speaker opens a modal (no navigation) with abbreviated profile info:
	- headshot (uses `_oras_speaker_headshot_id` first; falls back to Featured Image),
	- name,
	- affiliation,
	- short bio,
	- website link,
	- "View full profile" permalink link.
- Speaker permalink pages are supported via publicly queryable `oras_speaker` CPT and plugin template loader.
- Speaker rewrite slug is `speaker` (URLs like `/speaker/{post_name}/`).

## Verification Checklist
- `wp post meta get <event_id> _oras_agenda_v1`
- `wp post meta list <speaker_id> --keys=_oras_speaker_headshot_id`
- `wp post get <speaker_id> --fields=ID,post_status,post_name`
