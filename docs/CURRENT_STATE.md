# ORAS Events Add-On — Current State (Authoritative)

## Identity (Locked)

No completed functionality is removed or reset.
## Hard Rules (Non-Negotiable)
## Progress Snapshot & Recommended Focus (2026-02-25)

- Overall project maturity (estimate): **~72%** complete.
- Short-term focus: Consolidate Phase 5 (Registration & Capacity) before expanding further into Phase 6/7.

Why now:
- Core ticketing, RSVP, and attendees features are implemented and mostly stable; however, the admin UX is fragmented across metaboxes and dashboard panels which increases maintenance cost.

Immediate recommended actions (next 1–2 sprints):
1. Implement the unified `ORAS EVENTS ADDON` metabox (Phase 5.3) to host Tickets, Agenda, RSVP, Speakers, and Virtual Access UI containers.
2. Move operational RSVP attendee lists into the Dashboard (Phase 5.4) after the metabox refactor.
3. Harden handlers (remove temporary debug logs), add 5–10 WP-CLI integration checks for AJAX/admin_post and a GitHub Actions job to run them.

Acceptance criteria for finishing this consolidation:
- Single metabox present on event edit that contains vertical tabs with each feature and reuses existing save handlers.
- Dashboard `RSVP Management` tab shows event selector and paginated AJAX attendees list with CSV export and waitlist promotion actions.
- Automated WP-CLI checks for RSVP flows and attendees export pass in CI.

If these acceptance criteria are met, continue to Phase 6/7 feature expansion (QR/check-in, reservation window, speaker analytics).
- Add-on only (no forks of TEC, Event Tickets, or WooCommerce)
- WooCommerce is the only commerce engine
---

## Current System State (Authoritative)
- ORAS-Tickets provides full backend ticketing, reporting, REST API, and printable tickets.
- Phase 2: WooCommerce integration (one hidden Woo product per ticket, cart/checkout validation and revalidation).
- Phase 3.1: Frontend ticket rendering (TEC single event pages, on-sale logic, sold-out behavior, add-to-cart flow).
- Phase 3.2: Time-based pricing (pricing phases, price resolver, snapshot meta stored on order items).
- Phase 3.3: Admin UX redesign (tickets editor improvements, UI-only).
- Phase 3.4: Treasurer reporting (pricing aggregates, KPI correctness, multi-event summaries, filtering, CSV export).
- Phase 3.5-A: REST API.
- Phase 3.5-C: Member Hub UI rendering.
- Phase 3.5-D: Secure printable ticket pages (ticket-card layout, one card per ticket quantity, logged-in ownership validation, no QR or check-in).
- Phase 4.5.3: Agenda "currently happening" highlight + autoscroll.
- Phase 4.5.4: Agenda slot speaker modal UX.
Planned next steps (Phase 5.3-5.5):
 - Phase 5.3: Unified ORAS Event Metabox — create a single `ORAS EVENTS ADDON` master metabox in the event editor that contains vertical tabs for Tickets, Agenda, RSVP, Speakers, and Virtual Access. This is a UI container refactor only (reuse existing forms and save handlers; no meta schema changes).
---

## Not Implemented Yet
- Attendance tracking, QR codes, or check-in.
- Member-only gating beyond existing PMPro membership usage.
- Zoom or external integrations.
- Event agenda data is stored in event meta `_oras_agenda_v1` using a versioned envelope.
- Envelope shape: `settings` + `days[]`; each day contains `slots[]`.
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
