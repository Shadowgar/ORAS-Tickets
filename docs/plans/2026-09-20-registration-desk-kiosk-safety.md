# Registration Desk Kiosk Safety Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Simplify the volunteer kiosk around one registration finder, expose the safe membership workflow to volunteers, and preserve unfinished work through refreshes and temporary connection failures.

**Architecture:** Reuse the existing event-roster endpoint and UI as the sole Find Registration experience. Add one PMPro-backed membership offering resolver so kiosk names, prices, checkout URLs, and renewal semantics remain canonical while Registration Desk stores only eligible level IDs and historical activation snapshots. Extend the existing station-local storage envelope with short-lived, event- and operator-scoped walk-in and membership drafts, stable request IDs, payment-handled flags, and a persistent manager-mode indicator.

**Tech Stack:** WordPress/PHP 8, PMPro APIs, Registration Desk REST API, vanilla JavaScript/CSS, guarded wp-env integration checks, Playwright CLI.

---

### Task 1: Lock the simplified volunteer navigation contract

**Files:**
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `scripts/registration-desk-roster-checks.php`
- Modify: `oras-tickets/assets/registration-desk/desk.js`

1. Add failing source checks proving the separate Event Roster home action is absent, Find Registration opens the populated roster, the title/search copy is volunteer-facing, filters remain available, and searched no-results retain recovery actions.
2. Run both focused checks and confirm the new assertions fail for the old split search/roster UI.
3. Route Find Registration directly to the roster, remove the separate home action and obsolete duplicate search renderer, and update roster wording/status labels.
4. Rerun the focused checks and JavaScript syntax check.

### Task 2: Resolve volunteer membership offerings from PMPro

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Membership_Offering_Resolver.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/includes/Registration_Desk/Config.php`
- Modify: `oras-tickets/includes/Registration_Desk/Admin_Settings.php`
- Modify: `oras-tickets/includes/Registration_Desk/Membership_Credit_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `scripts/registration-desk-membership-checks.php`
- Modify: `scripts/registration-desk-operation-checks.php`

1. Add failing checks for level-ID-only eligibility, canonical PMPro name/price/checkout URL/period resolution, rename and price changes, unavailable/disabled levels, historical snapshots, and volunteer POST versus manager-only resend/cancel/list permissions.
2. Run the membership and operation checks and confirm the new contract fails.
3. Implement the resolver, migrate configuration normalization to level-ID eligibility, render canonical read-only level details with eligibility checkboxes, and re-resolve the offering at final creation.
4. Permit ordinary desk admission capability to create pending activations while preserving manager-only correction/recovery routes.
5. Rerun the focused tests and PHP syntax checks.

### Task 3: Build the volunteer membership wizard

**Files:**
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `scripts/registration-desk-roster-checks.php`
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`

1. Add failing checks for the two-choice volunteer membership menu, read-only lookup, dynamic level picker, cash/check step, AlfaPOS handoff and acknowledgement, stable idempotency identity, volunteer success screen, and manager-only exception controls.
2. Confirm RED with the focused source checks.
3. Implement the volunteer wizard and keep manager pending-activation controls separate.
4. Rerun focused checks and JavaScript syntax.

### Task 4: Add scoped drafts, safe navigation, and connection recovery

**Files:**
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`

1. Add failing checks for short-lived station/event/operator-scoped drafts, walk-in and membership restoration, clear-on-success/discard/logout, event-change warning, payment-handled persistence, stable request replay, plain connection-loss UI, and explicit Start Over confirmation.
2. Confirm RED.
3. Implement draft persistence and restoration, shared safe-home prompt, connection-loss recovery, and payment-already-handled warnings without offline synchronization.
4. Rerun focused checks and JavaScript syntax.

### Task 5: Make manager state and outcomes unmistakable

**Files:**
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`

1. Add failing checks for a persistent manager-mode banner/exit control, clearing on volunteer/event/logout changes, word/icon state labels, and unambiguous registration, RSVP, waitlist, and membership success screens.
2. Confirm RED, implement the shell-level manager indicator and outcome wording, then rerun focused checks.

### Task 6: Extend disposable WordPress regressions

**Files:**
- Modify: `scripts/registration-desk-integration-checks.php`
- Modify: `scripts/run-registration-desk-integration-checks.sh` only if its pinned test-safety contract requires an update

1. Add synthetic PMPro fixtures and assertions covering canonical membership rename/price propagation, eligibility exclusion, volunteer create permission, cash/check activation creation, mail/credit behavior, no attendee account creation, manager-only correction operations, and idempotent repeat submission.
2. Preserve the existing roster, RSVP, recovery, concurrency, and prohibited-side-effect snapshots.
3. Run guarded legacy and HPOS suites only through `/home/rocco/projects/oras-wp-env`.

### Task 7: Browser and final verification

**Files:**
- Capture ignored artifacts under: `output/playwright/`

1. Exercise the simplified home, unified finder, volunteer membership flow, refresh-restored walk-in/membership drafts, connection loss, payment warning, manager banner, and success states in a real browser.
2. Verify 1024x768, 768x1024, and 390x844 have no horizontal overflow or console errors and capture the requested screenshots.
3. Run all host checks, PHPStan, differential PHPCS, JavaScript syntax, guarded legacy/HPOS suites, and `git diff --check` scoped to owned files.
4. Confirm the owner’s exact `.gitignore` edit is the only remaining unstaged change, commit, push `main`, and wait for Phase5 and CodeQL on the exact final SHA.
