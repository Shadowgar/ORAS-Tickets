# QuickBooks Data Safety Hardening Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Prevent bad QuickBooks writes, require explicit operator control, and add deterministic rollback/audit paths for Woo order JournalEntry sync.

**Architecture:** Extend existing `ORAS\Tickets\Integrations\QuickBooks` module with fail-closed runtime gates, explicit write modes, and persisted order-level audit metadata. Keep JournalEntry-only design and preserve Stripe connector coexistence. Add CLI/operator tooling for preview, approve, reverse, and audit without changing third-party plugins.

**Tech Stack:** WordPress, WooCommerce, Action Scheduler, WP-CLI, ORAS-Tickets QuickBooks module.

---

### Task 1: Add safety controls to settings + admin UI

**Files:**
- Modify: `oras-tickets/src/Integrations/QuickBooks/Settings.php`
- Modify: `oras-tickets/includes/Admin/Pages/Settings_Page.php`

1. Add settings defaults/sanitization fields:
- `dry_run_mode` (default `true`)
- `require_manual_approval` (default `true`)
- `strict_mapping_mode` (default `true`)
- `allow_unmapped_fallback` (default `false`)
2. Register and render controls on QuickBooks settings page with clear operator text.
3. Ensure storage/hydration path preserves these values.

### Task 2: Enforce fail-closed orchestration and duplicate prevention

**Files:**
- Modify: `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
- Modify: `oras-tickets/src/Integrations/QuickBooks/Split_Calculator.php`
- Modify: `oras-tickets/src/Integrations/QuickBooks/Journal_Entry_Creator.php`
- Modify: `oras-tickets/src/Integrations/QuickBooks/Api_Client.php`

1. Add manual-approval queue status path (`pending_qbo_review`) that blocks auto enqueue.
2. Add strict mapping gate: block sync when unmapped buckets/warnings are present and strict mode is enabled.
3. Add preflight validator in JE creator for balanced/valid payload constraints.
4. Add duplicate lookup by deterministic `DocNumber` query before creating JE.
5. Persist additional sync metadata per order (doc number, request hash, intuit_tid if available).

### Task 3: Add retry classifier and robust audit trail

**Files:**
- Modify: `oras-tickets/src/Integrations/QuickBooks/Retry_Handler.php`
- Modify: `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`
- Modify: `oras-tickets/src/Integrations/QuickBooks/Api_Client.php`

1. Retry only transport/transient HTTP faults (`429`, `5xx`, network).
2. Do not retry validation/mapping/authorization grant errors.
3. Record order-level append-only audit entries (action, actor, status, intuit_tid, timestamp, mode).

### Task 4: Add operator rollback and CLI safety commands

**Files:**
- Modify: `oras-tickets/src/Integrations/QuickBooks/Cli_Command.php`
- Modify: `oras-tickets/src/Integrations/QuickBooks/Journal_Entry_Creator.php`
- Modify: `oras-tickets/src/Integrations/QuickBooks/Sync_Orchestrator.php`

1. Add `approve-order` command to process pending review orders.
2. Add `reverse-order` command to post reversing JE for previously synced order.
3. Add `audit-order` command to print sync metadata/history for troubleshooting.

### Task 5: Documentation + verification

**Files:**
- Modify: `docs/CURRENT_STATE.md`
- Modify: `docs/NEXT.md`
- Modify: `docs/CHANGELOG.md`
- Create/Modify: `docs/quickbooks-live-rollout-checklist.md`
- Modify: `scripts/qbo-sync-safeguard-tests.php`
- Create: `scripts/qbo-safety-controls-tests.php`

1. Document new safety model and rollback workflow.
2. Add deterministic test script for dry-run/manual-approval/strict-mapping gates.
3. Verify from `oras-wp-env` with `wp eval-file` scripts + PHP lint.

