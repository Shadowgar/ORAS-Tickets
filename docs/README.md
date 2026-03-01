# ORAS-Tickets Documentation Index

Last updated: 2026-03-01

## What this plugin is
ORAS-Tickets is an internal ORAS add-on for WordPress event operations.

Core stack:
- WordPress
- The Events Calendar / Events Calendar Pro
- Event Tickets (free)
- WooCommerce (+ Stripe)
- PMPro

## Documentation authority (read in this order)
1. `docs/MASTER_EXECUTION_TRACKER.md` (authoritative operational status and phase percentages)
2. `docs/NEXT.md` (current sprint queue)
3. `docs/MASTER_DEVELOPMENT_PLAN.md` (owner-approved strategic master plan)
4. `docs/PROJECT_STATE.md` (project identity, scope, constraints)
5. `docs/CURRENT_STATE.md` (high-level runtime snapshot)
6. `docs/ROADMAP.md` (phase sequence and gates)

## Non-negotiable rules
1. ORAS-Tickets is an add-on; do not edit TEC/Woo/PMPro/Event Tickets core files.
2. WooCommerce remains the only commerce engine.
3. ORAS-owned data uses versioned envelopes or deterministic schemas.
4. Capability gating is required for privileged operations.
5. No telemetry/SaaS lock-in.

## Current project mode
Phase 5 hardening and completion gating.

Do not start new Phase 6+ features until Phase 5 closure criteria in `MASTER_EXECUTION_TRACKER.md` are complete.

## Recent updates (2026-03-01)
- RSVP/waitlist state transitions now run under event-scoped DB locks.
- Woo order capacity consume/restore flows now run under order + event lock boundaries to prevent concurrent double-mutation.
- CSV exports are hardened through centralized CSV cell neutralization for formula-injection protection.
- Admin RSVP dashboard row rendering is hardened to DOM-safe element construction (no string-built HTML sinks).

## Key technical references
- `docs/EVENT_TICKETS_ENGINE_ARCHITECTURE.md`
- `docs/EVENT_TICKETS_PLUS_FEATURES.md`
- `docs/ET_CODEMAP.md`
- `docs/ET_PLUS_PARITY_MATRIX.md`
- `docs/ARCHITECTURE_BOUNDARIES.md`

## Changelog
- `docs/CHANGELOG.md`
