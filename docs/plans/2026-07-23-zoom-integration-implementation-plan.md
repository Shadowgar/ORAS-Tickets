# ORAS Zoom Integration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add secure Zoom invitation retrieval and attendee-specific registration for paid virtual ticket buyers and approved virtual RSVPs.

**Architecture:** The Events Calendar remains the meeting creator. ORAS-Tickets adds an isolated Zoom integration module with encrypted settings, Server-to-Server OAuth, normalized API access, event mapping, an ORAS-owned registration table, and adapters into existing ticket/RSVP email and cancellation paths. Existing shared-link behavior remains the fallback.

**Tech Stack:** WordPress PHP 8, WooCommerce hooks, The Events Calendar metadata, Zoom REST API, WordPress HTTP API, dbDelta, Action Scheduler-compatible hooks, existing ORAS email templates and communication logs.

---

### Task 1: Zoom settings and OAuth

**Files:**
- Create: `oras-tickets/src/Integrations/Zoom/Settings.php`
- Create: `oras-tickets/src/Integrations/Zoom/OAuth_Client.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

1. Write checks for encrypted secret storage, masked hydration, missing credentials, cached token reuse, and token API errors.
2. Run the checks and verify they fail because the Zoom classes do not exist.
3. Implement Zoom settings and OAuth token acquisition using the account-credentials grant.
4. Run the checks and verify they pass.

### Task 2: Zoom API and meeting invitation resolver

**Files:**
- Create: `oras-tickets/src/Integrations/Zoom/Api_Client.php`
- Create: `oras-tickets/src/Integrations/Zoom/Meeting_Service.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

1. Add failing checks for meeting-ID resolution, accepted Zoom hosts, invitation parsing, API status handling, and no secret leakage.
2. Implement bounded requests to meeting, invitation, and registrant endpoints.
3. Normalize invitation text into join URL, meeting ID, passcode, one-tap mobile lines, and local-number URL.
4. Run the checks and verify they pass.

### Task 3: Registration persistence and lifecycle

**Files:**
- Create: `oras-tickets/src/Integrations/Zoom/Registration_Store.php`
- Create: `oras-tickets/src/Integrations/Zoom/Registration_Service.php`
- Modify: `oras-tickets/oras-tickets.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

1. Add failing checks for schema, event/source uniqueness, idempotent registration, private join URL storage, and cancellation.
2. Implement the upgradeable registration table and service.
3. Install or upgrade the table during activation and bootstrap schema checks.
4. Run checks and verify they pass.

### Task 4: Event and administrator controls

**Files:**
- Create: `oras-tickets/src/Integrations/Zoom/Module.php`
- Create: `oras-tickets/includes/Admin/Metaboxes/Event_Zoom_Metabox.php`
- Modify: `oras-tickets/includes/Admin/Event_Addon_Metabox.php`
- Modify: `oras-tickets/includes/Admin/Pages/Settings_Page.php`
- Modify: `oras-tickets/includes/Admin/Admin_Menu.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

1. Add failing checks for administrator-only global controls, event-editor capability checks, nonce validation, and managed-registration default off.
2. Add the Zoom settings section, connection test action, and event-level Zoom Automation tab.
3. Register the Zoom module without adding access to Member Hub.
4. Run checks and verify they pass.

### Task 5: Paid virtual ticket registration and invitation email

**Files:**
- Modify: `oras-tickets/includes/Commerce/Woo/Virtual_Ticket_Access_Email.php`
- Modify: `oras-tickets/src/Integrations/Zoom/Module.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

1. Add failing checks proving one registration per buyer/event, unique join URL preference, professional invitation details, and shared-link fallback.
2. Register paid orders before sending access email.
3. Cancel registrations on cancelled/refunded orders.
4. Run checks and verify they pass.

### Task 6: RSVP approval and cancellation synchronization

**Files:**
- Modify: `oras-tickets/includes/Frontend/Event_RSVP.php`
- Modify: `oras-tickets/src/Integrations/Zoom/Module.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`
- Test: `oras-tickets/tools/phase1e-virtual-rsvp-approval-checks.php`
- Test: `oras-tickets/tools/phase1g-waitlist-cancellation-checks.php`

1. Add failing checks proving only approved virtual RSVPs are registered and pending/rejected messages contain no private link.
2. Register before the approval email is built.
3. Cancel on rejection, return to pending, or RSVP cancellation.
4. Preserve existing shared-link behavior when managed registration is disabled or unavailable.
5. Run Zoom and existing RSVP checks.

### Task 7: Release verification

**Files:**
- Modify: `docs/CHANGELOG.md`
- Modify: version metadata only after behavior passes.

1. Run `php -l` on every changed PHP file.
2. Run `git diff --check`.
3. Run `composer phpcs`.
4. Run `composer phpstan`.
5. Run Zoom, RSVP approval, cancellation, event-addon, bootstrap, and version checks.
6. Run targeted wp-env checks without modifying production data.
7. Review the diff for secret or join-URL exposure.
8. Commit the implementation only after all required checks pass.
