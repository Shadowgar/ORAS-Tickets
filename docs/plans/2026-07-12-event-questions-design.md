# Event Questions Design

## Goal
Allow event creators to define event-specific questions that are answered before ticket buyers reach the cart and during RSVP submission, then expose those answers in Board Reports without losing historical answer context.

## Architecture
ORAS-Tickets owns the full feature. Event question definitions live on the event post as versioned post meta. Ticket buyer answers are collected in a dedicated pre-cart step and copied into WooCommerce cart/order item metadata. RSVP answers are stored in the existing event-scoped RSVP user meta flow.

## Admin UX
Add an `Event Questions` tab to the existing `ORAS Events Addon` metabox.

Each question supports:
- Label
- Type: short text, long text, number, email, phone, select, radio, checkbox, yes/no
- Required flag
- Applies to: tickets, RSVP, or both
- Attendance scope: all, on-site, or virtual
- Options for select/radio/checkbox fields

Event creators may edit or delete questions after responses exist, but saved answers must retain a snapshot of question ID, original label, type, and answer. Board Reports must continue showing historical answers under the original label.

## Ticket Flow
Ticket question answers are collected once per buyer/event, not once per ticket.

Flow:
1. Buyer selects tickets on the event page.
2. Buyer clicks `Continue to Event Questions`.
3. ORAS renders a dedicated event-question step before cart.
4. Buyer submits answers.
5. ORAS validates required answers, adds selected tickets to cart, stores question answer snapshots on cart items, and redirects to cart.

If an event has no ticket questions, the existing add-to-cart flow can continue directly.

## RSVP Flow
RSVP questions render inside the existing RSVP details form after contact details. RSVP submission keeps the current contact fields, attendance mode, waitlist behavior, and virtual approval behavior. Additional question answers are stored with the RSVP contact metadata.

## Board Reports
Expose answers first in row detail views for Ticket Sales, RSVPs, and Attendees. Export support should include stable question answer columns where practical, using saved answer snapshots to preserve historical labels.

## Security And Compatibility
Use existing ORAS-Tickets nonce and capability patterns. Sanitize question definitions on event save. Sanitize answers by field type. Do not expose answers to unauthorized users. Do not modify ORAS-Member-Hub.
