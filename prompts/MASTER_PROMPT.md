# ✅ MASTER HANDOFF — ORAS-Tickets (TEC Integrated)

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
   * Never modify Event Tickets core.
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
* `_oras_speaker_history_v1` (Phase 4.6)

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
             'speakers' => [],
             'resources' => []  // Phase 4.6
           ]
       ]
     ]
  ]
]
```

---

## Frontend Controllers

* Ticket print route
* Agenda renderer
* Tickets display injection
* Member Hub REST bridge

---

# CRITICAL POLICY — RECURRENCE GUARDRAIL

TEC does not fully support ticketing on recurring events.

Therefore ORAS must implement a deterministic guardrail:

Recommended policy:

* If ORAS tickets exist → recurrence must be disabled.
* If recurrence is enabled → ORAS ticketing must be disabled.

This prevents undefined behavior and reporting corruption.

---

# STRATEGIC DIRECTION

ORAS-Tickets is not trying to replace TEC.

TEC handles:

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
* Speaker history index (Phase 4.6.1)
* Speaker resource archive (Phase 4.6.2)
* Recurrence guardrail (Phase 4.7)

In Progress:

* Phase 5 — RSVP + Waitlist System (frontend RSVP + waitlist implemented; virtual access gating implemented; REST endpoints implemented; admin management panel implemented). See `plugin/includes/Frontend/Event_RSVP.php`, `plugin/includes/Frontend/Virtual_Access.php`, `plugin/includes/Api/Rsvp.php`, and `plugin/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php`.

Architectural correction & next phases:

The event editor UI will be consolidated into a single master metabox (`ORAS EVENTS ADDON`) with vertical tabs for Tickets, Agenda, RSVP, Speakers, and Virtual Access (Phase 5.3). RSVP attendee lists, exports, and waitlist management will move from the event editor to a Dashboard management UI (Phase 5.4). Global defaults and feature toggles will be centralized under `ORAS-Tickets → Settings` (Phase 5.5). These changes are UI/layout focused; no meta schema or DB changes are required.

---

# NEXT ENGINEERING PRIORITY

1. Phase 4.7 (Recurrence Guardrail) is complete.
2. Begin Phase 5 — RSVP + Waitlist System.

---

# UPCOMING PHASES (HIGH LEVEL)

## Phase 5 — Registration & Capacity Intelligence

* RSVP mode (non-commerce registration)
* Waitlist system with priority promotion
* Capacity tracking and management
* Email confirmations and verification flows
* Admin dashboard with real-time metrics
* CSV/Excel export capabilities
* PMPro member restrictions

## Phase 6 — Advanced Ticketing Intelligence

* Structured ticket tier system (early bird/member/public)
* Date-based automatic pricing windows
* QR code generation and validation
* Check-in system with attendance tracking
* Reservation window logic with temporary holds
* Per-user purchase limits and enforcement

## Phase 7 — Speaker & Content Intelligence

* Speaker resource archive (slides/handouts/links)
* Speaker performance analytics and metrics
* Frontend speaker submission system
* Review queue and approval workflow
* Institutional memory preservation
* Speaker contribution scoring

## Phase 8 — Virtual & Hybrid Event System

* Zoom gated access based on tickets/RSVP
* Virtual-only ticket types
* Hybrid capacity modeling
* Virtual attendance tracking
* Recording access controls
* Post-event content distribution

## Phase 9 — User Dashboard (Member Hub Expansion)

* My Tickets and RSVP management
* My Speaker History archive
* Downloadable tickets and invoices
* Printable badges and materials
* Check-in status display
* Personalized event recommendations

## Phase 10 — Financial & Reporting Intelligence

* Advanced revenue analytics suite
* Automated PDF invoice generation
* Refund intelligence and tracking
* Treasurer-grade financial reporting
* Comprehensive export capabilities
* Tax calculation and compliance

## Phase 11 — Discovery & UX Features

* Event discovery and recommendation engine
* Advanced search and filtering
* Mobile optimization and accessibility
* User experience enhancements

## Phase 12 — Automation & Notifications

* Event communication automation (reminders)
* Post-event follow-up system
* Feedback form integration
* Engagement tracking and analytics

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

---

## HANDOFF PROMPT (Give this to ChatGPT with repository ZIP attached)

When you receive the ORAS-Tickets repository as a zip file, use the following instructions verbatim. Do not assume external context beyond the repository and the attachments.

1) Immediate audit (files to open first)
 - `plugin/includes/Bootstrap.php` — confirm registration of modules and where to hook Phase work (search for `register_phase1`).
 - `plugin/includes/Frontend/Event_RSVP.php` — frontend RSVP rendering and admin-post handler.
 - `plugin/includes/Frontend/Virtual_Access.php` — virtual event access gating logic.
 - `plugin/includes/Api/Rsvp.php` — REST endpoints for Member Hub (GET `/oras/v1/rsvp/my`, `/oras/v1/rsvp/event/{id}`).
 - `plugin/includes/Admin/Metaboxes/Event_RSVP_Metabox.php` — RSVP settings metabox.
 - `plugin/includes/Admin/Metaboxes/Event_RSVP_Attendees_Metabox.php` — RSVP attendees list, CSV export, promote action.
 - `plugin/assets/css/tickets-frontend.css` — frontend styles for RSVP block.
 - Docs: `docs/CURRENT_STATE.md`, `docs/PROJECT_STATE.md`, `docs/CHANGELOG.md`, `docs/NEXT.md` — read for phase status and planned work.

2) Hard rules (must follow exactly)
 - No new database tables.
 - No structural refactors without explicit approval.
 - Minimal diffs only; prefer small, targeted changes.
 - Do NOT modify The Events Calendar (TEC) core, Event Tickets, WooCommerce, or PMPro core files.
 - Follow existing meta envelope and usermeta patterns (`_oras_rsvp_v1`, `_oras_rsvp_event_{EVENT_ID}`).

3) Testing / smoke-check commands (use inside `oras-wp-env`)
 - Start environment:
```bash
npx wp-env start
```
 - Verify RSVP REST routes (if WP bootstrap memory is low, include PHP memory flag):
```bash
cd oras-wp-env
npx wp-env run cli php -d memory_limit=512M /usr/local/bin/wp eval ' $r = rest_get_server()->get_routes(); echo isset($r["/oras/v1/rsvp/my"]) ? "my:ok\n":"my:missing\n"; echo isset($r["/oras/v1/rsvp/event/(?P<id>\\d+)"]) ? "event:ok\n":"event:missing\n"; '
```
 - Exercise endpoints as an authenticated user (user id 1):
```bash
npx wp-env run cli php -d memory_limit=512M /usr/local/bin/wp eval 'wp_set_current_user(1); $req=new WP_REST_Request("GET","/oras/v1/rsvp/my"); $res=rest_do_request($req); echo $res->get_status(); echo "\n"; echo wp_json_encode($res->get_data());'
```
 - Verify metabox registration (manual WP eval):
```bash
npx wp-env run cli wp eval 'global $wp_meta_boxes; add_action("add_meta_boxes", function(){ global $post; $post=get_post(10000001); do_action("add_meta_boxes");}); do_action("add_meta_boxes"); var_export(isset($wp_meta_boxes["tribe_events"]["normal"]["default"]["oras_event_rsvp_attendees_metabox"]));'
```

4) Expected behaviors to confirm during audit
 - Frontend RSVP renders on single `tribe_events` when `_oras_rsvp_v1.enabled` is true.
 - RSVP user state stored in usermeta key `_oras_rsvp_event_{EVENT_ID}` with values `yes|no|waitlist`.
 - REST endpoints return deterministic JSON and require authentication.
 - Admin metabox `RSVP Attendees` shows counts and lists and exposes CSV export and promote actions.

5) Next recommended engineering task (start here)
 - Phase 5.3 — Implement `Event_Addon_Metabox.php` (master metabox):
   - Create `plugin/includes/Admin/Metaboxes/Event_Addon_Metabox.php` which renders a single master metabox titled `ORAS EVENTS ADDON`.
   - Left column: vertical nav (Tickets, Agenda, RSVP, Speakers). Right column: content panels.
   - Reuse existing rendering methods from `Tickets_Metabox`, `Event_Agenda_Metabox`, `Event_RSVP_Metabox` inside the respective panels.
   - Do NOT change underlying meta keys or save handlers — call existing save handlers as-is.
   - Minimal CSS additions allowed in `plugin/assets/css/tickets-frontend.css` or an admin CSS file; no frameworks.

6) Acceptance criteria for Phase 5.3
 - Single master metabox appears on the event edit screen and contains tabs.
 - Existing per-feature forms render unchanged inside their tab panels.
 - Saving the event preserves existing meta shapes and behavior.
 - No new DB tables and no core/plugin edits outside the plugin.

7) Developer guidelines for implementation
 - Always run `php -l` on modified PHP files before committing.
 - Run `npx wp-env run cli wp eval` checks above to validate runtime routes and metabox registration.
 - Keep commits small and descriptive; include which phase and short description.

8) Files to update when implementing Phase 5.3 (minimal diffs)
 - `plugin/includes/Admin/Metaboxes/Event_Addon_Metabox.php` (new)
 - `plugin/includes/Bootstrap.php` (require and register the new metabox; unregister old metaboxes only after new one is live)
 - `plugin/assets/css/tickets-frontend.css` (minor admin tab styling only)

9) If you run into memory/boot issues during WP-CLI checks
 - Increase PHP memory for the WP-CLI command with `php -d memory_limit=512M /usr/local/bin/wp ...` as shown above.

10) What to hand back in a PR or commit
 - Small focused commits implementing the UI container and wiring; include `php -l` validation output, and a short testing checklist in the PR description with the commands used.

Use this handoff text exactly when you upload the repo to the next assistant. The assistant should not guess repository layout beyond these paths — open and read the files above first.


Proceed carefully.

## Recent Sessions

### Session Snapshot
- Date: 2026-02-15T06:29:39.033280Z
- Author: github-actions[bot] <41898282+github-actions[bot]@users.noreply.github.com>
- Commit: 2fbd2a0
- Commit message: chore: auto-update MASTER_PROMPT.md (action)
- Goal: chore: auto-update MASTER_PROMPT.md (action)
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - prompts/MASTER_PROMPT.md
  - prompts/sessions/auto-20260215-051140-e4a73f5.md

### Session Snapshot
- Date: 2026-02-14T11:04:47.985446Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 2f147bd
- Commit message: Phase 4.7 Completed.
- Goal: Phase 4.7 Completed.
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - .wp-env.json
  - .wp-env/php/php.ini
  - plugin/includes/Admin/Tickets_Metabox.php
  - prompts/MASTER_PROMPT.md
  - prompts/sessions/auto-20260214-105713-0b5fc35.md

### Session Snapshot
- Date: 2026-02-14T10:57:13.920595Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 0b5fc35
- Commit message: Change Speaker Contributes
- Goal: Change Speaker Contributes
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - plugin/templates/single-oras_speaker.php
  - prompts/MASTER_PROMPT.md
  - prompts/sessions/auto-20260214-100446-65af058.md

### Session Snapshot
- Date: 2026-02-14T10:04:46.686905Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 65af058
- Commit message: Update Prompt
- Goal: Update Prompt
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - prompts/MASTER_PROMPT.md
  - prompts/sessions/auto-20260214-093648-88d1631.md

### Session Snapshot
- Date: 2026-02-14T09:36:48.747270Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 88d1631
- Commit message: Agenda Updates
- Goal: Agenda Updates
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - plugin/includes/Frontend/Event_Agenda_Render.php
  - prompts/MASTER_PROMPT.md
  - prompts/sessions/auto-20260214-093129-4501a00.md

