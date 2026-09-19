# Registration Desk Event Roster Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add an event-scoped, touch-first roster with canonical dynamic filters and manager-only detail, while correcting membership payment controls and qualifying everything against synthetic disposable events.

**Architecture:** Add one read-only `Event_Roster_Service` over the existing Registration Desk registration, attendee, attendance, and audit tables. Expose a station-bound roster REST route whose response is volunteer-safe by default and conditionally adds manager-only fields only after server-side manager-token validation. Reuse `Event_Offering_Resolver` for type filters, existing registration snapshots for historical labels, and existing detail/check-in services for actions.

**Tech Stack:** PHP 8.1+, WordPress REST API, WordPress database APIs, WooCommerce/TEC fixture integration, vanilla JavaScript/CSS, Playwright CLI, guarded `~/projects/oras-wp-env` integration runner.

---

### Task 1: Lock the event-roster and membership UI contracts

**Files:**
- Create: `scripts/registration-desk-roster-checks.php`
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `.github/workflows/phase5-verification.yml`

**Steps:**
1. Add failing checks for an event-scoped roster service/route, immediate roster loading, large status choices, Show Everyone, Show More People, canonical type picker, manager-only detail, and the new membership payment wording/control classes.
2. Run `php scripts/registration-desk-roster-checks.php` and `php scripts/registration-desk-operation-checks.php`; confirm failures are caused by missing roster behavior and old membership controls.
3. Add the new host check to Phase5 CI after it becomes green.

### Task 2: Implement the event-scoped roster backend

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Event_Roster_Service.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/includes/Registration_Desk/Audit_Store.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Test: `scripts/registration-desk-roster-checks.php`
- Test: `scripts/registration-desk-integration-checks.php`

**Steps:**
1. Add failing synthetic integration assertions for immediate event roster results, alphabetical ordering, event isolation, full-roster search, status/source/type filters, pagination, RSVP states, historical labels, and manager-only field authorization.
2. Run the guarded disposable integration command and confirm the new assertions fail for the missing route/service.
3. Implement efficient event-scoped SQL with alphabetical ordering, bounded limit/offset pagination, full-query search/filter predicates, attendee grouping, current-day attendance state, and historical snapshot labels.
4. Return canonical current type-filter choices only from `Event_Offering_Resolver`; never turn entitlements or historical labels into filter choices.
5. Add a station-bound `/registration-desk/roster` route. Return volunteer-safe rows normally; include email, address, source references, payment assertion, history, operator, and audit only after validating the manager header on the server.
6. Run host checks and guarded integration checks to green.

### Task 3: Build the kiosk Event Roster flow

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`
- Test: `scripts/registration-desk-operation-checks.php`
- Test: `scripts/registration-desk-roster-checks.php`

**Steps:**
1. Confirm the UI contract tests fail before production edits.
2. Replace volunteer-facing Member Lookup with Event Roster while preserving organization Membership Lookup as a clearly separate concept if exposed.
3. Load roster rows immediately; add a large optional Search This Roster field, Show Everyone reset, large single-choice status controls, canonical type controls, and bounded Show More People pagination.
4. Use a large full-screen type picker when the selected event has many canonical offerings; never use hard-coded ticket categories.
5. Open the existing Registration Detail/check-in flow from each roster row. Add manager-only expanded detail rendering when a validated manager token is active.
6. Add RSVP-only labels/filters without fabricating ticket products.
7. Run host tests and JavaScript syntax checks to green.

### Task 4: Correct membership and other critical touch controls

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`
- Test: `scripts/registration-desk-operation-checks.php`

**Steps:**
1. Add/confirm failing assertions for semantic radio inputs presented as large Cash/Check touch cards with an unmistakable selected state.
2. Implement keyboard-accessible large controls, side-by-side on iPad and stacked on narrow phones.
3. Change the helper wording to `PAYMENT RECORDED IN ALFAPOS` and the action to `RECORD MEMBERSHIP & SEND EMAIL` without changing the financial boundary.
4. Review only critical live-event choices for tiny native controls and correct clearly inappropriate cases.
5. Run host and membership regression checks to green.

### Task 5: Expand synthetic disposable-event qualification

**Files:**
- Modify: `scripts/registration-desk-integration-checks.php`
- Modify: `scripts/run-registration-desk-integration-checks.sh` only if the existing guarded fixture contract needs a bounded extension

**Steps:**
1. Build synthetic ticketed A, many-ticket B, RSVP-only, tickets-plus-RSVP, and cross-event source/target fixtures inside the existing verified disposable harness.
2. Prove ticket add/rename/phase/stock/sale-window propagation to walk-in and roster filters, unrelated-event isolation, historical snapshot honesty, RSVP roster states, tickets-over-RSVP precedence, and entitlement non-leakage.
3. Prove roster pagination, full-roster search, status filters, dynamic type filters, attendee/account separation, and manager/volunteer authorization.
4. Run legacy and HPOS guarded integration checks through `~/projects/oras-wp-env`, verifying the marker and ordinary-service preservation guards.

### Task 6: Browser and responsive qualification

**Files:**
- Evidence only: `output/playwright/*.png` (ignored)

**Steps:**
1. Use only the disposable LAN test site and synthetic events.
2. Exercise home, roster, search, status filtering, many-type picker, manager detail, RSVP roster, and membership payment controls.
3. Capture required screenshots at 1024x768, 768x1024, and a modern iPhone portrait viewport.
4. Confirm no horizontal scrolling, no console errors, and no tiny critical controls.

### Task 7: Final verification and delivery

**Files:**
- Preserve: `.gitignore` owner modification

**Steps:**
1. Run all focused host checks, JavaScript syntax, PHPStan, differential PHPCS, and committed-range whitespace checks.
2. Rerun the guarded WordPress integration suite on the exact final tree.
3. Commit only task files on `main`; preserve `.gitignore` unstaged.
4. Push `main`, wait for Phase5 and CodeQL on the exact commit, and fix directly related failures.
5. Report the final commit, workflow links/results, LAN URL, synthetic fixtures, and screenshot paths. Do not deploy or publish a release.
