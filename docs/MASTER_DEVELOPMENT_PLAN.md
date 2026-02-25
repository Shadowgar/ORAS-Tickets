ORAS-Tickets Master Development Plan
Oil Region Astronomical Society (ORAS)
WordPress Add-On Architecture

1. PROJECT IDENTITY
Plugin: ORAS-Tickets
Namespace: ORAS\Tickets
Companion Plugin: ORAS Member Hub
Platform Stack:
- WordPress
- The Events Calendar (TEC)
- Events Calendar Pro
- Event Tickets
- WooCommerce
- Stripe
- Paid Memberships Pro (PMPro)

2. CORE ARCHITECTURE PRINCIPLES
- ORAS-Tickets is an add-on — never modify TEC/Woo/PMPro core.
- Deterministic logic only.
- Versioned meta envelopes.
- Backwards compatibility required.
- Minimal diffs per phase.
- No SaaS lock-in.
- No telemetry.
- Phase-based development.
- Institutional memory is a primary objective.
- Capability-gated admin tools.

3. CURRENT SYSTEM STATUS OVERVIEW
Fully Stable & Production-Ready
- Ticket Model
- WooCommerce Mapping
- Ticket Print System
- Woo Auto-Completion
- Speaker CPT
- Speaker Assignment
- Speaker Reporting
- Agenda System
- Member Hub ticket display
- Commerce functionality is operational and stable.

4. PHASE HISTORY (COMPLETED)

Phase 0 – Core Architecture
- Namespace discipline
- Bootstrap loader
- Activation hooks
- Capability registration
- Documentation structure
Status: Complete & Stable

Phase 1 – Ticket Model
Meta Envelope:
_oras_tickets_v1
Features:
Ticket definitions per event
Structured storage
Deterministic schema
Status: Complete

Phase 2 – WooCommerce Mapping
Meta:
_oras_tickets_woo_map_v1
_oras_ticket_event_id
_oras_ticket_index
Features:
Hidden Woo products per ticket
Event-to-product linking
Deterministic index system
Status: Complete

Phase 3 – Reporting System (Treasurer)
Features:
Revenue per event
Revenue per ticket
CSV exports
Basic financial reporting UI
Status: Stable (expandable)

Phase 4.1 – Speaker Management
CPT: oras_speaker
Meta Keys:
email
affiliation
website
headshot
WP user ID
status
internal notes
Event Envelope:
_oras_speakers_v1
Status: Complete

Phase 4.2 – Speaker Reporting
CSV export
Fulfillment tracking
Email notifications
Status: Complete

Phase 4.3 – Ticket Print System
/oras-ticket/print route
Access validation
Member Hub integration
Secure print template
Status: Complete

Phase 4.4 – Agenda System
Meta:
_oras_agenda_v1
Features:
Multi-day agenda
Slot structure
Highlight current slot
Autoscroll
Speaker modal
Headshot fallback
Permalink support
Status: Complete (UI refinement ongoing)

Phase 4.5 – Woo Auto-Completion
Auto-complete ticket-only orders
Mixed merchandise remains processing
_oras_autocompleted marker
Status: Complete

Phase 4.6 – Speaker Resources
Slot-level resource attachments
Attachment ID + external URL
Type (slides/handout/video/link)
Visibility control
Speaker association
Status:
Admin UI implemented
History indexing not yet complete
Speaker archive rendering pending refinement

5. ACTIVE STABILIZATION WORK
RSVP System
Features:
RSVP Yes logic
RSVP status badge
Basic storage envelope
Pending:
RSVP No revoke stabilization
Immediate success notification
Cancel flow UX refinement
Deterministic idempotent revoke logic
Status: In Progress

6. PHASE 5 – Registration & Capacity Intelligence

5.1 RSVP Mode (Non-Commerce)
Features:
Free event registration
Capacity limits
Confirmation emails
Cancellation support
Waitlist integration
Status: Partial

5.2 Waitlist System
Features:
Event-level waitlist
Ticket-tier waitlist
Verification email
Cancellation link
FIFO promotion logic
Manual promotion
Admin bulk actions
CSV export
XLSX export
PMPro enforcement
Selective asset loading
Status: Not Built
Priority: High

5.3 Capacity Dashboard
Features:
RSVP count vs paid count
Capacity percentage
Sellout visibility
Future predictive analytics
Status: Not Built

7. PHASE 6 – Advanced Ticketing Intelligence

6.1 Tier System Enhancements
Features:
Early bird pricing
Member-only pricing
Date-based transitions
Per-user purchase limits
Status: Not Built

6.2 QR Code Ticket System
Features:
Unique QR per ticket
Verification endpoint
Duplicate prevention
Status: Not Built

6.3 Check-In System
Features:
Mobile-friendly interface
QR scan
Manual search
Timestamp log
Export check-in list
Status: Not Built

6.4 Seat Reservation (Optional)
Features:
Seat grid
Seat locking
VIP sections
Price-per-seat logic
Status: Not Built

8. PHASE 7 – Speaker Intelligence

7.1 Speaker Resource Archive
Features:
_oras_speaker_history_v1
Automatic indexing
Historical event list per speaker
Resource filtering by visibility
Status: Partially Built

7.2 Speaker Performance Analytics
Features:
Attendance per speaker
Revenue per speaker
Engagement metrics
Status: Not Built

7.3 Frontend Speaker Submission
Features:
Proposal intake form
Review queue
Status tracking
Internal notes
Status: Not Built

9. PHASE 8 – Virtual & Hybrid Events

8.1 Zoom Gated Access
Features:
Show Zoom link only to paid/RSVP users
Role enforcement
Controlled rendering
Status: Not Built

8.2 Zoom Auto Sync
Features:
Create meeting
Sync changes
Store meeting ID
Status: Not Built

8.3 Hybrid Mode
Features:
Separate virtual capacity
Separate ticket types
Separate reporting
Status: Not Built

10. PHASE 9 – Member Hub Expansion

9.1 My Tickets
View tickets
Print
Status
Status: Basic complete

9.2 My RSVPs
View RSVPs
Cancel
Waitlist
Status: Not Built

9.3 My Speaking History
View talks
Resources
Metrics
Status: Not Built

9.4 Invoice Access
View invoices
Download PDF
Tax breakdown
Status: Not Built

11. PHASE 10 – Financial Intelligence

10.1 Advanced Reporting Suite
Features:
Revenue per event
Revenue per tier
Revenue per speaker
Member vs non-member revenue
Date filtering
CSV export
Status: Basic reporting exists
Advanced analytics pending

10.2 Invoice Engine
Features:
PDF invoices
Unique invoice numbers
ORAS branding
Status: Not Built

10.3 Refund Intelligence
Features:
Refund reason tracking
Refund rate per event
Impact analysis
Status: Not Built

12. PHASE 11 – Discovery & UX Enhancements

11.1 Advanced Filtering
AJAX filtering
Category/date/speaker/location
Status: Not Built

11.2 Interactive Map View
Map browsing
Marker clustering
Radius search
Status: Not Built

11.3 Recurrence Intelligence
Handled by TEC Pro
Custom override logic not built

11.4 Door Prize System (New Feature)
Meta:
_oras_door_prizes_v1
Features:
Structured prize list per event
Media uploader for photos
Donor + value fields
Visibility control (public/members/internal)
Display modes:
Modal
Hover popover
Inline expand
Accessibility support
Future export for MC scripts
Status: Not Built

13. PHASE 12 – Automation & Notifications

12.1 Reminder Emails
24-hour reminder
1-hour virtual reminder
Custom templates
Status: Not Built

12.2 Post-Event Follow-Up
Slides link
Feedback form
Donation link
Status: Not Built

14. STRATEGIC DIFFERENTIATORS
Deterministic architecture
Versioned meta envelopes
WooCommerce-first integration
Institutional speaker archive
Treasurer-grade analytics
No SaaS dependency
ORAS-specific workflows

15. CURRENT PRIORITY ORDER
1. Fix RSVP revoke logic
2. Finalize Speaker History indexing
3. Build Waitlist system
4. Capacity dashboard
5. Door Prize system
6. QR + Check-in system
7. Tier pricing automation
8. Advanced reporting expansion
9. Virtual gating
10. Member dashboard expansion

16. COMPLETION ESTIMATE
Core infrastructure: ~60%
Modern calendar parity: ~35%
Advanced intelligence features: ~15%

If you would like, I can now generate:
- A simplified executive summary version (board-level)
- A strict technical AI handoff version
- A prioritized engineering sprint roadmap
- Or a structured task breakdown per phase for GitHub tracking
