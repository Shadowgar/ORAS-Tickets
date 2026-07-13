# Event-First Dashboard And Virtual Ticket Email Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make Board Reports event-first and send automatic virtual access emails to paid virtual ticket buyers while keeping virtual RSVP approval manual.

**Architecture:** ORAS-Tickets remains the only owner. Board Reports keeps existing shortcodes/actions but changes the dashboard presentation to a global event selector, overview cards, and clearer tabs. WooCommerce order-status hooks send one virtual access email per paid order/event when the order contains a virtual ORAS ticket.

**Tech Stack:** WordPress plugin PHP, WooCommerce order hooks, The Events Calendar metadata, existing ORAS-Tickets report services and RSVP virtual-link resolver.

---

### Task 1: Add Targeted Checks

**Files:**
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Steps:**
1. Add source assertions that Board Reports includes an event overview tab, Sales label, RSVP Management label, Roster label, and forces ticket-sales tab to ticket rows.
2. Add source assertions that the new WooCommerce virtual ticket email class is required/registered and sends only for virtual ticket order items.
3. Run `php oras-tickets/tools/phase1h-event-questions-checks.php` and confirm these new checks fail before implementation.

### Task 2: Add Virtual Ticket Access Email Service

**Files:**
- Create: `oras-tickets/includes/Commerce/Woo/Virtual_Ticket_Access_Email.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

**Steps:**
1. Register `woocommerce_order_status_processing` and `woocommerce_order_status_completed`.
2. Resolve virtual ticket events from order item `_oras_ticket_event_id` and `_oras_ticket_attendance_mode`.
3. Send one styled HTML email per order/event to the billing email when the event has a valid virtual join link.
4. Store duplicate-prevention order meta per event.
5. Add a communication log record with `related_action_type=virtual_ticket_access`.

### Task 3: Redesign Board Reports Shell

**Files:**
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`

**Steps:**
1. Add an event-first overview section with a global event selector and statistics cards.
2. Add `Event Overview` as the default tab.
3. Keep existing `ticket_sales`, `rsvps`, `communications`, and `attendees` query values backward compatible.
4. Rename visible tabs to Sales, RSVP Management, Communications, and Roster.
5. Map old `statistics` URLs to the overview tab.
6. Force Ticket Sales to ticket rows only and remove its report-type dropdown.

### Task 4: Verify

**Commands:**
- `php -l oras-tickets/includes/Frontend/Event_RSVP.php`
- `php -l oras-tickets/includes/Frontend/Board_Reports.php`
- `php -l oras-tickets/includes/Commerce/Woo/Virtual_Ticket_Access_Email.php`
- `php oras-tickets/tools/phase1h-event-questions-checks.php`
- `git diff --check`
- `composer phpcs`
- `composer phpstan`

Runtime wp-env checks should be attempted if local wp-env starts reliably; otherwise GitHub Phase5 remains the runtime gate.
