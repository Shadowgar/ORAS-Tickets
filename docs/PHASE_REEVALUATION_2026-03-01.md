# ORAS-Tickets Phase Re-Evaluation (Code + Docs)

> Historical assessment snapshot from 2026-03-01.
>
> Current authoritative operational status is tracked in:
> - `docs/MASTER_EXECUTION_TRACKER.md`
> - `docs/CURRENT_STATE.md`
> - `docs/NEXT.md`
>
> Governance note: subsequent lock decisions moved Phases 0-5 to LOCKED.

Date: 2026-03-01  
Auditor: GitHub Copilot (GPT-5.3-Codex)

## Purpose
This document re-evaluates actual implementation status across Phases 0-12 using:
- plugin code in `oras-tickets/includes`, `oras-tickets/src`, `oras-tickets/templates`, `oras-tickets/assets`, `scripts`
- planning/state docs in `docs/`

This is intended to replace assumption-based progress with evidence-based progress.

## Method
1. Read master planning/state docs (`MASTER_DEVELOPMENT_PLAN`, `MASTER_EXECUTION_TRACKER`, `NEXT`, `CURRENT_STATE`, `ROADMAP`).
2. Inventory implemented modules by file and feature markers (meta keys, routes, handlers, UI pages).
3. Mark each phase as:
   - Implemented baseline,
   - Partially implemented,
   - Not implemented.
4. Assign revised completion percentages by phase (engineering-completion estimate, not test-completion certainty).

## Executive Re-Score (Revised)

| Phase | Plan Theme | Revised % | Status | Why |
|---|---|---:|---|---|
| 0 | Foundations/bootstrap/caps | 90% | Mostly implemented | Strong bootstrap/capabilities and architecture boundaries, but still some structural debt and no dedicated bootstrap regression suite. |
| 1 | Ticket model | 88% | Implemented baseline | Ticket envelope and admin editing are in place; still relies on legacy metabox patterns and has guardrail complexity debt. |
| 2 | Woo mapping + commerce integrity | 90% | Implemented baseline | Product sync, cart revalidation, capacity consumption, order item snapshots are implemented and hardened. |
| 3 | Reporting system (treasurer) | 78% | Partial | Reports engine/UI exists, CSV export exists, reports navigation restored, member/non-member segmentation added, speaker-level allocation supports both equal and primary-weighted attribution, and board-facing refund rate/AOV KPI cards are now present; deeper analytics layering is still incomplete. |
| 4 | Speakers + agenda baseline | 68% | Partial | Speaker CPT, event assignment, agenda rendering, resources exist; archive refinement and some workflow polish remain. |
| 5 | Registration + capacity intelligence | 78% | Partial/advanced | RSVP + waitlist + attendee dashboard + concurrency hardening are strong; still needs closure soak, test-depth, and complete gate signoff. |
| 5.3 | QuickBooks revenue split | 72% | Pre-live partial | Core sync, safety controls, reconciliation, pending/history ops, waiting queue implemented; production go-live and signoff still pending. |
| 6 | Advanced ticketing (QR/check-in/reservation) | 5% | Largely not built | Capability exists, but no real QR/check-in/reservation implementation found. |
| 7 | Speaker intelligence expansion | 35% | Partial | Speaker history envelope and obligations/reporting exist; performance analytics and submission workflow not built. |
| 8 | Virtual/hybrid depth | 30% | Partial | Virtual access gating exists; Zoom auto-sync and hybrid capacity split are not implemented. |
| 9 | Member Hub expansion | 28% | Partial | Member ticket and RSVP APIs exist; broader member history/invoice/board dashboard implementation is not complete. |
| 10 | Financial intelligence | 25% | Partial | Basic reporting plus QBO reconciliation present; invoice engine/refund intelligence/board analytics layer not built. |
| 11 | Discovery/UX enhancements | 8% | Mostly not built | Minimal overlap only; advanced filtering/map/discovery work is not present. |
| 12 | Automation + notifications | 3% | Mostly not built | Manual attendee messaging exists; automated reminder/follow-up systems are not implemented. |

Estimated overall completion (master-plan aligned, revised): **~52%**

---

## Phase-by-Phase Evidence and Remaining Work

## Phase 0 — Core Architecture (90%)
### Implemented evidence
- Bootstrap/dependency gate/module registration: `oras-tickets/includes/Bootstrap.php`
- Capability model: `oras-tickets/includes/Capabilities.php`
- Domain constants and envelope keys: `oras-tickets/includes/Domain/Meta.php`
- Project boundaries docs and guardrails present.

### Remaining to call it 100%
- Add dedicated regression checks for bootstrap/capability invariants.
- Reduce monolithic bootstrap responsibilities (currently very large class/method surface).

## Phase 1 — Ticket Model (88%)
### Implemented evidence
- Versioned ticket envelope `_oras_tickets_v1`: `oras-tickets/includes/Domain/Meta.php`, `oras-tickets/includes/Domain/Ticket_Collection.php`
- Event ticket editor/UI: `oras-tickets/includes/Admin/Tickets_Metabox.php`

### Remaining
- Continue reducing legacy coupling in metabox save/render flow.
- Add stronger migration/compatibility checks for envelope schema changes.

## Phase 2 — Woo Mapping + Commerce Integrity (90%)
### Implemented evidence
- Event-ticket to Woo product mapping: `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
- Cart/checkout revalidation and display flow: `oras-tickets/includes/Frontend/Tickets_Display.php`
- Capacity mutation hardening: `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`
- Order autocomplete logic: `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php`

### Remaining
- Expand edge-case test matrix (refund/cancel partial scenarios under heavy concurrency).

## Phase 3 — Reporting System (78%)
### Implemented evidence
- Financial aggregation layer: `oras-tickets/includes/Admin/Reports_Aggregator.php`
- Reports UI + CSV export + date/status scopes: `oras-tickets/includes/Admin/Pages/Reports_Page.php`
- Speaker report exports: `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`

### Gaps found
- Reports page route exists (`page=oras-tickets-reports`) and submenu wiring has been restored in admin menu registration (`oras-tickets/includes/Admin/Admin_Menu.php`, 2026-03-02), but broader reporting scope remains incomplete.
- Member/non-member depth has started (event detail KPIs + CSV segmentation), speaker report includes event gross/net context plus deterministic per-speaker allocation fields with selectable equal-split and primary-weighted modes, and Event Detail now includes board-facing refund rate and average-order-value KPIs; deeper board analytics layering is still incomplete.

### Completion checklist
1. Restore/admin-wire Reports menu discoverability. ✅
2. Add report integration tests (render + export + capability/nonce checks). ✅
3. Add missing advanced KPI dimensions and board-oriented views.

## Phase 4 — Speaker + Agenda Baseline (68%)
### Implemented evidence
- Speaker CPT and profile metadata: `oras-tickets/includes/Admin/Speaker_CPT.php`
- Event-speaker assignment metabox: `oras-tickets/includes/Admin/Event_Speakers_Metabox.php`
- Agenda admin + frontend render (including resources):
  - `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`
  - `oras-tickets/includes/Frontend/Event_Agenda_Render.php`
- Speaker single template with contribution/resource sections: `oras-tickets/templates/single-oras_speaker.php`

### Gaps found
- Phase 4.6 still not fully closed: archive/rendering/workflow refinement is still inconsistent across docs and runtime paths.

### Completion checklist
1. Formalize speaker archive data contract and indexing refresh strategy.
2. Add deterministic tests for speaker-history rebuild/update scenarios.
3. Final UI/polish pass for speaker archive + agenda resources workflows.

## Phase 5 — Registration + Capacity Intelligence (78%)
### Implemented evidence
- RSVP frontend system and intents: `oras-tickets/includes/Frontend/Event_RSVP.php`
- Waitlist first-class storage: `oras-tickets/includes/Waitlist_Store.php`
- RSVP dashboard queue/history operations and attendee tooling: `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Admin/Pages/Dashboard_Page.php`
- Concurrency lock helper: `oras-tickets/includes/Support/DbLock.php`

### Gaps found
- Final closure conditions still open: operator soak + full regression depth + gate signoff.

### Completion checklist
1. Run documented operator soak and capture evidence.
2. Add more deterministic concurrency regression tests.
3. Close Phase 5 gates in tracker/docs after evidence pass.

## Phase 5.3 — QuickBooks Revenue Split (72%)
### Implemented evidence
- Integration module stack under `oras-tickets/src/Integrations/QuickBooks/`
- Safety controls, queueing, reconciliation, audit/reversal flows, pending/history operational tabs.
- Reclass hardening (source matching, waiting queue, customer entity propagation).

### Gaps found
- Pre-live status remains: production approval/signoff/evidence pack not complete.

### Completion checklist
1. Treasurer signoff on mapping/cutover policy.
2. Controlled production validation order + reconciliation evidence.
3. Final go-live checklist closure and closeout docs.

## Phase 6 — Advanced Ticketing (QR/Check-in/Reservation) (5%)
### Implemented evidence
- Capability token only: `oras_tickets_checkin` in `oras-tickets/includes/Capabilities.php`

### Gaps found
- No QR generation, check-in controller/UI, reservation window/seat-lock logic found.

### Completion checklist
1. Implement QR token model + verification endpoint.
2. Implement check-in admin/mobile workflows + audit trail.
3. Implement reservation window/inventory lock model.

## Phase 7 — Speaker Intelligence Expansion (35%)
### Implemented evidence
- Speaker obligations/reporting pages exist.
- Speaker history envelope build/update exists in agenda metabox logic.

### Gaps found
- Performance analytics and frontend speaker submission flow not present.

### Completion checklist
1. Implement speaker performance analytics KPIs and views.
2. Build proposal/submission queue if still in scope.

## Phase 8 — Virtual/Hybrid (30%)
### Implemented evidence
- Virtual access gating by ticket/RSVP/member conditions: `oras-tickets/includes/Frontend/Virtual_Access.php`

### Gaps found
- No Zoom auto-create/sync implementation.
- No hybrid split capacity model implementation.

### Completion checklist
1. Decide whether Zoom auto-sync stays in-scope for core plugin.
2. Add hybrid capacity model or explicitly defer from master plan.

## Phase 9 — Member Hub Expansion (28%)
### Implemented evidence
- Member ticket API endpoints: `oras-tickets/includes/Api/Member_Hub_Tickets.php`
- RSVP API endpoints: `oras-tickets/includes/Api/Rsvp.php`

### Gaps found
- My RSVPs/My Speaking History/Invoice UI completeness is not present in this plugin.
- Board dashboard implementation not present.

### Completion checklist
1. Finalize ORAS-Tickets API contracts required by Member Hub UI.
2. Implement missing member-history domains and board dashboard foundation.

## Phase 10 — Financial Intelligence (25%)
### Implemented evidence
- Core ticket reporting and CSV exports.
- QuickBooks reconciliation/reporting command in QBO CLI module.

### Gaps found
- Invoice engine not implemented.
- Refund intelligence system not implemented.
- Board analytics data layer not implemented.

### Completion checklist
1. Define invoice model and generation path.
2. Build refund intelligence metrics and data model.
3. Build board analytics query/cache layer.

## Phase 11 — Discovery + UX Enhancements (8%)
### Implemented evidence
- Only incidental UX improvements; no direct phase-level feature completion.

### Gaps found
- Advanced filtering, map view, recurrence intelligence overlays: not implemented.
- **Door Prize system is not implemented** (no `_oras_door_prizes_v1` code usage found outside planning docs).

### Completion checklist
1. Build Door Prize data envelope + admin metabox + frontend rendering (image/external link behavior).
2. Build discovery/filtering features or remove from near-term scope.

## Phase 12 — Automation + Notifications (3%)
### Implemented evidence
- Manual attendee messaging exists in dashboard workflows.

### Gaps found
- No automated reminder email engine.
- No post-event automated follow-up system.

### Completion checklist
1. Define deterministic notification scheduler and templates.
2. Implement reminder/follow-up jobs with opt-out and audit logs.

---

## Specific Concern Validation

### "Phase 3 reporting seems incomplete"
Confirmed. Reporting engine/UI exists, but integration and advanced-scope completeness are below prior assumptions.

### "Phase 4.1 may not be complete"
Partially confirmed. Baseline speaker system exists, but associated phase closure (especially 4.6 archive quality) is not complete enough for 100%.

### "Door Prize system missing"
Confirmed. No implementation found in plugin code; only planning references exist in `docs/MASTER_DEVELOPMENT_PLAN.md`.

---

## Recommended Next Execution Sequence (Re-Evaluation Driven)
1. Correct project-tracker percentages and priorities to this evidence baseline.
2. Finish Phase 3 reporting integration gaps (menu wiring + validation/tests + KPI depth decisions).
3. Close Phase 4.6 speaker archive/refinement items.
4. Implement Door Prize system (Phase 11.4) as a discrete, testable feature slice.
5. Continue Phase 5/5.3 closeout evidence tasks in parallel where operationally required.

---

## Notes
- Percentages are implementation estimates, not production-validation guarantees.
- This report should be reviewed with stakeholders and then used to update:
  - `docs/MASTER_EXECUTION_TRACKER.md`
  - `docs/NEXT.md`
  - `docs/PROJECT_STATE.md`
