# ORAS-Tickets (Internal)

ORAS-Tickets is an **internal-only** Event Tickets add-on for the Oil Region Astronomical Society.

It is designed to **replace Event Tickets Plus** by providing equal or better functionality
while integrating directly with **Event Tickets (free)**, **The Events Calendar**, and **WooCommerce**.

This plugin is:
- GPL-compatible
- Not sold
- Not distributed
- Used on a single private server

---

## Core Principles (DO NOT VIOLATE)

1. **Event Tickets (free) remains installed and active**
2. ORAS-Tickets is an **add-on**, not a fork
3. No shortcode-based ticket rendering
4. No reliance on `the_content()`
5. Tickets must appear automatically on single event pages
6. WooCommerce is the commerce engine
7. Event Tickets view system (v2) is the rendering layer
8. No external services, no license servers, no update engines

---

## Required Reading (in order)

Any AI or developer must read these files **before writing code**:

1. `docs/EVENT_TICKETS_ENGINE_ARCHITECTURE.md`
2. `docs/EVENT_TICKETS_PLUS_FEATURES.md`
3. `docs/ET_CODEMAP.md`
4. `docs/ET_PLUS_PARITY_MATRIX.md`

---

## Current Phase

**Phase 1 — Ticketing MVP**

Goal:
- Define tickets on the event editor
- Sync tickets to WooCommerce products
- Automatically render tickets on event pages using Event Tickets v2 views
- Complete checkout via WooCommerce

Nothing beyond Phase 1 should be started yet.

### Progress

- Phase 1.2 — Admin Ticket Metabox UI: ✅ Complete
	- Metabox added to the `tribe_events` editor with repeatable rows (vanilla JS).
	- Persisting ticket definitions to `_oras_tickets_v1` via `ORAS\Tickets\Domain\Ticket_Collection::save_for_event()`.
	- Implemented fields: `name`, `price`, `capacity`, `sale_start`, `sale_end`, `description`, `hide_sold_out`.
	- Frontend rendering and commerce/provider sync are NOT implemented in Phase 1.2.

---

## Forbidden Actions

- Do NOT modify Event Tickets core files
- Do NOT inject code into The Events Calendar templates
- Do NOT use global hook dumping
- Do NOT redesign architecture without updating docs first
- Do NOT add licensing, update checks, or obfuscation

---

## Repository Layout

