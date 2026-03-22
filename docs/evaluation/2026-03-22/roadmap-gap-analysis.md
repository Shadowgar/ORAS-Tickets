# ROADMAP GAP ANALYSIS

## Authority Used
- Runtime truth: code in `oras-tickets/`.
- Roadmap truth for classification:
  - `docs/CURRENT_STATE.md`
  - `docs/MASTER_EXECUTION_TRACKER.md`
  - `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`
  - `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`
- Secondary only where non-conflicting:
  - `docs/ROADMAP.md`

## Classification Table

| Capability / scope item | Label | Evidence | Notes |
|---|---|---|---|
| Phase 0 bootstrap / dependency guard / capabilities baseline | COMPLETE | `oras-tickets/oras-tickets.php`, `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Capabilities.php`, `docs/MASTER_EXECUTION_TRACKER.md`, `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md` | Code and lock docs align on a locked foundation baseline. |
| Phase 1 ticket envelope model | COMPLETE | `oras-tickets/includes/Domain/Meta.php`, `oras-tickets/includes/Domain/Ticket.php`, `oras-tickets/includes/Domain/Ticket_Collection.php`, `oras-tickets/includes/Admin/Tickets_Metabox.php`, `docs/MASTER_EXECUTION_TRACKER.md`, `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md` | `_oras_tickets_v1` exists and is actively used. |
| Phase 2 Woo mapping + commerce integrity | COMPLETE | `oras-tickets/includes/Commerce/Woo/Product_Sync.php`, `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`, `oras-tickets/includes/Commerce/Woo/Cart_Pricing.php`, `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php`, `docs/CURRENT_STATE.md`, `docs/MASTER_EXECUTION_TRACKER.md` | Hidden Woo products, order-item snapshots, capacity mutation, and auto-complete are live. |
| Phase 3 reporting baseline | COMPLETE | `oras-tickets/includes/Admin/Pages/Reports_Page.php`, `oras-tickets/includes/Admin/Reports_Aggregator.php`, `docs/CURRENT_STATE.md`, `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md` | Treasurer/reporting UI and export flows are in code and locked docs. |
| Phase 3 member ticket API + print | COMPLETE | `oras-tickets/includes/Api/Member_Hub_Tickets.php`, `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`, `oras-tickets/includes/Frontend/Print_Ticket_View.php`, `docs/CURRENT_STATE.md`, `docs/MASTER_EXECUTION_TRACKER.md` | Member API and print route are active. |
| Phase 4 speaker baseline | COMPLETE | `oras-tickets/includes/Admin/Speaker_CPT.php`, `oras-tickets/includes/Admin/Event_Speakers_Metabox.php`, `oras-tickets/includes/Admin/Pages/Speaker_Obligations_Page.php`, `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`, `docs/CURRENT_STATE.md`, `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md` | Speaker authoring and reporting surfaces are implemented and represented in lock docs. |
| Phase 4 agenda baseline | COMPLETE | `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`, `oras-tickets/includes/Frontend/Event_Agenda_Render.php`, `docs/CURRENT_STATE.md`, `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md` | Agenda admin and frontend rendering are present and phase-locked. |
| Phase 5 RSVP core | COMPLETE | `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`, `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php`, `docs/CURRENT_STATE.md`, `docs/PHASE5_OPERATOR_SOAK_2026-03-02.md` | Event-level RSVP enable/capacity/waitlist flow is implemented and included in the lock evidence set. |
| Phase 5 waitlist queue/history operator flow | COMPLETE | `oras-tickets/includes/Waitlist_Store.php`, `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Admin/Pages/Dashboard_Page.php`, `docs/CURRENT_STATE.md`, `docs/PHASE5_OPERATOR_SOAK_2026-03-02.md` | Queue/history retrieval, bulk promote, single promote/remove, and soak evidence all exist. |
| Phase 5 attendee operations | COMPLETE | `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Admin/Pages/Dashboard_Page.php`, `docs/CURRENT_STATE.md`, `docs/PHASE5_OPERATOR_SOAK_2026-03-02.md` | Attendee filtering, notes, export, and messaging are implemented in the locked baseline. |
| Phase 5.3 QuickBooks technical integration | PARTIAL | `oras-tickets/src/Integrations/QuickBooks/Module.php`, `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`, `docs/CURRENT_STATE.md`, `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`, `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md` | Code and technical evidence exist, but the authoritative docs keep Phase 5.3 `IN PROGRESS` / paused pending external approvals and production validation. |
| RSVP open/close window support | EXTRA | `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`, `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md` | Admin fields exist, but the feature is not called out in the current authoritative phase model and no runtime enforcement exists. |
| Ticket check-in runtime | EXTRA | `oras-tickets/includes/Security/Ticket_Checkin_Token.php`, `oras-tickets/includes/Api/Checkin.php`, `oras-tickets/includes/Admin/Pages/Checkin_Page.php`, `oras-tickets/includes/Admin/Admin_Menu.php`, `docs/MASTER_EXECUTION_TRACKER.md`, `docs/ROADMAP.md` | Runtime code exists even though Phase 6 remains `PLANNED` and `docs/MASTER_EXECUTION_TRACKER.md` says QR/check-in systems are largely not implemented. |
| Board dashboard runtime | EXTRA | `oras-tickets/includes/Frontend/Board_Dashboard.php`, `oras-tickets/includes/Bootstrap.php`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/MASTER_EXECUTION_TRACKER.md` | The board dashboard shortcode is active in code while `docs/ROADMAP.md` says board dashboard scope is sequenced after current gate closure. |
| Door prize runtime | EXTRA | `oras-tickets/includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php`, `oras-tickets/includes/Frontend/Door_Prizes.php`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md` | This module is in code, but it is not explicitly named in the authoritative phase tracker or lock packet. |
| Member-aware pricing | OUT OF SCOPE | `docs/MASTER_EXECUTION_TRACKER.md`, `docs/ROADMAP.md`, `docs/CURRENT_STATE.md` | Not part of the currently authorized Phase 0-5 / 5.3 path. |
| Role-based access expansion for Board / Treasurer / Event Manager | OUT OF SCOPE | `docs/MASTER_EXECUTION_TRACKER.md`, `docs/ROADMAP.md`, `docs/CURRENT_STATE.md` | Current capability scaffolding exists in code, but the broader future role model is not part of the currently authorized phase path. |
| Stripe + QuickBooks financial clarity as a downstream concern | COMPLETE | `oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php`, `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`, `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`, `docs/CURRENT_STATE.md`, `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md` | The current code already separates Stripe request metadata from QuickBooks accounting sync. |
| Frontend-first Member Hub ticket consumption | COMPLETE | `oras-tickets/includes/Api/Member_Hub_Tickets.php`, `docs/CURRENT_STATE.md`, `docs/MASTER_EXECUTION_TRACKER.md` | Read-only Member Hub ticket API exists in code and fits current baseline. |

## Blockers

| Item | Label | Evidence | Why it blocks approved future work |
|---|---|---|---|
| Speaker capability model is not aligned with plugin-specific roles | BLOCKER | `oras-tickets/includes/Capabilities.php`, `oras-tickets/includes/Admin/Speaker_CPT.php`, `oras-tickets/includes/Admin/Admin_Menu.php` | Future approved governance and role separation are risked because menu access uses `oras_tickets_manage_speakers`, but speaker post CRUD still depends on generic post caps. |
| Attendance state is split across usermeta, a custom waitlist table, Woo order-item meta, and event post meta without one shared service boundary | BLOCKER | `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Waitlist_Store.php`, `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`, `oras-tickets/includes/Admin/Reports_Aggregator.php` | Approved future expansion around RSVP/capacity/member-facing attendance views will continue to duplicate logic until one authoritative attendance service exists. |
| Ticket sale-window timezone semantics are inconsistent across storage, admin UI, and runtime validation | BLOCKER | `oras-tickets/includes/Domain/Ticket.php`, `oras-tickets/includes/Admin/Tickets_Metabox.php`, `oras-tickets/includes/Frontend/Tickets_Display.php`, `oras-tickets/includes/Commerce/Woo/Product_Sync.php` | Approved future pricing/tiering work depends on deterministic sale windows. |
| Door prize frontend rendering uses live external fetches | BLOCKER | `oras-tickets/includes/Frontend/Door_Prizes.php`, `docs/PROJECT_STATE.md` | The project’s deterministic/self-hosted requirement is weakened by remote HTML fetches during event-page render. |

## Items Explicitly Missing From Authorized Current Scope
- No `MISSING` items were recorded against the authoritative current phase path of locked Phases 0-5 plus paused Phase 5.3.
- The authoritative docs describe the current baseline as already locked for Phases 0-5 in:
  - `docs/CURRENT_STATE.md`
  - `docs/MASTER_EXECUTION_TRACKER.md`
  - `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`
  - `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`
- Features that belong to planned future phases were classified as `OUT OF SCOPE`, not `MISSING`, per the evaluation rules.

## Immediate Gap Summary
- The runtime matches the locked Phase 0-5 baseline more closely than the older strategic plan language.
- The largest governance gap is not a missing locked-phase feature; it is scope drift, where runtime code has already moved into pre-gate areas:
  - check-in
  - board dashboard
  - door prizes
- The largest technical gaps inside the locked baseline are implementation integrity gaps:
  - speaker capability mapping
  - split attendance model ownership
  - RSVP window fields without enforcement
  - ticket sale-window timezone inconsistency
