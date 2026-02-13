# PROJECT_STATE — Canonical Definition

This file defines WHAT this project is.
It changes rarely.

## Project
Name: ORAS Events Add-On  
Repo: ORAS-Tickets

## Purpose
Provide a modular, future-proof event enhancement platform for ORAS built on top of TEC.
Tickets remain the financial backbone.

## Stack
- WordPress
- PHP 8.0+
- The Events Calendar (TEC)
- Event Tickets (free)
- WooCommerce + Stripe
- Paid Memberships Pro (PMPro)

## Mental Model
One plugin.
Multiple modules.
Tickets are foundational, not exclusive.

## Authority
If any document conflicts with CURRENT_STATE.md, CURRENT_STATE.md wins.

## Current maturity (post Phase 4.5)
- ORAS-Tickets provides full backend ticketing, reporting, REST, and printable tickets.
- ORAS Member Hub provides member-facing display only.
- System is operational end-to-end.
- Ticketing is frontend-stable and production-ready for event pages: the ticket display, sales-window filtering, stock visibility, and add-to-cart flow are complete and intended for live use.
- ORAS-Tickets is evolving toward a broader "event add-on" system; ticketing remains the foundational module and primary supported surface.
- This document does not introduce new phases or feature commitments — it records the current scope and maturity only.

- Phase 3.2: Time-based pricing resolver is implemented and verified (frontend display, cart/checkout pricing, and order-item snapshot metadata).
- Phase 3.3: Admin UX redesign completed (tickets editor UI-only improvements).
- Phase 3.4: Treasurer reporting is complete and verified.
- Phase 3.5: Member tickets and visibility are complete (REST API, member hub display, printable tickets).
- Phase 4.1: Speaker management MVP is implemented (speaker CPT, event assignment envelope, obligations workflow).
- Phase 4.1-B: Public speaker profiles and speaker single page template routing are implemented.
- Phase 4.5.x: Agenda is implemented (multi-day admin metabox, frontend rendering, current-slot highlight/autoscroll, speaker modal popups).

## Locked Phases
- Phase 0 is complete and locked (foundations, bootstrapping, tooling, namespaces).
- Phase 1 is complete and locked (ticket core model, deterministic identities, admin save paths).
- Phase 2 is complete and locked (WooCommerce integration, cart/checkout validation and revalidation).
- Phase 3.1 and Phase 3.2 are complete and locked. Any change affecting time-based pricing, cart price application, or the Phase 3.1 frontend sale-window behaviors requires a documented design review and migration plan.
- Phase 3.3 is complete and locked as UI-only work; future UI changes require review to avoid regressions.
- Phase 3.4 is complete and locked (treasurer reporting).
- Phase 3.5-A and Phase 3.5-B are complete and locked (REST API, grouped-by-event API).
- Phase 3.5-C is complete and locked (Member Hub ticket display).
- Phase 3.5-D is complete and locked (Printable tickets).
- Phase 4.1 is complete and locked (Speaker management MVP).
- Phase 4.1-B is complete and locked (Public speaker profiles/event display baseline).
- Phase 4.5 is complete and locked (Agenda + speaker modal UX baseline).

## Full Phase List (Trackable)
- Phase 0 — Foundations (COMPLETE/LOCKED)
- Phase 1 — Ticket Core Model (COMPLETE/LOCKED)
- Phase 2 — WooCommerce Integration (COMPLETE/LOCKED)
- Phase 3.1 — Frontend Ticket Rendering (COMPLETE/LOCKED)
- Phase 3.2 — Time-Based Pricing (COMPLETE/LOCKED)
- Phase 3.3 — Admin UX Redesign (COMPLETE/LOCKED)
- Phase 3.4 — Treasurer Reporting (COMPLETE/LOCKED)
- Phase 3.5-A — REST API (COMPLETE/LOCKED)
- Phase 3.5-B — Grouped-by-Event API (COMPLETE/LOCKED)
- Phase 3.5-C — Member Hub UI Rendering (COMPLETE/LOCKED)
- Phase 3.5-D — Printable Tickets (COMPLETE/LOCKED)
- Phase 4.1 — Speaker Management (MVP) (COMPLETE/LOCKED)
- Phase 4.1-B — Public Speaker Profiles & Event Display (COMPLETE/LOCKED)
- Phase 4.5.1 — Agenda MVP (COMPLETE/LOCKED)
- Phase 4.5.3 — Agenda Current Highlight/Autoscroll (COMPLETE/LOCKED)
- Phase 4.5.4 — Agenda Speaker Modal (COMPLETE/LOCKED)
- Phase 4.2 — Speaker Reporting & Automation (PLANNED)
- Phase 5+ — Future (DEFERRED)

## Agenda + Speakers (Implemented Schema Notes)
- Event agenda envelope key: `_oras_agenda_v1` (`settings` + `days[]` + `slots[]`).
- Slot schema: `start`, `end`, `title`, `desc`, `type`, `location`, `visibility`, `speakers[]`.
- Slot speaker rows: `speaker_id`, `role`, optional `label`.
- Current-slot highlighting/autoscroll uses `assets/js/agenda-now.js`.
- Speaker modal data is embedded per-event and includes headshot fallback from `_oras_speaker_headshot_id` to featured image.
- Speaker single URLs use CPT rewrite slug `speaker` and plugin template loading.

## Planned Phases (Not Started)
Phase 4.2 — Speaker Reporting & Automation
- Per-speaker exports/analytics hardening.
- Optional internal notification refinements.

Phase 5+ — Future (explicitly deferred)
- QR codes and check-in.
- Attendance tracking.
- Member-only gating.
- Zoom or external integrations.
