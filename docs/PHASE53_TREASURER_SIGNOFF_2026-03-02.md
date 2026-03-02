# Phase 5.3 Treasurer Signoff — QuickBooks Revenue Split

Status: PENDING SIGNATURE
Date opened: 2026-03-02

## Signoff Scope
This signoff confirms acceptance of ORAS Tickets QuickBooks Revenue Split behavior for:
- mapping policy,
- clearing-account handling,
- dry-run to live cutover plan,
- reconciliation expectations,
- reversal/rollback procedure.

## Required Inputs (Attach or link)
- Phase 5.3 technical packet: `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`
- Live rollout checklist: `docs/quickbooks-live-rollout-checklist.md`
- Security/compliance notes: `docs/quickbooks-intuit-security-compliance.md`
- Reconciliation output sample (JSON/table) for controlled run.
- One sample order audit trail (`wp oras-tickets qbo audit-order <order_id> --format=json`).

## Treasurer Review Checklist
- [ ] Clearing account mapping is correct and aligns with Stripe connector accounting treatment.
- [ ] Default/fallback income account mapping is acceptable.
- [ ] Category/event mapping policy is acceptable (observer/merch/printful/donations/event-specific).
- [ ] Dry-run controls are acceptable before cutover (`Dry Run ON`, `Manual Approval ON`, `Strict Mapping ON`, `Unmapped Fallback OFF`).
- [ ] Controlled live validation order procedure is acceptable.
- [ ] Reconciliation report format and variance interpretation are acceptable.
- [ ] Reversal workflow and incident rollback procedure are acceptable.
- [ ] Production go/no-go conditions are acceptable.

## Cutover Decision
Decision: ☐ APPROVED  ☐ APPROVED WITH CONDITIONS  ☐ REJECTED

If approved with conditions, list conditions:
- 

If rejected, list required changes:
- 

## Final Authorization
Treasurer name: 
Treasurer signature (typed): 
Date: 

Technical owner witness: 
Date: 

## Notes
- Signoff completion moves the “treasurer signoff packet” blocker for Phase 5.3 from open to complete.
- Intuit production approval and controlled live validation remain separate blockers.
