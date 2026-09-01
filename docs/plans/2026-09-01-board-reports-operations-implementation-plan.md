# Board Reports Operations Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add secure frontend management and unified reporting for manual Annual Observer Passes, PMPro website memberships, and Legacy PayPal memberships.

**Architecture:** Hidden plugin-owned post types store manual and legacy records through dedicated repositories. Pure normalization/validity services merge those records with existing WooCommerce and PMPro sources, while the existing Board Reports shortcode owns frontend rendering and authenticated handlers.

**Tech Stack:** PHP 8, WordPress post/meta APIs, WooCommerce read APIs, Paid Memberships Pro read-only data/API integration, existing PHP integration scripts, PHPStan, PHPCS.

---

### Task 1: Shared Annual validity and internal storage foundations

**Files:**
- Create: `oras-tickets/includes/Domain/Annual_Pass_Validity.php`
- Create: `oras-tickets/includes/Storage/Manual_Observer_Pass_Store.php`
- Create: `oras-tickets/includes/Storage/Legacy_Membership_Store.php`
- Create: `scripts/board-operations-domain-checks.php`
- Modify: `oras-tickets/includes/Capabilities.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/oras-tickets.php`

**Steps:**
1. Add failing domain checks for anniversary dates, leap-day handling, expiration boundary, hidden post-type arguments, field sanitization, quantity independence, and the two new management capabilities.
2. Run `php scripts/board-operations-domain-checks.php` and confirm failure because the classes/capabilities do not exist.
3. Implement the pure Annual validity service and the minimum hidden-CPT store APIs (`register`, `create`, `update`, `get`, `query`) with namespaced metadata and audit fields.
4. Register includes/post types and add the capabilities to the managed capability list while keeping them out of `BOARD_CAPS`.
5. Run the focused domain checks, changed-file `php -l`, and `git diff --check` until green.
6. Commit as `Add Board Reports registry foundations`.

### Task 2: Merge manual Annual passes into Observer Pass reporting

**Files:**
- Modify: `scripts/board-reports-integration-checks.php`
- Modify: `oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php`
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`

**Steps:**
1. Add failing integration assertions proving one multi-holder manual record contributes explicit quantity to Active Annual totals, appears in verification/search/filter results, displays Manual source, and has no WooCommerce order dependency.
2. Run the Board Reports integration wrapper and confirm the new assertions fail for missing manual rows.
3. Replace the private Annual algorithm in the Observer service with `Annual_Pass_Validity`, normalize manual store records into the existing row shape, and merge before summary/filter/sort.
4. Add Source filtering/display and capability-gated frontend Add/Edit forms and handlers with nonces, validation, and safe redirects.
5. Add failing handler assertions for unauthorized, bad-nonce, invalid-data, successful create, and successful edit paths before implementing each path.
6. Run focused Board Reports integration, changed-file `php -l`, targeted PHPStan/PHPCS, and `git diff --check`.
7. Commit as `Add manual Annual Observer Pass management`.

### Task 3: Build normalized membership reporting

**Files:**
- Create: `oras-tickets/includes/Reporting/Membership_Report_Service.php`
- Create: `scripts/membership-report-integration-checks.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

**Steps:**
1. Add failing checks with PMPro-compatible fixture tables/rows and legacy records covering active, expiring, expired, linked, unlinked, exact-email match, and name-only match behavior.
2. Run the focused membership check and confirm failure because the service is absent.
3. Implement read-only PMPro availability/table detection, website-row normalization, legacy-row normalization, operational status, exact-email indicators, filters, search, summary, sorting, and pagination.
4. Verify no PMPro write functions or table mutations occur.
5. Run focused membership checks, changed-file `php -l`, targeted PHPStan, and `git diff --check`.
6. Commit as `Add unified membership reporting`.

### Task 4: Add the Memberships frontend tab and legacy management

**Files:**
- Modify: `scripts/board-reports-integration-checks.php`
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`

**Steps:**
1. Add failing UI assertions for the global Memberships tab, no event selector, summary cards, filters, search, pagination, details, source/linkage labels, and viewer access.
2. Add failing action assertions for separate management capability enforcement, nonces, sanitized create/edit/link transitions, and safe redirects.
3. Implement the Memberships branch in shortcode data loading/rendering, reusing existing Board Reports component classes and responsive patterns.
4. Implement capability-gated legacy Add/Edit forms and authenticated handlers; never render management controls to view-only users.
5. Run focused membership and Board Reports checks, changed-file `php -l`, targeted PHPStan/PHPCS, and `git diff --check`.
6. Commit as `Add frontend legacy membership management`.

### Task 5: Add Legacy PayPal CSV preview and commit

**Files:**
- Create: `oras-tickets/includes/Import/Legacy_Membership_Csv_Importer.php`
- Create: `scripts/legacy-membership-import-checks.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php`

**Steps:**
1. Add failing parser tests for allowed headers, normalization, CSV injection-safe display values, invalid/missing dates, duplicate references/emails, exact PMPro email matches, and advisory name matches.
2. Add failing handler tests for upload capability/nonce/size/type gates, per-user transient isolation, preview rendering, cancel cleanup, commit revalidation, duplicate avoidance, and transient deletion after commit.
3. Implement a bounded CSV parser that retains normalized membership fields only and returns classified preview rows.
4. Implement preview, cancel, and commit handlers using random per-user transient tokens with a short TTL; delete preview data immediately on cancel or successful commit.
5. Render the import form and preview inside Memberships only for membership managers.
6. Run focused import, membership, and Board Reports checks plus changed-file `php -l`, targeted PHPStan/PHPCS, and `git diff --check`.
7. Commit as `Add Legacy PayPal membership CSV import`.

### Task 6: Bounded feature verification

**Files:**
- Modify only if a focused check exposes a feature defect.

**Steps:**
1. Run `php -l` over PHP files changed on this branch.
2. Run the domain, membership-report, legacy-import, and Board Reports integration checks once from the isolated environment.
3. Run PHPStan on new/changed service and store files, PHPCS on changed files, and `git diff --check`.
4. Confirm version remains `0.4.51`, no ZIP/checksum/release-note/tag/push/deploy work exists, and the primary checkout remains untouched except its known `.playwright-cli/` directory.
5. Commit any focused corrections as one logical verification fix; otherwise leave the feature HEAD at the last implementation commit.
