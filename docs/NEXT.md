# NEXT — Immediate Work Queue

Last updated: 2026-02-28

## Current Sprint Goal
Close Phase 5 hardening gates so Phase 6 work can begin safely.

## Ordered Tasks
1. Waitlist Queue Operator Soak (Phase 5.2 final)
- Run operator walkthrough on new queue/history tools (manual promote/remove and bulk promote paths).
- Capture any UI/wording friction and apply a small polish pass.

2. Board Member Dashboard Design Pack (Phase 9.5 / 10.4)
- Define board KPI contract (PMPro, ticketing, finance, operational alerts).
- Define Members Hub-aligned UI spec (information hierarchy, card system, responsive behavior).
- Define capability/permission model for board-only access and exports.

3. Phase 5.3 — QuickBooks Revenue Split Sync (Woo Orders) (Post-Gate)
- Finalize clearing-account accounting policy with treasurer (to avoid Stripe duplicate revenue presentation). (In progress)
- Keep production safety defaults enabled until treasurer signoff:
  - `Dry Run Mode = ON`
  - `Require Manual Approval = ON`
  - `Strict Mapping Mode = ON`
  - `Allow Unmapped Fallback = OFF`
- Run operator validation workflow in sandbox/live-safe mode:
  - approve pending order manually,
  - run dry-run sync,
  - inspect order audit entries (`_oras_qbo_audit_entry`),
  - verify no JE write occurs in dry-run mode.
- Add compliance workstream for Intuit production audit readiness:
  - align plugin + deployment controls to PCI Security Standards guidance as close as practical for current architecture:
    - https://www.pcisecuritystandards.org/
  - align OAuth implementation and documentation to Intuit OpenID discovery requirements:
    - https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-openid-discovery-doc
  - include deterministic test evidence for required auth error scenarios:
    - `Auth Error Access`
    - `Auth Error Refresh`
    - `Auth Error Grant`
    - `CSRF Error`
- Validate account mappings for:
  - event ticket income (per-event slug map)
  - observer pass income
  - merchandise income
  - printful merchandise income
  - donations income
  - fallback/unmapped income
- Execute controlled sandbox test run using check gateway orders and WP-CLI sync commands. (In progress)
- Use dry-run command before live posting during mapping verification:
  - `wp oras-tickets qbo preview-order <order_id> [--format=json]`
- Use reconciliation command after each controlled run to verify variance and status distribution:
  - `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> [--format=table|json]`
- Use new safety commands for operator flow:
  - `wp oras-tickets qbo approve-order <order_id> [--sync-now]`
  - `wp oras-tickets qbo audit-order <order_id> [--format=json]`
  - `wp oras-tickets qbo reverse-order <order_id> [--force]`
- Run deterministic API fault matrix evidence script before live go/no-go:
  - `wp eval-file scripts/qbo-api-error-matrix-tests.php`
- Run deterministic OAuth callback guard evidence script before live go/no-go:
  - `wp eval-file scripts/qbo-oauth-callback-tests.php`
- Complete live go-live validation once Intuit production app approval is granted:
  - connect production QuickBooks credentials
  - run one controlled low-value live Woo/Stripe order
  - verify no duplicate net income effect in P&L
- Prepare refund-handling follow-up scope (reversal JournalEntry policy).

## Completed This Cycle
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
- Added test-run email suppression for local/CI integration scripts:
  - QBO and Phase 5 scripts disable outbound `wp_mail` under WP-CLI to prevent notification spam.
- Added GitHub branch-protection helper for required status checks:
  - `scripts/configure-branch-protection-required-checks.sh`
  - dry-run by default; `--apply` uses GitHub API with `GITHUB_TOKEN`.

## Out of Scope Until Above Is Done
- New Phase 6+ feature implementation (QR/check-in, reservation windows, advanced automation).
- Board dashboard implementation (design can be drafted, build starts after Phase 5 closure).
