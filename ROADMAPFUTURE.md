# ORAS-Tickets Strategic Roadmap (Revised for TEC Pro)

**Goal:** Build a deterministic ORAS Event Operating System on top of The Events Calendar Pro — focusing on ticket intelligence, institutional memory, and operational tooling — without duplicating TEC Pro features.

---

# FOUNDATIONAL POLICY (Before Phase 5)

## Recurrence + Ticketing Guardrail

### What It Is

A defined rule governing how ORAS-Tickets interacts with TEC Pro recurring events.

### Why It Exists

TEC Pro explicitly does not fully support ticketing on recurring patterns. This is a known limitation in Pro.

### ORAS Policy Recommendation (Deterministic & Safe)

* If ORAS tickets exist → recurrence is disabled.
* If recurrence is enabled → ORAS ticket metabox is disabled.

This prevents undefined behavior and protects reporting integrity.

---

# PHASE 5 — Registration & Capacity Intelligence

---

## 5.1 RSVP Mode (Non-Commerce Registration)

### What It Is

A lightweight registration system for free events that does **not** require WooCommerce checkout.

### What It Does

* Lets users reserve attendance for free events.
* Tracks capacity per event.
* Collects attendee data (name/email/custom fields).
* Sends confirmation email.
* Allows cancellation.
* Supports waitlist if capacity is full.

### Why It Matters

ORAS public nights and educational talks often don’t require payment but still need headcount tracking.

### Required Components

* `_oras_rsvp_v1` event meta envelope.
* Attendee storage table or CPT.
* Capacity decrement logic.
* Waitlist queue logic.
* Confirmation + cancellation flow.

### Architecture Fit

* Prefer a dedicated RSVP storage (custom DB table) to keep analytic queries fast and deterministic.
* Inject RSVP UI via ORAS frontend content hooks — avoid touching TEC Pro recurrence rendering.
* Do not duplicate TEC Pro recurrence logic; RSVP must be disabled when TEC Pro recurrence + ticketing conflicts would occur.

---

## 5.2 Waitlist System (Ticketed + RSVP)

### What It Is

A queue of users who want tickets/slots after capacity is full, with verification, cancellation, and promotion logic.

### What It Does

* Eligibility trigger: if event capacity is full **or** a specific ticket tier is sold out, show “Join Waitlist”.
* Supports post-sellout waitlist form per event and per ticket tier.
* Captures name/email + custom registration fields.
* Stores waitlist entries as first-class records with: event_id, optional ticket_tier_id/index, qty, submitted_at, status.
* Optional email verification step before entry is valid (anti-spam).
* Confirmation email includes one-click cancellation link.
* If spot opens → promote next eligible entry by priority (submitted_at).
* Supports manual and automatic promotion to reservation/booking flow.
* Optionally applies a promotion-to-checkout reservation window; if not purchased in time, release and promote next.
* Must respect members-only / logged-in access rules (including PMPro-gated flows).
* Admin list management supports bulk actions: pending, confirm, reject, verify.
* Admin can manually “promote next”.
* Export supports CSV and XLSX.
* Export dataset includes event/time, waitlist timestamps, ticket details, transaction and price info, attendee details, verification/confirmation status, custom fields, and attachment URLs.
* Frontend assets for waitlist should load only on relevant pages when assets-per-page mode is enabled.

### Why It Matters

Prevents overbooking and reduces manual coordination.

### ORAS-Specific Value

* Handles sold-out AstroBlast and special talks without spreadsheet fallback.
* Keeps membership and login policy enforcement consistent across booking and waitlist paths.
 
### Architecture Fit

* Implement the waitlist module under `Domain/` with admin surfaces in `Admin/Pages/`.
* Reuse secure route/token patterns (see `Print_Ticket_Controller`) for promotion and confirmation links.
* Promotion logic should integrate with Woo stock/order flow but be owned by the waitlist module to keep separation of concerns.

---

## 5.3 Capacity Dashboard

### What It Is

Centralized capacity intelligence panel.

### What It Does

* Shows event capacity usage.
* Shows RSVP count vs paid tickets.
* Displays percentage full.
* Predicts sellout timeline (optional analytics).
 
### Architecture Fit

* Extend the existing `Reports_Aggregator` to add a capacity tab.
* Add a dedicated admin Reports page tab under the ORAS admin UI for capacity and waitlist metrics.


# PHASE 6 — Advanced Ticketing Intelligence

---

## 6.1 Ticket Tier System Enhancements

### What It Is

Structured tier model for early bird / member / public pricing.

### What It Does

* Date-based pricing windows.
* Member-only pricing.
* Automatic tier transitions.
* Per-user purchase limits.

### Why It Matters

Removes manual price edits and prevents errors.

---

## 6.2 QR Code Ticket System

### What It Is

Machine-readable ticket validation system.

### What It Does

* Generates QR code per ticket.
* Links to verification endpoint.
* Allows check-in scan.
* Prevents duplicate check-ins.

### Why It Matters

AstroBlast scale events require efficient door management.

---

## 6.3 Check-In System

### What It Is

Mobile-friendly check-in interface.

### What It Does

* QR scan or manual search.
* Mark attendee as checked in.
* Timestamp log.
* Export check-in list.

---

## 6.4 Seat Reservation (Optional Future)

### What It Is

Graphical seating map tied to ticket selection.

### What It Does

* Seat grid definition per event.
* Seat locking during checkout.
* VIP sections.
* Price per seat.

### Why It Matters

Only needed for high-capacity indoor talks or theater settings.

---

## 6.5 Promotion-to-Checkout Reservation Window (Optional)

### What It Is

Temporary inventory lock immediately after waitlist promotion.

### What It Does

* Reserves 1–N tickets for the promoted user for X minutes.
* If purchase is completed in time, reservation converts to confirmed booking.
* If purchase expires, inventory is released and next waitlisted user can be promoted.

### Why It Matters

Prevents race conditions and creates a deterministic “fair chance” handoff from waitlist to checkout.
 
### Architecture Fit

* Hook reservation window logic into the waitlist module and Woo stock APIs so temporary holds are visible to commerce logic.
* Persist reservation timers in the DB to avoid relying on transient caches.


# PHASE 7 — Speaker & Content Intelligence

---

## 7.1 Speaker Resource Archive (Current Phase 4.6)

### What It Is

Structured attachment system per agenda slot.

### What It Does

* Attach slides / handouts / links to slot.
* Associate resources with specific speakers.
* Control visibility (public vs internal).
* Automatically build speaker history.

### Why It Matters

Creates long-term institutional memory for ORAS.
 
### Architecture Fit

* Extend `_oras_agenda_v1` and compute `_oras_speaker_history_v1` as domain-level artifacts.
* Render via `single-oras_speaker.php` and expose admin editing under `Admin/` pages.


## 7.2 Speaker Performance Analytics

### What It Is

Reporting system for speaker impact.

### What It Does

* Attendance per speaker.
* Revenue per speaker.
* Engagement metrics.
* Historical speaking frequency.

---

## 7.3 Frontend Speaker Submission

### What It Is

Public proposal intake system.

### What It Does

* Submission form.
* Proposal review queue.
* Status tracking (pending/approved/declined).
* Internal notes.

---

# PHASE 8 — Virtual & Hybrid Event System

---

## 8.1 Zoom Gated Access (Deterministic)

### What It Is

Controlled display of Zoom link based on purchase or RSVP.

### What It Does

* Shows join link only to:

  * Paid ticket holders, OR
  * Approved RSVP users.
* Can support:

  * Free ticket-only access.
  * Paid-only access.
 
### Architecture Fit

* Implement as a display-layer injection only — do not attempt to create/manage Zoom meetings from ORAS (leave meeting creation to other tooling).
* Validate access by checking order ownership or RSVP records server-side before revealing links.


## 8.2 Auto Zoom Meeting Sync

### What It Is

Admin convenience automation.

### What It Does

* Creates Zoom meeting from event.
* Syncs meeting time updates.
* Stores meeting ID in event meta.

---

## 8.3 Hybrid Mode

### What It Is

Support physical + virtual attendees simultaneously.

### What It Does

* Separate capacities for virtual vs in-person.
* Separate ticket types.
* Separate reporting.

---

# PHASE 9 — User Dashboard (Member Hub Expansion)

---

## 9.1 My Tickets

### What It Is

Frontend user ticket center.

### What It Does

* List purchased tickets.
* Download/print.
* See event status.
* Check-in history.

---

## 9.2 My RSVPs

### What It Is

RSVP management panel.

### What It Does

* List upcoming RSVPs.
* Cancel RSVP.
* Join waitlist.

---

## 9.3 My Speaking History

### What It Is

Speaker dashboard view.

### What It Does

* List past talks.
* Show uploaded resources.
* Show attendance metrics.

---

## 9.4 Invoice Access

### What It Is

Frontend billing archive.

### What It Does

* Download invoices.
* View order totals.
* See tax breakdown.

---

# PHASE 10 — Financial & Reporting Intelligence

---

## 10.1 Advanced Reporting Suite

### What It Is

Treasurer-grade analytics.

### What It Does

* Revenue per event.
* Revenue per ticket tier.
* Revenue per speaker.
* Member vs non-member revenue.
* Date range filters.
* CSV export.

---

## 10.2 Invoice Engine

### What It Is

Automated PDF invoice generator.

### What It Does

* Generates invoice per order.
* Includes ORAS branding.
* Tax line items.
* Unique invoice number.

---

## 10.3 Refund Intelligence

### What It Is

Refund tracking + analytics.

### What It Does

* Track refund reasons.
* Show refund rates per event.
* Impact analysis.

---

# PHASE 11 — Discovery & UX Features

---

## 11.1 Advanced Filtering

### What It Is

Modern faceted search system.

### What It Does

* Filter by category, date, speaker, location.
* AJAX updates.
* Combined filters.

---

## 11.2 Interactive Map View

### What It Is

Map-based event browsing.

### What It Does

* Show events by location.
* Marker clustering.
* Radius search.

---

## 11.3 Recurrence Intelligence

### What It Is

Advanced recurrence engine.

### What It Does

* Complex repeating rules.
* Excluded dates.
* Exception overrides.

---

# PHASE 12 — Automation & Notifications

---

## 12.1 Reminder Emails

### What It Is

Time-based automation system.

### What It Does

* Send reminder 24h before event.
* Send 1h reminder for virtual.
* Customizable templates.

---

## 12.2 Post-Event Follow-Up

### What It Is

Automated engagement.

### What It Does

* Send slides link.
* Send feedback form.
* Send donation link.

---

# Strategic Differentiator Opportunities

# Strategic Positioning (With TEC Pro in Mind)

ORAS-Tickets should not try to become a calendar plugin. TEC Pro handles views, recurrence, and rendering. ORAS-Tickets must own commerce intelligence, capacity lifecycle, waitlist logic, speaker institutional memory, check-in tooling, treasurer reporting, and access gating rules. Build on top of TEC Pro and avoid duplicating Pro functionality.


Instead of copying MEC entirely, ORAS can win by:

1. Deterministic architecture (no spaghetti meta).
2. Clean phase-based development.
3. Strong speaker intelligence system.
4. Tight WooCommerce integration.
5. Institutional memory archive (unique strength).
6. Treasurer-grade reporting.

---

# Modern Calendar Parity Additions (Waitlist)

Net-new parity items to retain in ORAS planning scope:

1. Post-sellout waiting form (per event / per ticket).
2. Verification email flow with confirm link.
3. Confirmation email flow with cancellation link.
4. Priority-based promotion from waitlist to confirmed booking.
5. Admin waitlist management with bulk actions.
6. CSV export.
7. Excel/XLSX export.
8. Export coverage for custom fields and attachment URLs.
9. PMPro/login restriction enforcement for waitlist access.
10. Assets-per-page selective loading for waitlist JS/CSS.

---
