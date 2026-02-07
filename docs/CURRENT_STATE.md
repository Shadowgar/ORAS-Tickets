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

Summary: Frontend ticket display and add-to-cart robustness work for event pages.

Frontend behavior:
- Tickets render only when currently on sale (display enforces `sale_start` / `sale_end`).
- Tickets that are not currently on sale do not render.
- Sold-out tickets remain visible while within the sales window unless `hide_sold_out` is enabled.

WooCommerce behavior:
- One hidden WooCommerce product is maintained per ticket.
- Products are saved with `post_status = publish` so Woo treats them as purchasable.
- Products use `catalog_visibility = hidden` to prevent catalog discovery.

Cart & checkout rules:
- Add-to-cart is performed via a custom POST handler (see `plugin/includes/Frontend/Tickets_Display.php`).
- Cart revalidation removes invalid or off-sale tickets (malformed ORAS items are cleaned up).
- Valid tickets are not removed during checkout; revalidation avoids removing items when Woo temporarily reports 0 stock and will only cap quantities when stock is positively available.

Phase 3.x exclusions (explicit):
- No cart icon
- No member-only logic
- No merchandise
- No attendee system

### Phase 3.2 — Time-based pricing (Early Bird)
Includes:
- Pricing phases per ticket
- Automatic price switching
- Early bird badge
- Countdown to cutoff
- Stripe metadata phase labels

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
