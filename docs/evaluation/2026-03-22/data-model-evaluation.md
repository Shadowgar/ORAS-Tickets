# DATA MODEL EVALUATION

## Summary
- The current model is event-centric and mostly deterministic.
- Ticketing and agenda data are stored in versioned event post-meta envelopes, which is acceptable for event-scoped authoring and rendering.
- Reporting and downstream accounting are anchored on Woo order-item snapshots, which is the strongest part of the current model.
- Attendance is the weakest part of the model because RSVP, waitlist, and attendee notes are split across different stores with duplicated semantics.

## Storage Map

| Entity | Primary store | Evidence | Assessment |
|---|---|---|---|
| Ticket definitions | Event post meta `_oras_tickets_v1` | `oras-tickets/includes/Domain/Meta.php`, `oras-tickets/includes/Domain/Ticket_Collection.php`, `oras-tickets/includes/Admin/Tickets_Metabox.php` | Deterministic, versioned, event-scoped, and appropriate for current ticket authoring. |
| Ticket-to-product map | Event post meta `_oras_tickets_woo_map_v1` plus Woo product meta `_oras_ticket_event_id` / `_oras_ticket_index` | `oras-tickets/includes/Commerce/Woo/Product_Sync.php` | Good minimal-diff mapping pattern; it keeps Woo as commerce engine while preserving ORAS event ownership. |
| Ticket order snapshots | Woo order-item meta `_oras_ticket_event_id`, `_oras_ticket_index`, `_oras_ticket_name`, `_oras_ticket_unit_price`, `_oras_ticket_currency`, `_oras_ticket_price_phase_*` | `oras-tickets/includes/Commerce/Woo/Product_Sync.php`, `oras-tickets/includes/Admin/Reports_Aggregator.php`, `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`, `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php` | Strongest reporting surface in the plugin; good for historical reporting and downstream accounting. |
| RSVP settings | Event post meta `_oras_rsvp_v1` | `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`, `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php` | Event-scoped settings envelope is appropriate; `open_at` / `close_at` are stored but not enforced. |
| RSVP attendee status | Usermeta `_oras_rsvp_event_<event_id>` and `_oras_rsvp_event_<event_id>_ts` | `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php`, `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php` | Functional but not normalized; cross-event queries depend on dynamic meta keys and repeated usermeta scans. |
| Waitlist lifecycle | Custom table `wp_oras_ticket_waitlist` | `oras-tickets/includes/Waitlist_Store.php` | Better normalized than RSVP state; queue order and audit fields are explicit and deterministic. |
| Attendee notes | Event post meta `_oras_attendee_notes_v1` | `oras-tickets/includes/Bootstrap.php` | Acceptable for event-scoped notes, but it creates another attendance-related store with its own keys. |
| Agenda | Event post meta `_oras_agenda_v1` | `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`, `oras-tickets/includes/Frontend/Event_Agenda_Render.php` | Appropriate for event-scoped authoring; supports multi-day agenda content. |
| Speakers | CPT `oras_speaker` + event envelope `_oras_speakers_v1` + speaker meta `_oras_speaker_*` | `oras-tickets/includes/Admin/Speaker_CPT.php`, `oras-tickets/includes/Admin/Event_Speakers_Metabox.php` | Mixed but coherent: reusable speaker entity plus event assignment envelope. |
| Door prizes | Event post meta `_oras_door_prizes_v1` | `oras-tickets/includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php`, `oras-tickets/includes/Frontend/Door_Prizes.php` | Event-scoped envelope is fine; frontend remote fetch behavior is the issue, not the storage shape. |
| QuickBooks settings | Option `oras_tickets_settings_v1` | `oras-tickets/includes/Admin/Pages/Settings_Page.php`, `oras-tickets/src/Integrations/QuickBooks/Settings.php` | Shared option works, but admin/settings ownership is split across two layers. |
| QuickBooks sync state | Woo order meta `_oras_qbo_*` | `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`, `oras-tickets/src/Integrations/QuickBooks/Cli_Command.php`, `oras-tickets/includes/Admin/Pages/Settings_Page.php` | Appropriate for downstream accounting because the sync state is tied to the order lifecycle. |

## Event Structure
- The event remains the root aggregate for tickets, RSVP settings, agenda, speakers, door prizes, attendee notes, and virtual-access rules. Evidence:
  - `oras-tickets/includes/Domain/Ticket_Collection.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`
  - `oras-tickets/includes/Admin/Event_Speakers_Metabox.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php`
  - `oras-tickets/includes/Frontend/Virtual_Access.php`
- This event-centric model is consistent with the repo’s add-on architecture and The Events Calendar dependency in `oras-tickets/includes/Bootstrap.php` and `oras-tickets/includes/Domain/Meta.php`.
- The tradeoff is that non-event-wide concepts are still forced into event-level storage:
  - attendee notes by event
  - RSVP status as per-event usermeta keys
  - waitlist as event-level queue with no ticket-key dimension

## Ticket Types and Capacity
- Ticket rows are not normalized into a separate table; they are array items inside `_oras_tickets_v1`. Evidence:
  - `oras-tickets/includes/Domain/Ticket_Collection.php`
  - `oras-tickets/includes/Domain/Ticket.php`
- Capacity is stored directly on the ticket row and mirrored into Woo product stock. Evidence:
  - `oras-tickets/includes/Domain/Ticket.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`
- This supports the current Phase 0-5 baseline well because:
  - ticket definitions remain event-scoped
  - Woo stock and order-item metadata provide transactional durability
  - reporting reads immutable order-item snapshots instead of mutable ticket definitions
- The model does not support ticket-key-specific waitlists today because `Waitlist_Store` keys on `(event_id, user_id)` and does not include `ticket_key` or `ticket_index`. Evidence: unique key `event_user` and all read/write methods in `oras-tickets/includes/Waitlist_Store.php`.

## Orders and Attendee Linkage
- Paid attendance linkage is derived from Woo order items, not from a separate attendee table. Evidence:
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
- RSVP attendance linkage is derived from usermeta plus the waitlist table. Evidence:
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Api/Rsvp.php`
- This means “attendee” is not one normalized entity in the plugin. It is a composite read model stitched together differently in:
  - `Bootstrap::get_filtered_attendees()` in `oras-tickets/includes/Bootstrap.php`
  - `Event_RSVP_Attendees_Metabox::get_attendees()` in `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `Api\Rsvp::get_my_rsvps()` in `oras-tickets/includes/Api/Rsvp.php`

## Metadata Design Quality

### Strengths
- Versioned envelopes exist for ORAS-owned event data:
  - `_oras_tickets_v1`
  - `_oras_rsvp_v1`
  - `_oras_agenda_v1`
  - `_oras_door_prizes_v1`
  - `_oras_virtual_access_v1`
  Evidence: `oras-tickets/includes/Domain/Meta.php`, `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`, `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`, `oras-tickets/includes/Frontend/Virtual_Access.php`.
- Order-item snapshots are explicit and downstream-friendly. Evidence:
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
- Waitlist persistence is not a serialized blob; it is a dedicated table with explicit queue and audit fields. Evidence:
  - `oras-tickets/includes/Waitlist_Store.php`

### Weaknesses
- RSVP state is not normalized. Dynamic usermeta keys force repeated `get_users()` and `WP_User_Query` scans. Evidence:
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Api/Rsvp.php`
- Waitlist state duplicates one branch of RSVP state instead of replacing it. Evidence:
  - `Event_RSVP::handle_post()` updates both usermeta and `Waitlist_Store` in `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `Bootstrap::handle_waitlist_*()` updates both usermeta and `Waitlist_Store` in `oras-tickets/includes/Bootstrap.php`
  - `Event_RSVP_Attendees_Metabox::handle_promote()` updates both usermeta and `Waitlist_Store` in `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
- RSVP window fields are metadata without behavior. Evidence:
  - write path: `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`
  - missing read path: `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php`
- Ticket sale-window semantics are stored as naive strings and interpreted inconsistently. Evidence:
  - `oras-tickets/includes/Domain/Ticket.php`
  - `oras-tickets/includes/Admin/Tickets_Metabox.php`
  - `oras-tickets/includes/Frontend/Tickets_Display.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`

## Scalability Assessment
- Event-scoped envelopes are scalable enough for ORAS-sized editorial use because each event owns a bounded set of tickets, agenda slots, speaker assignments, and RSVP settings. Evidence:
  - `oras-tickets/includes/Domain/Ticket_Collection.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`
  - `oras-tickets/includes/Admin/Event_Speakers_Metabox.php`
- Reporting and accounting scale better than attendance because immutable order-item snapshots avoid re-deriving historical ticket state. Evidence:
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
- RSVP scaling is the main concern because attendee counts, current-user RSVP lists, and dashboard reads all depend on dynamic usermeta and repeated joins back to users/posts. Evidence:
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Api/Rsvp.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`

## Support For Approved/Future Needs

| Need | Supported now? | Evidence | Evaluation |
|---|---|---|---|
| RSVP / waitlist | Yes, event-level only | `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Waitlist_Store.php`, `oras-tickets/includes/Bootstrap.php` | Current event-level RSVP and FIFO waitlist are supported. |
| Capacity limits | Yes | `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`, `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`, `oras-tickets/includes/Frontend/Event_RSVP.php` | Separate ticket-capacity and RSVP-capacity paths exist. |
| Multi-day events | Yes for agenda; no ticket/day partitioning | `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`, `oras-tickets/includes/Frontend/Event_Agenda_Render.php` | Agenda supports multi-day schedules; ticket inventory remains event-level. |
| QuickBooks reconciliation | Yes | `oras-tickets/includes/Admin/Reports_Aggregator.php`, `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`, `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`, `oras-tickets/includes/Admin/Pages/Settings_Page.php` | Order snapshots and `_oras_qbo_*` order meta support downstream reconciliation. |
| Ticket-tier waitlist | No | `oras-tickets/includes/Waitlist_Store.php`, `oras-tickets/includes/Frontend/Event_RSVP.php` | The current waitlist schema has no ticket dimension. |
| Per-unit check-in | Partially | `oras-tickets/includes/Security/Ticket_Checkin_Token.php` | The order-item/unit model exists, but it is outside the currently authorized phase path. |

## Minimal-Diff Schema Improvements

### 1. Keep the current event envelopes and Woo order-item snapshots
- Reason: these are already deterministic and are the least disruptive storage surfaces in the plugin.
- Evidence:
  - `oras-tickets/includes/Domain/Ticket_Collection.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
- Recommendation: do not replace `_oras_tickets_v1`, `_oras_agenda_v1`, `_oras_speakers_v1`, or the `_oras_ticket_*` order-item snapshot set.

### 2. Do not replace the waitlist table; add a shared attendance service first
- Reason: the schema problem is duplicated ownership, not the existence of `wp_oras_ticket_waitlist`.
- Evidence:
  - `oras-tickets/includes/Waitlist_Store.php`
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
- Recommendation: add one attendance service layer that reads/writes the existing usermeta and waitlist table consistently before considering any table changes.

### 3. Either enforce or remove RSVP window fields
- Reason: `_oras_rsvp_v1` already contains `open_at` and `close_at`, but they are not part of runtime behavior.
- Evidence:
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Api/Rsvp.php`
- Recommendation: reuse the current envelope and make `open_at` / `close_at` meaningful; do not add a new store.

### 4. Normalize sale-window semantics without changing the envelope shape
- Reason: the issue is inconsistent interpretation, not missing fields.
- Evidence:
  - `oras-tickets/includes/Domain/Ticket.php`
  - `oras-tickets/includes/Admin/Tickets_Metabox.php`
  - `oras-tickets/includes/Frontend/Tickets_Display.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
- Recommendation: keep `sale_start` / `sale_end`, but standardize one timezone convention and migrate only parsing/validation behavior.

## Overall Judgment
- The data model is scalable enough for the locked Phase 0-5 baseline.
- It is not cleanly normalized for attendance-centric growth because RSVP status, waitlist status, attendee notes, and ticket attendance are split across different stores.
- The plugin does not need a schema rewrite now.
- The minimal-diff path is:
  - preserve event envelopes
  - preserve order-item snapshots
  - preserve the waitlist table
  - centralize attendance logic
  - fix orphaned RSVP window fields
  - normalize ticket sale-window semantics
