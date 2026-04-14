# REFACTOR PLAN — PASS 5 (Refactor Items Only)

Scope: produce a minimal, evidence-driven refactor plan that is phase-aware, backward compatible where practical, and limited to fixes (no new features, no telemetry, no SaaS). Each item follows the required format.

Sources used (required):
- docs/evaluation/2026-03-22/current-state.md
- docs/evaluation/2026-03-22/roadmap-gap-analysis.md
- docs/evaluation/2026-03-22/data-model-evaluation.md
- docs/evaluation/2026-03-22/integration-review.md

Notes: this file is intentionally prescriptive and minimal-delta. Timing labels indicate recommended sequencing for release planning; "required now" denotes a refactor required to unblock safe operations or prevent data loss.

===============================================================================
Refactor Item 1 — Centralize Attendance Read Model (Authoritative Attendance Service)

1) Problem
- Attendance state is duplicated across multiple storage surfaces (per-event usermeta, normalized waitlist table, order-item snapshots, event postmeta notes). This duplication causes inconsistent admin views, reporting drift, and makes reconciliation error-prone (identified as a BLOCKER in PASS 2).

2) Evidence
- PASS 1: current-state (attendee surfaces: `includes/Waitlist_Store.php`, `includes/Frontend/Event_RSVP.php`, `_oras_ticket_*` order-item meta) — see docs/evaluation/2026-03-22/current-state.md.
- PASS 3: data-model evaluation (explicit BLOCKER: attendance state duplication; no normalized Attendee entity) — see docs/evaluation/2026-03-22/data-model-evaluation.md.
- PASS 4: integration-review (attendance is weakest boundary; admin/frontend/reporting rebuild attendees differently) — see docs/evaluation/2026-03-22/integration-review.md.

3) Affected files
- `oras-tickets/includes/Waitlist_Store.php`
- `oras-tickets/includes/Frontend/Event_RSVP.php`
- `oras-tickets/includes/Api/Rsvp.php`
- `oras-tickets/includes/Bootstrap.php`
- `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
- `oras-tickets/includes/Admin/Reports_Aggregator.php`

4) Affected classes / functions / hooks
- `Waitlist_Store::mark_waiting`, `::promote_next_waiting`, `::get_waiting_users`
- `Event_RSVP::render_rsvp_block`, RSVP REST handlers in `Api\\Rsvp`
- `Bootstrap::get_filtered_attendees`, `Event_RSVP_Attendees_Metabox::get_attendees`
- admin UI hooks that read/write usermeta or postmeta for RSVP/waitlist

5) Risk level
- High — touches admin views, reporting, attendee exports, and promotion workflows. Mistakes could lose or duplicate attendee state or disrupt operator workflows.

6) Timing
- Required now — classified as a BLOCKER in PASS 2; centralizing the read model is necessary before further Phase 5.3 operator soak or board-facing analytics.

Minimal refactor approach (non-invasive):
- Add a thin internal service (in-place, not new external service) `Attendance_Service` that provides read/write façade consolidating existing stores. Implement as an adapter that initially delegates to existing stores but provides canonical merge ordering, reconciliation APIs, and a single source-of-truth decision procedure. Do NOT rename storage surfaces in this change — only add the façade and migrate callers.
- Migration steps (minimal-delta):
  1. Create `Attendance_Service` in `includes/Attendance/Attendance_Service.php` (internal-only). Implement `get_attendees_for_event()`, `add_rsvp()`, `promote_waitlist()`, `mark_attended()` that delegate to existing APIs.
  2. Replace callers (one-by-one): `Bootstrap::get_filtered_attendees()`, `Event_RSVP_Attendees_Metabox::get_attendees()`, `Reports_Aggregator` to call the new service.
  3. Add reconciliation CLI (idempotent, read-only by default) to detect divergent state and emit a report; do not change recorded data automatically unless a follow-up is approved.
- Backward compatibility: keep existing storage schema and public admin_post endpoints intact; only internal call sites change.

===============================================================================
Refactor Item 2 — Make Capacity Consumption Idempotent & Status-Configurable

1) Problem
- `Capacity_Consumption` is tied to specific Woo order-status hooks (`processing`, `completed`, `cancelled`, `refunded`). This rigid mapping risks mis-consumption or double-consumption when shops use custom statuses or unusual gateway flows.

2) Evidence
- PASS 1: current-state shows `Capacity_Consumption::register()` mapping to Woo status hooks — docs/evaluation/2026-03-22/current-state.md.
- PASS 4: integration-review highlights coupling risks and implicit ordering assumptions (snapshot → status change → capacity) — docs/evaluation/2026-03-22/integration-review.md.

3) Affected files
- `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`
- `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php` (adjacent behavior)

4) Affected classes / functions / hooks
- `Capacity_Consumption::handle_paid_order`, `::handle_restore_order`, and `register()` hook bindings.
- Order meta keys: `_oras_capacity_consumed`, `_oras_capacity_restored`.

5) Risk level
- Medium — capacity correctness is essential to prevent oversell; changes must preserve existing behavior for sites using default Woo lifecycles while allowing safe configurability.

6) Timing
- Required now — before large-volume events or Phase 5 operator soak to avoid over/under-selling risk.

Minimal refactor approach (non-invasive):
- Introduce a small configuration read in `Capacity_Consumption` that maps logical states to hook triggers (config stored in plugin options, defaulting to current behavior). Implement idempotency guards around `_oras_capacity_consumed` and use DB locks already present (`DbLock::withLock`) — ensure checks remain.
- Migration plan: keep default mapping unchanged; add unit tests and a short admin-help note explaining mapping. No schema change.

===============================================================================
Refactor Item 3 — Product Sync: Reconciliation & Backfill Utility

1) Problem
- Orders created before a mapped product exists, or mapping update failures, can lead to missing order-item meta or incorrect product->ticket mappings. There is no small reconciliation/backfill tool in the codebase to fix stalled mappings.

2) Evidence
- PASS 1 / PASS 3: `Product_Sync` writes `_oras_tickets_woo_map_v1` and `snapshot_order_item_ticket_meta` writes order-item meta at checkout — docs/evaluation/2026-03-22/current-state.md and docs/evaluation/2026-03-22/data-model-evaluation.md.
- PASS 4: integration-review notes mapping race conditions and product lifecycle risk — docs/evaluation/2026-03-22/integration-review.md.

3) Affected files
- `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
- `oras-tickets/includes/Commerce/Woo/Cart_Pricing.php` (consumes mapping)

4) Affected classes / functions / hooks
- `Product_Sync::on_save_event`, `Product_Sync::snapshot_order_item_ticket_meta`, `get_or_create_product`, `is_valid_mapped_product`.

5) Risk level
- Low — primarily an operational/consistency risk; fixes are non-destructive if implemented with dry-run/backfill reporting.

6) Timing
- Safe to defer (recommended before next major release or before large event migrations).

Minimal refactor approach (non-invasive):
- Add an idempotent CLI/admin backfill command that:
  - Scans recent orders for missing `_oras_ticket_event_id`/`_oras_ticket_index` on order-items.
  - Attempts to resolve mapping via product postmeta or event mapping and reports suggested updates.
  - Supports a `--apply` flag for operators after review.
- Do not change runtime mapping code; provide reconciler as an ops tool only.

===============================================================================
Refactor Item 4 — QuickBooks Settings Ownership Separation

1) Problem
- QuickBooks adapter and the global settings UI share option structures; admin UI and adapter logic are interleaved, increasing coupling and risk of accidental setting schema changes.

2) Evidence
- PASS 4: integration-review highlights settings ownership coupling (`Settings_Page` and QuickBooks `Settings` both reference `oras_tickets_settings_v1`) — docs/evaluation/2026-03-22/integration-review.md.
- PASS 2: roadmap gap notes Phase 5.3 pre-live gating — docs/evaluation/2026-03-22/roadmap-gap-analysis.md.

3) Affected files
- `oras-tickets/includes/Admin/Pages/Settings_Page.php`
- `oras-tickets/src/Integrations/QuickBooks/Settings.php`

4) Affected classes / functions / hooks
- QuickBooks Settings class methods, settings page render/update hooks, admin_post handlers used by QBO module.

5) Risk level
- Low — configuration clarity is important for pre-live and operator tasks, but this refactor is not high-risk to runtime ticketing flows.

6) Timing
- Safe to defer, but recommended prior to QuickBooks Phase 5.3 pre-live operations.

Minimal refactor approach (non-invasive):
- Move adapter-specific defaults/validation into `src/Integrations/QuickBooks/Settings.php` without changing storage keys. Update `Settings_Page` to call adapter-provided validators/render helpers. No option-key renaming; start with code reorganization only.

===============================================================================
Refactor Item 5 — Explicitly Document & Guard Stripe-via-Woo Assumptions

1) Problem
- ORAS relies on Woo lifecycle hooks for payment completion; some gateways or custom integrations may not trigger `woocommerce_payment_complete` or expected status transitions, causing capacity/QuickBooks flows to be skipped silently.

2) Evidence
- PASS 4: integration-review notes NO EVIDENCE of direct Stripe webhooks and that Stripe is handled via Woo filters (see `Stripe_Intent_Description`) — docs/evaluation/2026-03-22/integration-review.md.
- PASS 1: `Stripe_Intent_Description` exists and is the only direct Stripe touchpoint — docs/evaluation/2026-03-22/current-state.md.

3) Affected files
- `oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php` (documented touchpoint)
- docs: operator guidance (add a docs file / README note)

4) Affected classes / functions / hooks
- `wc_stripe_generate_create_intent_request` filter (handled by `Stripe_Intent_Description`)
- `woocommerce_payment_complete` / order-status hooks used by capacity and QBO enqueue

5) Risk level
- Low — affects operational clarity and edge-case gateway setups rather than core code correctness.

6) Timing
- Safe to defer; document immediately and optionally ship small detection code to log when expected hooks are never fired for an order (non-invasive admin notice or ops report).

Minimal refactor approach (documentation-first):
- Add a short `docs/` note explaining that ORAS expects Woo to be the payment owner and that gateways must fire standard Woo lifecycle hooks. Optionally add a small admin-health check that warns if orders reach paid statuses without `_oras_capacity_consumed` updates for a configurable time window.

===============================================================================
Refactor Item 6 — Product_Sync Robustness Around save_event

1) Problem
- `Product_Sync::on_save_event` creates/updates mapped products during event save. Failures or long-running saves can leave mappings inconsistent; `get_or_create_product` returns an in-memory `WC_Product_Simple` when mapping invalid, raising a reconciliation need.

2) Evidence
- PASS 1: `Product_Sync::on_save_event` logic and `get_or_create_product` behavior (current-state) — docs/evaluation/2026-03-22/current-state.md.
- PASS 4: integration-review notes mapping lifecycle and race concerns — docs/evaluation/2026-03-22/integration-review.md.

3) Affected files
- `oras-tickets/includes/Commerce/Woo/Product_Sync.php`

4) Affected classes / functions / hooks
- `Product_Sync::on_save_event`, `get_or_create_product`, `is_valid_mapped_product`.

5) Risk level
- Medium — affects mapping correctness; incorrect mappings lead to checkout/pricing or reporting errors.

6) Timing
- Safe to defer, but recommended before next release that modifies product creation paths or before operator migrations.

Minimal refactor approach (non-invasive):
- Improve error handling in `on_save_event` to log failures, skip per-ticket save failures without aborting the full mapping loop, and ensure `_oras_tickets_woo_map_v1` is only updated after all successful mappings are collected. Add unit tests for `get_or_create_product` fallback behavior.

===============================================================================
Final notes
- All items adhere to the "minimal diff" constraint: they propose small, surgical changes (facade/adapters, configuration, operational tools, docs) that preserve existing storage and public endpoints.
- No new product features, no telemetry, and no SaaS dependencies are introduced.
- Next steps (suggested, not performed): schedule the high-risk refactor (Attendance centralization) as an immediate pre-release sprint item and perform Capacity idempotency work before any high-volume event.

End of PASS 5 — REFACTOR PLAN
# REFACTOR PLAN

## Summary
- Keep the current event-owned envelopes, Woo product mapping, order-item snapshot model, and QuickBooks adapter directory structure.
- Refactor only the areas where the current implementation either exceeds the authorized roadmap or creates repeat operational risk inside the locked baseline.
- Prioritize changes that improve determinism and boundary clarity without replacing existing schemas.

## Keep As-Is
- Event-owned versioned envelopes:
  - `_oras_tickets_v1`
  - `_oras_rsvp_v1`
  - `_oras_agenda_v1`
  - `_oras_speakers_v1`
  - `_oras_door_prizes_v1`
  Evidence:
  - `oras-tickets/includes/Domain/Meta.php`
  - `oras-tickets/includes/Domain/Ticket_Collection.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`
  - `oras-tickets/includes/Admin/Event_Speakers_Metabox.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php`
- Woo order-item snapshot pattern for reporting/print/QBO:
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
- Waitlist table `wp_oras_ticket_waitlist`:
  - `oras-tickets/includes/Waitlist_Store.php`
- QuickBooks adapter module layout under `oras-tickets/src/Integrations/QuickBooks/`.

## Refactor Recommendations

### Recommendation 1: Extract a shared attendance service
- Reason:
  - attendance reads and writes are duplicated across frontend, admin dashboard, metabox, and REST code
  - the current split increases drift risk for RSVP/waitlist/attendee behavior
- Evidence:
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Api/Rsvp.php`
- Affected files:
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Api/Rsvp.php`
  - new service file under `oras-tickets/includes/Domain/` or `oras-tickets/includes/Support/`
- Migration risk:
  - Low to medium. Existing stores stay unchanged; only read/write ownership moves.
- Timing:
  - Required now.

### Recommendation 2: Fix capability boundaries for attendee and speaker operations
- Reason:
  - custom plugin capabilities exist, but some entrypoints still rely on generic post capabilities
  - this blocks clean role separation in approved future phases
- Evidence:
  - `oras-tickets/includes/Capabilities.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Admin/Speaker_CPT.php`
  - `oras-tickets/includes/Admin/Admin_Menu.php`
- Affected files:
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Admin/Speaker_CPT.php`
  - `oras-tickets/includes/Capabilities.php`
  - possibly `oras-tickets/includes/Admin/Admin_Menu.php`
- Migration risk:
  - Medium. Role/cap mapping changes can hide screens or actions until roles are updated.
- Timing:
  - Required now.

### Recommendation 3: Enforce or remove RSVP window fields
- Reason:
  - `open_at` / `close_at` are persisted UI fields without runtime behavior
  - this creates false operator expectations
- Evidence:
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Api/Rsvp.php`
- Affected files:
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Api/Rsvp.php`
  - `oras-tickets/includes/Bootstrap.php` if dashboard/API stats need window awareness
- Migration risk:
  - Low. The envelope already contains the fields; only behavior or field removal changes.
- Timing:
  - Required now.

### Recommendation 4: Standardize ticket sale-window timezone semantics
- Reason:
  - sale-window logic is not deterministic when storage and validation disagree about timezone semantics
- Evidence:
  - `oras-tickets/includes/Domain/Ticket.php`
  - `oras-tickets/includes/Admin/Tickets_Metabox.php`
  - `oras-tickets/includes/Frontend/Tickets_Display.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
- Affected files:
  - `oras-tickets/includes/Domain/Ticket.php`
  - `oras-tickets/includes/Admin/Tickets_Metabox.php`
  - `oras-tickets/includes/Frontend/Tickets_Display.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
- Migration risk:
  - Medium. Existing event sale windows may behave differently at the boundary if current data is interpreted differently.
- Timing:
  - Required now.

### Recommendation 5: Keep QuickBooks downstream, but isolate its settings/rendering branch
- Reason:
  - QBO is already in the correct adapter layer, but settings ownership is split between the adapter and the general settings page
- Evidence:
  - `oras-tickets/includes/Admin/Pages/Settings_Page.php`
  - `oras-tickets/src/Integrations/QuickBooks/Settings.php`
  - `oras-tickets/src/Integrations/QuickBooks/Module.php`
- Affected files:
  - `oras-tickets/includes/Admin/Pages/Settings_Page.php`
  - `oras-tickets/src/Integrations/QuickBooks/Settings.php`
  - `oras-tickets/src/Integrations/QuickBooks/Module.php`
- Migration risk:
  - Low to medium. Option shape should remain stable; refactor should move rendering and validation ownership, not replace storage.
- Timing:
  - Can wait until locked-baseline stabilization items are complete.

### Recommendation 6: Remove live remote fetches from door-prize frontend rendering
- Reason:
  - current rendering performs network I/O during page generation
  - this weakens determinism and can slow event pages
- Evidence:
  - `oras-tickets/includes/Frontend/Door_Prizes.php`
  - `docs/PROJECT_STATE.md`
- Affected files:
  - `oras-tickets/includes/Frontend/Door_Prizes.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php`
- Migration risk:
  - Low. Existing `image_url` data can be preserved; only the fallback behavior changes.
- Timing:
  - Required now if door prizes remain active in runtime; otherwise can be combined with governance gating for that module.

### Recommendation 7: Gate or document runtime modules that exceed the locked baseline
- Reason:
  - runtime includes board dashboard, check-in, and door prizes while authoritative lock docs focus on a smaller Phase 0-5 / 5.3 surface
- Evidence:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - `oras-tickets/includes/Api/Checkin.php`
  - `oras-tickets/includes/Admin/Pages/Checkin_Page.php`
  - `oras-tickets/includes/Frontend/Door_Prizes.php`
  - `docs/CURRENT_STATE.md`
  - `docs/ROADMAP.md`
  - `docs/MASTER_EXECUTION_TRACKER.md`
- Affected files:
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Admin_Menu.php`
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - `oras-tickets/includes/Api/Checkin.php`
  - `oras-tickets/includes/Admin/Pages/Checkin_Page.php`
  - docs outside the evaluation folder in a separate follow-up change set
- Migration risk:
  - Low to medium. Removing registration/gating features changes available runtime surfaces, so the follow-up must be explicit.
- Timing:
  - Required now for governance clarity, but implementation can be doc-first if runtime gating would be disruptive.

## What Must Be Removed
- No schema replacement is required now.
- No full-module rewrite is required now.
- No Woo, Stripe, or QuickBooks framework swap is justified by the current evidence.
- The only removals currently justified are behavioral removals of:
  - live remote door-prize image fetches in `oras-tickets/includes/Frontend/Door_Prizes.php`
  - any unused UI fields that remain intentionally unimplemented after a decision on RSVP windows

## What Must Stay Backward-Compatible
- `oras_tickets_settings_v1` option storage
- `_oras_tickets_v1` event ticket envelope
- `_oras_tickets_woo_map_v1` ticket/product map
- `_oras_rsvp_v1` RSVP settings envelope
- `_oras_rsvp_event_<event_id>` usermeta keys
- `wp_oras_ticket_waitlist` table
- `_oras_ticket_*` order-item snapshot metadata
- `_oras_qbo_*` order metadata used by the QuickBooks adapter

## Recommended Implementation Order
1. Attendance service extraction
2. Capability boundary correction
3. RSVP window enforcement or removal
4. Sale-window timezone normalization
5. Governance alignment for extra runtime modules
6. Door-prize remote fetch removal
7. QuickBooks settings/render isolation
