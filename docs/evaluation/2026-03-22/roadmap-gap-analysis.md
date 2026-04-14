---
ROADMAP GAP ANALYSIS — PASS 2

Scope: ROADMAP GAPS only (evidence-mapped). This file records gaps between the project's documented roadmap and the codebase as of 2026-03-22.

1) Phase 0–4 (foundation, ticket model, Woo mapping, reporting, print, speakers)
- Status: Mostly implemented.
- Evidence of implementation:
  - Ticket model and versioned envelope: `includes/Domain/Meta.php` (META_KEY_TICKETS = `_oras_tickets_v1`).
  - Woo mapping and item snapshot meta: `includes/Commerce/Woo/Product_Sync.php` (snapshot meta `_oras_ticket_*`).
  - Ticket print route: `includes/Frontend/Ticket_Print_Controller.php` (`add_rewrite_rule('^oras-ticket/print/?$'...)`).
  - Speaker CPT: `includes/Admin/Speaker_CPT.php` (post type `oras_speaker`, speaker meta keys).
- Gaps:
  - UI refinement items mentioned in Phase 4.4 & 4.6 (agenda UI polishing, speaker history rendering) are only partially implemented; evidence: `Event_Agenda_Render.php` exists but notes in docs indicate UI refinement ongoing; `Speaker Resources` history indexing and archive rendering marked pending in master plan (no complete implementation files for history indexing).

2) Phase 3 & Reporting / Treasurer-grade analytics
- Status: Core reporting present; advanced analytics incomplete.
- Evidence:
  - CSV export and basic reporting: `includes/Reports/Reports_Aggregator.php` (report aggregation and CSV hooks) and admin exports in `Admin_Menu`.
- Gaps:
  - Advanced reporting suite, invoice engine, and board analytics data layer (Phase 10) are not implemented; no evidence of invoice PDF engine, unified KPI query layer, or cached aggregate views.

3) Phase 5 — Registration & Capacity Intelligence (including 5.1–5.3)
- Status: Partial — core systems present but governance-locked for Phase 5 closure.
- Evidence:
  - Waitlist system: `includes/Waitlist_Store.php` (custom table `oras_ticket_waitlist`, install_schema), features implemented and marked built in docs.
  - RSVP surfaces: `includes/Frontend/Event_RSVP.php`, `includes/Api/Rsvp.php` (API routes and frontend renderers exist).
  - Capacity consumption hooks: `includes/Commerce/Woo/Capacity_Consumption.php` (order status handlers and `_oras_capacity_consumed`).
  - QuickBooks integration code present: `src/Integrations/QuickBooks/*` (Sync_Orchestrator and admin_post hooks).
- Gaps:
  - Phase 5 closure criteria require CI integration tests and documented operator soak for QuickBooks Phase 5.3; current evidence shows QuickBooks module present but Phase 5.3 is paused (ROADMAP note: pre-live, paused). No evidence of completed pre-live reconciliation reports or production WP-CLI executions.
  - Capacity Dashboard (Phase 5.3/5.2 predictive layer and sellout visibility) is partial: core metrics exist but predictive analytics and dashboard aggregation are not implemented (no centralized KPI query layer).

4) Phase 6 — Advanced Ticketing Intelligence
- Status: Not Built / Missing
- Gaps (explicit roadmap items with no code evidence):
  - Tier System Enhancements (early bird, member-only pricing, per-user limits): NO EVIDENCE in `includes/Commerce` for date-based transitions or per-user limits.
  - QR Code Ticket System (unique QR, verification endpoint, duplicate prevention): NO EVIDENCE; though `includes/Api/Checkin.php` registers checkin routes, there is no full QR generation/verification implementation or QR payload issuance tied to order items.
  - Check-In System (mobile UI, QR scan, timestamps, exports): Partial API exists (`Api/Checkin.php`) but mobile-friendly UI and exportable check-in lists or timestamp logs are not implemented.
  - Seat Reservation: NO EVIDENCE (no seat grid, locking logic, or price-per-seat code).

5) Phase 7–9 (Speaker Intelligence, Virtual/Hybrid, Member Hub expansion)
- Status: Mostly Not Built / Partially Built
- Gaps:
  - Speaker history & performance analytics: partial; `Speaker_CPT` exists, but `oras_speaker_history_v1` and automatic indexing not found.
  - Virtual/Hybrid features (Zoom gating, meeting sync): NO EVIDENCE (no Zoom integration module).
  - Member Hub expansions (My RSVPs, My Speaking History, Invoice Access): My Tickets and basic member ticket display exist, but My RSVPs, speaking history pages, invoice access, and PDF invoice engine are not implemented.

6) Phase 10–12 (Financial intelligence, discovery, automation)
- Status: Not Built
- Gaps:
  - Invoice engine, refund analytics, board analytics data layer, advanced filtering, map view, recurrence intelligence extensions, door prize system, reminders/post-event automation: NO EVIDENCE or marked Not Built in master plan.

7) Cross-cutting governance & compliance
- Gaps:
  - Phase 5.3 QuickBooks pre-live evidence checklist (operator soak, reconciliation workflows, PCI checklist alignment) is not present in code; ROADMAP marks the phase paused pending external approvals and production WP-CLI availability.
  - CI test coverage for end-to-end RSVP/waitlist/attendee flows and QuickBooks pre-live reconciliation evidence: limited evidence of automated end-to-end CI tests in repo scan.

8) Summary / actionable gap list (evidence-only)
- Implemented or present (evidence files):
  - Ticket model (`includes/Domain/Meta.php`)
  - Woo mapping & snapshot meta (`includes/Commerce/Woo/Product_Sync.php`)
  - Waitlist store & schema (`includes/Waitlist_Store.php`)
  - RSVP surfaces and API (`includes/Frontend/Event_RSVP.php`, `includes/Api/Rsvp.php`)
  - Ticket print controller (`includes/Frontend/Ticket_Print_Controller.php`)
  - Speaker CPT (`includes/Admin/Speaker_CPT.php`)
  - QuickBooks integration code present but pre-live (`src/Integrations/QuickBooks/*`)

- Not implemented / missing evidence (roadmap items requiring work):
  - Phase 6 features (QR tickets, tier transitions, seat reservations)
  - Phase 7–9 expansions (speaker history indexing, virtual Zoom integration, My RSVPs, invoice engine)
  - Phase 10 advanced financial analytics and board KPI data layer
  - CI end-to-end tests for RSVP/waitlist/attendee flows and QuickBooks pre-live reconciliation evidence

---

Notes: This PASS 2 file strictly records roadmap-to-code gaps using explicit code references where present. No recommendations or remediation steps are included in this PASS 2 output.
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
