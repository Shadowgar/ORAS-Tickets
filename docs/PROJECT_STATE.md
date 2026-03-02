# PROJECT_STATE — Canonical Project Definition

Last updated: 2026-03-02

## Project Identity
- Name: ORAS Events Add-On
- Repository: ORAS-Tickets
- Namespace: `ORAS\\Tickets`
- Companion: ORAS Member Hub

## Purpose
Provide deterministic, auditable, ORAS-specific event operations on top of WordPress + TEC + WooCommerce without forking upstream plugins.

## Platform Stack
- WordPress
- The Events Calendar (TEC) + Events Calendar Pro
- Event Tickets (free)
- WooCommerce
- Stripe (via WooCommerce gateway)
- Paid Memberships Pro (PMPro)

## Non-Negotiables
1. ORAS-Tickets is an add-on; no core plugin forks or edits.
2. WooCommerce remains the single commerce engine.
3. Versioned envelopes are required for ORAS-owned data models.
4. Capability-gated admin operations are mandatory.
5. No telemetry or external SaaS lock-in.

## Documentation Authority
- Strategic roadmap: `docs/MASTER_DEVELOPMENT_PLAN.md`
- Operational tracker (authoritative phase progress): `docs/MASTER_EXECUTION_TRACKER.md`
- Immediate queue: `docs/NEXT.md`

If completion percentages differ, `MASTER_EXECUTION_TRACKER.md` wins.

Current recalculation artifact:
- `docs/PHASE_COMPLETENESS_AUDIT_2026-03-02.md`

## Current Project Maturity (Master-plan aligned)
- Overall completion: **58.5%**
- Core ticketing and reporting foundations are production-capable.
- Phases 0-5 are governance-locked for the current execution baseline.
- Phase 5.3 remains paused for advancement pending production WP-CLI availability and external approvals.
- Phases 6+ are mostly planned and should not advance until governance explicitly opens the next gate.
- Strategic scope now includes a board-only executive dashboard (Members Hub style) under Phase 9.5/10.4.

## Current Enforcement Decision
The project is in **locked-baseline enforcement mode**:
- Maintain Phase 0-5 lock integrity.
- Keep Phase 5.3 paused until constraints lift.
- Do not begin Phase 6+ build-out until governance opens the next gate.

## Evidence Snapshot (2026-03-01)
Recently completed hardening in code:
- RSVP intent parsing and waitlist actions corrected.
- First-class waitlist table with lifecycle/audit tracking added and wired into RSVP/admin promotion paths.
- Promotion handler conflicts removed.
- Unlimited-capacity checks corrected.
- Admin attendee rendering escaped.
- Frontend RSVP submission target bug fixed (form `action` shadowing issue resolved).
- Attendee list integrity corrected (ticket attendees no longer collapse by user; quantities now counted).
- Virtual access duplicate hook/log cleanup.
- Phase 5 WP-CLI integration check harness added.
- CI workflow added to run PHPCS, PHPStan, and Phase 5 checks.
- Waitlist queue operations and audit/history surface added to RSVP dashboard.
- New waitlist AJAX operation handlers added and covered by integration checks.
- Attendee dashboard ticket-source coverage expanded to all supported Woo statuses; on-hold/all status regression checks added.
- Centralized CSV export safety helper added and applied across RSVP/attendee/report/speaker export surfaces.
- Admin RSVP dashboard row rendering moved to DOM-safe element construction.
- Event-scoped lock helper added and applied to frontend/admin RSVP and waitlist promotion critical sections.
- Woo capacity consume/restore handlers hardened with order-scoped idempotency lock + event-scoped atomic ticket-envelope updates.
- QuickBooks reclass source-match hardening completed (queryability-safe source matching + customer entity propagation on JE lines).
- QuickBooks waiting queue and review escalation flow added for delayed source transaction visibility.
- QuickBooks Pending + Sync History operational tabs added with sync/approve/resync/reverse actions.
- QuickBooks safe add-only event-account auto-map added (manual action + refresh/event-update automation).
- Static analysis clean (`phpstan`, `phpcs`).

## Locked vs Active
- Locked baseline: Phases 0-5.
- Active: Phase 5.3 paused-state readiness artifacts only.
- Planned: Phases 6-12, gated by explicit post-lock governance decision.
