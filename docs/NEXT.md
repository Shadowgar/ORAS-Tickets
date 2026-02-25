# NEXT — Immediate Work Queue

Last updated: 2026-02-25

## Current Sprint Goal
Close Phase 5 hardening gates so Phase 6 work can begin safely.

## Ordered Tasks
1. Phase 4 Visual Polish Pass
- Remove temporary inline styling where practical.
- Replace temporary agenda dark-mode overrides with production-ready tokens.
- Validate desktop + mobile UI quality for agenda and speaker surfaces.

2. Waitlist Queue Operator Soak (Phase 5.2 final)
- Run operator walkthrough on new queue/history tools (manual promote/remove and bulk promote paths).
- Capture any UI/wording friction and apply a small polish pass.

3. Board Member Dashboard Design Pack (Phase 9.5 / 10.4)
- Define board KPI contract (PMPro, ticketing, finance, operational alerts).
- Define Members Hub-aligned UI spec (information hierarchy, card system, responsive behavior).
- Define capability/permission model for board-only access and exports.

## Completed This Cycle
- Fixed attendee dashboard ticket-source coverage across Woo statuses and added regression checks for on-hold/all status visibility.
- Added Phase 5 WP-CLI integration check harness (`scripts/phase5-integration-checks.php`).
- Added runtime wrapper (`scripts/run-phase5-integration-checks.sh`) with `ORAS_WP_ENV_DIR` support.
- Added CI workflow (`.github/workflows/phase5-verification.yml`) running PHPCS, PHPStan, and Phase 5 integration checks.
- Added waitlist queue operations and audit/history surface in RSVP dashboard (manual/bulk controls).
- Added new waitlist AJAX operations and expanded integration checks to verify those operations.

## Out of Scope Until Above Is Done
- New Phase 6+ feature implementation (QR/check-in, reservation windows, advanced automation).
- Board dashboard implementation (design can be drafted, build starts after Phase 5 closure).
