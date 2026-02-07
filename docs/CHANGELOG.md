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
