# Registration Desk Admission Consistency Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make every registration-detail screen and final check-in use one freshly resolved server-side admission state so volunteers never see valid controls beside an invalid result.

**Architecture:** Add a normalized admission preflight inside the Registration Desk service and use it both when loading detail and immediately before attendance writes. Return only plain volunteer state through the normal API, attach readable source/mapping/date diagnostics only to manager-qualified responses, and render mutually exclusive eligible, checked-in, and blocked screens in the kiosk.

**Tech Stack:** WordPress/PHP, WooCommerce/TEC source adapters, vanilla JavaScript/CSS kiosk UI, guarded `wp-env` integration scripts.

---

### Task 1: Reproduce the split authority

**Files:**
- Modify: `scripts/registration-desk-integration-checks.php`
- Modify: `scripts/registration-desk-operation-checks.php`

1. Add assertions for an active stored projection whose live Woo source is cancelled, plus wrong-day, refunded, mapping-review, waitlisted, valid, and checked-in detail states.
2. Add static UI assertions that blocked detail has no selectable form and stale final-submit errors reload authoritative detail.
3. Run the focused operation check and guarded integration check; confirm the new assertions fail for the missing normalized state.

### Task 2: Normalize live admission on the server

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`

1. Resolve operational status, event/date validity, RSVP status, canonical option mapping, Woo source identity/unit/lifecycle, and current attendance into one admission structure.
2. Reuse that resolver for detail and final check-in; preserve explicit-unpaid handling and all existing nonfinancial boundaries.
3. Strip internal diagnostic fields from volunteer responses and expose readable diagnostic labels only in manager-qualified detail.
4. Rerun the focused tests until the server regression cases pass.

### Task 3: Render one mutually exclusive volunteer state

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`
- Modify: `scripts/registration-desk-operation-checks.php`

1. Render eligible, already-checked-in, wrong-day, waitlisted, and manager-help detail panels from the normalized admission state.
2. Omit attendee controls and check-in actions entirely for registration-level blocks; disable only individually blocked attendees where mixed family state is available.
3. On a final-submit eligibility rejection, reload the detail screen instead of appending an error to stale valid controls.
4. Render manager diagnostics with readable labels and no raw objects or volunteer-facing internal phrases.

### Task 4: Verify, capture, and deliver

**Files:**
- Modify only if directly required by a failing check.

1. Run syntax, operation/domain/source/roster checks, guarded legacy integration, and guarded HPOS integration through `/home/rocco/projects/oras-wp-env`.
2. Exercise valid, blocked, checked-in, wrong-day, waitlisted, manager, iPad landscape, and iPad portrait states in a real browser; check console errors and save screenshots.
3. Verify no Woo order/payment, Stripe, QuickBooks, membership, or account side effects.
4. Preserve the owner's `.gitignore` edit, commit only task files on `main`, push, and wait for Phase5 and CodeQL on the exact final SHA.
