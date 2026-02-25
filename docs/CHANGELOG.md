# CHANGELOG (Append-Only)

## 2026-02-25 — Attendee Status Coverage Hardening (Phase 5)

Code:
- Fixed attendee ticket-order collection to include all dashboard-supported Woo order statuses (`completed`, `processing`, `on-hold`, `pending`, `refunded`, `cancelled`, `failed`) instead of only `processing`/`completed`.
- Expanded `scripts/phase5-integration-checks.php` with a regression scenario that creates an `on-hold` order and verifies:
  - `ticket_status=on-hold` includes that attendee.
  - `ticket_status=all` includes that attendee.

Verification:
- `bash scripts/run-phase5-integration-checks.sh` (pass, shared env `/home/rocco/projects/oras-wp-env`)
- `composer phpcs` (pass)
- `composer phpstan` (pass)

## 2026-02-25 — Phase 5.2 Waitlist Queue Operations + Audit Surface

Code:
- Added waitlist store query/operation methods for admin queue management (`get_event_rows`, `promote_user`, `bulk_promote_waiting`, `remove_waiting_user`).
- Added RSVP dashboard waitlist operations UI (bulk promote, manual promote/remove, queue and history tables).
- Added waitlist AJAX handlers in `Bootstrap`:
  - `oras_waitlist_queue_data`
  - `oras_waitlist_bulk_promote`
  - `oras_waitlist_promote_user`
  - `oras_waitlist_remove_user`
- Wired waitlist operations to deterministic lifecycle updates and RSVP usermeta sync.
- Extended `scripts/phase5-integration-checks.php` to verify the new queue/audit endpoints and operation flows.

Verification:
- `bash scripts/run-phase5-integration-checks.sh` (pass, shared env `/home/rocco/projects/oras-wp-env`)
- `composer phpcs` (pass)
- `composer phpstan` (pass)

## 2026-02-25 — Phase 5 Verification Suite + CI Enforcement

Code:
- Added `scripts/phase5-integration-checks.php` with deterministic WP-CLI integration checks for RSVP transitions, waitlist lifecycle/promotion, attendee data contracts, note save, and messaging handlers.
- Added `scripts/run-phase5-integration-checks.sh` to execute the checks against a configured wp-env runtime (`ORAS_WP_ENV_DIR` aware; defaults to `/home/rocco/projects/oras-wp-env` when present).
- Added first CI workflow `/.github/workflows/phase5-verification.yml` to run PHPCS, PHPStan, and Phase 5 integration checks on push/PR.

Verification:
- Local run passed in shared env (`/home/rocco/projects/oras-wp-env`) via:
  - `bash scripts/run-phase5-integration-checks.sh`
  - `composer phpcs`
  - `composer phpstan`

## 2026-02-25 — RSVP Frontend Endpoint + Attendee Integrity Fixes

Code:
- Fixed frontend RSVP submission routing where a hidden `action` field shadowed form `action` URL resolution.
- Fixed attendee aggregation so paid attendees are not collapsed by user and ticket quantities are counted correctly per event.

Verification:
- Reproduced undercount before fix and confirmed corrected attendee totals after fix.
- Static checks pass (`php -l`, `composer phpstan`, `composer phpcs`).

## 2026-02-25 — Board Dashboard Scope Added to Master Plan

Documentation:
- Added Phase 9.5 Board Member Dashboard scope to `MASTER_DEVELOPMENT_PLAN.md` with KPI domains, governance, and Members Hub style direction.
- Added Phase 10.4 Board Analytics Data Layer scope to `MASTER_DEVELOPMENT_PLAN.md`.
- Updated `MASTER_EXECUTION_TRACKER.md` to include phase-by-phase percentage rationale and board-dashboard-aware Phase 9/10 summaries.
- Updated `ROADMAP.md`, `NEXT.md`, `CURRENT_STATE.md`, and `PROJECT_STATE.md` to reflect approved board dashboard scope and sequencing gates.

## 2026-02-25 — Phase 5.2 Waitlist Data Model Upgrade

Code:
- Added first-class waitlist store `wp_oras_ticket_waitlist` with schema migration/install support.
- Added waitlist lifecycle states and audit fields (`waiting`, `promoted`, `left`, action source, actor, timestamps).
- Added legacy backfill path so existing waitlist usermeta records hydrate into first-class waitlist rows.
- Updated RSVP transition handling to synchronize waitlist lifecycle (join, leave, promote) into the store.
- Updated dashboard and metabox promotion/export/read paths to use deterministic queue ordering from the new store.
- Updated activation bootstrap to ensure waitlist schema installation.
- Static checks pass (`php -l`, `composer phpstan`, `composer phpcs`).

Documentation:
- Updated `MASTER_EXECUTION_TRACKER.md`, `NEXT.md`, `CURRENT_STATE.md`, and `PROJECT_STATE.md` to reflect Phase 5.2 progress and remaining closure tasks.

## 2026-02-25 — Phase 5 Hardening + Docs Consolidation

Code:
- Fixed RSVP intent normalization to correctly support `yes`, `no`, `waitlist`, and `leave_waitlist`.
- Fixed undefined redirect variable path in RSVP handler.
- Removed temporary RSVP debug logging noise from request paths.
- Resolved conflicting `admin_post_oras_rsvp_promote` handlers by moving legacy metabox promotion to its own action.
- Corrected unlimited-capacity logic in dashboard stats and promotion handlers (`capacity = 0` is no longer treated as full).
- Hardened admin attendees table rendering by escaping dynamic values before HTML injection.
- Removed duplicate virtual access hook registration and debug `error_log` output.
- Static checks now pass cleanly (`composer phpstan`, `composer phpcs`).

Documentation:
- Added `docs/MASTER_EXECUTION_TRACKER.md` as the operational source of truth for phase completion and gates.
- Reconciled `PROJECT_STATE.md`, `CURRENT_STATE.md`, `NEXT.md`, `ROADMAP.md`, and `README.md` to remove conflicting phase status claims.
- Replaced template `SECURITY.md` with project-specific policy.

## 2026-02-15 — Phase 6 Attendees Management (6.2 -> 6.5)
- Added attendees dashboard with event selector, filters, AJAX loading, and CSV export.
- Added attendee operations (View User, View Order, email action paths).
- Added attendee messaging with chunked sending and recipient normalization.
- Added attendee notes envelope `_oras_attendee_notes_v1` and inline note editing.

## 2026-02-15 — RSVP + Virtual Access Surfaces
- Added RSVP frontend/admin and REST surfaces for member-consumable status data.
- Added virtual access controls persisted in `_oras_virtual_access_v1`.

## 2026-02-14 — Speaker + Agenda Expansion
- Implemented speaker CPT/event assignment baseline and public speaker profile routing.
- Implemented agenda rendering with current-slot highlighting, autoscroll, and speaker modal UX.

## 2026-02-14 — Woo Ticket-Only Auto-Completion
- Added ticket-only order auto-completion with `_oras_autocompleted` marker.
