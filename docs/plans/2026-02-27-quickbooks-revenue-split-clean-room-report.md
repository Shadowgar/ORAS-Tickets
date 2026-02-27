# QuickBooks Revenue Split Clean-Room Architecture Report

Date: 2026-02-27  
Scope: architecture-only review of `reference/quickbook-sync` (third-party plugin) to inform original implementation in ORAS-Tickets.

## Authentication Architecture
- Uses OAuth v1/v2 support paths in a central QBO library (`includes/class-myworks-wc-qbo-sync-qbo-lib.php`).
- Stores connection/tokens via WordPress options and local encrypted blobs.
- Performs proactive OAuth2 refresh checks during queue runs and refreshes before API calls when needed.
- Supports sandbox mode toggling and realm-based company context.

## Sync Trigger Architecture
- Registers many Woo hooks in core loader (`includes/class-myworks-wc-qbo-sync.php`), including:
  - `woocommerce_payment_complete`
  - `woocommerce_thankyou`
  - `woocommerce_order_refunded`
  - configurable `woocommerce_order_status_*`
- Enqueues real-time work into a queue table instead of performing all sync work inline.
- Uses a queue worker that processes item type/action combinations (Invoice/Payment/Refund/etc).

## Data Mapping Architecture
- Uses multiple custom DB tables for mapping:
  - customer/product/variation/payment mappings
  - tax/payment method/shipping mappings
  - queue/history/log data
- Sync path supports many QBO object types (Invoice, SalesReceipt, Payment, RefundReceipt, Deposit, JournalEntry, etc).
- Idempotency patterns rely on object existence checks by DocNumber and mapping tables.

## Error Handling Architecture
- Writes structured plugin logs to custom log table with status levels.
- Adds order notes on success/failure outcomes.
- Surfaces errors in admin log UI and includes QBO error-code links.
- Includes masked request logging support for sensitive payload data.

## Retry / Background Processing
- Uses WP-Cron queue processing hooks and optional CLI/server cron entry scripts.
- Uses lock-file behavior to avoid concurrent queue workers (`log/ql.lock`).
- Processes queue with run/success markers and moves execution traces into history table.
- Handles payment-before-order sequencing edge case in queue ordering logic.

## Risks Observed in Reference Architecture
- Large single-library class footprint increases coupling and maintenance complexity.
- Heavy option/table coupling can make upgrades/migrations brittle.
- Broad hook surface can increase duplicate-trigger risk if idempotency checks drift.
- Mixed responsibilities (sync, admin, queue, mapping logic) make testing harder.

## Concepts To Adopt (Clean-Room)
- Queue-first processing for paid-order sync actions.
- Explicit idempotency keys/meta so duplicate hooks cannot create duplicate accounting entries.
- OAuth2 refresh-before-request and robust API error parsing.
- Masked logging and operational admin test actions.

## Concepts To Avoid (Clean-Room)
- Monolithic integration class design.
- Plugin-license/remote control coupling for core sync behavior.
- Overly broad object sync surface for ORAS needs (ORAS scope is JournalEntry-only split).
- Direct copy of table schemas, token formats, or proprietary flow details.

## ORAS Implementation Boundaries
- Keep Stripe connector enabled.
- Create JournalEntry-only split layer (no SalesReceipt/Invoice creation).
- Keep all behavior inside ORAS-Tickets codebase.
- No edits to WordPress core, WooCommerce core, TEC/Event Tickets core.
