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

## Locked & Completed

### Phases 1.x – 3.0 (CLOSED)
Tickets core, Woo lifecycle, refunds, reporting, admin UI

Status: COMPLETE AND CLOSED

---

## Active Roadmap (Authoritative)

### Phase 3.1 — Frontend UX polish (Tickets)
Status: COMPLETE
 Status: COMPLETE (LOCKED)

Summary: Frontend ticket display and add-to-cart robustness work for event pages.

Frontend behavior:

WooCommerce behavior:

Cart & checkout rules:

Phase 3.x exclusions (explicit):

### Phase 3.2 — Time-based pricing (Early Bird)
Status: COMPLETE and LOCKED

Verified end-to-end: frontend price resolution, cart/checkout pricing application, and order-item snapshot metadata.

Details:
	- _oras_ticket_price_phase_key
	- _oras_ticket_price_phase_label
	- _oras_ticket_price_phase_price

Phase 3.1 behavior remains LOCKED and unchanged.

Notes:
- The implementation of Phase 3.1 and 3.2 is intentionally locked. Any changes to these behaviors require a design review and migration plan.
### Phase 3.3 — Email & communication layer
Includes:
- Event-aware purchase email
- Ticket summary (non-QR)
- Event info & admin notes
- ORAS branding

### Phase 3.4 — Admin polish & treasurer confidence
Includes:
- Event sales status panel
- Capacity bars
- Pricing phase awareness
- Stripe metadata audit view
- Admin warnings

---

## Expansion Phases (Locked Order)

### Phase 4.0 — Plugin rebrand & modularization
Purpose: Prepare codebase for expansion without breaking tickets

Includes:
- Logical rename to ORAS Events Add-On
- Formal module boundaries
- Feature-flag-friendly bootstrap
- No functional regressions

### Phase 4.1 — Speakers module
### Phase 4.2 — Agenda / schedule builder
### Phase 4.3 — Door prizes
### Phase 4.4 — Event enhancements
### Phase 4.5 — Polish & hardening

---

## Explicitly Out of Scope
- Attendees
- Check-in / QR
- RSVP / free tickets
- TEC Pro view replacement
- SaaS / licensing
- Marketplace distribution
