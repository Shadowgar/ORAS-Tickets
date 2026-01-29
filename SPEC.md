# ORAS Tickets — Spec (v0.1)

## Goal
Provide event ticketing integrated with The Events Calendar (TEC) and WooCommerce:
- Create ticket types on the event editor
- Auto-create/update hidden Woo products
- Render a tickets module on the event page (qty + buy)
- Use Woo checkout (Stripe), Woo refunds, Woo reporting
- Generate attendees lists from Woo orders
- CSV export
- Check-in (later)
- Waitlist, presets, rules, wallet/PDF, seating (later phases)

## v1 Scope (Milestone order)
M1: Event editor tickets UI + Woo product auto-create/update  
M2: Front-end tickets module + sale windows + capacity  
M3: Attendees admin screen + CSV export  
M4: Attendee registration fields (per attendee)  
M5: Check-in (no QR first)  
M6+: Waitlist, presets, purchase rules, wallet/PDF, seating

## Hard constraints
- No platform commissions
- No license server
- No external services required
- Must survive board turnover (simple admin UX, documented)

## Dependencies
- The Events Calendar (TEC)
- WooCommerce

## Data model (initial)
- Event post meta: `_oras_tickets` (array of ticket definitions + linked product IDs)
- Woo product meta:
  - `_oras_event_id`
  - `_oras_ticket_key` (stable key per ticket row)
