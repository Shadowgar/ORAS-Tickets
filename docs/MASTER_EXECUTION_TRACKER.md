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
| 5 | Registration + capacity intelligence | 90% | ACTIVE | finish polish + signoff |
| 6 | Advanced ticketing intelligence | 8% | PLANNED | blocked by Phase 5 completion |
| 7 | Speaker intelligence expansion | 23% | PLANNED | blocked by Phase 4/5 polish |
| 8 | Virtual/hybrid advanced features | 15% | PLANNED | keep scoped to add-on rules |
| 9 | Member Hub expansion + board dashboard surface | 38% | PLANNED | build after Phase 5/6 baseline |
| 10 | Financial intelligence + board analytics layer | 17% | PLANNED | expand from existing reports |
| 11 | Discovery and UX enhancements | 5% | PLANNED | defer until core stabilizes |
| 12 | Automation and notifications | 0% | PLANNED | start only after data model maturity |

Overall completion (master-plan aligned): **~56%**

## Phase Scoring Rationale (Why Each % Is Not 100 Yet)
- Phase 0 (95%): architecture/capability/bootstrap baseline is stable; remaining 5% is reserved for stronger regression automation around bootstrap/capability invariants.
- Phase 1 (95%): versioned ticket envelope is stable in production paths; remaining 5% is deeper migration/edge-case test depth, not feature gaps.
- Phase 2 (95%): Woo mapping and commerce integrity are stable; remaining 5% is defensive edge-case and lifecycle verification breadth.
- Phase 3 (85%): core reports and exports exist; advanced analytics depth and board-ready KPI layering are still missing.
- Phase 4 (88%): speaker/agenda baseline is strong; archive refinement and final UI polish remain open.
- Phase 5 (90%): RSVP/waitlist/capacity baseline, queue operations, audit surface, and verification suite/CI are in place; remaining work is polish/soak and final signoff.
- Phase 6 (8%): advanced ticket intelligence (QR/check-in/reservations) is largely unstarted by design.
- Phase 7 (23%): speaker intelligence expansion has partial groundwork only.
- Phase 8 (15%): virtual/hybrid advanced automation is planned but mostly unimplemented.
- Phase 9 (38%): member-facing baseline exists, but major slices (RSVP history, speaking history, invoices, board dashboard surface) are pending.
- Phase 10 (17%): baseline reporting exists, but advanced suite, invoice/refund intelligence, and board analytics data layer are pending.
- Phase 11 (5%): discovery UX enhancements are intentionally deferred.
- Phase 12 (0%): automation/notification system has not started.

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
- Frontend RSVP submission URL resolution fixed (`action` field shadowing no longer breaks POST target).
- Attendee list integrity fixed so ticket attendees are no longer collapsed by user and ticket quantities are counted correctly per event.
- Added Phase 5 WP-CLI integration check harness (`scripts/phase5-integration-checks.php`) covering RSVP transitions, waitlist promotion flow, attendee data contracts, notes, and messaging handlers.
- Added wrapper runner (`scripts/run-phase5-integration-checks.sh`) with explicit `ORAS_WP_ENV_DIR` support for the shared `/projects/oras-wp-env` runtime.
- Added CI workflow (`.github/workflows/phase5-verification.yml`) to enforce PHPCS, PHPStan, and Phase 5 integration checks on push/PR.
- Added waitlist queue operations + audit surface in admin dashboard (manual promote/remove, bulk promote, queue/history visibility).
- Added waitlist AJAX operations (`oras_waitlist_queue_data`, `oras_waitlist_bulk_promote`, `oras_waitlist_promote_user`, `oras_waitlist_remove_user`) wired with capability and nonce checks.
- Expanded integration checks to cover the new waitlist queue operations and audit endpoints.
- Fixed attendee order-source coverage so dashboard attendee data includes all supported ticket statuses (`completed`, `processing`, `on-hold`, `pending`, `refunded`, `cancelled`, `failed`).
- Expanded integration checks with on-hold attendee regression assertions (`ticket_status=on-hold` and `ticket_status=all` contracts).

## Backtrack / Refine Before Advancing
Do not advance Phase 6+ until all items below are complete:

1. Phase 5.2 waitlist architecture depth
- Core complete:
  - First-class waitlist store implemented.
  - Lifecycle and audit fields implemented.
  - Deterministic promotion + leave flows wired through frontend/admin paths.
  - Admin queue operations beyond single promote implemented (bulk + manual controls).
  - Operational audit/history surface implemented in dashboard.
- Remaining before lock:
  - Perform UX polish + operator soak testing on queue/history screens.

2. Phase 5 verification and tests
- Completed:
  - WP-CLI integration checks for RSVP transitions, waitlist promotion flow, queue operations, attendee export contracts, notes, and messaging handlers.
  - CI job that runs PHPCS, PHPStan, and the WP-CLI checks.
- Remaining before lock:
  - Extend checks as new queue/audit behavior is added in future iterations.

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
1. Complete Phase 4 frontend/admin visual polish pass.
2. Perform short operator soak pass on new waitlist queue/history operations.
3. Advance Board Dashboard design pack (Phase 9.5/10.4) with KPI contract + access model.
4. Resume Phase 6 features (QR/check-in, reservation windows) after 1-3 are closed.
