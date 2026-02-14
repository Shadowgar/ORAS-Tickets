# ORAS-Tickets (Internal)

ORAS-Tickets is an **internal-only** WordPress plugin for the Oil Region Astronomical Society (ORAS). It extends our events + ticketing workflow while keeping the site’s core commerce and event systems intact.

- **Repository name:** ORAS-Tickets (kept as-is)
- **Logical project name:** ORAS Events Add-On
- **Stack:** WordPress + The Events Calendar (TEC) + Event Tickets (free) + WooCommerce (Stripe via WooCommerce gateway)

This plugin is one module within a broader, phased “event enhancement” platform for ORAS.

---

## What this plugin does

ORAS-Tickets adds ORAS-specific ticketing and reporting capabilities while:

- Using **The Events Calendar** as the event system (`tribe_events`)
- Keeping **WooCommerce** as the **only** commerce engine (cart, checkout, payment, stock)
- Keeping **Event Tickets (free)** installed and active for compatibility and future evolution
- Storing ORAS-specific structures in **versioned post meta envelopes** (deterministic, auditable)

---

## Non-negotiable principles

These are project constraints. Do not violate them without explicit approval.

1. **Event Tickets (free) remains installed and active.**
2. ORAS-Tickets is an **add-on**, not a fork.
3. **Do not modify** TEC, Event Tickets, WooCommerce, or PMPro core plugin files.
4. **WooCommerce owns commerce** (cart/checkout/stock stays Woo-native).
5. **No external services**: no license servers, telemetry, SaaS dependencies, or outbound tracking.
6. **Deterministic, auditable behavior**: minimal magic; explicit logic; versioned meta.
7. **Frontend tickets render automatically on event pages**  
   - Current implementation uses the `the_content` filter (intentional and accepted).  
   - Migration to ET v2 views is deferred to a later phase.

---

## Required reading (in order)

Before writing or changing code, read these files in this order:

1. `docs/CURRENT_STATE.md` (authoritative state + roadmap; wins conflicts)
2. `docs/COPILOT_CONTEXT.md` (non-negotiables + how we build)
3. `docs/PROJECT_STATE.md` (project description and scope)
4. `docs/EVENT_TICKETS_ENGINE_ARCHITECTURE.md`
5. `docs/EVENT_TICKETS_PLUS_FEATURES.md`
6. `docs/ET_CODEMAP.md`
7. `docs/ET_PLUS_PARITY_MATRIX.md`

---

## Current status (high level)

### Closed phases
- **Phases 0 → 3.2:** Complete / closed  
- **Phase 3.3:** Complete / closed  
- **Phase 3.4-A / 3.4-B:** Complete / closed  
- **Phase 3.5-A / 3.5-B / 3.5-C:** Complete / closed  
- **Phase 4.1:** Speaker Management (MVP) complete / closed
- **Phase 4.1-B:** Public speaker profiles + speaker event display complete / closed
- **Phase 4.5.1:** Agenda MVP (multi-day metabox + frontend renderer) complete / closed
- **Phase 4.5.3:** Current-slot highlight + autoscroll complete / closed
- **Phase 4.5.4:** Agenda speaker modal UX complete / closed
- **Phase 4.6.1:** Speaker historical index complete / closed
- **Phase 4.6.2:** Speaker resource archive complete / closed
- **Phase 4.7:** Recurrence guardrail complete / closed

> The definitive, locked phase plan is maintained in `docs/CURRENT_STATE.md`.

### What’s next
See:
- `docs/NEXT.md` for the single current focus / next allowed work
- `docs/CURRENT_STATE.md` for the locked roadmap and phase order

---

## High-level architecture

### Frontend rendering (current)
- Tickets UI is appended to single `tribe_events` via the `the_content` filter
- Add-to-cart uses a custom POST handler (event permalink / `template_redirect`)
- Cart and checkout revalidation uses WooCommerce hooks (sale windows, malformed items, etc.)

### Data model (versioned post meta envelopes)
Stored on the **event**:
- `_oras_tickets_v1` — ticket definitions envelope
- `_oras_tickets_woo_map_v1` — ticket index → Woo product ID map
- `_oras_speakers_v1` — event speaker assignments envelope (Phase 4)
- `_oras_agenda_v1` — event agenda envelope (`settings`, `days[]`, `slots[]`)

Stored on the **Woo product** (hidden per-ticket products):
- `_oras_ticket_event_id`
- `_oras_ticket_index`

### Commerce model (strict)
- One **hidden** WooCommerce product per ticket
- WooCommerce handles checkout, payment, and stock mechanics
- ORAS-Tickets validates ticket constraints (sale windows, mapping integrity, etc.) via Woo hooks

---

## Admin features (current)

This plugin includes internal admin pages and tooling for ORAS operations, including:

- Ticket configuration and Woo mapping
- Reporting and exports (treasurer/admin visibility)
- Speaker management (CPT + event assignments)
- Speaker obligations fulfillment (including PMPro membership fulfillment)
- Agenda management metabox (multi-day, nested day/slot/speaker repeaters, date/time pickers)
- Frontend agenda rendering with current-slot highlight/autoscroll option
- Speaker modal popup from agenda slots with profile-link handoff

Exact behavior and phase scope details belong in `docs/CURRENT_STATE.md` and `docs/PROJECT_STATE.md`.

---

## Forbidden actions

- Do not modify core files of:
  - The Events Calendar (TEC)
  - Event Tickets (free)
  - WooCommerce
  - Paid Memberships Pro (PMPro)
- Do not add:
  - Licensing servers, update engines, telemetry, analytics beacons
  - External service dependencies required for normal operation
- Do not inject global theme UI (header/footer cart icons/widgets) unless the phase explicitly allows it

---

## Development workflow

### Local development
Local dev runs with `wp-env` (repo includes configuration). Typical loop:

1. Write a Copilot prompt
2. Review the diff carefully
3. Run verification steps (WP-CLI checks where applicable)
4. Commit
5. Move to the next step

### Documentation-first rule
If behavior changes, update documentation **before** moving to the next phase.

---

## Repository hygiene

Tracked:
- `/plugin/` (the plugin)
- `/docs/`
- `composer.json` + `composer.lock`
- `package.json` + `package-lock.json`
- `phpstan.neon`, `phpcs.xml`
- `.github/*` (workflows/agents as used by the project)

Ignored (examples; see `.gitignore`):
- `/vendor/`
- `/node_modules/`
- `.env*`
- `.phpstan-cache/`
- build artifacts (e.g., `plugin.zip`)
- logs

---

## Support / ownership

This is an internal ORAS engineering project. External support expectations do not apply.

For active work and current constraints, always defer to:
- `docs/CURRENT_STATE.md`
- `docs/NEXT.md`
- `docs/COPILOT_CONTEXT.md`
## Roadmap (locked order)

The authoritative roadmap and phase details live in `docs/CURRENT_STATE.md`. This section is a high-level orientation only.

### Completed (closed)
- Phases 0 → 3.5 (ticketing core + pricing phases + reporting)
- Phase 4.1 — Speaker Management (MVP)
- Phase 4.1-B — Public Speaker Profiles & Event Display
- Phase 4.5.1 / 4.5.3 / 4.5.4 — Agenda + Current Highlight + Speaker Modal

### Next (approved)
- Phase 4.2 — Speaker Reporting & Automation refinements

### Phase 5+ — Future (explicitly NOT NOW unless approved)
These items are intentionally deferred. Do not start them without explicit approval.

- Attendees / Check-in / QR
  - Check-in UI
  - QR code generation and scanning
  - Attendance exports / audit trail

- Member-only logic
  - Member-only ticket access rules
  - Member pricing or gates (if ORAS policy allows)
  - Tight integration rules with PMPro

- Agenda / speakers / resources expansion
  - Rich agenda blocks per event (beyond current `_oras_agenda_v1` baseline)
  - Speaker resource uploads and distribution
  - Public vs internal resource visibility controls

- Integrations (blocked unless ORAS policy changes)
  - Zoom / webinar integrations
  - Calendar and external platform sync
  - Any third-party automation that changes data off-site
