# Paid-but-Not-Found Recovery Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a manager-qualified recovery path for legitimate website registrations missing from the desk, plus an audited nonfinancial manager-verified fallback and final kiosk wording/alignment polish.

**Architecture:** Add a manager-only recovery service that searches canonical Woo order evidence for the selected event and explicit cross-event entitlements, then delegates synchronization to the existing idempotent projection service. Add a distinct `manager_verified_manual` service operation that validates current canonical offerings, blocks conservative duplicates including unprojected canonical sources, creates attendee slots without attendance, and appends an audit record. The kiosk reuses the existing Manager PIN token and normal registration detail/check-in flow.

**Tech Stack:** WordPress REST API, WooCommerce CRUD order APIs, existing ORAS Registration Desk stores/services, vanilla JavaScript, CSS, guarded `wp-env` integration tests.

---

### Task 1: Specify recovery contracts with failing host checks

**Files:**
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `scripts/registration-desk-source-checks.php`
- Modify: `scripts/registration-desk-stats-checks.php`

1. Add assertions for manager-only recovery search/sync/manual routes and service methods.
2. Add assertions for the failed-search escalation screens, RSVP wording, plain volunteer language, and reusable centered-control CSS.
3. Add source-stat expectations for `manager_verified_manual` and distinct RSVP reporting.
4. Run the focused checks and confirm they fail because the new contracts are absent.

### Task 2: Implement canonical recovery search and idempotent synchronization

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Recovery_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Source_Adapter.php`
- Modify: `oras-tickets/includes/Registration_Desk/Projection_Service.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`

1. Add canonical order evidence search by name, email, phone, order number, order item/reference, and projected registration UUID/source key.
2. Resolve selected-event and explicit cross-event access with clear valid, review, or invalid outcomes.
3. Add manager-qualified REST search and sync endpoints.
4. Synchronize through `Projection_Service::reconcile_source()` so source identity, attendance, attendees, and history remain stable and repeat sync cannot duplicate records.
5. Return plain manager-facing results and the synchronized registration UUIDs.
6. Run focused checks until green.

### Task 3: Implement audited manager-verified manual registration

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Registration_Store.php`
- Modify: `oras-tickets/includes/Registration_Desk/Event_Roster_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Event_Stats_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `scripts/registration-desk-stats-checks.php`

1. Add a manager-only operation requiring name, current canonical offering/fingerprint, reason, and explicit proof acknowledgement; email and phone remain optional but validated when supplied.
2. Block exact email/phone desk duplicates and matching unprojected canonical website evidence; never merge by name alone and never allow an override.
3. Create `manager_verified_manual` registration plus stable attendee slots without attendance or financial/account side effects.
4. Append the manager/operator, timestamp, event, reason, and offering snapshot to the audit/evidence trail.
5. Show Manager Verified as a distinct roster/statistics source and preserve historical offering labels.
6. Run focused checks until green.

### Task 4: Prove backend behavior in the disposable WordPress instance

**Files:**
- Modify: `scripts/registration-desk-integration-checks.php`

1. Add synthetic missing-projection, cancelled/refunded, wrong-event, and cross-event orders.
2. Verify canonical recovery search, sync, immediate roster visibility, repeat idempotency, and preservation of existing attendee/audit history.
3. Verify manager-verified creation, reason/audit data, duplicate refusal, roster/statistics source, and absence of Woo, payment, Stripe, QuickBooks, mail, user, and membership side effects.
4. Verify volunteer authorization cannot use manager recovery endpoints.
5. Run legacy and HPOS guarded suites only through `/home/rocco/projects/oras-wp-env` and confirm red before implementation-specific assertions turn green.

### Task 5: Implement kiosk recovery workflow and wording cleanup

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `scripts/registration-desk-roster-checks.php`

1. Add the three-choice no-result screen and proof guidance.
2. Route Get Manager Help through the existing PIN screen and then directly to Find Missing Registration.
3. Add manager canonical-source search, result/access display, sync success, Open Registration, and manager-verified form with acknowledgement/reason.
4. Reuse normal detail/check-in after sync or manual creation; do not check in during manual record creation.
5. Change RSVP filters to All RSVPs, Confirmed, Waitlisted, Here Today and update RSVP detail/row wording without changing stored filter values.
6. Remove developer-facing language from volunteer-rendered content and map errors to plain language.
7. Run host checks and JavaScript syntax checks until green.

### Task 6: Center kiosk controls and verify responsive UI

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.css`

1. Apply reusable flex/grid centering, symmetric padding, wrapping, and min-height rules to the specified large controls without fixed heights.
2. Exercise failed search, PIN, recovery search, sync, manual verification, RSVP filters, and representative centered buttons in a real browser.
3. Verify 1024x768, 768x1024, and 390x844 have no horizontal overflow or console errors.
4. Capture all requested screenshots under `output/playwright/`.

### Task 7: Final verification and delivery

**Files:**
- Verify all changed files and preserved owner state.

1. Run host checks, PHPStan, differential PHPCS, JavaScript syntax, diff checks, and guarded legacy/HPOS Registration Desk integration suites on the final commit.
2. Confirm only the owner's exact `.gitignore` edit remains unstaged.
3. Commit implementation work in bounded commits, push `main`, and wait for Phase5 and CodeQL on the exact final SHA.
4. Fix directly related failures, restore the LAN test URL, and provide the requested concise screenshot handoff without deploying or releasing.
