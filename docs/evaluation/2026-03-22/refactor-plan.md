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
