# CURRENT_STATE — Operational Snapshot

Last updated: 2026-02-25

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

## Remaining Gaps Before Phase 6+
1. Visual consistency/polish work remains for agenda/speaker/frontend quality.
2. Short operator soak pass remains for the new waitlist queue/history dashboard flows.

## Approved Upcoming Scope (Post-Gate)
- Board Member Dashboard has been approved for the master plan.
- It is scoped as a board-only, Members Hub-style executive surface backed by PMPro + ticketing + financial KPIs.
- Build remains gated behind current Phase 5 closure requirements.

## Phase 5.3 Proposal — QuickBooks Revenue Split Sync (Woo Orders)
Status: planned and gated behind current Phase 5 closure tasks.

Goals:
- Create one QuickBooks JournalEntry per paid Woo order (`processing`/`completed`).
- Split Woo revenue into mapped income accounts by event/category (ticket event, observer pass, merchandise, fallback).
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

Testing strategy:
- WP-CLI quick checks:
  - `wp oras-tickets qbo test-connection`
  - `wp oras-tickets qbo sync-order <order_id>`
  - `wp oras-tickets qbo retry-failed`
- Split calculator unit-style script:
  - `wp eval-file scripts/qbo-split-calculator-tests.php`
- Manual scenarios:
  - ticket-only, merch-only, observer-only, mixed cart, coupon/discount, multi-quantity, and failure/retry path.

Risk analysis:
- Duplicate accounting risk if clearing account is mapped incorrectly in QBO.
- Misclassification risk when product categories/meta are inconsistent.
- OAuth token expiry/rotation risk (mitigated by refresh logic and retry flow).
- Network/API reliability risk (mitigated by queue + retry + sync status metadata).

## Required Next Closure Conditions
- Maintain and extend WP-CLI integration checks as remaining Phase 5 queue/audit features land.
- Complete Phase 4 visual quality pass.
- Complete operator soak + UX micro-polish for waitlist queue/history actions.
- Re-run lint/static checks and update docs in same change set.
