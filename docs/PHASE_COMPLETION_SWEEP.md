# Phase Completion Sweep Plan

Last updated: 2026-03-02

> Historical planning snapshot.
>
> Current authoritative sweep state is maintained in:
> - `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`
> - `docs/MASTER_EXECUTION_TRACKER.md`
> - `docs/CURRENT_STATE.md`
>
> Governance note: Phases 0-5 are now LOCKED for the current execution baseline.

Purpose: after Door Prize feature completion, execute unfinished work in strict phase order using the governance precedence in `docs/MASTER_EXECUTION_TRACKER.md` + `docs/CURRENT_STATE.md` + `docs/PROJECT_STATE.md`.

## Audit Baseline (2026-03-02)
- Full codebase recalculation completed and published:
	- `docs/PHASE_COMPLETENESS_AUDIT_2026-03-02.md`
- Recalculated overall completion: **58.5%**.
- Tracker/state docs synchronized to this baseline before continued strict phase-order execution.

## Execution Order
1. Phase 0
2. Phase 1
3. Phase 2
4. Phase 3
5. Phase 4
6. Phase 5
7. Phase 6
8. Phase 7
9. Phase 8
10. Phase 9
11. Phase 10
12. Phase 11
13. Phase 12

## Known Unfinished Items by Phase

### Phase 0-2 (Locked, residual hardening)
- Add deeper regression automation for bootstrap/capability and core envelope/mapping edge cases.

Progress update (2026-03-02):
- ✅ Added and validated core regression checks for capability + envelope mapping hardening:
	- `oras-tickets/tools/core-regression-checks.php` (wp-env executable)
	- `scripts/core-regression-checks.php` (wrapper entrypoint)
- ✅ Expanded capability-boundary assertions to full ORAS cap matrix:
	- all `Capabilities::CAPS` + `Capabilities::TREASURER_ONLY_CAPS` explicitly verified for admin allow + subscriber deny.
- Verified in wp-env with passing assertions for:
	- capability assignment boundary (`administrator` vs `subscriber`) across full cap matrix,
	- missing ticket envelope defaults,
	- ticket `price_phases` preservation when omitted,
	- invalid `price_phases` shape normalization.
- ✅ Added and validated bootstrap regression checks for dependency + hook surface hardening:
	- `oras-tickets/tools/bootstrap-regression-checks.php`
- Verified in wp-env with passing assertions for:
	- TEC/Woo dependency presence guard,
	- bootstrap singleton stability,
	- Phase 1 init registration (`init` priority `20`),
	- required RSVP/waitlist/attendees AJAX + admin-post handler hook registration,
	- Door Prize frontend renderer registration.

Phase 0-2 residual hardening status: ✅ complete for currently tracked regression-automation gaps.

### Phase 3
- Expand advanced analytics depth and board-ready KPI layering beyond current baseline reports.

### Phase 1 (Locked, residual hardening)
- Extend deterministic envelope regression depth for schema migration/normalization behaviors.

Progress update (2026-03-02):
- ✅ Extended `oras-tickets/tools/core-regression-checks.php` with Phase 1 envelope coverage for:
	- unsupported schema fallback to empty collection,
	- `ticket_key` fallback from row key when omitted,
	- ticket key generation invariants (expected length + uniqueness across calls).
- Verified in wp-env with passing assertions for all new checks.

Progress update (2026-03-02):
- ✅ Added first KPI-layering increment to Board Dashboard:
	- new **KPI Layer (Executive Signals)** section in `includes/Frontend/Board_Dashboard.php`.
- Added derived board signals from existing data feeds:
	- revenue diversity score,
	- membership dependency,
	- waitlist promotion efficiency,
	- subscriber confirmation ratio,
	- open-to-form momentum (30d).
- Remaining Phase 3 scope:
	- deeper analytics expansion (trend depth, board packet-level KPI layering, and additional comparative signals).

### Phase 4
- Final UI/archive refinement for speaker and agenda surfaces.

### Phase 5
- Complete operator soak evidence for waitlist queue/history operations.
- Keep extending deterministic concurrency/regression coverage as operational paths evolve.
- Complete QuickBooks Phase 5.3 pre-live validation packet and signoff evidence.

### Phase 6
- Build advanced ticketing intelligence set (tier automation, QR, check-in, optional seating).

### Phase 7
- Complete speaker history/archive indexing and analytics expansions.

### Phase 8
- Build virtual/hybrid advanced gating and sync features.

### Phase 9
- Complete member expansion areas (My RSVPs, speaking history, invoices) and board dashboard surface completion.

### Phase 10
- Build advanced financial intelligence (invoice engine, refund intelligence, board analytics data layer).

### Phase 11
- Build discovery/UX enhancements, including Door Prize system completion to full scope (media uploader, richer display modes, accessibility refinements, export support).

### Phase 12
- Build automation and notification workflows.

## Working Rules For Sweep
- For each phase, complete: code + tests/verification + docs updates in one change set.
- Do not advance to next phase until current phase closure criteria are satisfied.
- Preserve add-on boundaries and capability-gated controls.
