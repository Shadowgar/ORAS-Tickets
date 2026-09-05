# Membership Person Normalization Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace raw membership-relationship roster rows with one read-only normalized row per resolvable person.

**Architecture:** Preserve the existing PMPro and Legacy adapters as raw record readers, then aggregate records in `Membership_Report_Service` through strong identity keys. The frontend consumes aggregate person rows, exposes their retained source-record arrays in the native dialog, and shows a manager-only orphan diagnostic without source writes.

**Tech Stack:** WordPress/PHP 7.4+, Paid Memberships Pro tables, Legacy_Membership_Store, wp-env focused integration scripts, PHPStan.

---

### Task 1: Define the aggregate report contract with failing service assertions

**Files:**
- Modify: `scripts/membership-report-integration-checks.php`
- Modify: `oras-tickets/includes/Reporting/Membership_Report_Service.php`

**Step 1: Write failing tests**

Add fixtures for a user with one current and three historical PMPro relationships, a same-email two-profile Legacy person, an exact-email PMPro/Legacy match, same-name/different-email records, a former person, and an orphan PMPro relationship. Assert one aggregate person per strong identity; retained `website_membership`, `membership_history`, and `legacy_paypal_records`; Current/Former/All scopes; person-level status priority; and `Current Unique Members` counts.

**Step 2: Run test to verify it fails**

Run: `scripts/run-membership-report-integration-checks.sh`

Expected: FAIL because current output returns raw `all_rows` and has no person aggregate/history contract.

**Step 3: Implement the minimal aggregation boundary**

Keep raw row normalization intact. Add helpers to resolve stable person keys by explicit linkage/user ID/exact email, record name-only review hints, reject unresolved PMPro rows into an orphan collection, attach legacy records to exact-email or explicit linked people, and return normalized person rows.

**Step 4: Run test to verify it passes**

Run: `scripts/run-membership-report-integration-checks.sh`

Expected: PASS.

### Task 2: Compute person-level display fields, filters, and summary metrics

**Files:**
- Modify: `scripts/membership-report-integration-checks.php`
- Modify: `oras-tickets/includes/Reporting/Membership_Report_Service.php`

**Step 1: Write failing tests**

Assert Active wins over old inactive records, Expiring Soon is current, historical levels do not override current PMPro, Legacy-only current people display `Legacy PayPal Membership`, source filters inspect attached sources, and summary counts distinguish unique people from raw PMPro and Legacy current record counts.

**Step 2: Run test to verify it fails**

Run: `scripts/run-membership-report-integration-checks.sh`

Expected: FAIL on absent person scope and aggregate summary fields.

**Step 3: Implement minimal selection/filter/summary helpers**

Add person status ranking, current-record selection, compact source-label construction, membership-level precedence, `current|former|all` scope normalization, aggregate filtering/searching, sorting, and the four precise source/people metrics.

**Step 4: Run test to verify it passes**

Run: `scripts/run-membership-report-integration-checks.sh`

Expected: PASS.

### Task 3: Render aggregate summaries, scope controls, detail sections, and diagnostics

**Files:**
- Modify: `scripts/membership-frontend-integration-checks.php`
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`

**Step 1: Write failing tests**

Assert the default Current Members scope, the three scope options, exact summary labels, dialog Website Membership/Membership History/Legacy PayPal sections, distinct displayed Profile IDs, and a collapsed orphan count/list visible only to `oras_tickets_manage_memberships`.

**Step 2: Run test to verify it fails**

Run: `scripts/run-membership-frontend-integration-checks.sh`

Expected: FAIL because the frontend renders raw-row timing/source fields and legacy status/source/account-link filters.

**Step 3: Implement minimal frontend changes**

Replace source-row summary/filter labels with aggregate scope controls, retain four compact table columns, render aggregate dialog sections without arbitrary user meta, and add a manager-only read-only orphan diagnostics `<details>` block after the roster controls.

**Step 4: Run test to verify it passes**

Run: `scripts/run-membership-frontend-integration-checks.sh`

Expected: PASS.

### Task 4: Verify focused Board Reports integration and static safety

**Files:**
- Modify only files changed in Tasks 1-3 if corrective edits are needed.

**Step 1: Run focused checks**

Run:

```bash
php -l oras-tickets/includes/Reporting/Membership_Report_Service.php
php -l oras-tickets/includes/Frontend/Board_Reports.php
scripts/run-membership-report-integration-checks.sh
scripts/run-membership-frontend-integration-checks.sh
scripts/run-board-reports-integration-checks.sh
composer phpstan
git diff --check
```

Expected: each command exits 0; only task-scoped files are changed.

**Step 2: Commit the scoped correction**

```bash
git add oras-tickets/includes/Reporting/Membership_Report_Service.php oras-tickets/includes/Frontend/Board_Reports.php scripts/membership-report-integration-checks.php scripts/membership-frontend-integration-checks.php docs/plans/2026-09-05-membership-person-normalization-design.md docs/plans/2026-09-05-membership-person-normalization-implementation-plan.md
git commit -m "Normalize Board Reports membership roster by person"
```

Expected: one focused commit; no QuickBooks, version, package, or deployment changes.
