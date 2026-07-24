# CHANGELOG (Append-Only)

## 2026-07-24 - Release 0.4.46

- Fixed unattended Zoom synchronization for meetings created without a passcode.
- Preserved existing meeting passcodes and generated a new passcode only when one was missing.
- Sent meeting passcodes as top-level Zoom API properties before disabling Waiting Room.
- Added passcode presence to exact Zoom synchronization diagnostics.

## 2026-07-24 - Release 0.4.45

- Changed unattended Zoom synchronization to disable Waiting Room before enabling join-before-host, avoiding Zoom's conflicting-setting transition.
- Enabled and verified Telephone and Computer Audio so complete invitations can include one-tap mobile and local dial-in details.
- Added exact synchronization diagnostics showing the values Zoom returned for join-before-host, join time, Waiting Room, and audio.
- Documented the granular Zoom scopes required by meeting synchronization, invitation retrieval, registration, and cancellation.

## 2026-07-23 - Release 0.4.44

- Added per-event unattended Zoom access so approved attendees can enter without requiring an ORAS host to start the meeting.
- Added verified Zoom synchronization for `join_before_host`, unrestricted join time, and disabled Waiting Room.
- Added queued synchronization after event saves and a nonce-protected `Sync Zoom Settings` action for immediate verification.
- Added stale-job protection and bounded retries for Zoom rate limits and temporary service failures.
- Added event-editor synchronization status and actionable Zoom account-policy errors.
- Preserved attendee-specific registration, passcodes, approval gates, and private join-link handling.

## 2026-07-23 - Release 0.4.43

- Added an ORAS-owned Zoom Server-to-Server OAuth integration with encrypted credential storage and an administrator connection test.
- Added per-event managed Zoom registration controls while retaining The Events Calendar as the meeting creator.
- Added authoritative Zoom invitation parsing for meeting ID, passcode, one-tap mobile numbers, and local dial-in links.
- Added encrypted, idempotent attendee-specific Zoom registration storage with separate ticket and RSVP entitlement sources.
- Paid virtual ticket buyers now receive automatic Zoom registration and private access details; cancelled and refunded orders revoke their entitlement.
- Approved virtual RSVPs now receive attendee-specific Zoom access; pending, rejected, and cancelled RSVPs do not retain or receive private access.
- Preserved existing shared-link email behavior as a failure-safe fallback when managed Zoom registration is disabled or unavailable.

## 2026-07-23 - Releases 0.4.40 through 0.4.42

- Reconciled Board, Board Member, Treasurer, Administrator, and Event Coordinator capabilities.
- Added strict plugin version consistency checks to local verification and CI.
- Added 25/50/100-row pagination to Sales, RSVP Management, and Roster views while preserving complete exports.
- Moved mass communication delivery to resumable Action Scheduler batches with queued progress and sender audit logging.
- Upgraded the communication schema for delivery progress and transient recipient payloads.
- Added configurable completed-log retention, disabled by default, and immediate recipient-payload cleanup after delivery.
- Added release, deployment, rollback, and privacy operating guidance.

## 2026-03-02 — Phase 6 Gate-Open Governance Template

Docs:
- Added a standardized governance request template to open Phase 6+ work after locked-baseline enforcement:
  - `docs/PHASE6_GATE_OPEN_REQUEST_TEMPLATE_2026-03-02.md`
- Linked template in active status docs:
  - `docs/NEXT.md`
  - `docs/CURRENT_STATE.md`

Validation:
- `composer phpstan` (pass)

## 2026-03-02 — Internal Context Alignment (Locked Baseline)

Docs:
- Updated internal Copilot context status model to current governance state:
  - `docs/COPILOT_CONTEXT.md`
  - phases 0-5 set as locked baseline,
  - phase 5.3 set as paused for advancement,
  - 6-12 marked planned/gated.
- Updated remaining roadmap sequence wording to remove stale “current focus” phrasing:
  - `docs/ROADMAP.md`

Validation:
- `composer phpstan` (pass)

## 2026-03-02 — Historical Doc Supersession Labels

Docs:
- Marked legacy planning artifacts as historical/superseded and pointed readers to current authoritative lock-state docs:
  - `docs/PHASE_COMPLETION_SWEEP.md`
  - `docs/PHASE_REEVALUATION_2026-03-01.md`
- Updated docs index mode language to current governance posture:
  - `docs/README.md`

Behavior:
- Reduces ambiguity between historical assessments and current lock-state governance documents.

Validation:
- `composer phpstan` (pass)

## 2026-03-02 — Lock-State Consistency Sweep (Post-Lock Sync)

Docs:
- Updated active-status wording to reflect finalized Phase 0-5 `LOCKED` posture:
  - `docs/NEXT.md`
  - `docs/PHASE5_OPERATOR_SOAK_2026-03-02.md`
  - `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`

Behavior:
- Removed stale active references to interim `ready-for-lock review` state where lock decisions are already complete.
- Preserved historical transition context where it remains useful for audit chronology.

Validation:
- `composer phpstan` (pass)

## 2026-03-02 — Phase 0-5 Governance Lock + Phase 5.3 Restart Readiness

Docs:
- Finalized governance lock decision across core phase docs (Phases 0-5 now `LOCKED`):
  - `docs/MASTER_EXECUTION_TRACKER.md`
  - `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`
  - `docs/CURRENT_STATE.md`
  - `docs/NEXT.md`
- Completed lock-review packet checklist and synchronized lock status snapshot:
  - `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`

Phase 5.3 paused-state readiness:
- Added compact restart checklist for immediate resumption when production constraints lift:
  - `docs/PHASE53_RESTART_CHECKLIST_2026-03-02.md`
- Linked restart checklist into paused 5.3 workflow artifacts:
  - `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`
  - `docs/PHASE53_PRODUCTION_VALIDATION_EVIDENCE_2026-03-02.md`
  - `docs/NEXT.md`

Documentation alignment updates:
- Synced strategic/canonical status wording to locked-baseline mode:
  - `docs/ROADMAP.md`
  - `docs/PROJECT_STATE.md`

Validation:
- `composer phpstan` (pass)

## 2026-03-02 — Strict Phase 1 Envelope Regression Pass

Code:
- Extended Phase 1 regression depth in:
  - `oras-tickets/tools/core-regression-checks.php`
- Added deterministic assertions for:
  - unsupported ticket-envelope schema fallback (`load_for_event` returns empty collection),
  - ticket-key fallback when `ticket_key` is omitted in stored row payload,
  - ticket-key generation invariants (12-char length + consecutive uniqueness).

Validation:
- `php -l oras-tickets/tools/core-regression-checks.php`
- `wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/core-regression-checks.php`
- All assertions pass in wp-env runtime.

## 2026-03-02 — Full Phase Completeness Audit + Strict Phase 0 Pass

Docs:
- Added full codebase audit artifact with recalculated per-phase completion percentages:
  - `docs/PHASE_COMPLETENESS_AUDIT_2026-03-02.md`
- Synced operational status docs to audited baseline:
  - `docs/MASTER_EXECUTION_TRACKER.md`
  - `docs/PROJECT_STATE.md`
  - `docs/CURRENT_STATE.md`
  - `docs/PHASE_COMPLETION_SWEEP.md`

Phase 0 hardening (strict-order pass):
- Expanded `oras-tickets/tools/core-regression-checks.php` capability-boundary coverage to verify:
  - all `Capabilities::CAPS` are granted to administrator and denied to subscriber,
  - all `Capabilities::TREASURER_ONLY_CAPS` are granted to administrator and denied to subscriber.
- Re-ran wp-env checks with passing assertions:
  - `wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/core-regression-checks.php`
  - `wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/bootstrap-regression-checks.php`

## 2026-03-02 — Door Prize Feature Kickoff (Phase 11.4 start)

Code:
- Added first implementation slice for event door prizes:
  - `oras-tickets/includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php`
  - `oras-tickets/includes/Frontend/Door_Prizes.php`
  - `oras-tickets/assets/admin/event-door-prizes-metabox.js`
  - `oras-tickets/assets/admin/event-door-prizes-metabox.css`
  - `oras-tickets/assets/css/door-prizes-frontend.css`
- Wired feature into plugin bootstrap and unified event addon tabs:
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Event_Addon_Metabox.php`
  - `oras-tickets/includes/Domain/Meta.php`

Behavior:
- Event admins can manage structured door prize rows (title, donor, value, image URL, visibility, display mode).
- Frontend event pages now render visible door prizes with `inline`, `hover`, and `modal` presentation styles.
- Visibility filtering supports `public`, `members`, and `internal` audiences.

Follow-up fixes:
- Fixed door prize add-row behavior in admin metabox interaction.
- Added `External Link` field to door prize entries.
- Frontend now links door prize title/media to external URL when present.
- When no explicit image URL is provided, frontend attempts thumbnail fallback from external link (`og:image`/`twitter:image` or direct image URL).
- Added `Save Event` quick-action controls inside the unified ORAS event editor panel (header + footer), so admins can save from any ORAS tab without scrolling to the WordPress publish/update controls.
- Updated Door Prize frontend styling to be WP Dark Mode-aware (`html[data-wp-dark-mode-active]` and theme variants), using mode-aware color tokens so the same card layout remains polished in light and dark views.

Phase completion sweep execution (Phase 0-2 start):
- Added core hardening regression script for capability and envelope/mapping edge cases:
  - `oras-tickets/tools/core-regression-checks.php` (wp-env executable path)
  - `scripts/core-regression-checks.php` (wrapper entrypoint)
- Verified in wp-env:
  - admin vs subscriber capability boundary,
  - missing ticket envelope default shape,
  - `price_phases` preservation when omitted,
  - invalid `price_phases` normalization to empty array.
- Added bootstrap regression hardening script:
  - `oras-tickets/tools/bootstrap-regression-checks.php`
- Verified in wp-env:
  - TEC/Woo dependency presence guard,
  - bootstrap singleton + `init` hook registration,
  - required RSVP/waitlist/attendees handler hook registration,
  - Door Prize frontend renderer filter registration.

Phase completion sweep execution (Phase 3 start):
- Expanded board analytics layering with a new **KPI Layer (Executive Signals)** section in `includes/Frontend/Board_Dashboard.php`.
- Added derived board-facing signals:
  - revenue diversity score,
  - membership dependency,
  - waitlist promotion efficiency,
  - subscriber confirmation ratio,
  - open-to-form momentum (30d).

## 2026-03-02 — Phase Completion Sweep Plan Added

Docs:
- Added ordered phase-by-phase unfinished-work checklist:
  - `docs/PHASE_COMPLETION_SWEEP.md`
- This plan is intended for post-door-prize execution sequencing from Phase 0 through Phase 12.

## 2026-03-02 — Documentation Authority Alignment (Execution Precedence)

Docs:
- Updated instruction authority order to align execution with the full governance stack:
  - file: `copilot-instructions.md`
  - precedence now reflects tracker/state/queue/boundaries/plan responsibilities.

Behavior:
- Prevents planning drift caused by conflicting “single source of truth” wording.
- Reinforces gate-first execution (Phase tracker + state enforcement before queue work).

## 2026-03-02 — Phase 5 Task 2 Concurrency Regression Coverage

Code:
- Extended deterministic Phase 5 integration checks with explicit concurrency/idempotency assertions:
  - file: `scripts/phase5-integration-checks.php`
  - added lock-timeout coverage for RSVP writes and waitlist bulk promotion operations,
  - added invariant checks to confirm lock timeout paths do not mutate RSVP/waitlist state,
  - added Woo capacity idempotency checks for repeated paid/restore transition handling.

Validation:
- Executed Phase 5 integration checks in wp-env CLI and confirmed pass for all existing and new assertions.

## 2026-03-02 — Waitlist Operator Wording Polish (Soak Pass)

Code:
- Polished RSVP dashboard waitlist operator wording for manual and bulk queue actions:
  - `oras-tickets/includes/Admin/Pages/Dashboard_Page.php`
  - `oras-tickets/assets/admin/dashboard-rsvp.js`

UX:
- Clarified queue action labels (`Open Waitlist Queue`, `Promote Next in Queue`, `Refresh Queue Data`).
- Improved operation status text and confirmation prompts for promote/remove flows.
- Updated empty-state copy for queue/history to reduce ambiguity during operator use.

## 2026-03-02 — Section View Labels + Admin-Only Watch Alerts

Code:
- Updated board dashboard section headers to include explicit visibility labels:
  - `Board View`, `Admin View`, `Treasurer View`
  - file: `oras-tickets/includes/Frontend/Board_Dashboard.php`
- Restricted `Watch Alerts` section to admin users only.

Behavior:
- Board users do not see admin watch-alert diagnostics.
- Each block now indicates intended audience at the top of the section.

## 2026-03-02 — Operations Health Admin-Only Visibility

Code:
- Restricted `Operations Health` section to admin-capable users only:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`

Behavior:
- Non-admin board users no longer see operational queue/automation health details.
- Operations health data query is skipped entirely for non-admin viewers.

## 2026-03-02 — Board Watch Alert Threshold Row

Code:
- Added compact `Watch Alerts` row near the top of board dashboard:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - threshold-triggered alerts for:
    - elevated failed/pending automation queue,
    - waitlist pressure and low promotion efficiency,
    - high subscriber confirmation backlog.

Behavior:
- Displays alert chips (`WATCH` / `DOWN`) when thresholds are crossed.
- Displays `STABLE` when no watch thresholds are currently triggered.

## 2026-03-02 — Board Operations/Waitlist/Engagement KPIs

Code:
- Added three new board dashboard sections:
  - `Operations Health` (Action Scheduler failed/pending/completed plus recent queue pressure and top failed hooks),
  - `Waitlist Conversion` (current waiting count, promoted/left totals, promotion efficiency),
  - `Engagement Funnel` (MailPoet subscriber status mix, form submissions 30d, newsletter opens 30d).
- File:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`

Notes:
- Sections are board-safe/high-level and avoid treasurer-only reconciliation detail.
- Data automatically degrades to “unavailable” messaging if source tables are not present.

## 2026-03-02 — Notable Changes Severity Chips

Code:
- Enhanced `Top 5 notable changes this period` rendering with severity tags:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - each change now displays a chip: `UP`, `DOWN`, or `WATCH`.
- Added per-metric tone logic:
  - positive/negative trend scoring for sales/activity,
  - inverse scoring for refund-rate changes.

UX:
- Board can scan directional change faster without reading full sentence details first.

## 2026-03-02 — Board Top 5 Notable Changes Callout

Code:
- Added `Top 5 notable changes this period` section to board dashboard:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - compares current selected period to previous equivalent period.
- Callout currently summarizes key directional deltas for board readout:
  - gross sales change,
  - refund rate points change,
  - merch revenue change,
  - direct membership cashflow change,
  - website activity change (logins and signups).

Notes:
- Comparison uses adjacent equal-length time windows derived from the current board date range.

## 2026-03-02 — Membership Lifecycle Simplification + Level Breakdown

Code:
- Updated board membership lifecycle presentation:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - removed cancellation and net-change columns from board lifecycle table,
  - retained period-based new membership counts (weekly/monthly/yearly).
- Added active member distribution by membership level:
  - lifecycle section now includes `Active Members by Level` table,
  - populated from active PMPro membership rows joined to membership level names.

## 2026-03-02 — Board Website Activity (Logins + Signups)

Code:
- Added Website Activity section to board dashboard:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - weekly/monthly/yearly counts for:
    - logins,
    - user signups.
- Added notable site activity counters:
  - total users,
  - active members (when membership table is available).
- Added login event tracking hook:
  - stores daily login counts for trend rollups,
  - retains ~400 days of daily login history,
  - records per-user last login timestamp metadata.

Notes:
- Login trends accumulate from the point this tracking is enabled; historical pre-tracking login counts are not reconstructed.

## 2026-03-02 — Board KPI Grid Geometry Lock

Code:
- Tightened board KPI grid symmetry behavior:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - enabled equal row sizing (`grid-auto-rows: 1fr`),
  - enforced full-height card fill (`height: 100%`) to keep card blocks visually uniform.

## 2026-03-02 — Board Copy Cleanup + KPI Symmetry Polish

Code:
- Removed board subtitle copy:
  - `Rollup across ORAS ticketed events for the selected period.`
  - file: `oras-tickets/includes/Frontend/Board_Dashboard.php`
- Improved KPI card visual symmetry:
  - standardized subtitle block behavior and reserved subtitle space on cards without a natural subtitle,
  - consistent card interior alignment for mixed-content KPI cards.

## 2026-03-02 — Board Data Freshness Indicators

Code:
- Added `as of` source freshness display in board dashboard:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - shows freshness for financial totals source,
  - shows per-source freshness summary for Woo and PMPro in revenue streams section,
  - shows PMPro lifecycle freshness timestamp.

Notes:
- This supports board readability by making data recency explicit for operational snapshots.

## 2026-03-02 — Board Cashflow: PMPro Inclusion + Treasurer Confirmation Warning

Code:
- Expanded board cashflow view to include PMPro direct membership cashflow:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - reads successful PMPro membership orders from `pmpro_membership_orders` in selected date range,
  - surfaces `PMPro Direct Membership Cashflow` in revenue stream table,
  - adds `Estimated Total Inflow` KPI (`Woo/QBO gross + PMPro direct cashflow`).
- Added explicit board-level warning banner:
  - dashboard states totals are rough operational estimates,
  - final confirmed totals must come from Treasurer.

Notes:
- PMPro direct cashflow may overlap with Woo-based membership products depending on checkout configuration; dashboard now surfaces that caveat for board readers.

## 2026-03-02 — Board Note for Non-Treasurer Users

Code:
- Added a concise board-facing note below KPI cards when reconciliation details are hidden:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - clarifies that detailed variance/mismatch review is handled by Treasurer/Admin.

## 2026-03-02 — Treasurer View Label in Board Reconciliation

Code:
- Added explicit UI labeling for finance-operational visibility:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - reconciliation section now shows `Treasurer view` badge to make role-scoped access intent clear.

## 2026-03-02 — Treasurer-Only Reconciliation Visibility

Code:
- Restricted mismatch/variance reconciliation details to treasurer/admin users:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - QuickBooks reconciliation detail block now renders only when user has capability `oras_tickets_view_treasurer_reconciliation`.
- Added dedicated capability and role assignment logic:
  - `oras-tickets/includes/Capabilities.php`
  - new capability: `oras_tickets_view_treasurer_reconciliation`
  - granted to roles when present: `administrator`, `treasurer`.

Behavior:
- Board users continue to see high-level dashboard KPIs.
- Variance/mismatch operational reconciliation is hidden from board-only users.

## 2026-03-02 — Board Reconciliation Detail Table (Top Variance Orders)

Code:
- Expanded board frontend dashboard reconciliation visibility:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - added compact reconciliation section below KPI cards with:
    - completed order count for selected period,
    - mismatch count,
    - aggregate net variance sum,
    - top variance orders table (`Order`, `Status`, `Site`, `QBO Net`, `Variance`).
- Reconciliation row math mirrors existing QBO report semantics:
  - website line-item total compared against ORAS sync snapshot / JE metadata net amount,
  - rows sorted by absolute variance descending,
  - low-noise threshold retained (`abs(variance) > 0.009`).

## 2026-03-02 — Board Dashboard QBO Read-Only Reconciliation + Fallback

Code:
- Updated frontend board dashboard financial sourcing:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - financial cards now attempt a read-only QuickBooks poll of ORAS JournalEntry records in-range (`ORAS-WO-*`, `ORAS-RC-*`, `ORAS-RV-*`).
- Added automatic source selection behavior:
  - when QBO poll succeeds, dashboard financial totals (gross/refunded/net) render from QBO snapshot,
  - when QBO is unavailable/disabled/error, dashboard falls back to website aggregates.
- Added operator-facing source note in UI:
  - indicates whether values are from `QuickBooks (read-only poll)` or `website fallback`,
  - includes net variance reference between site and QBO when QBO is available.
- Added short transient cache for QBO snapshot polling to reduce repeated API calls during board-page refreshes.

## 2026-03-02 — Board Frontend Dashboard (Capability-Gated)

Code:
- Added a frontend board dashboard shortcode:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - shortcode: `[oras_board_dashboard]`
  - outputs board-facing rollup KPIs (gross/net/refunds/refund rate/orders/tickets/AOV/member mix).
- Registered frontend dashboard in bootstrap:
  - `oras-tickets/includes/Bootstrap.php`
- Added dedicated access capability:
  - `oras_tickets_view_board_dashboard`
  - `oras-tickets/includes/Capabilities.php`
  - capability now granted to roles when present: `administrator`, `board`, `board_member`.

Usage:
- Create a WordPress page with slug `board` and place shortcode `[oras_board_dashboard]` in page content.
- Board users must have capability `oras_tickets_view_board_dashboard`.

## 2026-03-02 — Phase 3 Board KPI Layer (Detail View)

Code:
- Added board-facing KPI cards to Event Detail report:
  - `oras-tickets/includes/Admin/Pages/Reports_Page.php`
  - new derived metrics:
    - `Refund rate` = refunded amount ÷ gross sales
    - `Average order value` = gross sales ÷ orders
- KPI derivation uses existing report summary aggregates with safe zero guards.

## 2026-03-02 — Phase 3 Reports Integration Checks

Code:
- Added deterministic reports integration check script:
  - `scripts/reports-integration-checks.php`
  - validates admin-post hook registration and hardening paths for:
    - reports export (`oras_tickets_export_csv`)
    - speaker reports export (`oras_speaker_reports_export_csv`)
  - validates capability and nonce guard behavior (expected `Not allowed` / `Invalid request` paths).
- Added reports integration runner:
  - `scripts/run-reports-integration-checks.sh`
  - executes report checks via `wp eval-file` in wp-env.
- Added CI execution step:
  - `.github/workflows/phase5-verification.yml`
  - now runs reports integration checks between Phase 5 checks and QBO checks.

## 2026-03-02 — Phase 3 Speaker Attribution Mode Expansion

Code:
- Added selectable speaker attribution mode in Speaker Reports filters and CSV export context:
  - `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`
  - new filter parameter `allocation_mode` supports:
    - `equal_assignment_split`
    - `primary_weighted_split` (primary speakers weighted 2x, non-primary 1x)
- Updated speaker allocation computation:
  - equal split remains deterministic by active assignment count,
  - primary-weighted mode computes per-row allocated gross/net using assignment weights,
  - weighted mode automatically falls back to equal split when no primary speakers are flagged for an event.

Notes:
- Allocation context is persisted in CSV via existing `allocation_mode` and `allocation_divisor` fields.

## 2026-03-02 — Phase 3 Reporting Segmentation (Member vs Non-Member)

Code:
- Added member vs non-member sales segmentation to reporting summary aggregation:
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - summary now tracks:
    - `member_orders`, `member_tickets_sold`, `member_gross_sales`
    - `non_member_orders`, `non_member_tickets_sold`, `non_member_gross_sales`
  - membership detection uses PMPro runtime lookup when available (`pmpro_getMembershipLevelForUser`).
- Added member segmentation to detail CSV export rows:
  - `oras-tickets/includes/Admin/Pages/Reports_Page.php`
  - new `member_segment` column (`member` / `non_member`).
- Added member/non-member KPI cards to event detail report view:
  - `oras-tickets/includes/Admin/Pages/Reports_Page.php`
- Added speaker report financial context columns:
  - `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`
  - table + CSV now include per-event `event_gross_sales` and `event_net_sales` snapshots for each speaker assignment row.
- Added deterministic speaker-level allocation fields:
  - `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`
  - table + CSV now include:
    - `allocated_event_gross_sales`
    - `allocated_event_net_sales`
    - `allocation_mode` (`equal_assignment_split`)
    - `allocation_divisor` (active speaker assignment count per event).

Notes:
- Member segmentation is based on purchaser account membership state at report runtime (when PMPro is available).

## 2026-03-02 — Phase 3 Reporting Navigation Restore

Code:
- Restored Reports page discoverability in ORAS Tickets admin navigation:
  - `oras-tickets/includes/Admin/Admin_Menu.php`
  - added missing submenu registration for `oras-tickets-reports` with `oras_tickets_view_reports` capability and existing `render_reports()` callback.

Verification:
- Reports page route remains `admin.php?page=oras-tickets-reports` and now appears in ORAS Tickets submenu.

## 2026-03-01 — QuickBooks Reclass Queue + Operator Tooling Hardening

Code:
- Extended reclass source-transaction matching and posting context:
  - `oras-tickets/src/Integrations/QuickBooks/Journal_Entry_Creator.php`
  - removed non-queryable `TotalAmt` WHERE filtering from QBO SQL and moved amount filtering to deterministic PHP-side candidate filtering,
  - expanded source candidate scan to include `Deposit` in addition to `SalesReceipt` and `Payment`,
  - added `CustomerRef` capture/scoring and propagated customer entity to JE lines when source customer is available.
- Added waiting-state orchestration for delayed Stripe/QBO source visibility:
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
  - introduced `waiting_for_source_txn` flow with bounded retry/poll scheduling,
  - added max-wait escalation path to `needs_review`,
  - added queue processor for waiting orders.
- Expanded admin operations for queue and history workflows:
  - `oras-tickets/includes/Admin/Pages/Settings_Page.php`
  - added `Sync History` tab with source-transaction context and reverse controls,
  - added pending/waiting operator actions for sync/approve/resync/reverse and queue processing controls,
  - added QuickBooks auto-map action button (`Safe Add-Only`).
- Expanded QuickBooks admin handlers and mapping automation:
  - `oras-tickets/src/Integrations/QuickBooks/Module.php`
  - added pending/history tab-preserving redirects across operator actions,
  - added auto-map handler for event-income account mapping (`oras_tickets_qbo_auto_map_event_accounts`),
  - restored runtime safe add-only auto-map triggers on account refresh and event save updates.
- Extended QuickBooks defaults/controls for source polling behavior:
  - `oras-tickets/src/Integrations/QuickBooks/Settings.php`
  - added source-match interval and max-wait settings defaults.
- Extended CLI queue operations:
  - `oras-tickets/src/Integrations/QuickBooks/Cli_Command.php`
  - added waiting queue processor command.

Verification:
- `php -l oras-tickets/src/Integrations/QuickBooks/Module.php` passed.
- `php -l oras-tickets/src/Integrations/QuickBooks/Journal_Entry_Creator.php` passed.
- `php -l oras-tickets/includes/Admin/Pages/Settings_Page.php` passed.
- Controlled live-safe sync validation completed on test-server workflow for Woo order `#4127` with successful sync status and JE creation metadata.

## 2026-03-01 — RSVP/Waitlist Concurrency + Capacity Consumption Hardening

Code:
- Added event-scoped DB lock helper:
  - `oras-tickets/includes/Support/DbLock.php`
  - provides named-lock wrappers for atomic event operations.
- Hardened RSVP/waitlist critical sections with event locks:
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
- Hardened Woo capacity consumption/restoration against concurrent order status transitions:
  - `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`
  - added order-scoped idempotency lock (`order:<id>`) around consume/restore flows,
  - grouped and applied ticket capacity mutations per event under event-scoped locks,
  - preserved product stock synchronization after locked capacity updates.

Verification:
- `php -l oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` passed.
- `php -l oras-tickets/includes/Support/DbLock.php` passed.
- `composer phpstan` passed.
- `composer phpcs` passed.

## 2026-02-28 — QuickBooks Reconciliation Reporting + API Error Matrix Coverage

Code:
- Added WP-CLI reconciliation reporting command:
  - `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> [--format=table|json] [--limit=<n>]`
  - reports completed/synced/pending/failed/dry-run/reversed/unsynced counts,
  - compares Woo line-item totals against QBO net posted totals from sync metadata,
  - outputs per-order mismatch rows with JE IDs and variance.
- Added deterministic API error matrix integration script:
  - `scripts/qbo-api-error-matrix-tests.php`
  - validates:
    - validation/syntax `400` fault handling,
    - retriable classification for `429`/`5xx`,
    - invalid JSON parse failure behavior,
    - transport failure metadata (`retriable` + endpoint),
    - 401 refresh retry success path,
    - refresh `invalid_grant` auth failure path,
    - `intuit_tid` capture and propagation.
- Added reconciliation integration script:
  - `scripts/qbo-reconciliation-tests.php`
- Added deterministic OAuth callback guard integration script:
  - `scripts/qbo-oauth-callback-tests.php`
  - validates callback CSRF/grant guard paths and state-transient cleanup behavior.
- Added QuickBooks redirect test hooks in module for deterministic callback testing:
  - action: `oras_tickets_qbo_redirecting`
  - filter: `oras_tickets_qbo_exit_after_redirect` (default `true`).
- Added QBO integration runner script:
  - `scripts/run-qbo-integration-checks.sh`
  - runs all QBO integration scripts in wp-env.
- Expanded CI workflow `phase5-verification.yml` to execute QBO integration checks on push/PR.
- Added email-spam suppression in integration tests:
  - QBO and Phase 5 `wp eval-file` scripts now short-circuit `pre_wp_mail` under WP-CLI.
  - prevents local/CI order-status test flows from sending transactional emails.
- Added branch-protection helper script:
  - `scripts/configure-branch-protection-required-checks.sh`
  - applies required status check context (`Phase5 Verification / verify`) via GitHub API.

Documentation:
- Updated:
  - `docs/CURRENT_STATE.md`
  - `docs/NEXT.md`
  - `docs/quickbooks-intuit-security-compliance.md`
  - `docs/quickbooks-live-rollout-checklist.md`
  to include reconciliation and error-matrix verification workflow.

## 2026-02-28 — QuickBooks Data-Safety Hardening (Fail-Closed + Reversal Controls)

Code:
- Added explicit QuickBooks write safety controls in settings/admin:
  - `Dry Run Mode` (default on),
  - `Require Manual Approval` (default on),
  - `Strict Mapping Mode` (default on),
  - `Allow Unmapped Fallback` (default off).
- Hardened sync orchestration for data safety:
  - manual review queue status (`pending_qbo_review`) before writes,
  - completed-only + cutoff-date + `_oras_qbo_synced` safeguard enforcement,
  - stale queue cleanup/unschedule when safeguards fail,
  - strict mapping fail-closed behavior for unmapped/unresolved lines.
- Added preflight payload validation in JournalEntry creator:
  - DocNumber length,
  - payload line/account integrity,
  - debit/credit balance checks.
- Added duplicate prevention by remote `DocNumber` lookup before JournalEntry create.
- Added order-level immutable audit metadata stream:
  - append-only `_oras_qbo_audit_entry`,
  - order summary metadata for sync/reversal/intuit tracing.
- Added reversal workflow:
  - orchestrator support to create reversing JournalEntry from stored split snapshot,
  - admin actions for approve/reverse by order ID,
  - WP-CLI commands:
    - `wp oras-tickets qbo approve-order <order_id> [--sync-now]`
    - `wp oras-tickets qbo reverse-order <order_id> [--force]`
    - `wp oras-tickets qbo audit-order <order_id> [--limit=25] [--format=table|json]`
- Retry policy hardening:
  - retry only transient transport faults (`429`, `5xx`, network),
  - no retries for validation/mapping/auth grant class errors.
- Added safety integration script:
  - `scripts/qbo-safety-controls-tests.php`.

Verification:
- `composer phpstan` passed.
- `php -l` passed for modified QuickBooks/admin/test files.
- `wp eval-file scripts/qbo-sync-safeguard-tests.php` (via wp-env) passed.
- `wp eval-file scripts/qbo-split-calculator-tests.php` (via wp-env) passed.
- `wp eval-file scripts/qbo-safety-controls-tests.php` (via wp-env) passed.

## 2026-02-28 — Frontend Dark-Mode Hardening (Tickets + RSVP)

Code:
- Updated `assets/css/tickets-frontend.css` to use tokenized frontend color variables instead of light-only hardcoded values for:
  - ticket description text,
  - price phase and countdown badges,
  - ticket status badges,
  - RSVP container/notice colors.
- Added explicit WP Dark Mode-compatible selectors and dark token values keyed to:
  - `html[data-wp-dark-mode-active]`
  - `html[data-wp-dark-mode-loading]`
  - `html.wp-dark-mode-active` / `body.wp-dark-mode-active`
  - `html.oras-dark-on` fallback
- Removed inline RSVP badge style from `includes/Frontend/Event_RSVP.php` and moved badge styling into CSS so light/dark toggles are consistent.

Verification:
- `php -l oras-tickets/includes/Frontend/Event_RSVP.php` passed.
- `composer phpstan` passed.
- `composer phpcs` passed.

## 2026-02-28 — Phase 4 Visual Polish Pass (Agenda + Speaker + Admin)

Code:
- Frontend agenda polish:
  - Removed temporary `wp_add_inline_style(...)` agenda overrides from `Event_Agenda_Render`.
  - Kept styling in static CSS with tokenized values for maintainability and deterministic rendering.
  - Replaced temporary dark-mode color overrides with production-ready selectors in `assets/css/oras-agenda-colors.css`.
- WP Dark Mode compatibility hardening:
  - Aligned dark-mode state detection to WP Dark Mode attributes (`html[data-wp-dark-mode-active]`, `html[data-wp-dark-mode-loading]`) and retained `html.oras-dark-on` fallback support.
  - Updated agenda and modal colors to remain readable when users toggle light/dark repeatedly.
- Speaker template polish:
  - Removed inline `<style>` block from `templates/single-oras_speaker.php`.
  - Added dedicated stylesheet `assets/css/speaker.css` and enqueue path for speaker single pages via `Template_Loader`.
  - Added consistent styles for contribution cards, chips, and resources archive sections with dark-mode token overrides.
- Admin agenda metabox polish:
  - Removed inline `style="..."` attributes from Event Agenda metabox markup and JS row templates.
  - Replaced with class-based hooks and updated `assets/admin/event-addon-metabox.css`.

Verification:
- `php -l` on updated PHP files passed.
- `composer phpcs` passed.
- `composer phpstan` passed.

## 2026-02-28 — QuickBooks Revenue Split Hardening + Category Expansion

Code:
- QuickBooks OAuth/admin hardening:
  - Added dedicated `ORAS Tickets > QuickBooks` admin page and persistent connection status indicator.
  - Fixed external OAuth redirect flow and callback state handling reliability.
  - Fixed token persistence/sanitization path so successful OAuth connections remain connected across refreshes.
  - Fixed QuickBooks `Test JournalEntry` payload `DocNumber` length to match QBO limit and clear stale `last_error` on success.
  - Added encrypted-at-rest storage handling for sensitive fields (`client_secret`, `access_token`, `refresh_token`, `realm_id`) with decryption-on-read for runtime/admin display.
  - Added production security guardrails before OAuth connect:
    - require HTTPS redirect URI when sandbox is off,
    - require explicit `ORAS_TICKETS_QBO_AES_KEY` in `wp-config.php` for production key separation compliance.
  - Reduced sensitive error logging detail (removed raw API/token response body logging).
  - Added explicit audit-facing auth error labels and handling:
    - `Auth Error Access`
    - `Auth Error Refresh`
    - `Auth Error Grant`
    - `CSRF Error`
- Split mapping hardening:
  - Added event-series slug fallback matching for per-event maps (`spring-stargaze` matches `spring-stargaze-26` via `slug-...` rule).
  - Added dedicated donations routing:
    - settings: `Donations Account`, `Donation Category Slugs`
    - classifier bucket: `donation`
  - Added dedicated Printful routing:
    - settings: `Printful Account`, `Printful Category Slugs`
    - classifier bucket: `printful`
    - fallback behavior: if `Printful Account` is blank, route printful bucket to `Merchandise Account`.
- Expanded split test script coverage for:
  - event-series slug fallback matching,
  - donations defaults/routing,
  - printful defaults/routing/fallback,
  - encryption storage/hydration round-trip checks.
- Added new WP-CLI dry-run command:
  - `wp oras-tickets qbo preview-order <order_id> [--format=json]`
  - outputs split routing without posting a JournalEntry.

Documentation:
- Synchronized QuickBooks implementation status and next validation steps in:
  - `docs/CURRENT_STATE.md`
  - `docs/NEXT.md`
- Added live rollout procedure:
  - `docs/quickbooks-live-rollout-checklist.md`
- Added security compliance mapping document:
  - `docs/quickbooks-intuit-security-compliance.md`

Verification:
- `wp eval-file scripts/qbo-split-calculator-tests.php` (via wp-env) passed.
- `composer phpstan` passed.
- `composer phpcs` passed.

## 2026-02-27 — QuickBooks Revenue Split Sync Foundation (Phase 5.3 plan/start)

Code:
- Added new QuickBooks integration module under `oras-tickets/src/Integrations/QuickBooks/`:
  - `OAuth_Client` (OAuth2 connect + refresh flow)
  - `Api_Client` (QBO API wrapper)
  - `Split_Calculator` (ticket/event/observer/merch split logic)
  - `Journal_Entry_Creator` (single JournalEntry per Woo order)
  - `Sync_Orchestrator` (paid-order hooks, idempotency, async queueing, retry)
  - `Retry_Handler` and `QuickBooks_Logger`
  - `Cli_Command` (`wp oras-tickets qbo ...`)
  - `Module` bootstrap/wiring and admin action handlers
- Wired module registration into plugin bootstrap.
- Extended ORAS settings page with QuickBooks Revenue Split section:
  - enable toggle, sandbox toggle
  - OAuth client credentials and realm
  - account mapping fields (clearing/default ticket/observer/merch/fallback)
  - category slug mapping fields and per-event account map
  - account selector support from cached account list
  - action buttons: Connect/Reconnect, Test Connection, Test JournalEntry
- Added split-calculator unit-style script: `scripts/qbo-split-calculator-tests.php`.

Documentation:
- Added Phase 5.3 QuickBooks Revenue Split proposal/details to:
  - `docs/CURRENT_STATE.md`
  - `docs/NEXT.md`
  - `docs/ROADMAP.md`

Verification:
- `php -l` against new/changed PHP files.
- `wp oras-tickets qbo test-connection` (manual runtime command; requires configured credentials).
- `wp eval-file scripts/qbo-split-calculator-tests.php` (manual runtime command).

## 2026-02-25 — Attendee Status Coverage Hardening (Phase 5)

Code:
- Fixed attendee ticket-order collection to include all dashboard-supported Woo order statuses (`completed`, `processing`, `on-hold`, `pending`, `refunded`, `cancelled`, `failed`) instead of only `processing`/`completed`.
- Expanded `scripts/phase5-integration-checks.php` with a regression scenario that creates an `on-hold` order and verifies:
  - `ticket_status=on-hold` includes that attendee.
  - `ticket_status=all` includes that attendee.

Verification:
- `bash scripts/run-phase5-integration-checks.sh` (pass, shared env `/home/rocco/projects/oras-wp-env`)
- `composer phpcs` (pass)
- `composer phpstan` (pass)

## 2026-02-25 — Phase 5.2 Waitlist Queue Operations + Audit Surface

Code:
- Added waitlist store query/operation methods for admin queue management (`get_event_rows`, `promote_user`, `bulk_promote_waiting`, `remove_waiting_user`).
- Added RSVP dashboard waitlist operations UI (bulk promote, manual promote/remove, queue and history tables).
- Added waitlist AJAX handlers in `Bootstrap`:
  - `oras_waitlist_queue_data`
  - `oras_waitlist_bulk_promote`
  - `oras_waitlist_promote_user`
  - `oras_waitlist_remove_user`
- Wired waitlist operations to deterministic lifecycle updates and RSVP usermeta sync.
- Extended `scripts/phase5-integration-checks.php` to verify the new queue/audit endpoints and operation flows.

Verification:
- `bash scripts/run-phase5-integration-checks.sh` (pass, shared env `/home/rocco/projects/oras-wp-env`)
- `composer phpcs` (pass)
- `composer phpstan` (pass)

## 2026-02-25 — Phase 5 Verification Suite + CI Enforcement

Code:
- Added `scripts/phase5-integration-checks.php` with deterministic WP-CLI integration checks for RSVP transitions, waitlist lifecycle/promotion, attendee data contracts, note save, and messaging handlers.
- Added `scripts/run-phase5-integration-checks.sh` to execute the checks against a configured wp-env runtime (`ORAS_WP_ENV_DIR` aware; defaults to `/home/rocco/projects/oras-wp-env` when present).
- Added first CI workflow `/.github/workflows/phase5-verification.yml` to run PHPCS, PHPStan, and Phase 5 integration checks on push/PR.

Verification:
- Local run passed in shared env (`/home/rocco/projects/oras-wp-env`) via:
  - `bash scripts/run-phase5-integration-checks.sh`
  - `composer phpcs`
  - `composer phpstan`

## 2026-02-25 — RSVP Frontend Endpoint + Attendee Integrity Fixes

Code:
- Fixed frontend RSVP submission routing where a hidden `action` field shadowed form `action` URL resolution.
- Fixed attendee aggregation so paid attendees are not collapsed by user and ticket quantities are counted correctly per event.

Verification:
- Reproduced undercount before fix and confirmed corrected attendee totals after fix.
- Static checks pass (`php -l`, `composer phpstan`, `composer phpcs`).

## 2026-02-25 — Board Dashboard Scope Added to Master Plan

Documentation:
- Added Phase 9.5 Board Member Dashboard scope to `MASTER_DEVELOPMENT_PLAN.md` with KPI domains, governance, and Members Hub style direction.
- Added Phase 10.4 Board Analytics Data Layer scope to `MASTER_DEVELOPMENT_PLAN.md`.
- Updated `MASTER_EXECUTION_TRACKER.md` to include phase-by-phase percentage rationale and board-dashboard-aware Phase 9/10 summaries.
- Updated `ROADMAP.md`, `NEXT.md`, `CURRENT_STATE.md`, and `PROJECT_STATE.md` to reflect approved board dashboard scope and sequencing gates.

## 2026-02-25 — Phase 5.2 Waitlist Data Model Upgrade

Code:
- Added first-class waitlist store `wp_oras_ticket_waitlist` with schema migration/install support.
- Added waitlist lifecycle states and audit fields (`waiting`, `promoted`, `left`, action source, actor, timestamps).
- Added legacy backfill path so existing waitlist usermeta records hydrate into first-class waitlist rows.
- Updated RSVP transition handling to synchronize waitlist lifecycle (join, leave, promote) into the store.
- Updated dashboard and metabox promotion/export/read paths to use deterministic queue ordering from the new store.
- Updated activation bootstrap to ensure waitlist schema installation.
- Static checks pass (`php -l`, `composer phpstan`, `composer phpcs`).

Documentation:
- Updated `MASTER_EXECUTION_TRACKER.md`, `NEXT.md`, `CURRENT_STATE.md`, and `PROJECT_STATE.md` to reflect Phase 5.2 progress and remaining closure tasks.

## 2026-02-25 — Phase 5 Hardening + Docs Consolidation

Code:
- Fixed RSVP intent normalization to correctly support `yes`, `no`, `waitlist`, and `leave_waitlist`.
- Fixed undefined redirect variable path in RSVP handler.
- Removed temporary RSVP debug logging noise from request paths.
- Resolved conflicting `admin_post_oras_rsvp_promote` handlers by moving legacy metabox promotion to its own action.
- Corrected unlimited-capacity logic in dashboard stats and promotion handlers (`capacity = 0` is no longer treated as full).
- Hardened admin attendees table rendering by escaping dynamic values before HTML injection.
- Removed duplicate virtual access hook registration and debug `error_log` output.
- Static checks now pass cleanly (`composer phpstan`, `composer phpcs`).

Documentation:
- Added `docs/MASTER_EXECUTION_TRACKER.md` as the operational source of truth for phase completion and gates.
- Reconciled `PROJECT_STATE.md`, `CURRENT_STATE.md`, `NEXT.md`, `ROADMAP.md`, and `README.md` to remove conflicting phase status claims.
- Replaced template `SECURITY.md` with project-specific policy.

## 2026-02-15 — Phase 6 Attendees Management (6.2 -> 6.5)
- Added attendees dashboard with event selector, filters, AJAX loading, and CSV export.
- Added attendee operations (View User, View Order, email action paths).
- Added attendee messaging with chunked sending and recipient normalization.
- Added attendee notes envelope `_oras_attendee_notes_v1` and inline note editing.

## 2026-02-15 — RSVP + Virtual Access Surfaces
- Added RSVP frontend/admin and REST surfaces for member-consumable status data.
- Added virtual access controls persisted in `_oras_virtual_access_v1`.

## 2026-02-14 — Speaker + Agenda Expansion
- Implemented speaker CPT/event assignment baseline and public speaker profile routing.
- Implemented agenda rendering with current-slot highlighting, autoscroll, and speaker modal UX.

## 2026-02-14 — Woo Ticket-Only Auto-Completion
- Added ticket-only order auto-completion with `_oras_autocompleted` marker.
