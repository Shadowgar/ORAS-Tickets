# Phase 5.3 Live Validation Run Log (Template)

Status: READY TO EXECUTE
Use this template during the controlled production validation order run.

## Session Header
- Run date/time (UTC):
- Environment/site URL:
- Operator:
- Treasurer observer:
- Woo order ID:
- QuickBooks company (realm label):

## Preconditions Check
- [ ] Intuit production app approved.
- [ ] Treasurer signoff completed (`docs/PHASE53_TREASURER_SIGNOFF_2026-03-02.md`).
- [ ] Safety defaults verified before live write (`Dry Run ON`, `Manual Approval ON`, `Strict Mapping ON`, `Unmapped Fallback OFF`).
- [ ] Mapping set confirmed (clearing/default/category/event).

## Step Log
### 1) Inspect order state
Command:
- `wp oras-tickets qbo audit-order <order_id> --format=json`
Result summary:
- 

### 2) Approve order (pending queue)
Command:
- `wp oras-tickets qbo approve-order <order_id>`
Result summary:
- 

### 3) Dry-run sync confirmation
Command:
- `wp oras-tickets qbo sync-order <order_id>`
Expected:
- status indicates dry-run,
- no JE write,
- audit entry appended.
Observed:
- 

### 4) Disable dry-run after approvals
Admin/UI action:
- Set `Dry Run Mode = OFF`.
Verification note:
- 

### 5) Live sync execution
Command:
- `wp oras-tickets qbo sync-order <order_id>`
Expected:
- synced status,
- JE ID and doc number stored.
Observed:
- 

### 6) Post-sync audit capture
Command:
- `wp oras-tickets qbo audit-order <order_id> --format=json`
Observed key fields:
- `_oras_qbo_sync_status`:
- `_oras_qbo_je_id`:
- `_oras_qbo_doc_number`:
- `_oras_qbo_last_intuit_tid`:

### 7) Reconciliation capture
Command:
- `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> --format=json`
Observed:
- 

### 8) Optional reverse validation
Command:
- `wp oras-tickets qbo reverse-order <order_id>`
If skipped, rationale:
- 

## Accounting Outcome Validation
- [ ] Clearing account debit equals order total.
- [ ] Income credits match configured split categories/maps.
- [ ] No duplicate net income effect in P&L.

Notes:
- 

## Attachments Checklist
- [ ] Audit output JSON before dry-run sync.
- [ ] Audit output JSON after live sync.
- [ ] Reconciliation output JSON.
- [ ] QuickBooks JE screenshot/reference.
- [ ] P&L validation snapshot/note.

## Final Result
Decision: ☐ PASS  ☐ FAIL
If fail, incident summary:
- 

Technical owner:
Date:

Treasurer witness:
Date:
