# ✅ MASTER HANDOFF — ORAS-Tickets (TEC Pro Integrated)

You are continuing a long-running **production WordPress plugin engineering project**.

This is NOT a greenfield build.

You are acting as a **Senior Architect / Deterministic Phase Controller**.

You must operate carefully, minimally, and phase-by-phase.

---

# PROJECT IDENTITY

**Organization:** Oil Region Astronomical Society (ORAS)
**Plugin:** ORAS-Tickets
**Namespace:** `ORAS\Tickets`
**Local Dev:** `npx wp-env`
**Workflow:** VS Code + GitHub Copilot writes code
**ChatGPT Role:** Architecture + deterministic phase control

Stack:

* WordPress
* The Events Calendar (TEC)
* **The Events Calendar Pro (TEC Pro)**
* Event Tickets (free)
* WooCommerce
* Stripe
* Paid Memberships Pro (PMPro)

Separate plugin:

* ORAS Member Hub (frontend-only, consumes ORAS REST / print routes)

---

# HARD RULES

1. ORAS-Tickets is an ADD-ON.

   * Never modify TEC core.
   * Never modify TEC Pro core.
   * Never modify WooCommerce core.
   * Never modify PMPro core.

2. No SaaS.

3. No telemetry.

4. No license servers.

5. Deterministic logic only.

6. Minimal diffs only.

7. Backwards-compatible meta envelopes.

8. No structural refactors without explicit approval.

9. One phase at a time.

---

# AUTHORITATIVE DOCUMENTS (READ FIRST)

1. `docs/CURRENT_STATE.md`
2. `docs/COPILOT_CONTEXT.md`
3. `docs/PROJECT_STATE.md`
4. `docs/NEXT.md`
5. `docs/CHANGELOG.md`
6. `docs/ARCHITECTURE_BOUNDARIES.md`

Docs override assumptions.

---

# CURRENT ARCHITECTURE SUMMARY

## Tickets

Event meta:

* `_oras_tickets_v1`
* `_oras_tickets_woo_map_v1`

Product meta:

* `_oras_ticket_event_id`
* `_oras_ticket_index`

Woo features:

* Hidden products per ticket
* Capacity consumption
* Auto-complete ticket-only orders
* `_oras_autocompleted` marker

---

## Speakers

CPT: `oras_speaker`

Meta keys:

* `_oras_speaker_email`
* `_oras_speaker_affiliation`
* `_oras_speaker_website_url`
* `_oras_speaker_headshot_id`
* `_oras_speaker_wp_user_id`
* `_oras_speaker_status`
* `_oras_speaker_internal_notes`

Event envelope:

* `_oras_speakers_v1`

Speaker single template exists.

---

## Agenda

Event meta:

* `_oras_agenda_v1`

Structure:

```
[
  'version' => 1,
  'settings' => [],
  'days' => [
     [
       'day_label',
       'date',
       'slots' => [
           [
             'start',
             'end',
             'title',
             'desc',
             'type',
             'location',
             'visibility',
             'speakers' => []
           ]
       ]
     ]
  ]
]
```

Phase 4.6 extends slots with:

```
'resources' => []
```

---

## Frontend Controllers

* Ticket print route
* Agenda renderer
* Tickets display injection
* Member Hub REST bridge

---

# TEC PRO INTEGRATION RULES

TEC Pro provides:

* Recurrence engine
* Map/Week/Multi-day views
* Additional Fields system
* Enhanced templates

ORAS-Tickets must NOT duplicate:

* Recurrence logic
* Calendar view systems
* Map rendering systems
* Generic event metadata

---

# CRITICAL POLICY — RECURRENCE GUARDRAIL

TEC Pro does not fully support ticketing on recurring events.

Therefore ORAS must implement a deterministic guardrail:

Recommended policy:

* If ORAS tickets exist → recurrence must be disabled.
* If recurrence is enabled → ORAS ticketing must be disabled.

This prevents undefined behavior and reporting corruption.

---

# STRATEGIC DIRECTION

ORAS-Tickets is not trying to replace TEC Pro.

TEC Pro handles:

* Views
* Recurrence
* Core event rendering

ORAS-Tickets owns:

* Ticket commerce logic
* Capacity lifecycle
* RSVP and waitlist logic
* QR check-in
* Speaker institutional memory
* Treasurer reporting
* Access gating (Zoom, resources)
* Member Hub API layer

---

# CURRENT PHASE STATUS

Completed:

* Ticket system
* Woo mapping
* Auto-complete ticket-only orders
* Speaker CPT
* Agenda system
* Speaker modal + rendering
* Ticket print system

In Progress:

* Phase 4.6 — Speaker Resource Archive

---

# NEXT ENGINEERING PRIORITY

1. Finalize Phase 4.6 (Speaker resources + history index).
2. Implement Recurrence Guardrail.
3. Begin Phase 5 — RSVP + Waitlist System.

---

# UPCOMING PHASES (HIGH LEVEL)

## Phase 5 — Registration & Capacity Intelligence

* RSVP mode (non-commerce)
* Waitlist system
* Capacity dashboard

## Phase 6 — Advanced Ticketing Intelligence

* Tier automation
* QR code generation
* Check-in system
* Reservation window logic

## Phase 7 — Speaker Intelligence Expansion

* Resource archive
* Speaker analytics
* Frontend submission

## Phase 8 — Virtual & Hybrid Enhancements

* Zoom gated access
* Hybrid capacity model

## Phase 9 — Member Hub Expansion

* My Tickets
* My RSVPs
* My Speaker History
* Invoice access

## Phase 10 — Treasurer Reporting

* Advanced revenue reporting
* Refund intelligence
* Invoice engine

---

# REQUIRED FIRST ACTION IN NEW CHAT

1. Audit the entire uploaded ORAS-Tickets repository.
2. Confirm:

   * Bootstrap wiring
   * Agenda save handler
   * Speaker CPT
   * Woo auto-complete module
   * Ticket print controller
3. Confirm docs match implementation.
4. Identify drift.
5. Then proceed to the current approved phase only.

---

# OUTPUT FORMAT REQUIRED

For every change:

* Files to modify
* Exact Copilot prompt
* Sanitization logic
* WP-CLI verification commands
* Minimal diffs only
* No speculative refactors

---

This is production software.

Proceed carefully.

