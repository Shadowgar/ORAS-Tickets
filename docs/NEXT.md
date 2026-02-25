# NEXT — Immediate Work Queue

Last updated: 2026-02-25

## Current Sprint Goal
Close Phase 5 hardening gates so Phase 6 work can begin safely.

## Ordered Tasks
1. Phase 5.2 Waitlist Architecture Finalization
- Completed: first-class waitlist table, lifecycle states, audit fields, and deterministic promotion integration.
- Remaining: admin queue operations beyond single promote and a readable audit/history surface.

2. Phase 5 Verification Suite
- Add WP-CLI integration checks for:
  - RSVP yes/no/waitlist transitions
  - waitlist promotion
  - RSVP exports
  - attendees CSV export
  - attendees note save + messaging endpoint contract

3. CI Enforcement
- Add/update GitHub workflow to run:
  - `php -l`
  - `composer phpcs`
  - `composer phpstan`
  - WP-CLI integration checks

4. Phase 4 Visual Polish Pass
- Remove temporary inline styling where practical.
- Replace temporary agenda dark-mode overrides with production-ready tokens.
- Validate desktop + mobile UI quality for agenda and speaker surfaces.

## Out of Scope Until Above Is Done
- New Phase 6+ feature implementation (QR/check-in, reservation windows, advanced automation).
