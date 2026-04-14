---
CURRENT STATE — PASS 1 (exact evidence)

This document is the PASS 1 output required by docs/evaluation/2026-03-22/EVAL_CONTROLLER.md.
It records exact code evidence for the current runtime shape of the plugin. No recommendations
or roadmap analysis are included — evidence only.

1) Plugin bootstrap path
- File: oras-tickets/oras-tickets.php
  - Evidence:
    - Registers `plugins_loaded` handler that calls `Bootstrap::instance()->init()`:
      `add_action('plugins_loaded', static function () { Bootstrap::instance()->init(); }, 20)` (oras-tickets/oras-tickets.php).
    - Registers activation hook calling `\ORAS\Tickets\Capabilities::add_caps()` and `Waitlist_Store::install_schema()`:
      `register_activation_hook(ORAS_TICKETS_FILE, static function (): void { \ORAS\Tickets\Capabilities::add_caps(); Waitlist_Store::install_schema(); })` (oras-tickets/oras-tickets.php).

- Bootstrap class (bootstrap wiring)
  - File: oras-tickets/includes/Bootstrap.php
  - Evidence:
    - `final class Bootstrap` declared (oras-tickets/includes/Bootstrap.php).
    - `require_once` of many modules inside Bootstrap, e.g. `includes/Admin/Metaboxes/Event_RSVP_Metabox.php`,
      `includes/Frontend/Tickets_Display.php`, `includes/Frontend/Ticket_Print_Controller.php`,
      `includes/Frontend/Virtual_Access.php`, `includes/Frontend/Event_RSVP.php`, `includes/Waitlist_Store.php`,
      `includes/Api/Member_Hub_Tickets.php`, `includes/Api/Checkin.php` (oras-tickets/includes/Bootstrap.php lines with require_once).
    - Registers init hook to call `register_phase1`: `add_action('init', array($this, 'register_phase1'), 20)` (oras-tickets/includes/Bootstrap.php).

2) Modules / services (file path + class + registration hook/evidence)
- Commerce / Product Sync
  - File: oras-tickets/includes/Commerce/Woo/Product_Sync.php
  - Class: `ORAS\Tickets\Commerce\Woo\Product_Sync` (final class Product_Sync)
  - Evidence:
    - `add_action('save_post_tribe_events', array($this, 'on_save_event'), 30, 3)` (Product_Sync.php).
    - `add_action('woocommerce_checkout_create_order_line_item', array($this, 'snapshot_order_item_ticket_meta'), 10, 4)` (Product_Sync.php).
    - Snapshot writes item meta keys: `_oras_ticket_event_id`, `_oras_ticket_index`, `_oras_ticket_name`, `_oras_ticket_unit_price`, `_oras_ticket_currency`, `_oras_ticket_price_phase_*` (Product_Sync.php).

- Cart pricing
  - File: oras-tickets/includes/Commerce/Woo/Cart_Pricing.php
  - Class: `ORAS\Tickets\Commerce\Woo\Cart_Pricing`
  - Evidence: `add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_cart_pricing'), 20, 1)` and usage of product meta `_oras_ticket_event_id`/_`oras_ticket_index` (Cart_Pricing.php).

- Capacity consumption
  - File: oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php
  - Class: `ORAS\Tickets\Commerce\Woo\Capacity_Consumption`
  - Evidence: hooks `woocommerce_order_status_processing`, `woocommerce_order_status_completed`,
    `woocommerce_order_status_cancelled`, `woocommerce_order_status_refunded` mapped to `handle_paid_order` / `handle_restore_order` (Capacity_Consumption.php) and uses order meta `_oras_capacity_consumed`.

- Order auto-complete
  - File: oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php
  - Class: `ORAS\Tickets\Commerce\Woo\Order_Autocomplete`
  - Evidence: hooks `woocommerce_order_status_processing` and `woocommerce_payment_complete` to `maybe_autocomplete`, sets order meta `_oras_autocompleted` (Order_Autocomplete.php).

- Stripe intent description
  - File: oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php
  - Class: `ORAS\Tickets\Commerce\Woo\Stripe_Intent_Description`
  - Evidence: hooks into `wc_stripe_generate_create_intent_request` and reads item meta `_oras_ticket_event_id`, `_oras_ticket_name` (Stripe_Intent_Description.php).

- QuickBooks integration (module + hooks)
  - Files: oras-tickets/src/Integrations/QuickBooks/Module.php and src/Integrations/QuickBooks/*
  - Evidence:
    - Module registers admin_post hooks: `admin_post_oras_tickets_qbo_oauth_start`, `admin_post_oras_tickets_qbo_oauth_callback`,
      `admin_post_oras_tickets_qbo_test_connection`, `admin_post_oras_tickets_qbo_test_journal_entry`, `admin_post_oras_tickets_qbo_process_waiting_queue`,
      `admin_post_oras_tickets_qbo_sync_order_now`, `admin_post_oras_tickets_qbo_approve_order`, `admin_post_oras_tickets_qbo_reverse_order`, `admin_post_oras_tickets_qbo_resync_order`, `admin_post_oras_tickets_qbo_auto_map_event_accounts` (src/Integrations/QuickBooks/Module.php lines adding add_action).
    - `Sync_Orchestrator` hooks `woocommerce_order_status_completed` to `enqueue_order_sync` and registers internal ACTION_HOOK for async processing (src/Integrations/QuickBooks/Sync_Orchestrator.php).

- Frontend renderers / controllers
  - Tickets display
    - File: oras-tickets/includes/Frontend/Tickets_Display.php
    - Class: `ORAS\Tickets\Frontend\Tickets_Display`
    - Evidence: `add_filter('the_content', array($this, 'the_content_filter'), 20)` and `add_action('template_redirect', array($this, 'handle_post'), 10)` (Tickets_Display.php).
  - Event Agenda render
    - File: oras-tickets/includes/Frontend/Event_Agenda_Render.php
    - Evidence: `add_filter('the_content', array(self::class, 'append_to_content'), 20)` (Event_Agenda_Render.php).
  - Event RSVP frontend
    - File: oras-tickets/includes/Frontend/Event_RSVP.php
    - Evidence: `add_filter('the_content', array(self::class, 'render_rsvp_block'), 21)` and `render_rsvp_block` (Event_RSVP.php).
  - Door prizes
    - File: oras-tickets/includes/Frontend/Door_Prizes.php
    - Evidence: `add_filter('the_content', array(self::class, 'append_to_content'), 30)` and `append_to_content` (Door_Prizes.php).
  - Ticket print controller
    - File: oras-tickets/includes/Frontend/Ticket_Print_Controller.php
    - Evidence: `add_filter('query_vars', array($this, 'register_query_vars'))`, `add_action('template_redirect', array($this, 'maybe_render_print_page'), 1)`, `add_rewrite_rule('^oras-ticket/print/?$', 'index.php?oras_ticket_print=1', 'top')` (Ticket_Print_Controller.php).

- Admin wiring and AJAX
  - File: oras-tickets/includes/Admin/Admin_Menu.php and includes/Admin/Pages/*
  - Evidence: `Admin_Menu::register` registers `admin_menu`, `admin_enqueue_scripts`, admin_post handlers (`admin_post_oras_tickets_export_csv`, `admin_post_oras_tickets_repair_caps`) and `add_menu_page` / `add_submenu_page` entries (Admin_Menu.php).

- Security / support services
  - File: oras-tickets/includes/Security/Ticket_Checkin_Token.php
  - Evidence: referenced by Checkin API (Ticket_Checkin_Token.php usage lines).

3) Custom Post Types (CPTs), taxonomies, meta keys (exact evidence)
- CPT: `oras_speaker`
  - File: oras-tickets/includes/Admin/Speaker_CPT.php
  - Evidence: `register_post_type` called with POST_TYPE = 'oras_speaker' in `Speaker_CPT::register_post_type()`; class constants exposing meta keys:
    - `_oras_speaker_email`, `_oras_speaker_affiliation`, `_oras_speaker_website_url`, `_oras_speaker_wp_user_id`, `_oras_speaker_status`, `_oras_speaker_internal_notes`, `_oras_speakers_v1` (Speaker_CPT.php).

- Tickets envelope meta key
  - File: oras-tickets/includes/Domain/Meta.php
  - Evidence: `public const META_KEY_TICKETS = '_oras_tickets_v1'` (Domain/Meta.php).

- Other event-owned envelopes / meta keys
  - `_oras_rsvp_v1` (Event RSVP envelope) — evidence: includes/Admin/Metaboxes/Event_RSVP_Metabox.php defines `META_KEY = '_oras_rsvp_v1'` and includes/Api/Rsvp.php reads `get_post_meta($event_id, '_oras_rsvp_v1', true)`.
  - `_oras_agenda_v1` — evidence: includes/Admin/Metaboxes/Event_Agenda_Metabox.php and includes/Frontend/Event_Agenda_Render.php.
  - `_oras_speakers_v1` — evidence: Event_Speakers_Metabox and templates reference `_oras_speakers_v1`.
  - `_oras_door_prizes_v1` — evidence: includes/Domain/Meta.php `META_KEY_DOOR_PRIZES = '_oras_door_prizes_v1'` and Door_Prizes.php reads it.
  - `_oras_attendee_notes_v1` — evidence: read/updated in Bootstrap (Bootstrap.php lines referencing `_oras_attendee_notes_v1`).
  - `_oras_virtual_access_v1` — evidence: includes/Frontend/Virtual_Access.php `META_KEY = '_oras_virtual_access_v1'`.

- Order/item snapshot meta keys (exact evidence)
  - `_oras_ticket_event_id`, `_oras_ticket_index`, `_oras_ticket_name`, `_oras_ticket_unit_price`, `_oras_ticket_currency`, `_oras_ticket_price_phase_key`, `_oras_ticket_price_phase_label`, `_oras_ticket_price_phase_price` — added by `Product_Sync::snapshot_order_item_ticket_meta` and consumed across `Member_Hub_Tickets`, `Ticket_Print_Controller`, `Reports_Aggregator`, `Capacity_Consumption`, `Board_Dashboard`, and QuickBooks sync code (Product_Sync.php, Member_Hub_Tickets.php, Ticket_Print_Controller.php, Reports_Aggregator.php, Capacity_Consumption.php, src/Integrations/QuickBooks/*).
  - `_oras_capacity_consumed` — order meta used by `Capacity_Consumption` (Capacity_Consumption.php).
  - `_oras_autocompleted` — order meta used by `Order_Autocomplete` (Order_Autocomplete.php).
  - `_oras_checkin_units_v1` — referenced by Ticket_Checkin_Token and checkin flows (Ticket_Checkin_Token.php evidence lines).

4) WooCommerce hooks and order/item meta (exact evidence)
- Hooks and mapping (evidence examples):
  - `woocommerce_checkout_create_order_line_item` → `Product_Sync::snapshot_order_item_ticket_meta` (Product_Sync.php).
  - `woocommerce_before_calculate_totals` → `Cart_Pricing::apply_cart_pricing` (Cart_Pricing.php).
  - `woocommerce_order_status_processing` / `woocommerce_order_status_completed` → `Capacity_Consumption::handle_paid_order` (Capacity_Consumption.php).
  - `woocommerce_order_status_cancelled` / `woocommerce_order_status_refunded` → `Capacity_Consumption::handle_restore_order` (Capacity_Consumption.php).
  - `woocommerce_order_status_processing` / `woocommerce_payment_complete` → `Order_Autocomplete::maybe_autocomplete` (Order_Autocomplete.php).
  - QuickBooks: `woocommerce_order_status_completed` → `Sync_Orchestrator::enqueue_order_sync` (src/Integrations/QuickBooks/Sync_Orchestrator.php).

5) REST routes (exact routes, methods, file/class/method evidence)
- Member Hub tickets
  - File: oras-tickets/includes/Api/Member_Hub_Tickets.php
  - Evidence / routes:
    - `register_rest_route('oras-tickets/v1', '/me/tickets', ...)` → `Member_Hub_Tickets::get_my_tickets` (Member_Hub_Tickets.php).
    - `register_rest_route('oras-tickets/v1', '/me/tickets/summary', ...)` → `Member_Hub_Tickets::get_my_tickets_summary` (Member_Hub_Tickets.php).

- RSVP API
  - File: oras-tickets/includes/Api/Rsvp.php
  - Evidence / routes:
    - `register_rest_route('oras/v1', '/rsvp/my', ...)` → `Rsvp::get_my_rsvps` (Api/Rsvp.php).
    - `register_rest_route('oras/v1', '/rsvp/event/(?P<id>\\d+)', ...)` → `Rsvp::get_event_rsvp` (Api/Rsvp.php).

- Check-in API
  - File: oras-tickets/includes/Api/Checkin.php
  - Evidence / routes:
    - `register_rest_route('oras-tickets/v1', '/checkin/verify', ...)` → `Checkin::verifyToken` (Api/Checkin.php).
    - `register_rest_route('oras-tickets/v1', '/checkin/mark', ...)` → `Checkin::markCheckedIn` (Api/Checkin.php).
    - `register_rest_route('oras-tickets/v1', '/checkin/unmark', ...)` → `Checkin::unmarkCheckedIn` (Api/Checkin.php).
    - Permission callback uses `current_user_can('oras_tickets_checkin')` (Api/Checkin.php).

6) Admin pages (file/class/method/hook evidence)
- Admin menu wiring
  - File: oras-tickets/includes/Admin/Admin_Menu.php
  - Evidence: `Admin_Menu::register` registers `admin_menu`, `admin_enqueue_scripts`, admin_post handlers and calls `Settings_Page::register_settings`.
  - `Admin_Menu::register_menu` calls `add_menu_page` and `add_submenu_page` entries for Dashboard (`oras_tickets_manage_events`), Reports (`oras_tickets_view_reports`), Check-In (`oras_tickets_checkin`), Speakers (`edit.php?post_type=oras_speaker`), Speaker Obligations, Speaker Reports, QuickBooks (`oras_tickets_manage_settings`), and Settings (`oras_tickets_manage_settings`) (Admin_Menu.php).

7) Frontend entry points (hooks/filters/actions evidence)
- Tickets render and POST handling
  - File: oras-tickets/includes/Frontend/Tickets_Display.php
  - Evidence: `add_filter('the_content', array($this, 'the_content_filter'), 20)` and `add_action('template_redirect', array($this, 'handle_post'), 10)` (Tickets_Display.php).

- Agenda render: `Event_Agenda_Render::append_to_content` via `the_content` (Event_Agenda_Render.php).
- RSVP block render: `Event_RSVP::render_rsvp_block` via `the_content` (Event_RSVP.php).
- Door prizes: `Door_Prizes::append_to_content` via `the_content` (Door_Prizes.php).
- Ticket print routing: rewrite `^oras-ticket/print/?$` and `template_redirect` handler `maybe_render_print_page` (Ticket_Print_Controller.php).
- Board dashboard shortcode: implemented in `includes/Frontend/Board_Dashboard.php` (Board_Dashboard.php).

8) Storage surfaces (post meta, user meta, options, custom tables) — exact evidence
- Post meta keys:
  - `_oras_tickets_v1` — `includes/Domain/Meta.php` `META_KEY_TICKETS = '_oras_tickets_v1'` (Domain/Meta.php).
  - `_oras_rsvp_v1` — `includes/Admin/Metaboxes/Event_RSVP_Metabox.php` and `includes/Api/Rsvp.php` (Event_RSVP_Metabox.php, Api/Rsvp.php).
  - `_oras_agenda_v1` — `includes/Admin/Metaboxes/Event_Agenda_Metabox.php` and `Event_Agenda_Render.php`.
  - `_oras_speakers_v1` — speaker assignments use `_oras_speakers_v1` (Event_Speakers_Metabox, templates).
  - `_oras_door_prizes_v1` — Domain/Meta.php and Door_Prizes.php.
  - `_oras_attendee_notes_v1` — read/updated in `Bootstrap` (Bootstrap.php lines referencing `_oras_attendee_notes_v1`).
  - `_oras_virtual_access_v1` — `Virtual_Access.php` `META_KEY = '_oras_virtual_access_v1'`.

- User meta keys:
  - `_oras_rsvp_event_<event_id>` and `_oras_rsvp_event_<event_id>_ts` — used across `Event_RSVP`, `Waitlist_Store`, `Bootstrap`, `Api/Rsvp` (Event_RSVP.php, Waitlist_Store.php, Bootstrap.php, Api/Rsvp.php).

- Options:
  - `oras_tickets_settings_v1` — Settings_Page::OPTION_KEY and QuickBooks Settings::OPTION_KEY (includes/Admin/Pages/Settings_Page.php, src/Integrations/QuickBooks/Settings.php).
  - `oras_tickets_waitlist_schema_version` — Waitlist_Store::OPTION_SCHEMA_VERSION (Waitlist_Store.php).
  - `oras_tickets_board_login_daily_v1` — Board_Dashboard::LOGIN_DAILY_OPTION (Board_Dashboard.php).
  - `oras_tickets_speaker_notify_emails` — Speaker_Obligations_Page::OPTION_NOTIFY_EMAILS (Speaker_Obligations_Page.php).

- Custom DB table:
  - Table: `{$wpdb->prefix}oras_ticket_waitlist` — created by `Waitlist_Store::install_schema()` with SQL `CREATE_TABLE {$table} (...)` and `table_name()` returns `$wpdb->prefix . 'oras_ticket_waitlist'` (Waitlist_Store.php).

9) Global registration points (evidence of wiring)
- `Bootstrap` registers `register_phase1` on `init` (Bootstrap.php).
- Admin menu pages registered in `Admin_Menu::register` via `admin_menu` hook (Admin_Menu.php).
- REST controllers register via `rest_api_init` inside each controller's `register()`/`register_routes()` methods (Member_Hub_Tickets::register, Rsvp::register, Checkin::register in includes/Api/*).

10) Evidence-not-found statements (explicit)
- Additional plugin-owned CPTs or taxonomies beyond `oras_speaker`: NO EVIDENCE FOUND (search of plugin includes shows only `oras_speaker` registration in includes/Admin/Speaker_CPT.php; other `register_post_type` occurrences are in tooling scripts, not plugin includes).
- Plugin telemetry or external SaaS enforcement hooks: NO EVIDENCE FOUND in plugin code for telemetry or SaaS checks (no external telemetry registrations found in includes/ or src/).

---

Document prepared strictly under PASS 1 rules: evidence-only, exact-file/class/method/hook/meta/table/option references. No recommendations, no roadmap analysis, no next steps.
