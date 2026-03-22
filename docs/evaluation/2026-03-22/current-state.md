# CURRENT STATE

## Authority
- Runtime truth for this report is the code in `oras-tickets/`.
- Roadmap truth used to frame the current-state summary: `docs/CURRENT_STATE.md`, `docs/MASTER_EXECUTION_TRACKER.md`, `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`, and `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`.
- Locked phase docs were identifiable with sufficient certainty for Phase 0-5 governance state:
  - `docs/PHASE0_5_LOCK_REVIEW_PACKET_2026-03-02.md`
  - `docs/PHASE_COMPLETION_SWEEP_2026-03-02.md`
  - `docs/PHASE5_OPERATOR_SOAK_2026-03-02.md`
  - `docs/PHASE53_PRELIVE_PACKET_2026-03-02.md`

## Architecture Boundaries

### Core domain
- Event-owned ticket definitions live in the versioned post-meta envelope `ORAS\Tickets\Domain\Meta::META_KEY_TICKETS` / `_oras_tickets_v1`; the aggregate and value object are `ORAS\Tickets\Domain\Ticket_Collection` and `ORAS\Tickets\Domain\Ticket` in `oras-tickets/includes/Domain/Ticket_Collection.php`, `oras-tickets/includes/Domain/Ticket.php`, and `oras-tickets/includes/Domain/Meta.php`.
- Ticket price-phase resolution lives in `ORAS\Tickets\Domain\Pricing\Price_Resolver` in `oras-tickets/includes/Domain/Pricing/Price_Resolver.php`.
- RSVP settings live in `_oras_rsvp_v1`, per-user RSVP state lives in usermeta `_oras_rsvp_event_<event_id>`, and waitlist lifecycle lives in the custom table created by `ORAS\Tickets\Waitlist_Store` in `oras-tickets/includes/Waitlist_Store.php`.
- Concurrency control for event/order mutations is centralized in `ORAS\Tickets\Support\DbLock` in `oras-tickets/includes/Support/DbLock.php`.

### Commerce layer
- Woo product mapping is created and maintained by `ORAS\Tickets\Commerce\Woo\Product_Sync` via `save_post_tribe_events` and `woocommerce_checkout_create_order_line_item` in `oras-tickets/includes/Commerce/Woo/Product_Sync.php`.
- Cart-time price resolution and hold expiration are enforced by `ORAS\Tickets\Frontend\Tickets_Display` via `woocommerce_add_to_cart_validation`, `woocommerce_add_cart_item_data`, `woocommerce_check_cart_items`, `woocommerce_before_checkout_process`, and `woocommerce_checkout_process` in `oras-tickets/includes/Frontend/Tickets_Display.php`.
- Capacity mutation is tied to Woo order-status transitions in `ORAS\Tickets\Commerce\Woo\Capacity_Consumption` via `woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_order_status_cancelled`, and `woocommerce_order_status_refunded` in `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`.
- Ticket-only auto-complete lives in `ORAS\Tickets\Commerce\Woo\Order_Autocomplete` via `woocommerce_order_status_processing` and `woocommerce_payment_complete` in `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php`.
- Stripe-specific description metadata is injected by `ORAS\Tickets\Commerce\Woo\Stripe_Intent_Description` via `wc_stripe_generate_create_intent_request` in `oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php`.

### Admin operations
- Admin entrypoints are registered from `ORAS\Tickets\Admin\Admin_Menu` in `oras-tickets/includes/Admin/Admin_Menu.php`.
- The event editor uses one unified add-on panel from `ORAS\Tickets\Admin\Event_Addon_Metabox`, which embeds `Tickets_Metabox`, `Event_Agenda_Metabox`, `Event_RSVP_Metabox`, `Event_Speakers_Metabox`, and `Event_Door_Prizes_Metabox` in `oras-tickets/includes/Admin/Event_Addon_Metabox.php`.
- Dashboard/operator flows are split across `Dashboard_Page`, `Reports_Page`, `Settings_Page`, `Speaker_Obligations_Page`, `Speaker_Reports_Page`, and `CheckinPage` in `oras-tickets/includes/Admin/Pages/`.
- A large amount of admin AJAX/admin-post behavior is still implemented directly on `ORAS\Tickets\Bootstrap` in `oras-tickets/includes/Bootstrap.php`.

### Frontend rendering
- Ticket sales UI is injected into event content by `ORAS\Tickets\Frontend\Tickets_Display::the_content_filter()` via `the_content` in `oras-tickets/includes/Frontend/Tickets_Display.php`.
- Agenda UI is appended by `ORAS\Tickets\Frontend\Event_Agenda_Render::append_to_content()` via `the_content` in `oras-tickets/includes/Frontend/Event_Agenda_Render.php`.
- RSVP UI is appended by `ORAS\Tickets\Frontend\Event_RSVP::render_rsvp_block()` via `the_content` in `oras-tickets/includes/Frontend/Event_RSVP.php`.
- Door prizes are appended by `ORAS\Tickets\Frontend\Door_Prizes::append_to_content()` via `the_content` in `oras-tickets/includes/Frontend/Door_Prizes.php`.
- Ticket print routing is handled by `ORAS\Tickets\Frontend\Ticket_Print_Controller` through `query_vars`, a rewrite rule for `/oras-ticket/print`, and `template_redirect` in `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`.
- Virtual event access is enforced by `ORAS\Tickets\Frontend\Virtual_Access` through multiple `tribe_template_pre_html:*` filters plus `tribe_events_virtual_show_virtual_content` in `oras-tickets/includes/Frontend/Virtual_Access.php`.
- The board dashboard is a shortcode surface registered by `ORAS\Tickets\Frontend\Board_Dashboard` with `[oras_board_dashboard]` in `oras-tickets/includes/Frontend/Board_Dashboard.php`.

### API / REST
- Member Hub ticket routes are registered by `ORAS\Tickets\Api\Member_Hub_Tickets`:
  - `oras-tickets/v1/me/tickets`
  - `oras-tickets/v1/me/tickets/summary`
  in `oras-tickets/includes/Api/Member_Hub_Tickets.php`.
- RSVP routes are registered by `ORAS\Tickets\Api\Rsvp`:
  - `oras/v1/rsvp/my`
  - `oras/v1/rsvp/event/(?P<id>\d+)`
  in `oras-tickets/includes/Api/Rsvp.php`.
- Check-in routes are registered by `ORAS\Tickets\Api\Checkin`:
  - `oras-tickets/v1/checkin/verify`
  - `oras-tickets/v1/checkin/mark`
  - `oras-tickets/v1/checkin/unmark`
  in `oras-tickets/includes/Api/Checkin.php`.

### Reporting
- Event sales reporting is rendered by `ORAS\Tickets\Admin\Pages\Reports_Page` and computed by `ORAS\Tickets\Admin\Reports_Aggregator` in `oras-tickets/includes/Admin/Pages/Reports_Page.php` and `oras-tickets/includes/Admin/Reports_Aggregator.php`.
- Speaker obligation/reporting surfaces live in `Speaker_Obligations_Page` and `Speaker_Reports_Page` in `oras-tickets/includes/Admin/Pages/Speaker_Obligations_Page.php` and `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`.
- Board-facing executive reporting is computed on the frontend by `ORAS\Tickets\Frontend\Board_Dashboard` in `oras-tickets/includes/Frontend/Board_Dashboard.php`.

### QuickBooks adapter layer
- QuickBooks integration is bounded under `oras-tickets/src/Integrations/QuickBooks/` and registered from `ORAS\Tickets\Integrations\QuickBooks\Module` in `oras-tickets/src/Integrations/QuickBooks/Module.php`.
- Storage for QuickBooks settings stays inside the shared option `oras_tickets_settings_v1` through `ORAS\Tickets\Integrations\QuickBooks\Settings` in `oras-tickets/src/Integrations/QuickBooks/Settings.php`.
- Runtime work is delegated to `OAuth_Client`, `Api_Client`, `Split_Calculator`, `Journal_Entry_Creator`, `Retry_Handler`, `Sync_Orchestrator`, and `Cli_Command` in `oras-tickets/src/Integrations/QuickBooks/`.

## Textual Architecture Diagram
```text
oras-tickets/oras-tickets.php
  -> plugins_loaded
    -> ORAS\Tickets\Bootstrap::init()
      -> dependency guard for TEC + Woo
      -> init hook -> Bootstrap::register_phase1()
        -> Core domain loaded from includes/Domain/*
        -> Woo module registration
          -> Product_Sync
          -> Cart_Pricing
          -> Capacity_Consumption
          -> Order_Autocomplete
          -> Stripe_Intent_Description
        -> REST registration
          -> Member_Hub_Tickets
          -> Rsvp
          -> Checkin
        -> Frontend registration
          -> Tickets_Display
          -> Ticket_Print_Controller
          -> Event_Agenda_Render
          -> Event_RSVP
          -> Virtual_Access
          -> Door_Prizes
          -> Board_Dashboard
        -> Admin registration
          -> Event_Addon_Metabox + embedded feature metaboxes
          -> Admin_Menu + Dashboard/Reports/Settings/Speaker/Checkin pages
          -> AJAX/admin-post handlers on Bootstrap
        -> Integration registration
          -> QuickBooks Module

Data stores
  -> Event post meta: _oras_tickets_v1, _oras_rsvp_v1, _oras_agenda_v1, _oras_speakers_v1, _oras_door_prizes_v1, _oras_attendee_notes_v1, _oras_virtual_access_v1
  -> User meta: _oras_rsvp_event_<event_id>, _oras_rsvp_event_<event_id>_ts
  -> Woo product/order/item meta: _oras_tickets_woo_map_v1, _oras_ticket_event_id, _oras_ticket_index, _oras_ticket_name, _oras_ticket_unit_price, _oras_ticket_price_phase_*, _oras_capacity_*, _oras_autocompleted, _oras_checkin_units_v1
  -> Custom DB table: wp_oras_ticket_waitlist
  -> Options: oras_tickets_settings_v1, oras_tickets_waitlist_schema_version, oras_tickets_board_login_daily_v1, oras_tickets_speaker_notify_emails
  -> QBO order meta: _oras_qbo_*
```

## Custom Post Types, Taxonomies, Meta, Tables, and Options

### CPTs and taxonomies
- Registered CPTs:
  - `oras_speaker` via `ORAS\Tickets\Admin\Speaker_CPT::register_post_type()` in `oras-tickets/includes/Admin/Speaker_CPT.php`
- Registered taxonomies:
  - No plugin-owned taxonomy registration was found in `oras-tickets/includes/` or `oras-tickets/src/`.

### Event-owned envelopes
| Store | Purpose | Evidence |
|---|---|---|
| `_oras_tickets_v1` | Ticket definitions per event | `oras-tickets/includes/Domain/Meta.php`, `oras-tickets/includes/Domain/Ticket_Collection.php` |
| `_oras_tickets_woo_map_v1` | Event ticket index to Woo product map | `oras-tickets/includes/Commerce/Woo/Product_Sync.php`, `oras-tickets/includes/Admin/Pages/Dashboard_Page.php` |
| `_oras_rsvp_v1` | RSVP settings envelope | `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`, `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php` |
| `_oras_agenda_v1` | Agenda days/settings/resources | `oras-tickets/includes/Admin/Metaboxes/Event_Agenda_Metabox.php`, `oras-tickets/includes/Frontend/Event_Agenda_Render.php` |
| `_oras_speakers_v1` | Speaker assignment envelope on events | `oras-tickets/includes/Admin/Event_Speakers_Metabox.php`, `oras-tickets/includes/Admin/Speaker_CPT.php`, `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php` |
| `_oras_door_prizes_v1` | Door prize envelope | `oras-tickets/includes/Domain/Meta.php`, `oras-tickets/includes/Admin/Metaboxes/Event_Door_Prizes_Metabox.php`, `oras-tickets/includes/Frontend/Door_Prizes.php` |
| `_oras_attendee_notes_v1` | Per-event attendee notes | `oras-tickets/includes/Bootstrap.php` |
| `_oras_virtual_access_v1` | Virtual-content visibility envelope | `oras-tickets/includes/Frontend/Virtual_Access.php` |

### Speaker CPT meta
| Meta key | Purpose | Evidence |
|---|---|---|
| `_oras_speaker_email` | Speaker contact | `oras-tickets/includes/Admin/Speaker_CPT.php` |
| `_oras_speaker_affiliation` | Speaker affiliation | `oras-tickets/includes/Admin/Speaker_CPT.php` |
| `_oras_speaker_website_url` | Speaker website | `oras-tickets/includes/Admin/Speaker_CPT.php` |
| `_oras_speaker_wp_user_id` | Linked WP user | `oras-tickets/includes/Admin/Speaker_CPT.php`, `oras-tickets/includes/Admin/Pages/Speaker_Obligations_Page.php` |
| `_oras_speaker_status` | Active/inactive flag | `oras-tickets/includes/Admin/Speaker_CPT.php` |
| `_oras_speaker_internal_notes` | Internal notes | `oras-tickets/includes/Admin/Speaker_CPT.php` |
| `_oras_speaker_headshot_id` | Headshot attachment | `oras-tickets/includes/Admin/Speaker_CPT.php`, `oras-tickets/includes/Frontend/Event_Agenda_Render.php` |

### Attendance and commerce meta
| Store | Purpose | Evidence |
|---|---|---|
| `_oras_rsvp_event_<event_id>` | Per-user RSVP state | `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Api/Rsvp.php`, `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php` |
| `_oras_rsvp_event_<event_id>_ts` | Waitlist join timestamp in usermeta | `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Bootstrap.php` |
| `wp_oras_ticket_waitlist` | Waitlist lifecycle/audit table | `oras-tickets/includes/Waitlist_Store.php` |
| `_oras_ticket_event_id` / `_oras_ticket_index` | Woo product/order-item linkage to event ticket row | `oras-tickets/includes/Commerce/Woo/Product_Sync.php`, `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`, `oras-tickets/includes/Admin/Reports_Aggregator.php`, `oras-tickets/includes/Frontend/Ticket_Print_Controller.php` |
| `_oras_ticket_name`, `_oras_ticket_unit_price`, `_oras_ticket_currency`, `_oras_ticket_price_phase_*` | Immutable order-item reporting/print/QBO snapshot | `oras-tickets/includes/Commerce/Woo/Product_Sync.php`, `oras-tickets/includes/Admin/Reports_Aggregator.php`, `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`, `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php` |
| `_oras_capacity_consumed` / `_oras_capacity_restored` | Order-level capacity idempotency flags | `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` |
| `_oras_autocompleted` | Ticket-only order auto-complete marker | `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php` |
| `_oras_checkin_units_v1` | Per-order-item check-in state | `oras-tickets/includes/Security/Ticket_Checkin_Token.php` |

### Options
| Option key | Purpose | Evidence |
|---|---|---|
| `oras_tickets_settings_v1` | Plugin settings + QuickBooks branch | `oras-tickets/includes/Admin/Pages/Settings_Page.php`, `oras-tickets/src/Integrations/QuickBooks/Settings.php` |
| `oras_tickets_waitlist_schema_version` | Waitlist table schema version | `oras-tickets/includes/Waitlist_Store.php` |
| `oras_tickets_board_login_daily_v1` | Board dashboard activity counters | `oras-tickets/includes/Frontend/Board_Dashboard.php` |
| `oras_tickets_speaker_notify_emails` | Speaker obligations notification recipients | `oras-tickets/includes/Admin/Pages/Speaker_Obligations_Page.php` |

## Data Flow

### Ticket authoring to Woo sale
- Event editing writes `_oras_tickets_v1` through `Tickets_Metabox::save_post()` and `Ticket_Collection::save_for_event()` in `oras-tickets/includes/Admin/Tickets_Metabox.php` and `oras-tickets/includes/Domain/Ticket_Collection.php`.
- `Product_Sync::on_save_event()` reads `_oras_tickets_v1`, creates/updates hidden `WC_Product_Simple` products, and persists `_oras_tickets_woo_map_v1`, `_oras_ticket_event_id`, and `_oras_ticket_index` in `oras-tickets/includes/Commerce/Woo/Product_Sync.php`.
- `Tickets_Display` reads ticket envelopes and Woo product meta to render sales UI, validate add-to-cart, stamp `_oras_hold_started_at`, and revalidate cart contents in `oras-tickets/includes/Frontend/Tickets_Display.php`.

### Woo checkout to reporting / print / QBO
- `Product_Sync::snapshot_order_item_ticket_meta()` snapshots order-item metadata during `woocommerce_checkout_create_order_line_item` in `oras-tickets/includes/Commerce/Woo/Product_Sync.php`.
- `Capacity_Consumption` mutates event ticket capacity on paid/cancelled/refunded order transitions in `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`.
- `Order_Autocomplete` auto-completes qualifying orders in `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php`.
- `Reports_Aggregator`, `Ticket_Print_Controller`, `Member_Hub_Tickets`, `Board_Dashboard`, and QuickBooks classes all consume the same `_oras_ticket_*` order-item snapshot metadata in:
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`
  - `oras-tickets/includes/Api/Member_Hub_Tickets.php`
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`

### RSVP / waitlist lifecycle
- RSVP configuration is edited in `Event_RSVP_Metabox::save()` and rendered in `Event_RSVP::render_rsvp_block()` in `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php` and `oras-tickets/includes/Frontend/Event_RSVP.php`.
- RSVP writes happen in `Event_RSVP::handle_post()`, which updates usermeta and `Waitlist_Store` under `DbLock::forEvent()` in `oras-tickets/includes/Frontend/Event_RSVP.php`, `oras-tickets/includes/Waitlist_Store.php`, and `oras-tickets/includes/Support/DbLock.php`.
- Admin queue operations happen through `Bootstrap::handle_waitlist_*` and `Event_RSVP_Attendees_Metabox::handle_promote()` in `oras-tickets/includes/Bootstrap.php` and `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`.
- API read surfaces consume the same stores in `oras-tickets/includes/Api/Rsvp.php`.

## Admin vs Frontend Responsibilities
- Admin-only responsibilities are concentrated in event editing, reports, settings, speaker operations, check-in UI, RSVP dashboard exports, queue promotion/removal, and attendee messaging in:
  - `oras-tickets/includes/Admin/*`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/src/Integrations/QuickBooks/Module.php`
- Frontend responsibilities are concentrated in content rendering, checkout validation, print rendering, virtual-access filtering, RSVP form handling, and Member Hub/board shortcode/API read models in:
  - `oras-tickets/includes/Frontend/*`
  - `oras-tickets/includes/Api/*`
- The boundary is not clean for attendance operations because `Bootstrap` still owns attendee AJAX, RSVP dashboard JSON, CSV exports, and outbound email logic in `oras-tickets/includes/Bootstrap.php`.

## Global State and Tight Coupling
- `ORAS\Tickets\Bootstrap` is a central service locator and handler container. It directly `require_once`s most modules, constructs services, and contains admin AJAX/admin-post business logic in `oras-tickets/includes/Bootstrap.php`.
- Static singletons and static guards are used in several runtime paths:
  - `Bootstrap::$instance` in `oras-tickets/includes/Bootstrap.php`
  - `Tickets_Display::$instance` in `oras-tickets/includes/Frontend/Tickets_Display.php`
  - `Ticket_Print_Controller::$instance` in `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`
  - `Product_Sync::$running` in `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `Event_RSVP` static methods in `oras-tickets/includes/Frontend/Event_RSVP.php`
- Frontend composition depends on `the_content` filter ordering:
  - agenda at priority `20` in `Event_Agenda_Render`
  - tickets at priority `20` in `Tickets_Display`
  - RSVP at priority `21` in `Event_RSVP`
  - door prizes at priority `30` in `Door_Prizes`
  This creates ordering coupling across separate modules in:
  - `oras-tickets/includes/Frontend/Event_Agenda_Render.php`
  - `oras-tickets/includes/Frontend/Tickets_Display.php`
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Frontend/Door_Prizes.php`

## Code Smells, Technical Debt, Best-Practice Drift, Security Risks, and Performance Concerns

### Code smells / technical debt
- `ORAS\Tickets\Bootstrap` combines bootstrap wiring, RSVP queue logic, attendee querying, attendee note persistence, CSV export routing, and outbound email sending in one class; evidence: `register_phase1()`, `handle_rsvp_dashboard_data()`, `handle_waitlist_*()`, `handle_attendees_dashboard_data()`, `handle_attendees_send_email()`, and `handle_attendees_save_note()` in `oras-tickets/includes/Bootstrap.php`.
- QuickBooks settings storage has split ownership between the general admin settings page and the integration adapter:
  - `ORAS\Tickets\Admin\Pages\Settings_Page::sanitize_settings()` and `get_default_settings()` in `oras-tickets/includes/Admin/Pages/Settings_Page.php`
  - `ORAS\Tickets\Integrations\QuickBooks\Settings` in `oras-tickets/src/Integrations/QuickBooks/Settings.php`
- Speaker access control is partly menu-level only. `Capabilities::CAPS` defines `oras_tickets_manage_speakers`, but `Speaker_CPT::register_post_type()` uses `capability_type => 'post'`, not custom post-type capabilities, in `oras-tickets/includes/Capabilities.php` and `oras-tickets/includes/Admin/Speaker_CPT.php`.

### WordPress best-practice drift
- The plugin still relies on manual `require_once` loading instead of autoloaded modules across `oras-tickets/oras-tickets.php`, `oras-tickets/includes/Bootstrap.php`, and `oras-tickets/includes/Admin/Admin_Menu.php`.
- Large inline `<style>` blocks are embedded directly in admin/frontend render methods instead of dedicated assets in:
  - `oras-tickets/includes/Admin/Pages/Reports_Page.php`
  - `oras-tickets/includes/Admin/Pages/Settings_Page.php`
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`

### Security and capability findings
- `Event_RSVP_Attendees_Metabox::handle_export()` and `Event_RSVP_Attendees_Metabox::handle_promote()` gate with `current_user_can( 'edit_posts' )`, not the plugin’s attendee/RSVP capabilities. Evidence:
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Capabilities.php`
- Speaker-management entrypoints use the custom `oras_tickets_manage_speakers` capability at page level, but speaker post editing still inherits the generic post capability model because `Speaker_CPT` uses `capability_type => 'post'`. Evidence:
  - `oras-tickets/includes/Admin/Admin_Menu.php`
  - `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`
  - `oras-tickets/includes/Admin/Pages/Speaker_Obligations_Page.php`
  - `oras-tickets/includes/Admin/Speaker_CPT.php`
- Check-in REST endpoints are correctly capability-gated with `oras_tickets_checkin`, and the admin page is nonce-protected. Evidence:
  - `oras-tickets/includes/Api/Checkin.php`
  - `oras-tickets/includes/Admin/Pages/Checkin_Page.php`
  - `oras-tickets/includes/Capabilities.php`

### Performance and determinism concerns
- `Door_Prizes::resolve_thumbnail_url()` performs `wp_safe_remote_get()` during frontend rendering when `image_url` is empty and `external_link` is set. This adds network I/O to page render and weakens deterministic/self-hosted behavior. Evidence: `oras-tickets/includes/Frontend/Door_Prizes.php`.
- `Board_Dashboard::render_shortcode()` builds many live aggregates in one frontend request by calling `build_woo_cashflow_summary()`, `build_pmpro_cashflow_summary()`, `resolve_financials()`, `build_pmpro_lifecycle_summary()`, `build_website_activity_summary()`, `build_operations_health_summary()`, `build_waitlist_conversion_summary()`, and `build_engagement_funnel_summary()` in `oras-tickets/includes/Frontend/Board_Dashboard.php`.
- RSVP counts are computed repeatedly with `get_users()` / `WP_User_Query` against dynamic usermeta keys in:
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Api/Rsvp.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  This is acceptable for small volume but not a normalized read model.

### Runtime inconsistencies with implementation impact
- RSVP window fields `open_at` and `close_at` are captured and persisted in `Event_RSVP_Metabox::save()`, but no runtime enforcement path was found in `Event_RSVP`, `Bootstrap`, or `Api\Rsvp`. Evidence:
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Metabox.php`
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Api/Rsvp.php`
- Ticket sale-window timezone handling is internally inconsistent. `Ticket` documents `sale_start` / `sale_end` as site-timezone values in `oras-tickets/includes/Domain/Ticket.php`, the admin UI captures them with `datetime-local` in `oras-tickets/includes/Admin/Tickets_Metabox.php`, but `Tickets_Display` validates them with `strtotime( $sale_start . ' UTC' )` / `strtotime( $sale_end . ' UTC' )` in `oras-tickets/includes/Frontend/Tickets_Display.php`.
