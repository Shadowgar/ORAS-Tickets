# Copilot Context — ORAS Tickets (Internal)

You are coding a WordPress plugin add-on for The Events Calendar + WooCommerce.

## Non-negotiable rules
- Do NOT modify The Events Calendar, Event Tickets, or WooCommerce plugin code.
- No external services, license servers, or update engines.
- WooCommerce is the commerce engine.
- Follow WordPress Coding Standards.
- Namespacing under ORAS\Tickets\
- ORAS-Tickets owns all ticket logic, pricing, reporting, REST, and printable tickets.
- ORAS Member Hub is member-facing UI only and must not contain ticket logic.
- Speakers are not WordPress users by default; linking to a WP user is optional and only for complimentary PMPro membership use cases.
- No scope creep into public features unless explicitly approved.
- Speakers are CPT records (`post_type` = `oras_speaker`) with public single profile support enabled.
- Speaker CPT schema keys are locked (v1):
	- `_oras_speaker_email`, `_oras_speaker_affiliation`, `_oras_speaker_website_url`, `_oras_speaker_headshot_id`, `_oras_speaker_wp_user_id`, `_oras_speaker_status`, `_oras_speaker_internal_notes`.
- Speaker to Event assignments live in TEC event meta `_oras_speakers_v1` (v1 envelope).
- Agenda rendering uses TEC event meta `_oras_agenda_v1` and optional current-slot highlighting (`assets/js/agenda-now.js`).

## Frontend rendering (current state)
- Tickets render automatically on single `tribe_events` pages.
- Rendering is implemented via `the_content` filter.
- POST handling via `template_redirect`.
- Cart and checkout revalidation via Woo hooks.
- Migration to ET v2 views is deferred to a later phase.

## Current phase status
- Phases 0-5: ✅ LOCKED (governance lock applied for current baseline)
- Phase 5.3: ⏸️ Paused for advancement (production WP-CLI constraint; restart checklist prepared)
- Phases 6-12: PLANNED (do not start until governance opens next gate)

## Recent activity
- 2026-02-15: Phase 5.1 — RSVP frontend + waitlist implemented (basic non-commerce RSVP UI and admin-post handler). See `plugin/includes/Frontend/Event_RSVP.php` for the frontend renderer and handler.
- 2026-02-15: ORAS-only virtual access gating implemented for TEC Virtual Events (meta `_oras_virtual_access_v1`). See `plugin/includes/Frontend/Virtual_Access.php`.

- 2026-02-15: Phase 6 — Attendees Management added admin attendees dashboard, attendee messaging (BCC chunking, validation), and attendee notes with inline editing; key handlers are in `plugin/includes/Bootstrap.php` and frontend JS is `plugin/assets/admin/dashboard-rsvp.js`.
- 2026-03-01: Security and integrity hardening pass completed for RSVP/waitlist and capacity operations:
	- event-scoped DB lock helper added and applied to RSVP + waitlist promotion critical sections,
	- Woo capacity consume/restore hardened with order-level idempotency lock + event-level atomic mutation lock,
	- CSV export safety centralized and applied across report/export handlers,
	- admin RSVP dashboard row rendering moved to DOM-safe element construction.

## Phase 3.1 — Locked (Frontend & UX)
The Phase 3.1 frontend behaviors are locked and must not be regressed. Implementations that change the runtime display, purchasability, or cart revalidation behavior described below require an explicit design review and a versioned migration plan.

Invariants (do not change without review):
- Sale window filtering happens at display time: tickets are shown only when `sale_start` <= now <= `sale_end`.
- Woo products representing tickets must be saved with `post_status = publish` and `catalog_visibility = hidden`.
- Cart revalidation must not remove valid tickets during checkout; revalidation may remove malformed or off-sale items but must avoid removing items when Woo temporarily reports 0 stock during reservation.

Forbidden in ticket phases (explicit):
- Adding a global cart icon or global cart UI injection into themes.
- Injecting UI into site header/footer or modifying site themes directly.
- Implementing global UI widgets as part of Phase 3.x (cart widgets are a post-3.x concern).

Guidance for future phases:
- Cart UI widgets and visual cart affordances belong in post-3.x phases and require separate design/UX work.
- Member hub and member-only features are separate concerns and should be scoped to dedicated phases.

## Locked Phases
Phases 0 through 5 are governance-locked and must not be modified without an explicit design review and a documented migration plan.

Prohibited changes (unless explicitly approved):
- Time-based pricing resolver logic (`ORAS\Tickets\Domain\Pricing\Price_Resolver`).
- Cart pricing hook logic (server-side cart price application).
- Order item snapshot metadata keys and behavior (order item meta written during checkout).
- Phase 3.1 sale window filtering, cart safety checks, and frontend UX behaviors.

## Upcoming Work
- Next planned action: Phase 5.3 restart only after constraints lift, following `docs/PHASE53_RESTART_CHECKLIST_2026-03-02.md`.
