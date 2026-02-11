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

## Current maturity (post Phase 3.5)
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
- Phase 4.1 — Speaker Management (MVP, internal only) (APPROVED/PLANNED)
- Phase 4.1-B — Public Speaker Profiles & Event Display (PLANNED)
- Phase 4.2 — Speaker Reporting & Automation (PLANNED)
- Phase 5+ — Future (DEFERRED)

## Planned Phases (Not Started)
Phase 4.1 — Speaker Management (MVP, internal only)
- Speaker entity stored as a WordPress Custom Post Type (CPT).
	- `post_type`: `oras_speaker`.
	- Purpose: internal speaker records (not WP users by default).
	- Public display: not in Phase 4.1 (planned in Phase 4.1-B).
- Field schema (locked, v1):
	- Post Title: Speaker full name (authoritative display name).
	- Post Content (editor): Long bio (internal now; reusable for 4.1-B).
	- Post meta keys:
		- `_oras_speaker_email` (string, internal; never public).
		- `_oras_speaker_affiliation` (string).
		- `_oras_speaker_website_url` (url, optional).
		- `_oras_speaker_headshot_id` (int attachment ID, optional).
		- `_oras_speaker_wp_user_id` (int nullable; set only when PMPro membership is granted).
		- `_oras_speaker_status` (enum: `active`|`inactive`).
		- `_oras_speaker_internal_notes` (string/textarea).
- Speaker to Event assignments stored on the TEC Event as a single versioned meta envelope.
	- Meta key: `_oras_speakers_v1`.
	- Value: array of assignments; each assignment includes:
		- `speaker_id` (int post ID).
		- `role` (string).
		- `is_primary` (bool).
		- `compensation_type` (enum: `membership`|`fee`|`none`).
		- `fee_amount` (number; only if `compensation_type = fee`).
		- `pmpro_level_id` (int; only if `compensation_type = membership`).
		- `fulfilled` (bool).
		- `fulfilled_date` (date string or timestamp).
		- `internal_notes` (string).
- Admin UX (locked, MVP):
	- TEC Event edit screen includes one metabox/panel: "Speakers for this Event".
	- Add existing speaker via search/select.
	- Repeater rows per assignment with fields above.
	- Conditional inputs (fee vs membership).
	- Mark fulfilled with date and internal notes per assignment.
- For current implementation sequencing and tasks, see docs/NEXT.md.
- Reporting queries event meta directly in Phase 4.1; performance indexing may be added in Phase 4.2.
- Hard rules: Speakers are not WP users unless membership fulfillment requires it; never expose email publicly in 4.1; no automated emails in 4.1.

Phase 4.1-B — Public Speaker Profiles & Event Display (planned)
- Optional public speaker profiles (bio, photo, links).
- Speakers displayed on event pages with visibility controls.
- No speaker self-service accounts.
- No compensation logic changes.

Phase 4.2 — Speaker Reporting & Automation (planned)
- Per-speaker history and treasurer exports.
- Internal email notifications and automation.

Phase 5+ — Future (explicitly deferred)
- QR codes and check-in.
- Attendance tracking.
- Member-only gating.
- Zoom or external integrations.
