# QuickBooks Live Rollout Checklist (ORAS Tickets)

Last updated: 2026-02-28

## Purpose
Run a controlled production rollout of ORAS Tickets QuickBooks Revenue Split Sync while Stripe Connector remains active, and verify no duplicate net income effect.

## Preconditions
- Intuit production app is approved and production OAuth keys are available.
- Stripe Connector is connected to the live QuickBooks company.
- ORAS Tickets plugin build with QuickBooks hardening + donations/printful routing is deployed.
- `ORAS_TICKETS_QBO_AES_KEY` is defined in production `wp-config.php`.

## Configuration Steps
1. In `ORAS Tickets > QuickBooks` (production site):
- Disable `Sandbox Mode`.
- Enter production `Client ID` and `Client Secret`.
- Keep safety defaults ON for first live validation:
  - `Dry Run Mode = ON`
  - `Require Manual Approval = ON`
  - `Strict Mapping Mode = ON`
  - `Allow Unmapped Fallback = OFF`
- Save settings.
2. Click `Connect / Reconnect QuickBooks` and authorize the live company.
3. Click `Test Connection + Refresh Accounts`.
4. Confirm `Connection Status: Connected` and production mode in the indicator.
5. Set income mappings:
- Clearing Account: use the same account Stripe Connector uses as source/clearing for Woo revenue.
- Default Ticket Income Account.
- Observer Pass Account.
- Merchandise Account.
- Printful Account (or leave blank to fall back to Merchandise Account).
- Donations Account.
- Fallback Unmapped Account.
6. Set classification slugs:
- Observer Category Slugs.
- Merch Category Slugs.
- Printful Category Slugs.
- Donation Category Slugs.
- Per-Event Account Map.
7. Save settings.
8. Optional dry-run check on a known paid order:
- `wp oras-tickets qbo preview-order <order_id> --format=json`
9. Run deterministic fault/observability checks in staging/sandbox before production posting:
- `wp eval-file scripts/qbo-api-error-matrix-tests.php`

## Controlled Live Validation
1. Enable ORAS QuickBooks sync if currently disabled.
2. Place one low-value real Woo order via Stripe and ensure order reaches `completed` (sync safeguard requires completed).
3. Approve order manually:
   - admin: `ORAS Tickets > QuickBooks > Order Safety Controls`
   - CLI: `wp oras-tickets qbo approve-order <order_id>`
4. Run sync while Dry Run is ON and validate:
   - `_oras_qbo_sync_status = dry_run`
   - no `_oras_qbo_je_id` is set
   - `_oras_qbo_audit_entry` records exist
5. After treasurer signoff, disable `Dry Run Mode` and re-run one approved order sync.
6. Validate Woo order meta:
   - `_oras_qbo_sync_status = synced`
   - `_oras_qbo_je_id` exists
   - `_oras_qbo_doc_number` exists
7. Validate QuickBooks entries:
   - Stripe Connector entry exists for payment flow.
   - ORAS JournalEntry exists with matching `DocNumber`.
   - JournalEntry debits clearing account for full order amount.
   - JournalEntry credits mapped income accounts by category/event.
8. Validate reporting:
   - net impact does not double-count income,
   - split amounts appear in intended income accounts (tickets/observer/merch/printful/donations).
9. Run reconciliation summary for the live validation date range and archive result:
   - `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> --format=json`

## Rollback / Safety
If mapping is incorrect or duplicate presentation appears:
1. Disable ORAS QuickBooks sync immediately.
2. Correct account mappings and/or clearing account alignment.
3. Re-test with one controlled order.
4. Reverse affected JournalEntry using plugin controls:
   - admin action: `Reverse Order JE` (Order Safety Controls),
   - or CLI: `wp oras-tickets qbo reverse-order <order_id>`.
5. Pull audit trail for incident notes:
   - `wp oras-tickets qbo audit-order <order_id> --format=json`

## Notes
- `Test JournalEntry` creates a real JournalEntry in the connected company.
- Avoid force-resync on already-synced production orders unless explicitly required.
