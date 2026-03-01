# CURRENT_STATE — Operational Snapshot

Last updated: 2026-03-01

## Authoritative Status
For phase percentages and advancement rules, use:
- `docs/MASTER_EXECUTION_TRACKER.md`

## Current Mode
**Phase 5 stabilization and completion hardening**.

The project should not move into new Phase 6+ implementation until Phase 5 completion gates are passed.

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

## Remaining Gaps Before Phase 6+
1. Short operator soak pass remains for the new waitlist queue/history dashboard flows.

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
- Re-run lint/static checks and update docs in same change set.
- Add and maintain a compliance checklist + evidence pack for:
  - PCI Security Standards baseline controls relevant to current architecture:
    - https://www.pcisecuritystandards.org/
  - Intuit OAuth/OpenID discovery requirements:
    - https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-openid-discovery-doc
