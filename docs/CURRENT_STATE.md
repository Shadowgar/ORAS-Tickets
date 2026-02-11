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
- Speakers are not implemented.

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

---

## Not Implemented Yet
- Speakers, public speaker profiles, or speaker reporting.
- Attendance tracking, QR codes, or check-in.
- Member-only gating beyond existing PMPro membership usage.
- Zoom or external integrations.

---

## Speakers (Planned)
- Not implemented yet.
- Phase 4.1 will be internal only (admin/treasurer).
- Public speaker profiles and event page display are planned for Phase 4.1-B.
