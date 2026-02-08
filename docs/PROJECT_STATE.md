# PROJECT_STATE — Canonical Definition

This file defines WHAT this project is.
It changes rarely.

## Project
Name: ORAS Events Add-On  
Repo: ORAS-Tickets

## Purpose
Provide a modular, future-proof event enhancement platform for ORAS built on top of TEC.
Tickets remain the financial backbone.

## Stack
- WordPress
- PHP 8.0+
- The Events Calendar
- WooCommerce (optional but primary for tickets)

## Mental Model
One plugin.
Multiple modules.
Tickets are foundational, not exclusive.

## Authority
If any document conflicts with CURRENT_STATE.md, CURRENT_STATE.md wins.

## Current maturity (post Phase 3.3)
- Ticketing is frontend-stable and production-ready for event pages: the ticket display, sales-window filtering, stock visibility, and add-to-cart flow are complete and intended for live use.
- ORAS-Tickets is evolving toward a broader "event add-on" system; ticketing remains the foundational module and primary supported surface.
- This document does not introduce new phases or feature commitments — it records the current scope and maturity only.

- Phase 3.2: Time-based pricing resolver is implemented and verified (frontend display, cart/checkout pricing, and order-item snapshot metadata).
- Phase 3.3: Admin UX redesign completed (tickets editor UI-only improvements).
- Next planned work: Phase 3.4 — Admin polish & treasurer confidence.

## Locked Phases
- Phase 3.1 and Phase 3.2 are complete and locked. Any change affecting time-based pricing, cart price application, or the Phase 3.1 frontend sale-window behaviors requires a documented design review and migration plan.
- Phase 3.3 is complete and locked as UI-only work; future UI changes require review to avoid regressions.
