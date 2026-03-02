# Phase 3 KPI Layering Backlog

Date: 2026-03-02
Scope: reporting depth expansion (non-production-command work)
Status: In progress

## Purpose
Define the next deterministic Reporting-depth increments after Phase 3 integration closure.

## Baseline Confirmed
- Report render/export capability and nonce guards are validated.
- Stable local runner exists: `scripts/run-phase3-reporting-checks.sh`.

## Next KPI Increments
1. Trend depth — Completed 2026-03-02
- Added period-over-period deltas for gross/net/refunds at overview scope.
- Added explicit event-level trend direction markers in detail scope (gross/refunded/net/refund rate/AOV cards).

2. Board packet layering — Completed 2026-03-02
- Added export-safe KPI slice fields for board packet intake in overview CSV export:
	- `board_kpi_refund_rate_pct`
	- `board_kpi_average_order_value`
	- `board_kpi_slice_version`
- Kept CSV schema deterministic and append-only by adding new fields at the end of the existing header.

3. Comparative signals — Completed 2026-03-02
- Added member vs non-member comparative contribution ratios in detail KPI cards:
	- member gross share vs non-member gross share,
	- member ticket share vs non-member ticket share.
- Added refund-rate and average-order-value trend deltas over matched ranges (completed in prior trend-depth increment).

## Constraints
- No production WP-CLI dependency.
- No Phase 6+ feature work.
- Keep changes inside ORAS-Tickets boundaries.

## Verification Plan
- `composer phpstan`
- `ORAS_WP_ENV_DIR=/home/rocco/projects/oras-wp-env bash scripts/run-phase3-reporting-checks.sh`

## Evidence (2026-03-02)
- Updated report rendering in `oras-tickets/includes/Admin/Pages/Reports_Page.php` to compute previous matched-period windows and delta labels.
- `composer phpstan` passed.
- `scripts/run-phase3-reporting-checks.sh` passed in `oras-wp-env`.

## Evidence (2026-03-02 — board packet layering)
- Updated overview CSV export in `oras-tickets/includes/Admin/Pages/Reports_Page.php` with append-only board KPI slice fields.
- `composer phpstan` passed.
- `scripts/run-phase3-reporting-checks.sh` passed in `oras-wp-env`.

## Evidence (2026-03-02 — comparative signals)
- Updated detail KPI cards in `oras-tickets/includes/Admin/Pages/Reports_Page.php` with member/non-member comparative contribution ratios.
- Reused matched prior-period trend deltas already added for refund rate and AOV.
- `composer phpstan` passed.
- `scripts/run-phase3-reporting-checks.sh` passed in `oras-wp-env`.
