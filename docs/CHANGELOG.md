# CHANGELOG (Append-Only)

## Phase 3.0 — COMPLETED
- Woo product sync finalized
- Stock = capacity logic implemented
- Refund handling verified
- Reporting and CSV exports completed

## Phase 3.1 — COMPLETED
- Frontend ticket filtering by sale window: tickets render only during `sale_start` / `sale_end`.
- Inventory visibility: in-badge notes show "X left" or "Unlimited".
- Qty input layout fixes: resilient to long names and browser spinner overlay (Firefox/Edge).
- Robust cart revalidation: malformed or off-sale ORAS ticket items are removed; valid items are preserved during checkout.
- Product purchasability fix: Woo products saved with `post_status = publish` and `catalog_visibility = hidden`.
- Improved add-to-cart success notice includes a cart link.

Status: COMPLETE (LOCKED)

Notes: Phase 3.1 behaviors (sale-window filtering, add-to-cart revalidation, frontend UX fixes) are locked; changes require a design review.

## Phase 3.2 — COMPLETED
- Implemented server-side `price_phases` resolver for time-based pricing.
- Frontend ticket display shows active phase badge and server-rendered countdown.
- Cart and checkout totals apply resolved phase pricing server-side.
- Order item metadata now includes active `price_phase` snapshot keys for audits and reporting.

Status: COMPLETE (LOCKED)

## Phase 3.3 — COMPLETED
- Admin tickets editor now uses WooCommerce-style vertical tabs within each ticket (General, Inventory, Sale window, Pricing, Pricing phases).
- Pricing phases UI redesigned into a card/grid layout with Advanced expand/collapse.
- Left ticket rail shows title + meta (price + status) and updates live while editing.
- Add Ticket / Remove Ticket update the UI immediately (no refresh required).
- Initialization fixes prevent blank/ghost rows on refresh.
- Inline styles reduced/moved to CSS for maintainability.

Status: COMPLETE (UI-only)
