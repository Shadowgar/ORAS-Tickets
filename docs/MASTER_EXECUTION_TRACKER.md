# ORAS-Tickets Master Execution Tracker

Last updated: 2026-02-25

## Purpose
This is the operational source of truth for execution progress.

- Strategic baseline: `docs/MASTER_DEVELOPMENT_PLAN.md`
- Operational status and completion scoring: this document
- Immediate work queue: `docs/NEXT.md`

If phase status conflicts across docs, this file wins.

## Status Model
- `LOCKED`: complete and only changed via explicit design review
- `ACTIVE`: in-flight implementation/hardening
- `PLANNED`: approved but not started
- `DEFERRED`: intentionally postponed

## Phase Scorecard (0 -> 12)

| Phase | Summary | Completion | Status | Decision |
|---|---|---:|---|---|
| 0 | Foundations / bootstrap / capabilities | 95% | LOCKED | keep |
| 1 | Ticket model + versioned envelope | 95% | LOCKED | keep |
| 2 | Woo mapping + commerce integrity | 95% | LOCKED | keep |
| 3 | Reporting, pricing, member APIs, print | 85% | LOCKED | polish only |
| 4 | Speakers + agenda + recurrence guardrail | 88% | ACTIVE | polish UI + archive refinement |
| 5 | Registration + capacity intelligence | 74% | ACTIVE | harden before Phase 6+ |
| 6 | Advanced ticketing intelligence | 8% | PLANNED | blocked by Phase 5 completion |
| 7 | Speaker intelligence expansion | 23% | PLANNED | blocked by Phase 4/5 polish |
| 8 | Virtual/hybrid advanced features | 15% | PLANNED | keep scoped to add-on rules |
| 9 | Member Hub expansion | 38% | PLANNED | build after Phase 5/6 baseline |
| 10 | Financial intelligence | 17% | PLANNED | expand from existing reports |
| 11 | Discovery and UX enhancements | 5% | PLANNED | defer until core stabilizes |
| 12 | Automation and notifications | 0% | PLANNED | start only after data model maturity |

Overall completion (master-plan aligned): **~53%**

## 2026-02-25 Hardening Update
Completed in code:
- RSVP intent parser fixed to support `waitlist` and `leave_waitlist`.
- Undefined redirect variable in RSVP handler removed.
- Conflicting `admin_post_oras_rsvp_promote` handler removed from legacy metabox path.
- Unlimited capacity logic corrected in dashboard and waitlist promotion paths.
- Dashboard attendee table rendering hardened with output escaping.
- Virtual access duplicate hook and debug log noise removed.
- Static analysis restored to green (`composer phpstan`, `composer phpcs`).
- Waitlist moved to first-class store: `wp_oras_ticket_waitlist` with lifecycle fields (`waiting`, `promoted`, `left`) and audit fields (`source`, `actor_user_id`, timestamps).
- Legacy waitlist usermeta now backfills into the new store per event, and admin/dashboard promotion reads queue order from the store.

## Backtrack / Refine Before Advancing
Do not advance Phase 6+ until all items below are complete:

1. Phase 5.2 waitlist architecture depth
- Core complete:
  - First-class waitlist store implemented.
  - Lifecycle and audit fields implemented.
  - Deterministic promotion + leave flows wired through frontend/admin paths.
- Remaining before lock:
  - Add explicit admin queue operations beyond single promote (bulk/manual controls).
  - Add operational audit surface (readable history/report view) for admins.

2. Phase 5 verification and tests
- Add WP-CLI integration checks for RSVP, waitlist promotion, attendees exports, and messaging handlers.
- Add CI job that runs syntax, PHPCS, PHPStan, and the WP-CLI checks.

3. Phase 4 visual quality and consistency
- Remove remaining inline CSS in frontend templates where feasible.
- Replace temporary color overrides (agenda neon dark-mode overrides) with production theme tokens.
- Ensure mobile + desktop rendering quality for speaker and agenda views.

## Definition of Done (for Phase Advancement)
A phase cannot move to `LOCKED` unless:
- Feature behavior is deterministic and capability-gated.
- Security and output encoding checks are passed.
- Basic integration checks exist for critical paths.
- Docs (`CURRENT_STATE.md`, `PROJECT_STATE.md`, `NEXT.md`, `ROADMAP.md`) are updated in same change set.

## Active Priority Order
1. Complete Phase 5 hardening and waitlist architecture depth.
2. Complete Phase 4 frontend/admin visual polish pass.
3. Build minimal integration test suite + CI enforcement.
4. Resume Phase 6 features (QR/check-in, reservation windows) only after 1-3 are closed.
