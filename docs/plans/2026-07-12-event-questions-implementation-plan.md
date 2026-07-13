# Event Questions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build event-specific questions for tickets and RSVPs, collect ticket answers before cart, collect RSVP answers during RSVP, and display answer snapshots in Board Reports.

**Architecture:** Add an ORAS-Tickets question service for definitions, rendering, validation, and answer snapshots. Add an Event Questions admin tab to the existing ORAS Events Addon metabox. Store ticket answers in Woo cart/order item meta and RSVP answers in existing event-scoped user meta.

**Tech Stack:** WordPress plugin PHP, The Events Calendar `tribe_events`, WooCommerce cart/order item meta, existing ORAS-Tickets Board Reports.

---

### Task 1: Targeted Checks

**Files:**
- Create: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Steps:**
1. Write a targeted check that expects an `Event_Questions` service class.
2. Verify it can normalize question definitions.
3. Verify required answer validation fails when missing.
4. Verify answer snapshots keep original labels.
5. Run it before implementation and confirm it fails because the service is missing.

### Task 2: Question Service

**Files:**
- Create: `oras-tickets/includes/Event_Questions.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

**Steps:**
1. Add constants for `_oras_event_questions_v1`, answer meta keys, field types, applies-to scopes, and attendance scopes.
2. Add definition normalization and sanitization.
3. Add answer sanitization by type.
4. Add required validation.
5. Add answer snapshot creation with question ID, original label, field type, answer, applies-to, and attendance scope.
6. Include the service during bootstrap.
7. Run targeted checks until green.

### Task 3: Admin Event Questions Tab

**Files:**
- Create: `oras-tickets/includes/Admin/Metaboxes/Event_Questions_Metabox.php`
- Modify: `oras-tickets/includes/Admin/Event_Addon_Metabox.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

**Steps:**
1. Render an `Event Questions` tab in the ORAS Events Addon metabox.
2. Render repeatable question rows with label, type, required, applies-to, attendance scope, and options.
3. Save definitions through `save_post_tribe_events` using nonce and `edit_post`.
4. Keep deleted/edited questions from deleting already stored answer snapshots.

### Task 4: Ticket Pre-Cart Question Step

**Files:**
- Modify: `oras-tickets/includes/Frontend/Tickets_Display.php`

**Steps:**
1. Change the ticket submit label to `Continue to Event Questions` when ticket questions apply.
2. Preserve selected ticket quantities in hidden fields on the question step.
3. Render the question step before adding to cart.
4. Validate answers before cart add.
5. Add sanitized answer snapshots to cart item data for each selected ticket.
6. Redirect to cart after successful add.
7. Preserve direct add-to-cart behavior when no ticket questions exist.

### Task 5: Woo Order Snapshot

**Files:**
- Modify: `oras-tickets/includes/Commerce/Woo/Product_Sync.php`

**Steps:**
1. Copy cart answer snapshots into order item meta.
2. Store under a private machine meta key and optionally a readable summary meta key.
3. Ensure order reporting can read historical snapshots even if event questions later change.

### Task 6: RSVP Answers

**Files:**
- Modify: `oras-tickets/includes/Frontend/Event_RSVP.php`

**Steps:**
1. Render RSVP-applicable event questions inside the existing RSVP details form.
2. Validate required answers for RSVP yes/waitlist submissions.
3. Store answer snapshots in existing RSVP contact metadata.
4. Preserve existing RSVP removal, waitlist, approval, and email behavior.

### Task 7: Board Reports

**Files:**
- Modify: `oras-tickets/includes/Reporting/Board_Report_Service.php`
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`
- Modify: `oras-tickets/includes/Reporting/Board_Report_Exporter.php`

**Steps:**
1. Add answer snapshots to ticket and RSVP report rows.
2. Show answers in Ticket Sales, RSVPs, and Attendees `View Details`.
3. Add export columns for question answer snapshots where practical.
4. Keep existing report filters, exports, and permissions.

### Task 8: Verification

**Commands:**
- `php -l oras-tickets/includes/Event_Questions.php`
- `php -l oras-tickets/includes/Admin/Metaboxes/Event_Questions_Metabox.php`
- `find oras-tickets -type f -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git diff --check`
- `composer phpcs`
- `php oras-tickets/tools/phase1h-event-questions-checks.php`

**Expected:** All checks pass.
