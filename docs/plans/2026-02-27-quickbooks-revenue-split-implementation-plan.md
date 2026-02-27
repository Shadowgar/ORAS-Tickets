# QuickBooks Revenue Split Sync Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add deterministic Woo paid-order revenue split syncing to QuickBooks using one JournalEntry per order with idempotency.

**Architecture:** Introduce a dedicated QuickBooks integration module in ORAS-Tickets that handles OAuth2, API requests, split calculation, async orchestration, and retry behavior while keeping Stripe connector active and unmodified. Revenue classification occurs at Woo line-item level and posts debit/credit JournalEntry lines via configurable account mapping.

**Tech Stack:** WordPress, WooCommerce, Action Scheduler, WP-CLI, QuickBooks Online REST API.

---

### Task 1: Add QuickBooks Integration Module

**Files:**
- Create: `oras-tickets/src/Integrations/QuickBooks/Settings.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/QuickBooks_Logger.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/OAuth_Client.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/Api_Client.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/Journal_Entry_Creator.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/Retry_Handler.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
- Create: `oras-tickets/src/Integrations/QuickBooks/Module.php`

### Task 2: Wire Module Into Plugin Bootstrap

**Files:**
- Modify: `oras-tickets/includes/Bootstrap.php`

### Task 3: Extend ORAS Settings Page

**Files:**
- Modify: `oras-tickets/includes/Admin/Pages/Settings_Page.php`

### Task 4: Add WP-CLI Commands

**Files:**
- Create: `oras-tickets/src/Integrations/QuickBooks/Cli_Command.php`

### Task 5: Add Split Calculator Unit-Style Checks

**Files:**
- Create: `scripts/qbo-split-calculator-tests.php`

### Task 6: Update Roadmap and State Docs

**Files:**
- Modify: `docs/CURRENT_STATE.md`
- Modify: `docs/NEXT.md`
- Modify: `docs/ROADMAP.md`
- Modify: `docs/CHANGELOG.md`
- Create: `docs/plans/2026-02-27-quickbooks-revenue-split-clean-room-report.md`

### Task 7: Verification

Run:
- `php -l` on each changed PHP file.
- `wp eval-file scripts/qbo-split-calculator-tests.php` in wp-env.
- `wp oras-tickets qbo test-connection` with configured sandbox credentials.

Expected:
- Syntax checks pass.
- Split calculator script prints pass line.
- QuickBooks connection command returns success and refreshes account cache.
