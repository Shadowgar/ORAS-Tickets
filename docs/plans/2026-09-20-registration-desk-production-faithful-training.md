# Production-Faithful Registration Desk Training Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make Training Mode use a complete isolated snapshot of the selected event's current roster while rendering the same workflow and wording as the live Registration Desk.

**Architecture:** Add a snapshot builder that reads the existing event roster and registration detail services without writing live data, then stores normalized live-shaped records in the existing training-session JSON state. Route training transport through the shared live browser renderer while keeping all mutation endpoints and state transitions training-only.

**Tech Stack:** WordPress/PHP 8, existing Registration Desk stores and REST controllers, vanilla JavaScript/CSS, standalone focused PHP checks, disposable wp-env integration checks.

---

### Task 1: Specify production-faithful snapshot and presentation behavior

**Files:**
- Modify: `scripts/registration-desk-training-checks.php`
- Modify: `scripts/registration-desk-operation-checks.php`

1. Add failing assertions for live-shaped snapshot records, preservation of actual roster data, shared live detail rendering, live payment/membership/statistics wording, and a compact single training marker.
2. Run `php scripts/registration-desk-training-checks.php` and `php scripts/registration-desk-operation-checks.php`; confirm failures identify the missing snapshot and duplicated training presentation.

### Task 2: Snapshot the complete current event roster

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Training_Snapshot_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Training_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Training_Rest_Controller.php`
- Modify: `oras-tickets/oras-tickets.php`
- Test: `scripts/registration-desk-training-checks.php`

1. Page through `Event_Roster_Service` for the selected event and capture every registration plus its `Service::detail()` result.
2. Normalize the snapshot into live API shapes with deterministic training identifiers where needed.
3. Use the snapshot on start and reset; retain the current canonical offerings and isolated attendance/request/history collections.
4. Run the focused training checks and confirm snapshot assertions pass.

### Task 3: Reuse the live operational renderer

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`
- Modify: `oras-tickets/includes/Registration_Desk/Training_Rest_Controller.php`
- Modify: `oras-tickets/includes/Registration_Desk/Training_Service.php`
- Test: `scripts/registration-desk-operation-checks.php`

1. Make registration detail and check-in use the shared live renderer with training-only endpoint selection.
2. Make walk-in, payment, membership, completion, and stats screens render live wording in both modes.
3. Replace the large warning banner with one compact top-shell `TRAINING` marker and simulated date.
4. Keep manager-only training controls explicit.
5. Run focused training and operation checks until green.

### Task 4: Prove isolation and lifecycle behavior

**Files:**
- Modify: `scripts/registration-desk-integration-checks.php`

1. Add a failing guarded assertion that start/reset snapshot the current event roster and that training check-in changes only training state.
2. Add coverage that reset refreshes the source snapshot and date changes preserve copied records and prior-day training attendance.
3. Run only the focused Training Mode integration section in the verified disposable environment.

### Task 5: Final verification and release preparation

**Files:**
- Modify version files only after behavior is green.

1. Run focused Training Mode checks, syntax checks for changed PHP/JS, differential PHPCS, PHPStan if required by changed types, and `git diff --check`.
2. Commit the implementation on `main` without staging `.gitignore` or generated release archives.
3. Bump the patch version and publish only after explicit owner direction for the corrected release.
