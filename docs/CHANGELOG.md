# CHANGELOG (Append-Only)

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
