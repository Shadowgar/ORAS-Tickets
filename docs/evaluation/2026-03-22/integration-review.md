# INTEGRATION REVIEW

## Summary
- WooCommerce is the operational center of paid ticketing, which aligns with the repo’s architectural rules.
- Stripe is only touched through a Woo Stripe gateway filter, so payment execution remains outside ORAS core ticketing logic.
- QuickBooks is already bounded as a downstream adapter in code, but the admin/settings layer still mixes general plugin settings and QBO-specific concerns.
- The cleanest integration surface in the plugin is the order-item snapshot model; the messiest is attendance, not payments.

## WooCommerce Integration

### Integration points
| Integration point | Evidence | Review |
|---|---|---|
| Event save -> hidden Woo products | `oras-tickets/includes/Commerce/Woo/Product_Sync.php` via `save_post_tribe_events` | Good fit for the “Woo is the only commerce engine” rule. |
PASS 4 — INTEGRATIONS: WooCommerce / Stripe (via Woo) / QuickBooks

Source inputs:
- `docs/evaluation/2026-03-22/current-state.md`
- `docs/evaluation/2026-03-22/data-model-evaluation.md`

Goal: produce an evidence-first review of integration seams. Where evidence is missing, the file states "NO EVIDENCE FOUND".

-- Architecture separation (required)

1) Core ticketing domain
- Primary files/classes: `oras-tickets/includes/Domain/*` (Ticket model + `Meta.php`), `oras-tickets/includes/Bootstrap.php`, `oras-tickets/includes/Frontend/Tickets_Display.php` (presentation + validation), `oras-tickets/includes/Waitlist_Store.php` (custom table), `oras-tickets/oras-tickets.php` (plugin bootstrap).
- Responsibilities: event/ticket envelope storage (`_oras_tickets_v1` schema via `Meta::META_KEY_TICKETS`), ticket business rules (capacity, sale windows), data model (tickets[] objects) as captured in `docs/evaluation/2026-03-22/data-model-evaluation.md`.

2) WooCommerce / payment layer (integration seam)
- Primary files/classes:
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php` — maps tickets → Woo products; registers:
    - `add_action( 'save_post_tribe_events', array( $this, 'on_save_event' ), 30, 3 )`
    - `add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'snapshot_order_item_ticket_meta' ), 10, 4 )`
    - Behavior: updates mapped product meta `_oras_ticket_event_id` and `_oras_ticket_index` and creates per-product mapping stored on event as `_oras_tickets_woo_map_v1`.

  - `oras-tickets/includes/Commerce/Woo/Cart_Pricing.php` — pricing during cart lifecycle; registers:
    - `add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_cart_pricing' ), 20, 1 )`
    - Behavior: reads product meta `_oras_ticket_event_id` and `_oras_ticket_index` to resolve price via `Price_Resolver` and sets product price in cart.

  - `oras-tickets/includes/Frontend/Tickets_Display.php` — cart/checkout validation & cart hold; registers:
    - `add_filter( 'the_content', array( $this, 'the_content_filter' ), 20 )`
    - `add_action( 'woocommerce_check_cart_items', array( $this, 'revalidate_cart_items' ), 10 )`
    - `add_action( 'woocommerce_before_checkout_process', array( $this, 'revalidate_cart_items' ), 10 )`
    - `add_action( 'woocommerce_checkout_process', array( $this, 'revalidate_cart_items' ), 10 )`
    - `add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validateAddToCart' ), 10, 5 )`
    - `add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_oras_hold_timestamp' ), 10, 4 )`
    - Behavior: enforces sale windows, stock/quantity rules, and sets `_oras_hold_started_at` in cart item data.

  - `oras-tickets/includes/Commerce/Woo/Product_Sync::snapshot_order_item_ticket_meta` — snapshots ticket context onto order items during `woocommerce_checkout_create_order_line_item`:
    - Order-item meta written: `_oras_ticket_event_id`, `_oras_ticket_index`, `_oras_ticket_name`, `_oras_ticket_unit_price`, `_oras_ticket_currency`, `_oras_ticket_schema`, `_oras_ticket_price_phase_key`, `_oras_ticket_price_phase_label`, `_oras_ticket_price_phase_price`.
    - Source: `oras-tickets/includes/Commerce/Woo/Product_Sync.php` (method `snapshot_order_item_ticket_meta`).

  - `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` — mutates ticket capacity on order lifecycle; registers:
    - `add_action( 'woocommerce_order_status_processing', array( $this, 'handle_paid_order' ), 10, 1 )`
    - `add_action( 'woocommerce_order_status_completed', array( $this, 'handle_paid_order' ), 10, 1 )`
    - `add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_restore_order' ), 10, 1 )`
    - `add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_restore_order' ), 10, 1 )`
    - Behavior: collects order line items (uses product postmeta fallback then order-item meta), updates event ticket envelope capacities, and syncs mapped product stock.

  - `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php` — auto-completes ticket-only paid orders; registers:
    - `add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_autocomplete' ), 30, 1 )`
    - `add_action( 'woocommerce_payment_complete', array( $this, 'maybe_autocomplete' ), 30, 1 )`
    - Behavior: sets order meta `_oras_autocompleted` and transitions order to `completed` when order contains only ORAS virtual tickets or allowed donation/observer items.

3) Reporting / integration layer (QuickBooks downstream)
- Primary files/classes: `oras-tickets/src/Integrations/QuickBooks/*` — `Sync_Orchestrator.php`, `Split_Calculator.php`, `Journal_Entry_Creator.php`, `Settings.php`.
- Key hook registrations / action hooks:
  - `add_action('woocommerce_order_status_completed', array($this, 'enqueue_order_sync'), 10, 1)` — Sync_Orchestrator registers this to queue order syncs when orders complete.
  - `Sync_Orchestrator::ACTION_HOOK` constant: `oras_tickets_qbo_sync_order` registered to `sync_order_async` for asynchronous processing.
  - Internal scheduled hook: `oras_tickets_qbo_waiting_queue_sweep` used for waiting queue processing.

- Data kept on orders for QuickBooks workflow (order meta keys written/read):
  - `_oras_qbo_je_id` (existing JournalEntry id)
  - `_oras_qbo_je_hash` (order hash stored after sync)
  - `_oras_qbo_sync_status` (queued/syncing/synced/etc.)
  - `_oras_qbo_split_snapshot` (serialized split payload)
  - `_oras_qbo_last_intuit_tid`, `_oras_qbo_reversal_je_id`, `_oras_qbo_reversal_at`
  - Approval/timing keys: `_oras_qbo_manual_approved_at`, `_oras_qbo_wait_first_at`, `_oras_qbo_wait_last_check_at`, `_oras_qbo_wait_next_check_at`, `_oras_qbo_wait_attempts`.

-- Exact data-dependencies & order fields

- Product / product-postmeta:
  - `_oras_ticket_event_id` — maps a Woo product to an event ID (used across Product_Sync, Cart_Pricing, Tickets_Display, Capacity_Consumption).
  - `_oras_ticket_index` — ticket index within the event envelope (same usage as above).
  - `_oras_qbo_bucket` — optional per-product bucket used by QuickBooks Split_Calculator classification.
  - `_oras_tickets_woo_map_v1` — event post meta created by Product_Sync mapping event->product IDs.

- Order-item meta (set at checkout by Product_Sync::snapshot_order_item_ticket_meta):
  - `_oras_ticket_event_id`
  - `_oras_ticket_index`
  - `_oras_ticket_name`
  - `_oras_ticket_unit_price`
  - `_oras_ticket_currency`
  - `_oras_ticket_schema`
  - `_oras_ticket_price_phase_key`, `_oras_ticket_price_phase_label`, `_oras_ticket_price_phase_price`

- Order meta used by integrations and capacity code:
  - `_oras_capacity_consumed` — set when Capacity_Consumption consumes capacity on paid order
  - `_oras_capacity_restored` — set when capacity is restored on cancellation/refund
  - `_oras_autocompleted` — set by Order_Autocomplete
  - QBO meta keys enumerated above

-- Hooks (actions/filters) — exact names and where registered (evidence)

- `woocommerce_checkout_create_order_line_item` — registered in:
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php` (method `register`) via `add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'snapshot_order_item_ticket_meta' ), 10, 4 )`.

- `woocommerce_before_calculate_totals` — registered in:
  - `oras-tickets/includes/Commerce/Woo/Cart_Pricing.php` via `add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_cart_pricing' ), 20, 1 )`.

- `woocommerce_add_to_cart_validation`, `woocommerce_add_cart_item_data`, `woocommerce_check_cart_items`, `woocommerce_before_checkout_process`, `woocommerce_checkout_process` — registered in:
  - `oras-tickets/includes/Frontend/Tickets_Display.php` via `add_filter`/`add_action` calls (see file header lines for exact signatures).

- `woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_order_status_cancelled`, `woocommerce_order_status_refunded` — registered in:
  - `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` (`handle_paid_order` / `handle_restore_order`) and in `Order_Autocomplete.php` (`maybe_autocomplete`).

- QuickBooks sync hooks:
  - `add_action('woocommerce_order_status_completed', array($this, 'enqueue_order_sync'), 10, 1)` — `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`.
  - `add_action(Sync_Orchestrator::ACTION_HOOK, array($this, 'sync_order_async'), 10, 1)` — internal async hook `oras_tickets_qbo_sync_order`.
  - `add_action(Sync_Orchestrator::ACTION_WAITING_SWEEP_HOOK, array($this, 'process_waiting_queue_async'))` — internal waiting queue sweep.

-- Architecture rule check: QuickBooks as downstream only

- Evidence that QuickBooks is treated as downstream:
  - `Sync_Orchestrator` subscribes to `woocommerce_order_status_completed` and queues/schedules an async action — it does not mutate ticket model directly; it builds a split payload from order data (`Split_Calculator::calculate`) and posts JournalEntry payloads via `Journal_Entry_Creator`.
  - Order/model ownership: capacity and ticket envelope changes are applied by `Capacity_Consumption` (Woo lifecycle) and `Ticket_Collection::save_for_event` — QuickBooks code reads order and product meta but does not write core ticket envelope fields.

- Check for violations (QuickBooks defining ticketing structure or workflows):
  - NO EVIDENCE FOUND that QuickBooks integration writes to event ticket envelopes (no writes to event post meta such as `_oras_tickets_v1` from `src/Integrations/QuickBooks/*`). QuickBooks writes exclusively to order-level meta like `_oras_qbo_*` and audit entries.

-- Coupling risks, incorrect boundaries, dependency assumptions

1) Coupling risks
- Capacity mutation timing tied to Woo order status transitions
  - Risk: `Capacity_Consumption` responds to `woocommerce_order_status_processing` / `completed` / `cancelled` / `refunded`. If store workflows or other gateways alter order-status transitions (custom statuses, manual adjustments), ORAS capacity may be misapplied or double-applied. Evidence: `Capacity_Consumption::register` hooks.

- Reliance on product postmeta vs. order-item meta
  - `Capacity_Consumption` first attempts to read `_oras_ticket_event_id`/`_oras_ticket_index` from product postmeta, falling back to order-item meta. If product mapping changes after order creation, capacity code uses order-item meta when present — safe; however, edge cases exist where postmeta is missing and order meta absent if Product_Sync didn't snapshot correctly.

- Product mapping lifecycle
  - `Product_Sync::on_save_event` will create/update Woo products and persist mapping `_oras_tickets_woo_map_v1`. If product-save fails or mapping is out of date, add-to-cart and pricing may misclassify products. Risk when product is deleted or unpublished: `get_or_create_product` creates a new `WC_Product_Simple()` without persistence until `save()` — mapping flow and product lifecycle must be robust.

- Autocomplete assumptions
  - `Order_Autocomplete` uses `product->is_virtual()` and `_oras_qbo_bucket` to decide allowed non-ticket items. If third-party products are virtual but not ORAS items, they may be auto-completed incorrectly.

2) Incorrect boundaries
- QuickBooks classification performed from Woo items
  - `Split_Calculator` classifies items by checking order item meta and product taxonomies (`product_cat`) and `_oras_qbo_bucket`. That is correct for a downstream reporting service, but any business logic that changes bucket classification (e.g., moving ticket classification into QuickBooks settings) would create a two-way dependency. Currently classification is downstream-only; no evidence that QuickBooks drives the ticket model. (Good.)

3) Dependency assumptions
- Assumes WooCommerce core functions and order lifecycle are present: `wc_get_order`, `WC()->cart`, `wc_get_product`. If Woo is disabled, many integration points early-return safely.
- Assumes `woocommerce_checkout_create_order_line_item` is called and that `Product_Sync` snapshots meta before QuickBooks/split calc runs. There is an implicit ordering assumption: snapshot → order creation → status change → capacity consumption → qbo enqueue on completed.
- Assumes product meta `_oras_ticket_event_id` and `_oras_ticket_index` exist for mapped products; code falls back to order-item meta (good defensive design).

-- Missing evidence or gaps

- Stripe-specific code: NO EVIDENCE FOUND for a dedicated Stripe integration layer in this repo that bypasses WooCommerce. Stripe is treated implicitly via WooCommerce payment lifecycle (hooks like `woocommerce_payment_complete`). There are references to Stripe in docs/plans, but no core logic in `oras-tickets` that calls Stripe APIs directly. Therefore:
  - Conclusion: Stripe assumptions are mediated by WooCommerce (payments complete/status transitions). There is NO EVIDENCE FOUND that ORAS depends on Stripe-specific webhooks or identifiers.

- Third-party QuickBooks reference plugins in `reference/quickbook-sync/` exist; these are external reference implementations and are not the primary `src/Integrations/QuickBooks` code, but their presence indicates historical coupling choices. Evidence: `reference/quickbook-sync/*` files.

-- Recommendations (evidence-based, concise)

1) Harden order-status invariants
- Record explicit sync-consumption guards: ensure `Capacity_Consumption` is resilient to non-standard statuses by centralizing a mapping table (configurable list of statuses that mean 'paid' vs 'cancelled'). Right now code assumes `processing/completed` semantics.

2) Defend mapping race conditions
- Ensure `Product_Sync::on_save_event` handles transient failures and exposes a reconciler that can backfill missing order-item meta for orders that were created before mapping existed.

3) Keep QuickBooks downstream
- Continue enforcing QuickBooks as a downstream consumer: only read order/product meta, write only `_oras_qbo_*` order meta and audit entries. Avoid adding ticket model mutations in `src/Integrations/QuickBooks/*` (NO EVIDENCE FOUND — good current state).

4) Document Stripe assumptions
- Add explicit README note: ORAS expects payments to be processed by WooCommerce; ORAS listens for `woocommerce_payment_complete` / order status changes. If a site uses a gateway that does not trigger those hooks, QuickBooks and capacity flows will not run.

-- Summary verdict (evidence-first)

- WooCommerce integration: well-scoped and implemented via explicit hooks:
  - `Product_Sync` maps tickets to products and snapshots order-item meta via `woocommerce_checkout_create_order_line_item`.
  - `Cart_Pricing` applies time-based pricing during `woocommerce_before_calculate_totals`.
  - Validation and hold semantics implemented in `Tickets_Display` using `woocommerce_add_to_cart_validation`, `woocommerce_add_cart_item_data`, and cart/checkout validation hooks.
  - Capacity and lifecycle mutations occur in `Capacity_Consumption` bound to order-status hooks.

- Stripe: treated implicitly through WooCommerce (NO EVIDENCE FOUND of direct Stripe integration). ORAS relies on WooCommerce order lifecycle hooks (e.g., `woocommerce_payment_complete`) rather than payment-provider-specific webhooks.

- QuickBooks: implemented as downstream only (evidence in `src/Integrations/QuickBooks/Sync_Orchestrator.php`, `Split_Calculator.php`): it registers `woocommerce_order_status_completed`, schedules async actions, and writes only order-level `_oras_qbo_*` meta. NO EVIDENCE FOUND of QuickBooks code mutating core ticket envelopes.

-- Evidence references (key files)

- `oras-tickets/includes/Commerce/Woo/Product_Sync.php` — mapping & `woocommerce_checkout_create_order_line_item` (snapshot).
- `oras-tickets/includes/Commerce/Woo/Cart_Pricing.php` — `woocommerce_before_calculate_totals`.
- `oras-tickets/includes/Frontend/Tickets_Display.php` — add-to-cart validation + hold keys (`_oras_hold_started_at`).
- `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` — capacity mutation on `woocommerce_order_status_*` hooks and uses `_oras_capacity_consumed` order meta.
- `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php` — `woocommerce_order_status_processing` / `woocommerce_payment_complete` and `_oras_autocompleted` meta.
- `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php` — `woocommerce_order_status_completed` enqueue, `oras_tickets_qbo_sync_order` action, order meta `_oras_qbo_*` usage.

-- End of PASS 4

If additional detail is needed (line-level citations or expanded gap remediation steps), request permission and I will produce them. This file overwrote previous content.


### Assessment
- The plugin is not trying to replace Woo order ownership. Evidence:
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`
  - `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php`
  - `oras-tickets/includes/Frontend/Tickets_Display.php`
- Hidden/private virtual products per ticket are a reasonable add-on pattern because event ownership remains in ORAS event meta while purchase state stays in Woo. Evidence:
  - `oras-tickets/includes/Domain/Ticket_Collection.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
- The order-item snapshot strategy is the key abstraction that makes reporting, print, Member Hub reads, and QuickBooks sync possible without querying live event ticket definitions. Evidence:
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - `oras-tickets/includes/Frontend/Ticket_Print_Controller.php`
  - `oras-tickets/includes/Api/Member_Hub_Tickets.php`
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`

### Reliance on Woo internals
- Acceptable reliance:
  - order status hooks
  - Woo product API
  - Woo order/order-item metadata
  - `wc_get_orders()` / `wc_get_order()`
- Higher-coupling areas:
  - capacity release/consume is status-hook-driven, so any future status-policy change affects inventory semantics. Evidence: `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`.
  - order auto-complete infers allowed non-ticket items from product meta/category slugs. Evidence: `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php`.
  - attendee read models derive paid attendance from Woo orders in `Bootstrap::get_filtered_attendees()` and `Reports_Aggregator`, which couples operations/reporting to current Woo order semantics. Evidence:
    - `oras-tickets/includes/Bootstrap.php`
    - `oras-tickets/includes/Admin/Reports_Aggregator.php`

## Stripe Touchpoints

### Integration points
- `ORAS\Tickets\Commerce\Woo\Stripe_Intent_Description` filters `wc_stripe_generate_create_intent_request` in `oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php`.
- QuickBooks reconciliation logic also inspects payment method and downstream source-transaction data in:
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`

### Assessment
- Payment processing is correctly left to Woo + Woo Stripe. The plugin only augments Stripe metadata/description and does not implement its own card/payment execution path. Evidence:
  - `oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php`
  - `docs/PROJECT_STATE.md`
- This is a clean separation:
  - ticket logic chooses what is being sold
  - Woo handles the order and payment transaction
  - Stripe receives a better description string
- The Stripe touchpoint is gateway-specific, but it is isolated to one class and one filter, which is acceptable. Evidence: `oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php`.

## QuickBooks Adapter Review

### Integration points
| Integration point | Evidence | Review |
|---|---|---|
| Module registration | `oras-tickets/src/Integrations/QuickBooks/Module.php` | Good boundary: all QBO runtime registration starts from one module class. |
| Settings storage / encryption | `oras-tickets/src/Integrations/QuickBooks/Settings.php`, `oras-tickets/includes/Admin/Pages/Settings_Page.php` | Storage is centralized in one option, but ownership is split between adapter and admin UI. |
| Sync orchestration | `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php` | Strong downstream model: reads Woo orders and writes accounting state. |
| Split classification | `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php` | Correct layer for accounting categorization; it consumes Woo/product/order snapshot data instead of altering ticket models. |
| API / OAuth / retries | `OAuth_Client.php`, `Api_Client.php`, `Retry_Handler.php`, `Journal_Entry_Creator.php` | Properly contained inside the adapter directory. |
| Admin actions | `oras-tickets/src/Integrations/QuickBooks/Module.php`, `oras-tickets/includes/Admin/Pages/Settings_Page.php` | Operational controls exist, but admin UI and integration logic are still closely paired. |

### Assessment
- QuickBooks is mostly implemented as a downstream adapter and does not redefine event/ticket/order ownership. Evidence:
  - `oras-tickets/src/Integrations/QuickBooks/Module.php`
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
- The adapter correctly consumes:
  - Woo orders
  - Woo order items
  - ORAS order-item ticket snapshots
  - settings/mappings
  rather than changing core ticket models. Evidence:
  - `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
  - `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
  - `oras-tickets/includes/Commerce/Woo/Product_Sync.php`
- The largest coupling issue is settings ownership. `Settings_Page` and `QuickBooks\Settings` both know the same option structure `oras_tickets_settings_v1`. Evidence:
  - `oras-tickets/includes/Admin/Pages/Settings_Page.php`
  - `oras-tickets/src/Integrations/QuickBooks/Settings.php`

## Separation of Ticket Logic, Payment Logic, and Reporting Logic

### Ticket logic
- Ticket authoring and sale rules live in:
  - `oras-tickets/includes/Domain/Ticket.php`
  - `oras-tickets/includes/Domain/Ticket_Collection.php`
  - `oras-tickets/includes/Domain/Pricing/Price_Resolver.php`
  - `oras-tickets/includes/Admin/Tickets_Metabox.php`
  - `oras-tickets/includes/Frontend/Tickets_Display.php`

### Payment logic
- Payment and order transitions live in:
  - WooCommerce itself
  - `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php`
  - `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php`
  - `oras-tickets/includes/Commerce/Woo/Stripe_Intent_Description.php`

### Reporting logic
- Financial and operational reporting live in:
  - `oras-tickets/includes/Admin/Reports_Aggregator.php`
  - `oras-tickets/includes/Admin/Pages/Reports_Page.php`
  - `oras-tickets/includes/Admin/Pages/Speaker_Reports_Page.php`
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - `oras-tickets/src/Integrations/QuickBooks/*`

### Boundary evaluation
- The ticket/payment boundary is mostly sound because order ownership stays in Woo and ticket meaning stays in ORAS metadata.
- The payment/reporting boundary is also mostly sound because reporting and QuickBooks consume order-item snapshots instead of mutating ticket models.
- The weakest boundary is attendance operations, where admin, frontend, API, and reporting surfaces all rebuild attendance differently from multiple stores. Evidence:
  - `oras-tickets/includes/Frontend/Event_RSVP.php`
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`
  - `oras-tickets/includes/Api/Rsvp.php`

## Specific Risks

### Risk 1: Integration logic is concentrated in `Bootstrap`
- Evidence:
  - `oras-tickets/includes/Bootstrap.php`
  - `oras-tickets/includes/Admin/Pages/Dashboard_Page.php`
- Impact:
  - admin operations are harder to test and isolate
  - attendance logic is duplicated across UI/API paths

### Risk 2: QuickBooks settings are not cleanly isolated from general settings UI
- Evidence:
  - `oras-tickets/includes/Admin/Pages/Settings_Page.php`
  - `oras-tickets/src/Integrations/QuickBooks/Settings.php`
- Impact:
  - adapter-layer changes can leak into unrelated settings rendering
  - storage invariants are harder to reason about

### Risk 3: Frontend board dashboard and door prizes exceed the clean downstream/reporting model
- Evidence:
  - `oras-tickets/includes/Frontend/Board_Dashboard.php`
  - `oras-tickets/includes/Frontend/Door_Prizes.php`
  - `docs/ROADMAP.md`
  - `docs/MASTER_EXECUTION_TRACKER.md`
- Impact:
  - runtime has already moved into planned/post-gate scope
  - page-render performance and governance clarity are both affected

## Overall Judgment
- WooCommerce integration is strong enough to keep.
- Stripe integration is appropriately thin and should stay thin.
- QuickBooks is already in the right architectural layer, but its admin/settings coupling should be reduced.
- The main integration refactor target is not Woo or QuickBooks; it is the attendance/query layer that sits between admin, frontend, API, and reporting.
