# Event Questions Attention Workflow Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Turn event questions into a scalable coordination workflow with controlled answer types, attention rules, dashboard review, an Event Creator role, and dashboard notifications.

**Architecture:** Extend the existing ORAS-Tickets event question definition and answer snapshot system instead of replacing it. Store question-generated attention items as dedicated ORAS-Tickets workflow records so Board Reports can review, filter, resolve, and export them without rendering every answer inline.

**Tech Stack:** WordPress plugin PHP, The Events Calendar `tribe_events`, WooCommerce cart/order item meta, existing ORAS-Tickets RSVP user meta, custom ORAS-Tickets database tables, existing Board Reports shortcode.

---

### Task 1: Controlled Question Types

**Files:**
- Modify: `oras-tickets/includes/Event_Questions.php`
- Modify: `oras-tickets/includes/Admin/Metaboxes/Event_Questions_Metabox.php`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Steps:**
1. Update the targeted event question checks to expect controlled field types.
2. Verify the checks fail while `Event_Questions::normalize_type()` still forces `text`.
3. Restore supported types: short text, long text, yes/no, single choice, multiple choice, and number.
4. Restore admin controls for selecting type and adding options where relevant.
5. Preserve backward compatibility for existing short-text question definitions and answer snapshots.
6. Run the targeted checks until green.
7. Commit and push.

### Task 2: Attention Rule Definitions

**Files:**
- Modify: `oras-tickets/includes/Event_Questions.php`
- Modify: `oras-tickets/includes/Admin/Metaboxes/Event_Questions_Metabox.php`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Steps:**
1. Add normalized `attention_rules` to question definitions.
2. Support rule operators: equals, contains, is blank, is not blank, greater than, less than, and always when answered.
3. Store a board-facing flag label and severity with each rule.
4. Add repeatable rule controls inside each question row.
5. Keep definitions valid when no attention rules exist.
6. Run targeted checks until green.
7. Commit and push.

### Task 3: Attention Item Store

**Files:**
- Create: `oras-tickets/includes/Event_Question_Attention_Store.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/oras-tickets.php`
- Create: `oras-tickets/tools/event-question-attention-checks.php`

**Steps:**
1. Write targeted checks for rule matching and idempotent attention item creation.
2. Add a custom table, for example `{$wpdb->prefix}oras_event_question_attention`.
3. Store event ID, source type, source identifier, user/order context, question ID, question label, answer snapshot, rule label, severity, status, created timestamp, reviewed metadata, and internal note.
4. Add install and maybe-upgrade methods modeled after existing stores.
5. Add service methods to evaluate answers and upsert open attention items.
6. Run targeted checks until green.
7. Commit and push.

### Task 4: Generate Attention Items From Ticket And RSVP Answers

**Files:**
- Modify: `oras-tickets/includes/Frontend/Event_RSVP.php`
- Modify: `oras-tickets/includes/Commerce/Woo/Product_Sync.php` or the current order item snapshot hook owner
- Modify: `oras-tickets/tools/event-question-attention-checks.php`

**Steps:**
1. Extend tests so RSVP and ticket answer snapshots generate matching attention items.
2. Generate attention records after RSVP answer snapshots are saved.
3. Generate attention records after ticket answer snapshots are copied to order items.
4. Ensure empty/nonmatching answers do not create attention records.
5. Preserve existing RSVP, ticket, cart, order, export, and email behavior.
6. Run targeted checks until green.
7. Commit and push.

### Task 5: Board Reports Attention Review

**Files:**
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`
- Modify: `oras-tickets/includes/Reporting/Board_Report_Service.php`
- Modify: `oras-tickets/includes/Reporting/Board_Report_Exporter.php`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Steps:**
1. Add an `Attention Needed` area to the event-first dashboard.
2. Keep roster/RSVP tables lean with answer counts and flag counts instead of rendering all answers inline by default.
3. Add a review screen with filters for status, question, source, severity, attendee, and search.
4. Add actions to mark attention items reviewed, resolved, or dismissed with nonce and capability checks.
5. Add export support for flagged answers.
6. Run targeted checks until green.
7. Commit and push.

### Task 6: Event Creator Role

**Files:**
- Modify: `oras-tickets/includes/Capabilities.php`
- Modify: `oras-tickets/oras-tickets.php`
- Create or modify: targeted capability check script

**Steps:**
1. Add an `event_creator` role if it does not exist.
2. Grant event-operation capabilities needed for event creation, editing, publishing, deleting, tickets, RSVP, Zoom/virtual setup, event questions, Board Reports, RSVP management, attendee management, exports, and event communications.
3. Do not grant settings capabilities such as `manage_options` or `oras_tickets_manage_settings`.
4. Preserve Board and Board Member existing access.
5. Run targeted capability checks until green.
6. Commit and push.

### Task 7: Dashboard Notification Center

**Files:**
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`
- Modify: `oras-tickets/includes/Event_Question_Attention_Store.php`
- Create or modify: targeted attention checks

**Steps:**
1. Add open attention counts to the dashboard header/cards.
2. Add a notification center inside Board Reports that lists open event coordination items.
3. Clicking a notification should deep-link to the filtered attention review list.
4. Avoid login popups in the first release; use dashboard-only alerts.
5. Run targeted checks until green.
6. Commit and push.

### Task 8: Final Verification

**Commands:**
- `php -l` on modified PHP files.
- `git diff --check`
- `composer phpcs`
- `php oras-tickets/tools/phase1h-event-questions-checks.php`
- `php oras-tickets/tools/event-question-attention-checks.php`
- Existing relevant report/RSVP checks if available.

**Expected:** All required checks pass before final push.
