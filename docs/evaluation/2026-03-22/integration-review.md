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
| Cart-time phase pricing | `oras-tickets/includes/Commerce/Woo/Cart_Pricing.php` via `woocommerce_before_calculate_totals` | Keeps price resolution in Woo cart lifecycle instead of inventing a parallel order system. |
| Add-to-cart and checkout revalidation | `oras-tickets/includes/Frontend/Tickets_Display.php` via `woocommerce_add_to_cart_validation`, `woocommerce_check_cart_items`, `woocommerce_before_checkout_process`, `woocommerce_checkout_process` | Strong commerce integrity pattern; ticket rules are enforced before payment. |
| Order-item ticket snapshot | `oras-tickets/includes/Commerce/Woo/Product_Sync.php` via `woocommerce_checkout_create_order_line_item` | Strongest integration seam in the plugin; it preserves ticket context beyond mutable event/product state. |
| Capacity mutation on order status | `oras-tickets/includes/Commerce/Woo/Capacity_Consumption.php` via `woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_order_status_cancelled`, `woocommerce_order_status_refunded` | Effective, but coupled tightly to ORAS’s current Woo status policy. |
| Ticket-only order auto-complete | `oras-tickets/includes/Commerce/Woo/Order_Autocomplete.php` via `woocommerce_order_status_processing`, `woocommerce_payment_complete` | Appropriate convenience layer; still downstream of Woo payment state. |

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
