# Copilot Context — ORAS Tickets (Internal)

You are coding a WordPress plugin add-on for The Events Calendar + WooCommerce.

## Non-negotiable rules
- Do NOT modify The Events Calendar, Event Tickets, or WooCommerce plugin code.
- No external services, license servers, or update engines.
- WooCommerce is the commerce engine.
- Follow WordPress Coding Standards.
- Namespacing under ORAS\Tickets\

## Frontend rendering (current state)
- Tickets render automatically on single `tribe_events` pages.
- Rendering is implemented via `the_content` filter.
- POST handling via `template_redirect`.
- Cart and checkout revalidation via Woo hooks.
- Migration to ET v2 views is deferred to a later phase.

## Current phase status
- Phase 1.2 — Admin Ticket Metabox UI: ✅ Complete
- Phase 2.0 — Woo Product Sync: ✅ Complete
- Phase 2.1B — POST Enforcement: ✅ Complete
- Phase 2.1C — Cart & Checkout Revalidation: ✅ Complete

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
