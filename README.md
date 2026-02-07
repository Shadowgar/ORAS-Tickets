# ORAS-Tickets (Internal)

ORAS-Tickets is an **internal-only** WordPress add-on for the Oil Region Astronomical Society (ORAS).

Logical project name: **ORAS Events Add-On**  
Repository name: **ORAS-Tickets** (unchanged):contentReference[oaicite:6]{index=6}:contentReference[oaicite:7]{index=7}

It is built on top of:
- The Events Calendar (TEC)
- Event Tickets (free)
- WooCommerce (Stripe via WooCommerce gateway)

Tickets are a foundational module within a broader event-enhancement platform.

---

## Core Principles (DO NOT VIOLATE)

1. **Event Tickets (free) remains installed and active**:contentReference[oaicite:8]{index=8}
2. ORAS-Tickets is an **add-on**, not a fork:contentReference[oaicite:9]{index=9}
3. **Do NOT modify** TEC, Event Tickets, or WooCommerce core plugin files:contentReference[oaicite:10]{index=10}
4. **WooCommerce is the commerce engine** (cart/checkout/stock behavior stays Woo-native):contentReference[oaicite:11]{index=11}
5. **No external services** (no license servers, update engines, telemetry, SaaS dependencies):contentReference[oaicite:12]{index=12}
6. **Deterministic, auditable behavior** (minimal magic; explicit logic):contentReference[oaicite:13]{index=13}
7. **Frontend tickets render automatically on event pages**
   - Current implementation uses the `the_content` filter (intentional and accepted):contentReference[oaicite:14]{index=14}
   - Migration to ET v2 views is deferred to a later phase:contentReference[oaicite:15]{index=15}

---

## Required Reading (in order)

Before writing or changing code, read:
1. `docs/CURRENT_STATE.md` (authoritative state / roadmap; wins conflicts):contentReference[oaicite:16]{index=16}
2. `docs/PROJECT_STATE.md` (what the project is)
3. `docs/COPILOT_CONTEXT.md` (non-negotiables and “how we build” rules):contentReference[oaicite:17]{index=17}
4. `docs/EVENT_TICKETS_ENGINE_ARCHITECTURE.md`
5. `docs/EVENT_TICKETS_PLUS_FEATURES.md`
6. `docs/ET_CODEMAP.md`
7. `docs/ET_PLUS_PARITY_MATRIX.md`

---

## Current Status

### Phases 1.x – 3.0: CLOSED
Tickets core, Woo lifecycle, refunds, reporting, and admin UI are complete and closed:contentReference[oaicite:18]{index=18}.

### Phase 3.1: COMPLETED (Frontend UX polish)
Phase 3.1 is complete. Highlights:
- Tickets render only when currently on sale (sale window enforced at display time):contentReference[oaicite:19]{index=19}
- Sold-out tickets remain visible during sales window unless `hide_sold_out` is enabled:contentReference[oaicite:20]{index=20}
- Woo products are `post_status=publish` and `catalog_visibility=hidden`:contentReference[oaicite:21]{index=21}
- Add-to-cart uses a custom POST handler (event permalink), while cart/checkout validation uses Woo hooks:contentReference[oaicite:22]{index=22}
- Phase 3.x exclusions remain explicit: no cart icon, no member logic, no merchandise, no attendees:contentReference[oaicite:23]{index=23}

### Next Allowed Phase: 3.2 — Time-based pricing / Early Bird
Phase 3.2 scope includes:
- Pricing phases per ticket
- Automatic price switching
- Early bird badge
- Countdown to cutoff
- Stripe metadata phase labels:contentReference[oaicite:24]{index=24}:contentReference[oaicite:25]{index=25}

No new work should begin until documentation is aligned (this README update is part of that closeout):contentReference[oaicite:26]{index=26}.

---

## High-level Architecture (Current)

Frontend rendering (current implementation):
- Tickets display is appended on single `tribe_events` via `the_content` filter
- POST handling via `template_redirect`
- Cart and checkout revalidation via Woo hooks:contentReference[oaicite:27]{index=27}

Data model (versioned post meta):
- Event meta:
  - `_oras_tickets_v1` (ticket definitions envelope)
  - `_oras_tickets_woo_map_v1` (ticket index → Woo product ID map)
- Product meta:
  - `_oras_ticket_event_id`
  - `_oras_ticket_index`:contentReference[oaicite:28]{index=28}

Commerce model:
- One hidden WooCommerce product per ticket
- Woo is responsible for checkout and stock mechanics; ORAS-Tickets only validates ticket-specific constraints (sale windows, malformed items) via hooks:contentReference[oaicite:29]{index=29}

---

## Forbidden Actions

- Do NOT modify Event Tickets core files:contentReference[oaicite:30]{index=30}
- Do NOT modify The Events Calendar core files
- Do NOT modify WooCommerce core files
- Do NOT add licensing, update checks, telemetry, or external calls:contentReference[oaicite:31]{index=31}
- Do NOT inject UI into themes (header/footer) as part of Phase 3.x (cart icon/widgets are post-3.x):contentReference[oaicite:32]{index=32}

---

## Development

Local dev uses `wp-env` (repo includes configuration). Use the project’s existing workflow:
- VS Code + GitHub Copilot (“vibe coding”)
- One step at a time: Copilot prompt → review diff → test → next prompt

Documentation-first rule:
- If behavior changes, docs must be updated to match before moving phases.

---

## Roadmap (Authoritative)

See:
- `docs/CURRENT_STATE.md` for the active roadmap and locked order of phases:contentReference[oaicite:33]{index=33}
- `docs/NEXT.md` for the single focus / next allowed work:contentReference[oaicite:34]{index=34}
