# Copilot Context — ORAS Tickets (Internal)

You are coding a WordPress plugin add-on for Event Tickets (free) + The Events Calendar + WooCommerce.

## Non-negotiable rules
- Do NOT modify Event Tickets or The Events Calendar plugin code.
- Do NOT use shortcodes for display.
- Do NOT use `the_content()` filters.
- Must render tickets automatically on single event pages using TEC/ET v2 view system.
- WooCommerce is the commerce engine.
- Keep code performance-safe: no broad hook dumping, no heavy queries on non-event pages.
- Phase 1 only (ticket definition + Woo product sync + front-end ticket module + add-to-cart).

## Required reading
- docs/EVENT_TICKETS_ENGINE_ARCHITECTURE.md
- docs/ET_CODEMAP.md
- docs/ET_PLUS_PARITY_MATRIX.md

## Current status
- Plugin scaffold is live and logs successfully.
- Phase 1.2 — Admin Ticket Metabox UI: ✅ Complete
  - Metabox present on the `tribe_events` editor.
  - Repeatable ticket rows implemented with vanilla JS.
  - Persisting to the `_oras_tickets_v1` postmeta envelope via `ORAS\Tickets\Domain\Ticket_Collection::save_for_event()`.

- Next: Ticket storage format (define schema)
## Coding standards
- PHP 8.0+
- WordPress Coding Standards
- Namespacing under ORAS\\Tickets\\
- No global functions except template tags if required.
