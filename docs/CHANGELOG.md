# CHANGELOG (Append-Only)

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
