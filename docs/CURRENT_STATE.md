# CURRENT_STATE — Operational Snapshot

Last updated: 2026-03-02

## Authoritative Status
For phase percentages and advancement rules, use:
- `docs/MASTER_EXECUTION_TRACKER.md`
- Recalculation evidence: `docs/PHASE_COMPLETENESS_AUDIT_2026-03-02.md`

## Current Mode
**Phase 0-5 governance-locked baseline with Phase 5.3 paused**.

The project should not move into new Phase 6+ implementation until governance explicitly opens the next gate.

Execution mode is currently a **sequential Phase 0->12 closure sweep** with evidence-first gates and same-change-set documentation sync.

## What Is Stable
- Ticket core, Woo mapping, pricing, cart/checkout revalidation, print routes.
- Treasurer reporting baseline and member ticket APIs.
- Speaker CPT baseline and agenda rendering baseline.
- Dashboard attendees/RSVP operational surface exists.

## What Was Fixed (2026-02-25)
- RSVP parser and waitlist intents fixed.
- Waitlist promotion action conflict removed.
- Unlimited-capacity logic fixed in dashboard/promotion flows.
- Dashboard table rendering escaped for safer output handling.
- Virtual access debug noise and duplicate hook removed.
- First-class waitlist store added with lifecycle/audit fields and legacy backfill.
- Dashboard/metabox promotion and waitlist reads now use deterministic queue ordering from waitlist store.
- Frontend RSVP submission target bug fixed (form `action` shadowing issue resolved).
- Attendee list source merge fixed so paid attendees are not collapsed and ticket quantities are counted correctly.
- Phase 5 WP-CLI integration checks added (`scripts/phase5-integration-checks.php`).
- CI workflow added for PHPCS + PHPStan + Phase 5 integration checks.
- Waitlist queue operations and audit/history surface added to RSVP dashboard (manual promote/remove + bulk promote).
- Waitlist queue AJAX endpoints added and covered by integration checks.
- Attendee ticket source now includes all dashboard-supported Woo statuses (not just processing/completed), resolving missing attendees for on-hold/pending/refund/cancel workflows.
- Phase 5 integration checks expanded with explicit on-hold attendee regression coverage.
- Static analysis restored to green.

## What Was Fixed (2026-02-28)
- Phase 4 visual polish pass completed for agenda/speaker/admin surfaces:
  - removed temporary inline agenda CSS injection and moved final styles to tokenized stylesheet rules,
  - replaced temporary dark-mode overrides with production-ready CSS tokens keyed to WP Dark Mode state (`html[data-wp-dark-mode-active]` / loading),
  - removed inline speaker template styles and moved them to dedicated frontend stylesheet (`assets/css/speaker.css`),
  - removed inline style attributes in Event Agenda admin metabox rows/templates and switched to class-based admin CSS hooks.
- Frontend dark-mode readability hardening for ticketing/RSVP surfaces:
  - converted ticket and RSVP frontend colors in `assets/css/tickets-frontend.css` to tokenized variables with explicit light and dark-mode values,
  - added WP Dark Mode-compatible dark selectors for ticket status/phase/countdown badges and RSVP notices/surface,
  - removed inline RSVP badge color styles from `Event_RSVP` so badge contrast now tracks mode changes.
- QuickBooks admin/OAuth reliability hardening:
  - dedicated QuickBooks settings surface with connection indicator,
  - OAuth redirect/state/callback reliability fixes,
  - token persistence/sanitization fixes,
  - Test JournalEntry payload length fix for QBO constraints.
- QuickBooks security hardening:
  - encrypted-at-rest storage handling for sensitive OAuth fields,
  - production OAuth guardrails (HTTPS redirect URI + explicit encryption key constant),
  - reduced sensitive error logging detail,
  - labeled auth/CSRF handling paths for audit scenarios:
    - `Auth Error Access`
    - `Auth Error Refresh`
    - `Auth Error Grant`
    - `CSRF Error`.
- QuickBooks split classification expansion:
  - event-series mapping fallback (`slug-...`),
  - dedicated donations routing (`Donations Account` + category slugs),
  - dedicated printful routing (`Printful Account` + category slugs, with fallback to Merchandise Account).
- QuickBooks data-safety hardening:
  - added sync-mode controls with safe defaults:
    - `Dry Run Mode` (default on),
    - `Require Manual Approval` (default on),
    - `Strict Mapping Mode` (default on),
    - `Allow Unmapped Fallback` (default off),
  - added fail-closed queue/sync behavior for safeguard violations,
  - added manual approval queue status (`pending_qbo_review`),
  - added duplicate lookup by deterministic `DocNumber` before live writes,
  - added append-only order audit trail entries (`_oras_qbo_audit_entry`),
  - added reversal workflow support (dry-run and live) with reversal JE metadata,
  - added stricter retry policy (retry only transient transport/http faults, no retry for validation/mapping/auth-grant failures).
- QuickBooks split regression script expanded to cover:
  - event-series slug fallback,
  - donations defaults/routing,
  - printful defaults/routing/fallback.
- Added QuickBooks safety-control integration script:
  - `scripts/qbo-safety-controls-tests.php`
  - validates manual approval gate, dry-run no-write behavior, strict mapping block, and reversal dry-run path.
- Added QuickBooks reconciliation reporting and verification tooling:
  - WP-CLI: `wp oras-tickets qbo reconcile-report ...`
  - script: `scripts/qbo-reconciliation-tests.php`.
- Added QuickBooks API error-matrix integration script:
  - `scripts/qbo-api-error-matrix-tests.php`
  - validates validation/syntax API faults, retriable HTTP fault classification (`429`/`5xx`), invalid JSON handling, transport failure metadata, 401 refresh retry path, refresh `invalid_grant` path, and `intuit_tid` propagation.
- Added QuickBooks OAuth callback guard integration script:
  - `scripts/qbo-oauth-callback-tests.php`
  - validates callback guard paths for:
    - `CSRF Error: missing OAuth state parameter`,
    - `Auth Error Grant: missing callback grant fields`,
    - `CSRF Error: state validation failed`,
    - `CSRF Error: state owner mismatch`,
    - and verifies transient cleanup for mismatch cases.
- Added QBO integration runner script for deterministic CI/local verification:
  - `scripts/run-qbo-integration-checks.sh`
  - executes all QuickBooks integration scripts against wp-env.
- Added outbound email suppression for WP-CLI integration scripts:
  - QBO and Phase 5 `wp eval-file` scripts now short-circuit `pre_wp_mail` during test runs,
  - prevents transactional email spam during automated/local verification,
  - does not change production runtime behavior for normal web requests.
- Added branch-protection automation helper for CI enforcement:
  - `scripts/configure-branch-protection-required-checks.sh`
  - targets required status context `Phase5 Verification / verify`.

## What Was Fixed (2026-03-01)
- Concurrency hardening for RSVP/waitlist state transitions:
  - event-scoped lock helper added (`includes/Support/DbLock.php`),
  - frontend RSVP mutation path now executes under event lock,
  - dashboard and metabox waitlist promotion paths now execute under event lock.
- Concurrency hardening for Woo capacity mutation paths:
  - `includes/Commerce/Woo/Capacity_Consumption.php` now wraps consume/restore flows in order-scoped idempotency locks,
  - capacity changes are aggregated per order and applied under event-scoped locks,
  - ticket-capacity envelope saves and product stock sync run from locked, fresh event state.
- QuickBooks reclass sync reliability hardening:
  - reclass source lookup no longer queries non-queryable `TotalAmt` in QBO SQL filters; amount matching now occurs in deterministic PHP candidate filtering,
  - source candidate scan includes `Deposit` plus `SalesReceipt`/`Payment`,
  - source `CustomerRef` scoring and JE line customer-entity propagation added for improved accounting name visibility.
- QuickBooks waiting queue + operator control expansion:
  - added `waiting_for_source_txn` orchestration path with bounded polling and escalation to `needs_review`,
  - added waiting queue processing support in admin and WP-CLI,
  - added QuickBooks `Pending` and `Sync History` operational tabs with reverse/resync/sync-now actions.
- QuickBooks mapping automation expansion:
  - safe add-only event-account auto-map action added to QuickBooks settings,
  - safe add-only auto-map now runs after account refresh and event update when account cache is available.

## What Was Fixed (2026-03-02)
  - `oras-tickets/tools/core-regression-checks.php`
  - validates capability boundary and ticket envelope/mapping edge cases.
  - `oras-tickets/tools/bootstrap-regression-checks.php`
  - validates dependency guard conditions and required hook registration surface.
  - new executive-signal KPI section added to Board Dashboard,
  - adds derived signal coverage for diversification, dependency, waitlist efficiency, and engagement conversion indicators.
  - expanded core regression checks to cover unsupported schema fallback,
  - added `ticket_key` row-key fallback assertions,
  - added ticket-key generator invariant checks (length + uniqueness),
  - validated new checks in wp-env with passing assertions.
  - clarified queue operation guidance in RSVP dashboard,
  - added explicit confirmations for bulk promote and manual promote/remove actions,
  - added per-action button locking during queue AJAX mutations.
  - extended `scripts/phase5-integration-checks.php` with interleaved RSVP/waitlist + Woo paid/restore transition assertions,
  - validated that order-status capacity mutations preserve waitlist lifecycle state under interleaved operations,
  - validated promotion correctness after slot release in the interleaved path.
  - `composer phpstan` passing,
  - `tools/core-regression-checks.php` passing in `oras-wp-env`,
  - `tools/bootstrap-regression-checks.php` passing in `oras-wp-env`,
  - updated Phase 5 integration checks passing in `oras-wp-env`.
  - new reporting checks script added: `scripts/phase3-reporting-checks.php`,
  - validated reports page capability gating and render surface for authorized admins,
  - validated reports export admin-post wiring and nonce/capability rejection paths,
  - validated script in `oras-wp-env` with passing assertions.
  - added stable runner `scripts/run-phase3-reporting-checks.sh` for deterministic wp-env execution via plugin tools staging,
  - validated runner execution in `oras-wp-env` with passing assertions.
  - fixed agenda-clear save path in `includes/Admin/Metaboxes/Event_Agenda_Metabox.php` to rebuild speaker history index before early return,
  - added deterministic script `scripts/phase4-speaker-history-checks.php` with runtime entrypoint copy at `oras-tickets/tools/phase4-speaker-history-checks.php`,
  - validated speaker-history rebuild/update/clear behavior (including resource-attributed speakers) in `oras-wp-env` with passing assertions.
  - added deterministic script `scripts/phase4-surface-checks.php` with runtime entrypoint copy at `oras-tickets/tools/phase4-surface-checks.php`,
  - validated speaker assignment admin sanitization invariants (compensation normalization, notes/role sanitization),
  - validated frontend speaker payload/modal generation invariants in frontend PHP context (`ORAS_WP_LOAD_PATH` bootstrap) in `oras-wp-env` with passing assertions.
- Phase 4 closure gate moved from ready-for-lock review to LOCKED after governance synchronization.
- Refreshed Phase 5 deterministic verification evidence:
  - copied runtime entrypoint `scripts/phase5-integration-checks.php` to `oras-tickets/tools/phase5-integration-checks.php` for `wp-env` execution,
  - re-ran `tools/phase5-integration-checks.php` in `oras-wp-env` with passing assertions,
  - re-ran `composer phpstan` with passing result.
- Completed Phase 5 operator soak closeout packet: `docs/PHASE5_OPERATOR_SOAK_2026-03-02.md`.
- Phase 5 closure gate moved from ready-for-lock review to LOCKED after governance synchronization.
- Completed Phase 5.3 technical pre-live evidence packet: `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`.
- Added treasurer signoff template: `docs/PHASE53_TREASURER_SIGNOFF_2026-03-02.md`.
- Added production approval/live validation evidence tracker: `docs/PHASE53_PRODUCTION_VALIDATION_EVIDENCE_2026-03-02.md`.
- Added production live validation run log template: `docs/PHASE53_LIVE_RUN_LOG_TEMPLATE_2026-03-02.md`.
- Added Phase 5.3 operator handoff sequence: `docs/PHASE53_OPERATOR_HANDOFF_2026-03-02.md`.
- Phase 5.3 is paused for implementation advancement due operating constraint: no WP-CLI on production.
- Keep existing 5.3 evidence artifacts as reference only; do not schedule additional production-command validation work.
- Phase 3 reporting depth gate moved from READY FOR LOCK review to LOCKED after deterministic integration + KPI layering completion.
- Next active non-production item: `docs/PHASE3_KPI_LAYERING_BACKLOG_2026-03-02.md`.
- Implemented first Phase 3 KPI layering increment in reports: prior-period trend delta labels for overview and detail KPI cards.
- Re-validated with `composer phpstan` and `scripts/run-phase3-reporting-checks.sh` in `oras-wp-env`.
- Implemented second Phase 3 KPI layering increment: append-only board packet KPI slice fields in overview CSV export (`board_kpi_refund_rate_pct`, `board_kpi_average_order_value`, `board_kpi_slice_version`).
- Implemented third Phase 3 KPI layering increment: member vs non-member comparative contribution ratios in detail KPI cards (gross-share and ticket-share signals).
- Added governance closeout artifact for Phases 0-5 lock decision: `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`.
- Fixed QBO integration runner reliability:
  - updated `scripts/run-qbo-integration-checks.sh` to remove stdin/TTY-dependent execution,
  - validated full runner execution in `oras-wp-env` with all six deterministic QBO checks passing,
  - validated `composer phpstan` pass after runner update.

## Remaining Gaps Before Phase 6+
1. QuickBooks Phase 5.3 pre-live completion remains:
  - execute smoke + reconciliation run after test-server deploy validation window,
  - complete treasurer signoff on production mapping/cutover policy,
  - complete Intuit production app approval and one controlled production validation order.

## Approved Upcoming Scope (Post-Gate)
- Board Member Dashboard has been approved for the master plan.
- It is scoped as a board-only, Members Hub-style executive surface backed by PMPro + ticketing + financial KPIs.
- Build remains gated behind current Phase 5 closure requirements.

## Phase 5.3 Proposal — QuickBooks Revenue Split Sync (Woo Orders)
Status: implemented in plugin and currently in pre-live validation mode (live connector verification pending Intuit production app approval).

Goals:
- Create one QuickBooks JournalEntry per eligible Woo order (`completed` only, cutoff-date gated, never previously synced).
- Split Woo revenue into mapped income accounts by event/category (ticket event, observer pass, merchandise, printful, donations, fallback).
- Keep Stripe connector enabled while moving Woo revenue from a configurable clearing account into specific income accounts.
- Guarantee idempotency using order meta keys: `_oras_qbo_je_id`, `_oras_qbo_je_hash`, `_oras_qbo_sync_status`.

Non-goals (initial release):
- No replacement of Stripe connector.
- No SalesReceipt/Invoice creation from ORAS-Tickets (JournalEntry-only flow).
- No automatic refund reversal JournalEntry in first release (documented manual handling path).

Architecture:
- New module: `oras-tickets/src/Integrations/QuickBooks/`.
- Components: OAuth client, API wrapper, split calculator, JournalEntry creator, sync orchestrator, retry handler, logger, WP-CLI command.
- Async execution: Action Scheduler when available, `wp_schedule_single_event` fallback.
- Admin controls: ORAS Tickets Settings page fields and actions for enable/connect/map/test.
- Write safety model:
  - manual approval queue before write,
  - dry-run payload generation mode (no QBO writes),
  - strict mapping fail-closed behavior,
  - remote duplicate check by `DocNumber`,
  - append-only order-level audit metadata,
  - reversal JE command/admin action for recovery.

Testing strategy:
- WP-CLI quick checks:
  - `wp oras-tickets qbo test-connection`
  - `wp oras-tickets qbo preview-order <order_id> [--format=json]`
  - `wp oras-tickets qbo sync-order <order_id>`
  - `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> [--format=table|json] [--limit=<n>]`
  - `wp oras-tickets qbo retry-failed`
- Split calculator unit-style script:
  - `wp eval-file scripts/qbo-split-calculator-tests.php`
- Safety controls script:
  - `wp eval-file scripts/qbo-safety-controls-tests.php`
- Reconciliation integration script:
  - `wp eval-file scripts/qbo-reconciliation-tests.php`
- API error matrix script:
  - `wp eval-file scripts/qbo-api-error-matrix-tests.php`
- OAuth callback guard script:
  - `wp eval-file scripts/qbo-oauth-callback-tests.php`
- Manual scenarios:
  - ticket-only, merch-only, observer-only, printful-only, donation-only, mixed cart, coupon/discount, multi-quantity, and failure/retry path.

Risk analysis:
- Duplicate accounting risk if clearing account is mapped incorrectly in QBO.
- Misclassification risk when product categories/meta are inconsistent.
- OAuth token expiry/rotation risk (mitigated by refresh logic and retry flow).
- Network/API reliability risk (mitigated by queue + retry + sync status metadata).
- Live validation dependency risk: production coexistence test with Stripe connector is blocked until Intuit production app approval.
- Operational key-management risk: production requires secure provisioning/rotation of `ORAS_TICKETS_QBO_AES_KEY` in server config.
- Compliance risk: production acceptance can fail if PCI/Intuit audit evidence is incomplete.

## Required Next Closure Conditions
- Maintain and extend WP-CLI integration checks as remaining Phase 5 queue/audit features land.
- Complete operator soak + UX micro-polish for waitlist queue/history actions.
- Complete QuickBooks operator soak on pending/history/reversal actions and waiting queue processing.
- Re-run lint/static checks and update docs in same change set.
- Add and maintain a compliance checklist + evidence pack for:
  - PCI Security Standards baseline controls relevant to current architecture:
    - https://www.pcisecuritystandards.org/
  - Intuit OAuth/OpenID discovery requirements:
    - https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-openid-discovery-doc
