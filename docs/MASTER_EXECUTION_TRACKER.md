# ORAS-Tickets Master Execution Tracker

Last updated: 2026-03-02 (phase sweep mode)

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
| 0 | Foundations / bootstrap / capabilities | 90% | LOCKED | locked |
| 1 | Ticket model + versioned envelope | 88% | LOCKED | locked |
| 2 | Woo mapping + commerce integrity | 90% | LOCKED | locked |
| 3 | Reporting, pricing, member APIs, print | 80% | LOCKED | locked |
| 4 | Speakers + agenda + recurrence guardrail | 82% | LOCKED | locked |
| 5 | Registration + capacity intelligence | 86% | LOCKED | locked |
| 6 | Advanced ticketing intelligence | 5% | PLANNED | begin after Phase 0-5 closure |
| 7 | Speaker intelligence expansion | 35% | PLANNED | blocked by Phase 4/5 polish |
| 8 | Virtual/hybrid advanced features | 30% | PLANNED | keep scoped to add-on rules |
| 9 | Member Hub expansion + board dashboard surface | 28% | PLANNED | build after earlier phase closure |
| 10 | Financial intelligence + board analytics layer | 25% | PLANNED | expand from reporting baseline |
| 11 | Discovery and UX enhancements | 8% | PLANNED | defer until core stabilizes |
| 12 | Automation and notifications | 3% | PLANNED | start only after data model maturity |

Overall completion (master-plan aligned): **~52%**

Recalculation source: `docs/PHASE_COMPLETENESS_AUDIT_2026-03-02.md`

## Phase Scoring Rationale (Why Each % Is Not 100 Yet)
- Phase 0 (90%): governance lock decision applied for the current execution baseline.
- Phase 1 (88%): governance lock decision applied for the current execution baseline.
- Phase 2 (90%): governance lock decision applied for the current execution baseline.
- Phase 3 (80%): governance lock decision applied after deterministic reporting integration and KPI-layering completion.
- Phase 4 (82%): governance lock decision applied after deterministic speaker-history and surface regression evidence.
- Phase 5 (86%): governance lock decision applied after deterministic concurrency coverage and operator soak closeout evidence.
- Phase 5.3 (technical pre-live): deterministic QBO verification suite and pre-live packet are complete; treasurer signoff, production approval/live validation tracker, and operator handoff sequence are prepared; phase advancement is currently paused because production WP-CLI execution is not available in the operating model.
- Phase 6 (5%): QR/check-in/reservation systems are largely not implemented.
- Phase 7 (35%): speaker intelligence expansion is partial (history/reporting groundwork only).
- Phase 8 (30%): virtual access baseline exists; Zoom auto-sync and hybrid split capacity are not complete.
- Phase 9 (28%): APIs/baseline member support exists; broader dashboard/history/invoice completion remains.
- Phase 10 (25%): financial baseline and QBO reconciliation exist; invoice/refund intelligence is incomplete.
- Phase 11 (8%): discovery/UX expansion remains largely unimplemented.
- Phase 12 (3%): automation/reminder systems remain largely unimplemented.

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

## 2026-03-01 Hardening Update
Completed in code:
- Added centralized CSV export safety helper and applied it across RSVP/attendee/report/speaker export surfaces.
- Hardened admin RSVP dashboard rendering by replacing string-built row HTML with DOM-safe element construction.
- Added event-scoped DB lock helper and wrapped frontend/admin RSVP + waitlist promotion critical sections.
- Hardened Woo capacity consume/restore handlers with order-scoped idempotency locking and event-scoped atomic envelope updates.
- Added QuickBooks reclass source-match hardening (`TotalAmt` queryability fix, candidate expansion to Deposit, and customer-entity propagation on JE lines).
- Added QuickBooks waiting queue orchestration (`waiting_for_source_txn` polling + escalation to `needs_review`) and queue processor support.
- Added QuickBooks operator workflow surfaces (Pending + Sync History tabs, reverse/resync/sync-now queue actions, tab-preserving redirects).
- Added safe add-only event-income auto-map action and runtime auto-map hooks on account refresh and event update.
- Validation clean for modified scope (`php -l`, `composer phpstan`, `composer phpcs`).

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
  - Add focused concurrency regression coverage for simultaneous promotion/RSVP/order-transition paths.

2. Phase 5 verification and tests
- Completed:
  - WP-CLI integration checks for RSVP transitions, waitlist promotion flow, queue operations, attendee export contracts, notes, and messaging handlers.
  - CI job that runs PHPCS, PHPStan, and the WP-CLI checks.
- Remaining before lock:
  - Extend checks as new queue/audit behavior is added in future iterations.
  - Add deterministic integration checks for QuickBooks waiting queue/history/operator paths (pending/history actions + source-match wait escalation).

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
1. Maintain Phase 0-5 lock integrity; changes require explicit design review.
2. Keep Phase 5.3 in paused state until production WP-CLI constraint is resolved.
3. Keep deferred artifacts current for immediate 5.3 restart when constraints change.
4. Do not begin Phase 6 implementation until governance explicitly opens the next gate.

Governance packet available:
- `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`
