# ORAS-Tickets — Current State (Phase 2.1C)

## What exists
- Ticket definitions stored in `_oras_tickets_v1`
- Idempotent Woo product sync
- Hidden, private, virtual Woo products
- Frontend ticket UI rendered on event pages
- Server-side enforcement:
  - Sale windows
  - Stock caps
  - Unlimited ticket caps
- Cart and checkout revalidation

## What does NOT exist yet
- ET provider registration
- Attendee records
- Check-in
- CSV exports
- ET v2 template integration

## Design intent
Correctness > parity.

Defense-in-depth enforced at:
- UI
- POST
- Cart
- Checkout
