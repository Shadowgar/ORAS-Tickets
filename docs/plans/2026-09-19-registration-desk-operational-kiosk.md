# Registration Desk Operational Kiosk Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Turn Registration Desk V1 into the approved event-scoped volunteer kiosk with PIN-gated manager operations, offline membership activation, shared event statistics, historical Board Reports, and responsive real-device behavior.

**Architecture:** Keep the four existing nonfinancial registration tables as the attendance authority, add one desk-owned pending-membership table, and integrate one-use zero-initial-payment credits with the existing PMPro discount-code authority. Bind each station token to its selected eligible TEC event, issue a separate PIN-derived manager token, and expose one canonical event-statistics service to both kiosk REST responses and Board Reports. Keep AlfaPOS, Woo event orders, Stripe, QuickBooks, products, stock, attendee accounts, and final membership activation outside Registration Desk.

**Tech Stack:** PHP 8+, WordPress REST API and mail, The Events Calendar metadata, WooCommerce read APIs, Paid Memberships Pro 3.8-compatible discount codes, MySQL/InnoDB, vanilla JavaScript/CSS, Playwright CLI, and the guarded `/home/rocco/projects/oras-wp-env` test instance.

---

### Task 1: Event catalog, event-bound station sessions, manager PIN, and official branding

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Event_Catalog.php`
- Create: `oras-tickets/includes/Registration_Desk/Manager_Access.php`
- Create: `oras-tickets/assets/registration-desk/oras-mark.png`
- Modify: `oras-tickets/includes/Registration_Desk/Config.php`
- Modify: `oras-tickets/includes/Registration_Desk/Station_Session.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Registration_Desk/Admin_Settings.php`
- Modify: `oras-tickets/includes/Registration_Desk/Landing_Page.php`
- Test: `scripts/registration-desk-domain-checks.php`
- Test: `scripts/registration-desk-access-checks.php`
- Test: `scripts/registration-desk-integration-checks.php`

1. Add failing checks for current-year overlap, ticket/RSVP eligibility, today/upcoming/past ordering, station event binding, hashed four-digit PIN storage, throttled incorrect PINs, manager-token invalidation boundaries, and local official logo metadata.
2. Run host checks and the guarded integration script; confirm RED for the missing catalog/PIN behavior.
3. Implement the smallest event catalog and signed manager token, keeping event/config scope server-derived from the station token.
4. Add admin PIN setup and kiosk-only iOS metadata using the exact official ORAS mark.
5. Re-run focused checks until GREEN.

### Task 2: Offline membership credit and activation lifecycle

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Offline_Membership_Store.php`
- Create: `oras-tickets/includes/Registration_Desk/Membership_Credit_Service.php`
- Create: `oras-tickets/includes/Registration_Desk/Member_Lookup_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Schema.php`
- Modify: `oras-tickets/includes/Registration_Desk/Config.php`
- Modify: `oras-tickets/includes/Registration_Desk/Admin_Settings.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Test: `scripts/registration-desk-membership-checks.php`
- Test: `scripts/registration-desk-integration-checks.php`

1. Add failing checks for mapping validation, pending-record persistence, random one-use 90-day exact-level codes, zero initial payment with unchanged recurring/expiration terms, email binding, resend-same-code, email retry, cancellation, expiry, redemption linking, no kiosk user creation, and no direct membership activation.
2. Confirm RED, then add the additive schema and PMPro-compatible credit service.
3. Hook PMPro validation/redemption so successful matching checkout marks the pending record redeemed without duplicating the resulting website person.
4. Add limited volunteer member lookup and PIN-gated membership management REST operations.
5. Re-run focused and guarded checks until GREEN.

### Task 3: Canonical event statistics and Board Reports

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Event_Stats_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Reporting/Membership_Report_Service.php`
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Test: `scripts/registration-desk-stats-checks.php`
- Test: `scripts/membership-report-integration-checks.php`
- Test: `scripts/board-reports-integration-checks.php`

1. Add failing fixtures for registrations versus people, source splits, complimentary, family attendance, one-day/full-event, no-shows, payment assertions, unique attendees, attendance instances, attendance by day, and event-originated membership statuses.
2. Confirm RED, implement one query/service definition, and expose the same response to kiosk and Board Reports.
3. Merge pending/redeemed offline activation records conservatively into membership person aggregation using linked user ID or exact normalized email, never name-only matching.
4. Re-run all report checks until GREEN.

### Task 4: Foolproof kiosk flows and recovery

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Registration_Desk/Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Attendee_Store.php`
- Test: `scripts/registration-desk-operation-checks.php`
- Test: `scripts/registration-desk-integration-checks.php`

1. Add failing source/operation assertions for event selection, family slot edits, already-checked-in completion, manager corrections/reversal/sync, AlfaPOS handoff, and bounded failed-save recovery using the same request identity.
2. Confirm RED, implement the event picker, compact five-action home, useful detail screen, family behavior, handoff step, manager PIN/area, Member Lookup, Event Stats, membership recording, and recoverable second failure.
3. Preserve draft/request/payment state until success or explicit confirmed abandonment.
4. Re-run host and guarded integration checks until GREEN.

### Task 5: Responsive browser qualification and delivery

**Files:**
- Modify as defects require: `oras-tickets/assets/registration-desk/desk.js`
- Modify as defects require: `oras-tickets/assets/registration-desk/desk.css`
- Modify as defects require: directly related PHP/tests only

1. Start only the verified disposable test instance and create ticket, RSVP-only, ordinary excluded, today, upcoming, and earlier-year fixtures plus PMPro-compatible membership levels.
2. Exercise every requested volunteer, registration, manager, membership, stats, Board Reports, failure/retry, and financial-isolation workflow.
3. Capture the requested screenshots at 1024x768, 768x1024, and 390x844; verify no horizontal overflow, clipped controls, or console errors.
4. Run PHP syntax, JavaScript syntax, focused PHPCS, PHPStan, registration/report suites, guarded legacy and HPOS integration, `git diff --check`, and version/capability checks.
5. Commit coherent changes, push `main`, wait for CI on the exact commit, fix directly related failures, and provide the concise hands-on handoff without releasing or deploying.
