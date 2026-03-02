# NEXT — Immediate Work Queue

Last updated: 2026-03-02

## Current Sprint Goal
Maintain Phase 0-5 locked baseline and hold Phase 5.3 in paused state until external constraints are resolved.

Phase 0-5 status update: **LOCKED** (governance closeout complete).

## Ordered Tasks
1. Waitlist Queue Operator Soak (Phase 5.2 final) — Completed 2026-03-02
- Run operator walkthrough on new queue/history tools (manual promote/remove and bulk promote paths).
- Capture any UI/wording friction and apply a small polish pass.

2. Concurrency Regression Coverage (Phase 5 hardening closeout) — Completed 2026-03-02
- Add deterministic integration checks for concurrent RSVP + waitlist promotion + order status transition scenarios.
- Validate capacity invariants under simultaneous paid/refund/cancel transition triggers.

3. Board Member Dashboard Design Pack (Phase 9.5 / 10.4) — Completed 2026-03-02
- Define board KPI contract (PMPro, ticketing, finance, operational alerts).
- Define Members Hub-aligned UI spec (information hierarchy, card system, responsive behavior).
- Define capability/permission model for board-only access and exports.
- Artifact: `docs/BOARD_DASHBOARD_DESIGN_PACK_2026-03-02.md`.

3a. Phase 3 Reporting Integration Closure — Completed 2026-03-02
- Add deterministic integration checks for reports render and export permission/nonce contracts.
- Validate reports route discoverability + export action wiring via admin hooks.
- Stable runner: `scripts/run-phase3-reporting-checks.sh`.

4. Phase 3 KPI Layering Backlog — Completed 2026-03-02
- Define next reporting-depth increments for trend and comparative board-ready signals.
- Artifact: `docs/PHASE3_KPI_LAYERING_BACKLOG_2026-03-02.md`.

5. Phase 0-5 Governance Lock Review Packet — Completed 2026-03-02
- Consolidate lock-review evidence and reviewer checklist for Phases 0-5.
- Artifact: `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`.

## Deferred / Parked (Constraint-Bound)
### Phase 5.3 — QuickBooks Revenue Split Sync (post-gate)
- Paused due operating constraint: no production WP-CLI execution.
- Keep evidence artifacts ready:
  - `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`
  - `docs/PHASE53_TREASURER_SIGNOFF_2026-03-02.md`
  - `docs/PHASE53_PRODUCTION_VALIDATION_EVIDENCE_2026-03-02.md`
  - `docs/PHASE53_LIVE_RUN_LOG_TEMPLATE_2026-03-02.md`
  - `docs/PHASE53_OPERATOR_HANDOFF_2026-03-02.md`
  - `docs/PHASE53_RESTART_CHECKLIST_2026-03-02.md`

## Completed This Cycle
- Added compact Phase 5.3 restart checklist for constraint-lift resumption:
  - `docs/PHASE53_RESTART_CHECKLIST_2026-03-02.md`.
- Executed governance lock decision sweep for Phases 0-5:
  - synchronized lock state across tracker/sweep/state/queue docs,
  - lock packet checklist completed in `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`.
- Added Phase 0-5 governance lock review packet:
  - `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`.
- Implemented third Phase 3 KPI layering increment (comparative signals):
  - added member vs non-member gross-share and ticket-share comparative KPI cards,
  - implemented in `oras-tickets/includes/Admin/Pages/Reports_Page.php`.
- Implemented second Phase 3 KPI layering increment (board packet CSV slice fields):
  - added append-only overview CSV columns `board_kpi_refund_rate_pct`, `board_kpi_average_order_value`, and `board_kpi_slice_version`,
  - implemented in `oras-tickets/includes/Admin/Pages/Reports_Page.php`.
- Implemented first Phase 3 KPI layering increment (trend deltas):
  - overview trend labels for gross/refunded/net,
  - detail trend labels for gross/refunded/net/refund rate/AOV,
  - implemented in `oras-tickets/includes/Admin/Pages/Reports_Page.php`.
- Added deterministic Phase 3 reporting runner for wp-env execution:
  - `scripts/run-phase3-reporting-checks.sh`.
- Runner stages `scripts/phase3-reporting-checks.php` into plugin tools path at runtime for deterministic `wp eval-file` execution.
- Added board dashboard design pack artifact (design-only, implementation gated by Phase 5 closure):
  - `docs/BOARD_DASHBOARD_DESIGN_PACK_2026-03-02.md`.
- Added Phase 5.3 operator handoff sequence:
  - `docs/PHASE53_OPERATOR_HANDOFF_2026-03-02.md`.
- Added Phase 5.3 production approval/live validation tracker:
  - `docs/PHASE53_PRODUCTION_VALIDATION_EVIDENCE_2026-03-02.md`.
- Fixed `scripts/run-qbo-integration-checks.sh` TTY/stdin execution issue:
  - replaced stdin-piped `wp eval-file` execution with runtime tool-path staging + direct `wp eval-file`,
  - validated end-to-end runner execution in `oras-wp-env` with all QBO deterministic scripts passing,
  - validated `composer phpstan` pass after the runner fix.

## Direction Change (Operator Constraint)
- Do not continue advanced Phase 5.3 execution work that requires production WP-CLI.
- Treat Phase 5.3 as paused until a non-production-command validation path is approved.
- Prioritize simpler, local-only hardening/documentation tasks instead of further 5.3 runbook expansion.
- Completed Phase 5.3 technical pre-live packet:
  - added `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`,
  - executed full deterministic QBO verification suite (safeguards, split, safety controls, reconciliation, API error matrix, OAuth callback guards) with passing results,
  - confirmed `composer phpstan` pass after evidence run.
- Completed Phase 5 operator soak closeout packet and gate transition:
  - added `docs/PHASE5_OPERATOR_SOAK_2026-03-02.md`,
  - captured deterministic Phase 5 soak evidence (`composer phpstan` + `tools/phase5-integration-checks.php` pass),
  - moved Phase 5 from ready-for-lock review to LOCKED in sweep/tracker/state docs.
- Refreshed Phase 5 gate evidence run:
  - synced `scripts/phase5-integration-checks.php` to runtime path `oras-tickets/tools/phase5-integration-checks.php`,
  - executed deterministic integration checks in `oras-wp-env` with passing assertions,
  - re-ran `composer phpstan` with passing result.
- Completed Phase 4 frontend/admin surface regression checks:
  - added `scripts/phase4-surface-checks.php` and `oras-tickets/tools/phase4-surface-checks.php`,
  - validated admin speaker assignment sanitization invariants,
  - validated frontend speaker payload/modal generation invariants,
  - executed checks in `oras-wp-env` via `npx wp-env run cli sh -lc 'ORAS_WP_LOAD_PATH=/var/www/html/wp-load.php php /var/www/html/wp-content/plugins/oras-tickets/tools/phase4-surface-checks.php'`.
- Completed Phase 4 speaker-history indexing hardening + deterministic checks:
  - fixed agenda-clear path in `Event_Agenda_Metabox::save()` to rebuild speaker history index before returning,
  - added `scripts/phase4-speaker-history-checks.php` and `oras-tickets/tools/phase4-speaker-history-checks.php`,
  - validated rebuild/update/clear scenarios (including resource-linked speaker history and stale-entry removal),
  - executed checks in `oras-wp-env` via `wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase4-speaker-history-checks.php`.
- Completed deterministic Phase 3 reporting integration checks:
  - added `scripts/phase3-reporting-checks.php`,
  - validated reports render capability gating (403 for unauthorized, success for admin),
  - validated reports export nonce/capability guards and admin-post handler wiring,
  - validated new checks in `oras-wp-env` via `wp eval-file /tmp/oras-phase3-reporting-checks.php`.
- Completed phase-sweep evidence refresh for Phases 0-2:
  - re-ran `composer phpstan` with passing result,
  - re-ran core/bootstrap regression scripts in `oras-wp-env` with passing results,
  - synced tracker to reevaluation baseline for sequential 0->12 closure mode.
- Completed deterministic interleaved concurrency regression coverage (Phase 5 hardening closeout):
  - expanded `scripts/phase5-integration-checks.php` with interleaved RSVP/waitlist + Woo paid/restore transition assertions,
  - validated invariants that order transitions do not mutate waitlist lifecycle unexpectedly,
  - validated promotion correctness after slot release in the same interleaved scenario,
  - executed updated script in `oras-wp-env` with passing assertions.
- Completed waitlist queue operator micro-polish pass (Phase 5.2 final hardening):
  - clarified queue operation copy in RSVP dashboard,
  - added explicit operator confirmations for bulk promote and single promote/remove actions,
  - added per-row action button locking during AJAX requests to reduce accidental double-submits,
  - validated with `composer phpstan` and wp-env core/bootstrap regression scripts.
- Completed RSVP/waitlist concurrency hardening and Woo capacity race mitigation:
  - added DB named-lock helper (`includes/Support/DbLock.php`),
  - locked frontend RSVP and admin waitlist promotion critical sections,
  - locked Woo capacity consume/restore paths with order-scoped idempotency lock plus event-scoped mutation lock.
- Completed export and admin-surface hardening pass:
  - centralized CSV formula-injection mitigation helper and applied across export endpoints,
  - replaced string-built admin table rows in RSVP dashboard with DOM-safe element construction.
- Completed Phase 4 visual polish pass:
  - removed temporary inline styling from agenda and speaker surfaces,
  - replaced temporary agenda dark-mode overrides with production-ready tokenized CSS,
  - aligned frontend dark-mode behavior to WP Dark Mode state attributes for readable light↔dark switching,
  - converted Event Agenda admin metabox inline styles to class-based CSS hooks.
- Expanded dark-mode readability coverage to ticket and RSVP frontend UI:
  - tokenized `assets/css/tickets-frontend.css` colors and added explicit WP Dark Mode light/dark variable sets,
  - removed inline RSVP badge style so badge contrast follows active mode.
- Fixed attendee dashboard ticket-source coverage across Woo statuses and added regression checks for on-hold/all status visibility.
- Added Phase 5 WP-CLI integration check harness (`scripts/phase5-integration-checks.php`).
- Added runtime wrapper (`scripts/run-phase5-integration-checks.sh`) with `ORAS_WP_ENV_DIR` support.
- Added CI workflow (`.github/workflows/phase5-verification.yml`) running PHPCS, PHPStan, and Phase 5 integration checks.
- Expanded CI workflow to also run QBO integration checks via `scripts/run-qbo-integration-checks.sh`:
  - safeguard checks,
  - split calculator checks,
  - safety controls checks,
  - reconciliation checks,
  - API error-matrix checks,
  - OAuth callback/CSRF guard checks.
- Added waitlist queue operations and audit/history surface in RSVP dashboard (manual/bulk controls).
- Added new waitlist AJAX operations and expanded integration checks to verify those operations.
- Added QuickBooks OAuth/status hardening and fixed Test JournalEntry payload length issue.
- Added event-series slug fallback mapping (`slug-...`) for per-event account maps.
- Added dedicated donations and printful account/category routing for QuickBooks split sync.
- Added Intuit security hardening for QuickBooks credentials/tokens and production guardrails.
- Added WP-CLI dry-run split preview command (`preview_order`) to validate mappings without posting JE.
- Added `docs/quickbooks-live-rollout-checklist.md` for production onboarding + verification.
- Added QuickBooks data-safety control layer:
  - dry-run/manual-approval/strict-mapping toggles with safe defaults,
  - append-only audit log entries per order,
  - remote duplicate guard by `DocNumber`,
  - reversal workflow and operator controls (admin + WP-CLI),
  - retry behavior restricted to transient transport/HTTP faults.
- Added QuickBooks reconciliation reporting command:
  - `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> [--format=table|json] [--limit=<n>]`
- Added reconciliation integration script:
  - `scripts/qbo-reconciliation-tests.php`
- Added deterministic QuickBooks API error-matrix test coverage:
  - `scripts/qbo-api-error-matrix-tests.php`
- Added deterministic QuickBooks OAuth callback guard test coverage:
  - `scripts/qbo-oauth-callback-tests.php`
- Added QuickBooks reclass/operator hardening:
  - waiting queue orchestration (`waiting_for_source_txn` polling + escalation),
  - Pending + Sync History admin tabs with reverse controls,
  - source-match query fix for non-queryable `TotalAmt` filter,
  - source `CustomerRef` scoring + JE line customer entity propagation,
  - safe add-only event-account auto-map action plus runtime auto-map on account refresh/event update.
- Added test-run email suppression for local/CI integration scripts:
  - QBO and Phase 5 scripts disable outbound `wp_mail` under WP-CLI to prevent notification spam.
- Added GitHub branch-protection helper for required status checks:
  - `scripts/configure-branch-protection-required-checks.sh`
  - dry-run by default; `--apply` uses GitHub API with `GITHUB_TOKEN`.

## Out of Scope Until Above Is Done
- New Phase 6+ feature implementation (QR/check-in, reservation windows, advanced automation).
- Board dashboard implementation (design can be drafted, build starts after Phase 5 closure).
