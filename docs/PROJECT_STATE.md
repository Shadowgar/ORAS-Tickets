# PROJECT_STATE — Canonical Project Definition

Last updated: 2026-02-25

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
- Overall completion: **~53%**
- Core ticketing and reporting foundations are production-capable.
- Phase 5 is partially complete and currently in hardening mode.
- Phases 6+ are mostly planned and should not advance until Phase 5 completion gates are satisfied.

## Current Enforcement Decision
The project is in **stabilize-and-refine mode**:
- Finish Phase 5 depth and hardening first.
- Apply Phase 4 UI/UX polish pass.
- Only then continue into Phase 6+ build-out.

## Evidence Snapshot (2026-02-25)
Recently completed hardening in code:
- RSVP intent parsing and waitlist actions corrected.
- First-class waitlist table with lifecycle/audit tracking added and wired into RSVP/admin promotion paths.
- Promotion handler conflicts removed.
- Unlimited-capacity checks corrected.
- Admin attendee rendering escaped.
- Virtual access duplicate hook/log cleanup.
- Static analysis clean (`phpstan`, `phpcs`).

## Locked vs Active
- Locked baseline: Phases 0-3; major parts of Phase 4.
- Active: Phase 5 hardening and completion criteria.
- Planned: Phases 6-12, gated by Phase 5 completion.
