# Zoom Unattended Meetings Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let approved virtual attendees enter ORAS-managed Zoom meetings at any time without a host.

**Architecture:** Extend the Zoom API contract and meeting service to update and verify unattended-access settings. Persist the per-event policy in the existing Zoom event configuration, expose it in the existing event metabox, and provide a nonce-protected manual synchronization action with visible status.

**Tech Stack:** WordPress PHP, Zoom Meetings REST API, existing ORAS-Tickets Zoom services, custom PHP test harness, PHPCS, PHPStan.

---

### Task 1: Add Zoom Meeting Update Support

**Files:**
- Modify: `oras-tickets/src/Integrations/Zoom/Api_Interface.php`
- Modify: `oras-tickets/src/Integrations/Zoom/Api_Client.php`
- Modify: `oras-tickets/src/Integrations/Zoom/Meeting_Service.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

**Steps:**
1. Add failing checks for the unattended settings payload and verification.
2. Run `php oras-tickets/tools/zoom-integration-checks.php` and confirm failure.
3. Add `update_meeting()` to the API contract and client using `PATCH /meetings/{id}`.
4. Add a meeting-service method that writes `join_before_host`, `jbh_time`, and `waiting_room`, then reads the meeting back and verifies all three values.
5. Run the Zoom harness and confirm it passes.

### Task 2: Persist And Render Event Policy

**Files:**
- Modify: `oras-tickets/includes/Admin/Metaboxes/Event_Zoom_Metabox.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

**Steps:**
1. Add failing checks for the default, saved policy, and synchronization status fields.
2. Add the unattended-access checkbox, warning text, and last-sync status to the Zoom Automation panel.
3. Preserve the policy and synchronization metadata in `_oras_zoom_integration_v1`.
4. Synchronize enabled managed meetings during a normal authorized event save.
5. Run the targeted harness and confirm it passes.

### Task 3: Add Manual Synchronization

**Files:**
- Modify: `oras-tickets/src/Integrations/Zoom/Module.php`
- Modify: `oras-tickets/includes/Admin/Metaboxes/Event_Zoom_Metabox.php`
- Test: `oras-tickets/tools/zoom-integration-checks.php`

**Steps:**
1. Add failing checks for capability, nonce, event validation, redirect notices, and API failure handling.
2. Register a nonce-protected `admin_post` action.
3. Add `Sync Zoom Settings` to the event panel.
4. Store sanitized success or failure status and redirect back to the event editor.
5. Run the targeted harness and confirm it passes.

### Task 4: Document And Verify

**Files:**
- Modify: `docs/ZOOM_INTEGRATION_DEPLOYMENT.md`
- Modify: `docs/CHANGELOG.md`

**Steps:**
1. Document unattended access, security implications, required Zoom scopes, and existing-event synchronization.
2. Run all PHP syntax checks on modified PHP files.
3. Run `php oras-tickets/tools/zoom-integration-checks.php`.
4. Run `composer phpcs`.
5. Run `composer phpstan`.
6. Run `git diff --check`.
7. Review the final diff for unrelated changes and compatibility regressions.
