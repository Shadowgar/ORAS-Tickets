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
