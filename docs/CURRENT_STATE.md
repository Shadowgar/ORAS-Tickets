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
- No attendance tracking or QR scanning exists.
- Agenda (event schedule) is implemented for TEC single events via event meta `_oras_agenda_v1`.
- Speaker management and speaker public profiles are implemented.

---

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
