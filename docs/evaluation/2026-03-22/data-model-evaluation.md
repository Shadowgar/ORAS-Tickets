# DATA MODEL EVALUATION — PASS 3 (DATA MODEL ONLY)

Scope: analyze concrete data structures only for the following domains: events, tickets, attendees, RSVP/waitlist, Woo order + order-item linkage, pricing and capacity data surfaces. Evidence-only. No recommendations except to explain a BLOCKER where required.

1) Event
- Primary store: event postmeta envelopes.
  - Meta keys and files:
    - `_oras_tickets_v1` — `oras-tickets/includes/Domain/Meta.php` (public const META_KEY_TICKETS).
    - `_oras_rsvp_v1` — `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php` (META_KEY constant) and read by `oras-tickets/includes/Api/Rsvp.php`.
    - `_oras_agenda_v1` — `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php` and `oras-tickets/includes/Frontend/Event_Agenda_Render.php` (private const META_KEY = '_oras_agenda_v1').
    - `_oras_speakers_v1` — event assignment envelope (templates and `Event_Speakers_Metabox.php`).
    - `_oras_door_prizes_v1` — `oras-tickets/includes/Domain/Meta.php` (META_KEY_DOOR_PRIZES).
    - `_oras_attendee_notes_v1` — read/updated in `oras-tickets/includes/Bootstrap.php`.
  - Option keys (global/settings related to events): `oras_tickets_settings_v1` (settings page: `oras-tickets/includes/Admin/Pages/Settings_Page.php`).

2) Tickets
- Primary store: ticket definitions live inside event envelope `_oras_tickets_v1` (array rows).
  - Evidence:
    - `oras-tickets/includes/Domain/Ticket.php` and `oras-tickets/includes/Domain/Ticket_Collection.php` implement ticket row logic and parsing of `_oras_tickets_v1`.
  - Ticket ↔ Product mapping (commerce linkage): Woo product postmeta keys added/updated by Product_Sync:
    - Product meta keys: `_oras_ticket_event_id`, `_oras_ticket_index` — set in `oras-tickets/includes/Commerce/Woo/Product_Sync.php` (update_post_meta calls and checkout snapshot logic).
  - Order-item snapshot meta (set when creating order line items): `_oras_ticket_event_id`, `_oras_ticket_index`, `_oras_ticket_name`, `_oras_ticket_unit_price`, `_oras_ticket_currency`, `_oras_ticket_price_phase_key`, `_oras_ticket_price_phase_label`, `_oras_ticket_price_phase_price` — evidence: `Product_Sync::snapshot_order_item_ticket_meta` in `oras-tickets/includes/Commerce/Woo/Product_Sync.php`.

3) Attendees (how plugin models an attendee)
- There is no single normalized attendee table or dedicated `Attendee` class evident.
  - Evidence of NO normalized attendee entity: NO EVIDENCE FOUND for a dedicated `Attendee` table/class; attendee rows are derived at read-time from multiple surfaces.
- Surfaces that collectively define an attendee read-model:
  - Ticket-attendees (paid): derived from Woo orders/order-items using `_oras_ticket_*` order-item meta and order IDs. Evidence: `oras-tickets/includes/Frontend/Ticket_Print_Controller.php` (reads order-item meta), `oras-tickets/includes/Admin/Reports_Aggregator.php`, `oras-tickets/includes/Commerce/Woo/Product_Sync.php`.
  - RSVP attendees (YES): stored per-event in usermeta keys named `_oras_rsvp_event_<event_id>` and `_oras_rsvp_event_<event_id>_ts`. Evidence: `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php` (reads `get_post_meta($event_id, '_oras_rsvp_v1', true)` and writes usermeta), and tooling in `tools/phase5-integration-checks.php` referencing these keys.
  - Waitlist entries: stored in a normalized custom table `{$wpdb->prefix}oras_ticket_waitlist`. Evidence: `oras-tickets/includes/Waitlist_Store.php` (table_name() returns `$wpdb->prefix . 'oras_ticket_waitlist'`; install_schema creates the table).
  - Attendee notes: event postmeta `_oras_attendee_notes_v1` (read/updated in `oras-tickets/includes/Bootstrap.php`).
  - Attendee dashboard aggregation: `Bootstrap::get_filtered_attendees()` composes attendees from the above sources (`oras-tickets/includes/Bootstrap.php`), and `Event_RSVP_Attendees_Metabox::get_attendees()` provides admin listing (`includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`).

4) RSVP / Waitlist
- RSVP settings (event-scoped): key `_oras_rsvp_v1` stored in postmeta. Evidence: `includes/Admin/Metaboxes/Event_RSVP_Metabox.php` (META_KEY), `includes/Api/Rsvp.php` (reads meta), `tools/phase5-integration-checks.php` (test scripts referencing `_oras_rsvp_v1`).
- Per-user RSVP YES state: usermeta keys of the form `_oras_rsvp_event_<event_id>` and timestamp suffix. Evidence: `includes/Frontend/Event_RSVP.php` (writes/reads usermeta), `includes/Api/Rsvp.php`.
- Waitlist (FIFO, audited): normalized table `wp_oras_ticket_waitlist`. Evidence:
  - `oras-tickets/includes/Waitlist_Store.php` class with methods `mark_waiting`, `mark_promoted`, `remove_waiting`, `get_waiting_users`, `count_waiting`, `promote_next_waiting`, and `table_name()` returns `$wpdb->prefix . 'oras_ticket_waitlist'`.
  - `oras-tickets/includes/Bootstrap.php` and admin/dashboard pages interact with `Waitlist_Store` (calls to `Waitlist_Store::get_waiting_users`, `->promote_user`, `->bulk_promote_waiting`).
- Ticket-tier dimension in waitlist: NO EVIDENCE FOUND — `Waitlist_Store` records are keyed by event and user (`event_user` unique key), and the schema and methods do not include `ticket_index` or `ticket_key` by default. Evidence: inspection of `Waitlist_Store.php` methods and tests in `tools/phase5-integration-checks.php` showing waitlist operations operate at event-level.

5) WooCommerce order + order-item linkage
- Ticket → product mapping: product postmeta `_oras_ticket_event_id` and `_oras_ticket_index` are set on hidden Woo products representing ticket rows. Evidence: `Product_Sync.php` (`update_post_meta($pid, '_oras_ticket_event_id', (int) $post_id)` and `_oras_ticket_index`).
- Order-item snapshots: when line items are created, `snapshot_order_item_ticket_meta` adds `_oras_ticket_*` meta to order items. Evidence: `add_action('woocommerce_checkout_create_order_line_item', array($this, 'snapshot_order_item_ticket_meta'), 10, 4)` and the method body in `oras-tickets/includes/Commerce/Woo/Product_Sync.php` which uses `$item->add_meta_data(... '_oras_ticket_event_id', ...)`.
- Order-level markers and capacity flags: order meta `_oras_capacity_consumed` (set/read by `Capacity_Consumption` class to avoid double-counting) and `_oras_autocompleted` (used by Order_Autocomplete). Evidence: `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` and `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php` (`$order->update_meta_data('_oras_capacity_consumed','1')`).
- QuickBooks linkage uses order items: QuickBooks sync code reads order-item meta `_oras_ticket_event_id` etc. Evidence: `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php` and `Split_Calculator.php` (accesses `$item->get_meta('_oras_ticket_event_id', true)`).

6) Pricing and capacity data surfaces
- Pricing snapshots (immutable at purchase): order-item meta set at checkout — `_oras_ticket_unit_price`, `_oras_ticket_currency`, and `_oras_ticket_price_phase_*` keys. Evidence: `Product_Sync::snapshot_order_item_ticket_meta` in `oras-tickets/includes/Commerce/Woo/Product_Sync.php`.
- Ticket-level capacity: capacity is stored on ticket row inside `_oras_tickets_v1` (ticket data parsed by `Domain/Ticket.php`) and mirrored to Woo product stock by Product_Sync. Evidence: `oras-tickets/includes/Domain/Ticket.php` and `oras-tickets/includes/Commerce/Woo/Product_Sync.php` (stock sync), and capacity consumption logic in `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` (reads product meta and marks order meta `_oras_capacity_consumed`).

7) Relationships (evidence-cited)
- event → tickets:
  - Event postmeta `_oras_tickets_v1` contains ticket rows. Evidence: `includes/Domain/Ticket_Collection.php`, `includes/Domain/Ticket.php`.
- ticket → product:
  - Each ticket row maps to a Woo product post which stores `_oras_ticket_event_id` and `_oras_ticket_index`. Evidence: `includes/Commerce/Woo/Product_Sync.php` (`update_post_meta` calls and mapping logic).
- product → order_item (snapshot):
  - At checkout, order-item meta `_oras_ticket_*` is recorded (snapshot). Evidence: `includes/Commerce/Woo/Product_Sync.php` (`snapshot_order_item_ticket_meta`) and `add_action('woocommerce_checkout_create_order_line_item', ...)` registration.
- order_item → attendee (paid attendee):
  - Attendee rows for paid tickets are derived by scanning orders/order-items and reading `_oras_ticket_*` meta, producing `order_id`, `user_id`, `name`, `email`, `order_status`. Evidence: `includes/Bootstrap.php` (`get_ticket_attendees_for_event()`), `includes/Frontend/Ticket_Print_Controller.php` (reads order-item meta), `tools/phase5-integration-checks.php` (test assertions linking attendee rows to `order_id`).
- RSVP/waitlist attendee linkage:
  - RSVP YES entries produce usermeta `_oras_rsvp_event_<id>`; waitlist entries are rows in `wp_oras_ticket_waitlist`. Admin/dashboard code composes attendee lists by merging RSVP attendees, ticket-attendees, and notes: `Bootstrap::get_filtered_attendees()` uses `Waitlist_Store` and usermeta to build a unified list (file: `oras-tickets/includes/Bootstrap.php`).

8) BLOCKER statement (evidence-only)
- Attendance state is duplicated across multiple stores and read paths, creating a technical BLOCKER for having a single authoritative attendance source. Evidence:
  - RSVP usermeta: `oras-tickets/includes/Frontend/Event_RSVP.php` (writes usermeta `_oras_rsvp_event_<id>`).
  - Waitlist table: `oras-tickets/includes/Waitlist_Store.php` (normalized table `wp_oras_ticket_waitlist`).
  - Attendee notes: event postmeta `_oras_attendee_notes_v1` (`oras-tickets/includes/Bootstrap.php`).
  - Admin/dashboard attendee composition: `oras-tickets/includes/Bootstrap.php` (methods `get_filtered_attendees`, `get_ticket_attendees_for_event`) and `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php` (`get_attendees`).
  - Reports/exports rely on order-item snapshots and the admin aggregator: `oras-tickets/includes/Admin/Reports_Aggregator.php`.

9) Explicit NO EVIDENCE FOUND statements
- Normalized `Attendee` table/class: NO EVIDENCE FOUND (no dedicated attendees DB table or namespaced `Attendee` class discovered).
- Ticket-tier-specific waitlist records in `Waitlist_Store` schema: NO EVIDENCE FOUND (schema and methods operate at event-user level; no `ticket_index` column read/written in primary methods).
- Enforced `open_at`/`close_at` runtime enforcement for RSVP in `Event_RSVP` handler (strict enforcement): evidence shows `open_at`/`close_at` are stored in `_oras_rsvp_v1` but runtime enforcement is not present or inconsistent — treated as metadata without strict runtime gating (see `includes/Admin/Metaboxes/Event_RSVP_Metabox.php` and `includes/Frontend/Event_RSVP.php`).

10) Minimal citations (quick reference)
- Meta keys: `_oras_tickets_v1`, `_oras_rsvp_v1`, `_oras_agenda_v1`, `_oras_speakers_v1`, `_oras_door_prizes_v1`, `_oras_attendee_notes_v1`, `_oras_virtual_access_v1`, `_oras_ticket_event_id`, `_oras_ticket_index`, `_oras_ticket_name`, `_oras_ticket_unit_price`, `_oras_ticket_currency`, `_oras_ticket_price_phase_key`, `_oras_ticket_price_phase_label`, `_oras_ticket_price_phase_price`, `_oras_capacity_consumed`, `_oras_autocompleted`, usermeta `_oras_rsvp_event_<event_id>`.
- Option keys: `oras_tickets_settings_v1`, `oras_tickets_waitlist_schema_version`, `oras_tickets_board_login_daily_v1`, `oras_tickets_speaker_notify_emails` (evidence in settings and code paths).
- Tables: `{$wpdb->prefix}oras_ticket_waitlist` (returned by `Waitlist_Store::table_name()` in `oras-tickets/includes/Waitlist_Store.php`).
- Primary files / classes / functions referenced:
  - `oras-tickets/includes/Domain/Meta.php` (meta constants)
  - `oras-tickets/includes/Domain/Ticket.php`, `Ticket_Collection.php` (ticket row parsing)
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php` (`snapshot_order_item_ticket_meta`, product mapping)
  - `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` (capacity consumption, `_oras_capacity_consumed`)
  - `oras-tickets/includes/Frontend/Event_RSVP.php` (RSVP handler, usermeta writes)
  - `oras-tickets/includes/Waitlist_Store.php` (waitlist table and APIs)
  - `oras-tickets/includes/Bootstrap.php` (`get_filtered_attendees`, attendees dashboard handlers)
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php` (admin attendee listing)
  - `oras-tickets/includes/Api/Rsvp.php` (REST RSVP endpoints)
  - `oras-tickets/includes/Frontend/Ticket_Print_Controller.php` (reads order-item snapshots for print)
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php` (reads order-item meta)

---

End of PASS 3 evidence-only data model evaluation. File contains only data-structure evidence and relationships; no recommendations except the explicit BLOCKER statement above.

