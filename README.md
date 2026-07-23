# ORAS-Tickets

ORAS-Tickets is an internal WordPress add-on for Oil Region Astronomical Society event operations.

It extends a WordPress + The Events Calendar + WooCommerce + PMPro stack with ORAS-specific ticketing, RSVP/waitlist operations, speaker workflows, reporting, and QuickBooks sync orchestration.

## What this plugin is

- Add-on architecture only (does not modify TEC, WooCommerce, PMPro, or Event Tickets core)
- Deterministic storage patterns (versioned envelopes and strict schema handling)
- Capability-gated admin and operator actions
- WordPress-native operational tooling (AJAX actions, WP-CLI scripts, admin dashboards)

## Current features (implemented)

- Ticket model and WooCommerce mapping
  - Event ticket envelopes and deterministic ticket/product mapping
  - Cart/checkout revalidation and capacity mutation hardening
- Event admin surfaces
  - Unified ORAS Events add-on metabox
  - Agenda and speaker management baselines
  - Door prizes and attendee operations hooks
- Registration and capacity intelligence (Phase 5 surface)
  - RSVP mode for non-commerce attendance
  - Waitlist queueing, promotion flows, queue/history operator actions
  - Capacity and attendance dashboard surfaces
- Reporting and exports
  - Treasurer baseline reporting and CSV export workflows
  - Reconciliation detail surface for QBO-linked order snapshots
- QuickBooks integration (Phase 5.3 pre-live)
  - OAuth + connection controls
  - Dry-run/manual-approval/strict-mapping safety controls
  - JournalEntry split sync orchestration, retries, waiting queue, reversal paths
  - Reconciliation tooling and CLI/admin operator workflows
- Zoom attendee integration
  - Server-to-Server OAuth with encrypted credentials
  - Event-level managed registration using The Events Calendar Zoom meetings
  - Automatic attendee-specific access for paid virtual ticket buyers
  - Board-approved virtual RSVP registration and cancellation synchronization
  - Full invitation details with shared-link fallback

## Planned features (roadmap)

Phase-gated items remain intentionally deferred until current stabilization gates are passed.

- Phase 5 completion hardening
  - Operator soak completion on waitlist queue/history workflows
  - QBO pre-live closure tasks (smoke/reconciliation reruns, signoff, production app approval)
- Phase 6+ (after gate)
  - Advanced ticketing intelligence (tier enhancements, QR tickets, check-in system)
  - Optional seat reservation flows
  - Expanded speaker intelligence and analytics
  - Member Hub expansions (My RSVPs, speaker history, invoice access)

See strategic detail in docs:
- `docs/MASTER_EXECUTION_TRACKER.md`
- `docs/CURRENT_STATE.md`
- `docs/MASTER_DEVELOPMENT_PLAN.md`

## Local development

### Runtime environment
Use the dedicated environment workspace in `oras-wp-env`, which maps this plugin into wp-env.

Start environment:

```bash
cd /home/rocco/projects/oras-wp-env
npx wp-env start
```

### Static analysis

```bash
cd /home/rocco/projects/ORAS-Tickets
composer phpstan
```

### Regression checks (run in oras-wp-env)

```bash
cd /home/rocco/projects/oras-wp-env
npx wp-env run cli wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/core-regression-checks.php
npx wp-env run cli wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/bootstrap-regression-checks.php
```

## Documentation map

The documentation index and authoritative status sources are in:
- `docs/README.md`

Key references:
- `docs/ARCHITECTURE_BOUNDARIES.md`
- `docs/EVENT_TICKETS_ENGINE_ARCHITECTURE.md`
- `docs/EVENT_TICKETS_PLUS_FEATURES.md`
- `docs/CHANGELOG.md`
