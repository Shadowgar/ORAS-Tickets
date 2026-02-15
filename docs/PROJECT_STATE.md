# PROJECT_STATE — Canonical Definition

This file defines WHAT this project is.
It changes rarely.

## Project
Name: ORAS Events Add-On  
Repo: ORAS-Tickets

## Purpose
Provide a modular, future-proof event enhancement platform for ORAS built on top of TEC.
Tickets remain the financial backbone.

## Stack
- WordPress
- PHP 8.0+
- The Events Calendar (TEC)
- Event Tickets (free)
- WooCommerce + Stripe
- Paid Memberships Pro (PMPro)

## Mental Model
One plugin.
Multiple modules.
Tickets are foundational, not exclusive.

## Authority
If any document conflicts with CURRENT_STATE.md, CURRENT_STATE.md wins.

## Current maturity (post Phase 4.7)
- ORAS-Tickets provides full backend ticketing, reporting, REST, and printable tickets.
- ORAS Member Hub provides member-facing display only.
- System is operational end-to-end.
- Ticketing is frontend-stable and production-ready for event pages: the ticket display, sales-window filtering, stock visibility, and add-to-cart flow are complete and intended for live use.
- ORAS-Tickets is evolving toward a broader "event add-on" system; ticketing remains the foundational module and primary supported surface.
- This document does not introduce new phases or feature commitments — it records the current scope and maturity only.

- Phase 3.2: Time-based pricing resolver is implemented and verified (frontend display, cart/checkout pricing, and order-item snapshot metadata).
- Phase 3.3: Admin UX redesign completed (tickets editor UI-only improvements).
- Phase 3.4: Treasurer reporting is complete and verified.
- Phase 3.5: Member tickets and visibility are complete (REST API, member hub display, printable tickets).
- Phase 4.1: Speaker management MVP is implemented (speaker CPT, event assignment envelope, obligations workflow).
- Phase 4.1-B: Public speaker profiles and speaker single page template routing are implemented.
- Phase 4.5.x: Agenda is implemented (multi-day admin metabox, frontend rendering, current-slot highlight/autoscroll, speaker modal popups).
- Phase 4.6.1: Speaker historical index is implemented (_oras_speaker_history_v1 envelope).
- Phase 4.6.2: Speaker resource archive is implemented (slot-level resources, speaker page rendering).
- Phase 4.7: Recurrence guardrail is implemented (prevents ORAS ticketing on recurring TEC events).

## Locked Phases
- Phase 0 is complete and locked (foundations, bootstrapping, tooling, namespaces).
- Phase 1 is complete and locked (ticket core model, deterministic identities, admin save paths).
- Phase 2 is complete and locked (WooCommerce integration, cart/checkout validation and revalidation).
- Phase 3.1 and Phase 3.2 are complete and locked. Any change affecting time-based pricing, cart price application, or the Phase 3.1 frontend sale-window behaviors requires a documented design review and migration plan.
- Phase 3.3 is complete and locked as UI-only work; future UI changes require review to avoid regressions.
- Phase 3.4 is complete and locked (treasurer reporting).
- Phase 3.5-A and Phase 3.5-B are complete and locked (REST API, grouped-by-event API).
- Phase 3.5-C is complete and locked (Member Hub ticket display).
- Phase 3.5-D is complete and locked (Printable tickets).
- Phase 4.1 is complete and locked (Speaker management MVP).
- Phase 4.1-B is complete and locked (Public speaker profiles/event display baseline).
- Phase 4.5 is complete and locked (Agenda + speaker modal UX baseline).
- Phase 4.6.1 is complete and locked (Speaker historical index).
- Phase 4.6.2 is complete and locked (Speaker resource archive).
- Phase 4.7 is complete and locked (Recurrence guardrail).
 
## Recent updates
- 2026-02-15: Phase 5.1 (RSVP frontend + waitlist) implemented: `plugin/includes/Frontend/Event_RSVP.php` provides non-commerce RSVP UI and handlers; per-user RSVP state stored in usermeta `_oras_rsvp_event_{EVENT_ID}`. Virtual access gating implemented in `plugin/includes/Frontend/Virtual_Access.php` and persisted in `_oras_virtual_access_v1`. Phase 5.1-C (RSVP REST endpoints) implemented: `plugin/includes/Api/Rsvp.php` provides GET `/oras/v1/rsvp/my` and `/oras/v1/rsvp/event/{event_id}` for Member Hub consumption.

- 2026-02-15: Phase 6 (Attendees Management) implemented (6.2 → 6.5): attendees dashboard, attendee operations, messaging, and attendee notes. Admin AJAX handlers (`oras_attendees_dashboard_data`, `oras_attendees_send_email`, `oras_attendees_save_note`) and CSV export were added; attendee notes persist in `_oras_attendee_notes_v1` post meta. Verification performed via WP-CLI AJAX simulations and CSV export checks.

## Full Phase List (Trackable)
- Phase 0 — Foundations (COMPLETE/LOCKED)
- Phase 1 — Ticket Core Model (COMPLETE/LOCKED)
- Phase 2 — WooCommerce Integration (COMPLETE/LOCKED)
- Phase 3.1 — Frontend Ticket Rendering (COMPLETE/LOCKED)
- Phase 3.2 — Time-Based Pricing (COMPLETE/LOCKED)
- Phase 3.3 — Admin UX Redesign (COMPLETE/LOCKED)
- Phase 3.4 — Treasurer Reporting (COMPLETE/LOCKED)
- Phase 3.5-A — REST API (COMPLETE/LOCKED)
- Phase 3.5-B — Grouped-by-Event API (COMPLETE/LOCKED)
- Phase 3.5-C — Member Hub UI Rendering (COMPLETE/LOCKED)
- Phase 3.5-D — Printable Tickets (COMPLETE/LOCKED)
- Phase 4.1 — Speaker Management (MVP) (COMPLETE/LOCKED)
- Phase 4.1-B — Public Speaker Profiles & Event Display (COMPLETE/LOCKED)
- Phase 4.5.1 — Agenda MVP (COMPLETE/LOCKED)
- Phase 4.5.3 — Agenda Current Highlight/Autoscroll (COMPLETE/LOCKED)
- Phase 4.5.4 — Agenda Speaker Modal (COMPLETE/LOCKED)
- Phase 4.6.1 — Speaker Historical Index (COMPLETE/LOCKED)
- Phase 4.6.2 — Speaker Resource Archive (COMPLETE/LOCKED)
- Phase 4.7 — Recurrence Guardrail (COMPLETE/LOCKED)
- Phase 5.1-B — Frontend RSVP + Waitlist (COMPLETE/LOCKED)
- Phase 5.1-C — RSVP REST Endpoints (COMPLETE/LOCKED)
- Phase 5.2 — RSVP Admin Management Panel (COMPLETE/LOCKED)
 - Phase 6.2 — Attendees Dashboard (COMPLETE)
 - Phase 6.3 — Attendee Operations (COMPLETE)
 - Phase 6.4 — Attendee Messaging (COMPLETE)
 - Phase 6.5 — Attendee Notes (COMPLETE)
- Phase 4.2 — Speaker Reporting & Automation (PLANNED)
- Phase 5+ — Future (DEFERRED)

## Agenda + Speakers (Implemented Schema Notes)
- Event agenda envelope key: `_oras_agenda_v1` (`settings` + `days[]` + `slots[]`).
- Slot schema: `start`, `end`, `title`, `desc`, `type`, `location`, `visibility`, `speakers[]`.
- Slot speaker rows: `speaker_id`, `role`, optional `label`.
- Current-slot highlighting/autoscroll uses `assets/js/agenda-now.js`.
- Speaker modal data is embedded per-event and includes headshot fallback from `_oras_speaker_headshot_id` to featured image.
- Speaker single URLs use CPT rewrite slug `speaker` and plugin template loading.

## Planned Phases (Not Started)
Phase 4.2 — Speaker Reporting & Automation
- Per-speaker exports/analytics hardening.
- Optional internal notification refinements.

Phase 5+ — Future (explicitly deferred)
- QR codes and check-in.
- Attendance tracking.
- Member-only gating.
- Zoom or external integrations.

---

# Complete Structured Phase Map

## ✅ PHASE 0 — Foundations (Completed)

* Plugin bootstrap architecture
* Namespacing: `ORAS\Tickets`
* Dependency checks (TEC, WooCommerce, etc.)
* Deterministic envelope structure patterns
* Backwards-compatible meta design
* Minimal-diff discipline established

## ✅ PHASE 1 — Core Ticket Engine (Completed)

* `_oras_tickets_v1` envelope
* `_oras_tickets_woo_map_v1`
* Hidden Woo products per ticket
* Capacity lifecycle logic
* Sale start / end windows
* Auto-complete ticket-only orders
* `_oras_autocompleted` marker
* Ticket print route
* Frontend ticket injection

## ✅ PHASE 2 — Commerce Integrity (Completed)

* Capacity consumption on order
* Prevent oversell
* Ticket ↔ Woo product mapping sync
* Deterministic price enforcement
* Basic reporting groundwork

## ✅ PHASE 3 — Agenda System (Completed)

* `_oras_agenda_v1` envelope
* Multi-day structure
* Slot definitions
* Slot types (talk, break, slides, etc.)
* Visibility rules
* Agenda frontend renderer
* Speaker slot association
* Resource slot association

## ✅ PHASE 4 — Speaker Intelligence System

### Phase 4.1 — Speaker Management (Completed)

* CPT: `oras_speaker`
* Speaker meta schema
* Event ↔ speaker association (`_oras_speakers_v1`)
* Compensation tracking
* PMPro fulfillment
* Admin visibility

### Phase 4.2 — Speaker Reporting (Planned)

* Treasurer reporting structure
* Per-speaker history view
* Notification groundwork

### Phase 4.3 — Agenda ↔ Speaker Rendering (Completed)

* Speaker modal integration
* Agenda speaker linking
* Speaker public template

### Phase 4.6.1 — Speaker Historical Index (Completed)

* `_oras_speaker_history_v1`
* Rebuilt on agenda save
* Historical slot index

### Phase 4.6.2 — Speaker Resource Archive (Completed)

* Slot-level resource uploads
* Speaker page resource rendering
* Resource visibility enforcement

## ✅ PHASE 4.7 — Recurrence Guardrail (Completed)

* Detect TEC recurrence:
  * `_EventRecurrence`
  * `_tribe_blocks_recurrence_rules`
  * `_EventRecurrenceID`
* Prevent ORAS ticketing on recurring events
* Deterministic envelope clearing
* Guardrail meta markers

## 🔜 PHASE 5 — Registration & Capacity Intelligence

### 5.1 — RSVP System (Non-Commerce) ✅ IMPLEMENTED

* `_oras_rsvp_v1` envelope
* Per-user RSVP state tracking (`_oras_rsvp_event_{EVENT_ID}` usermeta)
* Capacity management for free events
* Waitlist logic with priority promotion
* Frontend UI rendering on single events
* Admin metabox for RSVP settings
* REST API endpoints: GET `/oras/v1/rsvp/my` and `/oras/v1/rsvp/event/{event_id}`
* Virtual access gating for TEC Virtual Events (`_oras_virtual_access_v1`)

### 5.3 — Unified ORAS Event Metabox (Phase)

* Create a single master metabox `ORAS EVENTS ADDON` rendered in the event editor
* Vertical navigation tabs within the metabox: Tickets, Agenda, RSVP, Speakers, Virtual Access
* Reuse existing per-feature forms and save handlers inside the new UI container
* No meta schema changes; purely UI container refactor

### 5.4 — RSVP Management Dashboard (Phase)

* Move RSVP attendee list, CSV export, and waitlist promotion out of the event edit screen
* Add `RSVP Management` section in the plugin Dashboard with event selector and AJAX-driven lists
* Keeps Event Edit focused on per-event configuration; operational management lives in Dashboard

### 5.5 — Settings Page Expansion (Phase)

* Centralize global defaults and feature toggles on `ORAS-Tickets → Settings`
* Examples: default RSVP capacity, default waitlist behavior, virtual access defaults, ticket auto-complete toggles
* All global behavior controls belong on the settings page, not per-event metabox

### 5.2 — RSVP Admin Management Panel ✅ IMPLEMENTED

* “RSVP Attendees” metabox in event editor (only for RSVP-enabled events)
* Displays RSVP stats: yes_count, waitlist_count, capacity, is_full
* List table of attendees: name, email, status
* CSV export for YES attendees (name, email, status)
* “Promote from Waitlist” action (promotes oldest waitlist user if capacity allows)
* Admin-post handlers with nonce protection

### 5.2 — RSVP Admin Dashboard

* Real-time capacity and waitlist metrics
* Bulk waitlist management actions
* Custom field collection and export
* Verification email flows

## 🔜 PHASE 6 — Advanced Ticketing Intelligence

### 6.1 — Ticket Tier System Enhancements

* Structured tier model (early bird/member/public)
* Date-based automatic pricing windows
* Member-only pricing with PMPro integration
* Per-user purchase limits and enforcement
* Automatic tier transitions without manual intervention

### 6.2 — QR Code Ticket System

* QR code generation per ticket
* Secure ticket validation endpoints
* Check-in system with attendance tracking
* Mobile-optimized ticket display
* Duplicate prevention and fraud protection

### 6.3 — Reservation Window Logic

* Temporary holds on ticket inventory
* Configurable reservation timers
* Automatic release of expired reservations
* Integration with waitlist promotion
* Real-time availability updates

## 🔜 PHASE 7 — Speaker & Content Intelligence

### 7.1 — Speaker Resource Archive (Completed as Phase 4.6)

* Slot-level resource attachments (slides/handouts/links)
* Speaker-specific resource association
* Visibility controls (public vs internal)
* Automatic speaker history building
* Institutional memory preservation

### 7.2 — Speaker Performance Analytics

* Attendance metrics per speaker
* Revenue attribution per speaker
* Engagement and performance tracking
* Historical speaking frequency analysis
* Speaker contribution scoring system

### 7.3 — Frontend Speaker Submission

* Public proposal intake forms
* Admin review queue system
* Status tracking (pending/approved/declined)
* Internal notes and feedback system
* Automated workflow notifications

## 🔜 PHASE 8 — Virtual & Hybrid Event System

### 8.1 — Zoom Gated Access (Deterministic)

* Ticket/RSVP-based Zoom link display
* Controlled access for paid vs free participants
* Virtual-only ticket types
* Hybrid capacity modeling (in-person + virtual)
* Meeting management integration

### 8.2 — Virtual Event Infrastructure

* Virtual attendance tracking
* Recording access controls
* Post-event content distribution
* Virtual event reporting metrics

## 🔜 PHASE 9 — User Dashboard (Member Hub Expansion)

### 9.1 — My Tickets & RSVPs

* Personal ticket history and management
* RSVP status tracking and modification
* Downloadable tickets and confirmations
* Event-specific access controls

### 9.2 — My Speaker History

* Personal speaking engagement archive
* Resource access for past presentations
* Performance metrics visibility
* Speaker profile management

### 9.3 — Enhanced Member Experience

* Printable badges and materials
* Check-in status display
* Invoice access and download
* Personalized event recommendations

## 🔜 PHASE 10 — Financial & Reporting Intelligence

### 10.1 — Advanced Reporting Suite

* Revenue analytics per event/tier/speaker
* Member vs non-member revenue segmentation
* Date range filtering and comparison
* Comprehensive CSV/Excel export capabilities
* Treasurer-grade financial reporting

### 10.2 — Invoice Engine

* Automated PDF invoice generation
* ORAS branding and customization
* Tax calculation and line items
* Unique invoice numbering system
* Email delivery integration

### 10.3 — Refund Intelligence

* Refund reason tracking and categorization
* Refund rate analysis per event
* Financial impact assessment
* Automated refund processing workflows

## 🔜 PHASE 11 — Discovery & UX Features

* Event discovery and recommendation engine
* Advanced search and filtering capabilities
* User experience enhancements
* Mobile optimization improvements
* Accessibility compliance updates

## 🔜 PHASE 12 — Automation & Notifications

### 12.1 — Event Communication Automation

* 24-hour pre-event reminders
* 1-hour virtual event notifications
* Customizable email templates
* Automated communication workflows

### 12.2 — Post-Event Follow-Up

* Automated slides and resource distribution
* Feedback form integration
* Donation link distribution
* Engagement tracking and analytics

## Strategic Structure Summary

| Layer           | Ownership                              |
| --------------- | -------------------------------------- |
| TEC             | Events, recurrence, core views         |
| Woo             | Payment processing                     |
| ORAS-Tickets    | Commerce logic, capacity, intelligence |
| ORAS Member Hub | Frontend member experience             |

## Strategic Positioning (TEC Pro Compatible)

ORAS-Tickets builds **on top** of TEC Pro without duplicating its functionality:

- **TEC Pro owns**: Calendar views, recurrence patterns, event rendering
- **ORAS-Tickets owns**: Commerce intelligence, capacity lifecycle, speaker institutional memory, treasurer reporting, access gating rules
- **Strategic differentiators**: Deterministic architecture, clean phase-based development, strong speaker intelligence, institutional memory archive, treasurer-grade reporting

**Key policy**: Recurrence + ticketing guardrail prevents undefined behavior with TEC Pro's known limitations on recurring event ticketing.
