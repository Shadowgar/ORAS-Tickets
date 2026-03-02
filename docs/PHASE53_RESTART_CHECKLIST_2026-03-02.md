# Phase 5.3 Restart Checklist

Status: READY WHEN CONSTRAINTS LIFT
Date: 2026-03-02

## Trigger Condition
Use this checklist when production WP-CLI execution is available again and external approvals can proceed.

## Step 1 — Preconditions
- [ ] Confirm production WP-CLI is available for ORAS-Tickets commands.
- [ ] Confirm Intuit production app approval status and reference ID.
- [ ] Confirm treasurer signoff workflow owner and target date.

## Step 2 — Artifact Readiness
- [ ] Review `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`.
- [ ] Review `docs/PHASE53_OPERATOR_HANDOFF_2026-03-02.md`.
- [ ] Review `docs/PHASE53_PRODUCTION_VALIDATION_EVIDENCE_2026-03-02.md`.
- [ ] Prepare `docs/PHASE53_LIVE_RUN_LOG_TEMPLATE_2026-03-02.md` for the execution window.

## Step 3 — Controlled Validation Run
- [ ] Run `wp oras-tickets qbo audit-order <order_id> --format=json`.
- [ ] Run `wp oras-tickets qbo approve-order <order_id>`.
- [ ] Run dry-run validation first (`wp oras-tickets qbo sync-order <order_id>` with dry-run mode enabled).
- [ ] Disable dry-run only after approvals and run live sync once.
- [ ] Run `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> --format=json`.

## Step 4 — Evidence Capture
- [ ] Attach Intuit approval evidence.
- [ ] Attach treasurer signoff completion.
- [ ] Attach audit output, reconciliation output, and JE reference.
- [ ] Record PASS/FAIL in `docs/PHASE53_PRODUCTION_VALIDATION_EVIDENCE_2026-03-02.md`.

## Step 5 — Gate Update
- [ ] If all checks pass, move Phase 5.3 to READY FOR LOCK in:
  - `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`
  - `docs/MASTER_EXECUTION_TRACKER.md`
  - `docs/CURRENT_STATE.md`
  - `docs/NEXT.md`
