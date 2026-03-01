# ORAS-Tickets Roadmap (Execution-Aligned)

Last updated: 2026-03-01

This roadmap is aligned to `docs/MASTER_DEVELOPMENT_PLAN.md` and the live execution tracker in `docs/MASTER_EXECUTION_TRACKER.md`.

## Phase Sequence
1. Phase 0-3: Core foundation, commerce integrity, reporting, print.
2. Phase 4: Speaker + agenda intelligence baseline and refinement.
3. Phase 5: Registration + capacity intelligence (current focus).
4. Phase 5.3: QuickBooks Revenue Split Sync for Woo orders (journal-entry split layer, post-gate).
5. Phase 6: Advanced ticketing intelligence.
6. Phase 7-12: Speaker intelligence expansion, virtual/hybrid depth, member expansion (including board dashboard), financial intelligence, discovery UX, and automation.

## Current Gate
The project remains in **stabilize-and-refine mode** before Phase 6+ development (Phase 5 depth largely complete, final polish/soak pending). QuickBooks Phase 5.3 implementation is in pre-live validation pending Intuit production app approval.

Recent gate progress (2026-03-01):
- RSVP/waitlist mutation paths were hardened with event-scoped locking.
- Woo capacity consume/restore paths were hardened with order-scoped idempotency locking + event-scoped atomic updates.
- CSV exports and admin RSVP dashboard rendering were hardened against spreadsheet-formula and DOM-injection classes.

Compliance note:
- Phase 5.3 closeout must include documented alignment and evidence for:
  - PCI Security Standards baseline controls (environment + process level):
    - https://www.pcisecuritystandards.org/
  - Intuit OAuth/OpenID discovery requirements:
    - https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-openid-discovery-doc

## Closure Criteria for Phase 5
- Waitlist architecture depth reaches master-plan expectations. (Implemented 2026-02-25)
- Promotion and cancellation lifecycle is deterministic and auditable. (Implemented 2026-02-25)
- Integration checks for RSVP/waitlist/attendees flows exist and pass in CI. (Implemented 2026-02-25)
- Core docs remain synchronized in same PR/change set.

## Board Dashboard Scope (Approved)
- Board-only one-stop dashboard in Members Hub visual style.
- Primary KPI domains: PMPro membership intelligence, ticketing/attendees intelligence, and financial rollups.
- Planning and build are sequenced after Phase 5 closure so data integrity and auditability are not compromised.

## Strategic Differentiators to Preserve
- Deterministic architecture and versioned envelopes.
- Woo-first commerce ownership.
- Institutional memory (speaker/archive/history models).
- No SaaS lock-in.

## Reference Documents
- Strategic baseline: `docs/MASTER_DEVELOPMENT_PLAN.md`
- Live phase scoring: `docs/MASTER_EXECUTION_TRACKER.md`
- Immediate queue: `docs/NEXT.md`
