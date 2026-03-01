# PROJECT_STATE — Canonical Project Definition

Last updated: 2026-03-01

## Project Identity
- Name: ORAS Events Add-On
- Repository: ORAS-Tickets
- Namespace: `ORAS\\Tickets`
- Companion: ORAS Member Hub

## Purpose
Provide deterministic, auditable, ORAS-specific event operations on top of WordPress + TEC + WooCommerce without forking upstream plugins.

## Platform Stack
- WordPress
- The Events Calendar (TEC) + Events Calendar Pro
- Event Tickets (free)
- WooCommerce
- Stripe (via WooCommerce gateway)
- Paid Memberships Pro (PMPro)

## Non-Negotiables
1. ORAS-Tickets is an add-on; no core plugin forks or edits.
2. WooCommerce remains the single commerce engine.
3. Versioned envelopes are required for ORAS-owned data models.
4. Capability-gated admin operations are mandatory.
5. No telemetry or external SaaS lock-in.

## Documentation Authority
- Strategic roadmap: `docs/MASTER_DEVELOPMENT_PLAN.md`
- Operational tracker (authoritative phase progress): `docs/MASTER_EXECUTION_TRACKER.md`
- Immediate queue: `docs/NEXT.md`

If completion percentages differ, `MASTER_EXECUTION_TRACKER.md` wins.

## Current Project Maturity (Master-plan aligned)
- Overall completion: **~56%**
- Core ticketing and reporting foundations are production-capable.
- Phase 5 is partially complete and currently in hardening mode.
- Phases 6+ are mostly planned and should not advance until Phase 5 completion gates are satisfied.
- Strategic scope now includes a board-only executive dashboard (Members Hub style) under Phase 9.5/10.4.

## Current Enforcement Decision
The project is in **stabilize-and-refine mode**:
- Finish Phase 5 depth and hardening first.
- Apply Phase 4 UI/UX polish pass.
- Only then continue into Phase 6+ build-out.

## Evidence Snapshot (2026-03-01)
Recently completed hardening in code:
- RSVP intent parsing and waitlist actions corrected.
- First-class waitlist table with lifecycle/audit tracking added and wired into RSVP/admin promotion paths.
- Promotion handler conflicts removed.
- Unlimited-capacity checks corrected.
- Admin attendee rendering escaped.
- Frontend RSVP submission target bug fixed (form `action` shadowing issue resolved).
- Attendee list integrity corrected (ticket attendees no longer collapse by user; quantities now counted).
- Virtual access duplicate hook/log cleanup.
- Phase 5 WP-CLI integration check harness added.
- CI workflow added to run PHPCS, PHPStan, and Phase 5 checks.
- Waitlist queue operations and audit/history surface added to RSVP dashboard.
- New waitlist AJAX operation handlers added and covered by integration checks.
- Attendee dashboard ticket-source coverage expanded to all supported Woo statuses; on-hold/all status regression checks added.
- Centralized CSV export safety helper added and applied across RSVP/attendee/report/speaker export surfaces.
- Admin RSVP dashboard row rendering moved to DOM-safe element construction.
- Event-scoped lock helper added and applied to frontend/admin RSVP and waitlist promotion critical sections.
- Woo capacity consume/restore handlers hardened with order-scoped idempotency lock + event-scoped atomic ticket-envelope updates.
- Static analysis clean (`phpstan`, `phpcs`).

## Locked vs Active
- Locked baseline: Phases 0-3; major parts of Phase 4.
- Active: Phase 5 hardening and completion criteria.
- Planned: Phases 6-12, gated by Phase 5 completion.
