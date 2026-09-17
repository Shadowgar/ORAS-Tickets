# Registration Desk M1A.1 Corrections Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Correct the five focused M1A review findings while preserving the permanent nonfinancial boundary and producing guarded legacy/HPOS evidence.

**Architecture:** A Woo order-status listener performs automatic desk-only projection, while an administrator recovery endpoint traverses a signed, snapshot-bound cursor and persists honest coverage state. Admission validates the stored registration against fresh source option/unit evidence; configuration and active-event publication use one globally serialized database transaction; restricted accounts are denied before AJAX dispatch; audit failures distinguish true duplicates from storage faults.

**Tech Stack:** WordPress 6.x, WooCommerce 11.1, The Events Calendar 6.17, PHP 8.1, MariaDB/InnoDB, WP REST API, wp-env/Docker, PHPStan, PHPCS.

---

### Task 1: Deny restricted AJAX before dispatch

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Access.php`
- Modify: `scripts/fixtures/oras-registration-desk-test-guard.php`
- Modify: `scripts/registration-desk-integration-checks.php`
- Modify: `scripts/run-registration-desk-integration-checks.sh`

**Step 1: Add failing authenticated HTTP probes**

Register test-only `wp_ajax_oras_registration_desk_probe` and `wc_ajax_oras_registration_desk_probe` handlers which increment isolated marker options. Add a guarded helper that generates a real logged-in cookie for a fixture user and sends HTTP requests to `admin-ajax.php` and `/?wc-ajax=...`.

Assert that a restricted desk account receives the desk-specific 403 response and each marker remains zero. Assert the same requests execute for an administrator and ordinary user. Retain REST, capability, and legacy endpoint tests.

**Step 2: Run the disposable integration test and verify RED**

Run: `./scripts/run-registration-desk-integration-checks.sh --mode=legacy`

Expected: FAIL because the restricted probe handler executes or Woo dispatches before the current priority-1 guard.

**Step 3: Implement the early denial**

Remove the blanket `wp_doing_ajax()` exemption. For the restricted role, reject `admin-ajax.php` during `admin_init` with a safe 403. Register the frontend guard before Woo's priority-0 dispatcher and reject `WC_DOING_AJAX`/`wc-ajax` requests before dispatch. Keep desk REST and nonrestricted accounts unchanged.

**Step 4: Verify GREEN and commit**

Run the access probes and existing guarded access assertions. Commit production code and its regression together.

### Task 2: Add deterministic discovery, recovery cursors, and coverage state

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Coverage_Store.php`
- Create: `oras-tickets/includes/Registration_Desk/Recovery_Cursor.php`
- Create: `oras-tickets/includes/Registration_Desk/Source_Change_Listener.php`
- Modify: `oras-tickets/includes/Registration_Desk/Source_Adapter.php`
- Modify: `oras-tickets/includes/Registration_Desk/Projection_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `scripts/registration-desk-source-checks.php`
- Modify: `scripts/registration-desk-integration-checks.php`

**Step 1: Add failing source/pagination regressions**

Add fixtures proving:

- a qualifying order completed after initial recovery becomes searchable through the registered listener;
- an older pending/on-hold order becomes eligible on transition;
- repeated processing/completed transitions preserve one registration identity;
- unrelated orders occupy earlier pages, including a nonfinal page with zero event matches;
- responses distinguish `scanned_orders`, `matching_items`, `has_more`, signed continuation, and coverage state;
- interrupted/failed recovery retains an incomplete/failed state and retries the same cursor;
- a later unrelated listener success does not clear an unresolved failure;
- a source snapshot change during recovery rejects the old cursor instead of claiming completion.

**Step 2: Run focused tests and verify RED**

Run the source host check and guarded prepare phase. Expected failures must identify missing listener/cursor/coverage behavior.

**Step 3: Implement coverage and cursor primitives**

Store a bounded, non-sensitive per-event state keyed by configuration revision with status, snapshot count/highest ID, next cursor metadata, unresolved failure identity, and timestamps. Sign cursor JSON using the WordPress auth salt; validate signature, event, revision, page size, source count, and highest ID.

**Step 4: Implement deterministic source paging**

Query all relevant Woo order statuses in ascending ID order with `paginate => true`. Return orders scanned separately from matching item evidence, total pages/source orders, highest order ID, and `has_more`. Before continuing, compare the live snapshot identity with the cursor and fail closed if it changed.

**Step 5: Implement listener and recovery semantics**

Register an order-status-changed listener. It reads the persisted order, filters direct active-event items, and calls desk projection inside exception containment. On failure it records an unresolved coverage failure without throwing. On success it leaves unrelated failures intact.

Recovery marks `in_progress`, advances only after a complete page succeeds, and publishes `complete` only on the final successful page with no unresolved failure. A successful retry clears only the matching failure.

**Step 6: Expose the REST contract, verify GREEN, and commit**

Accept an opaque `continuation` on the administrator projection route and return coverage metadata on projection and search. Run focused plus guarded tests and commit.

### Task 3: Bind admission to stored state, option, and source unit

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Registration_Store.php`
- Modify: `oras-tickets/includes/Registration_Desk/Projection_Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Service.php`
- Modify: `scripts/registration-desk-integration-checks.php`

**Step 1: Add failing admission regressions**

Project quantity two, reduce the synthetic source quantity to one, and assert unit two is rejected both before and after refresh while unit one remains admissible. Remap the product from option A to B and assert the A registration is rejected before and after refresh. Assert stored `needs_review` and `revoked` states block normal and explicit-unpaid admission. Preserve existing attendee/attendance/audit history assertions.

**Step 2: Verify RED**

Run the focused integration phase and confirm the stale unit/remapped option is currently admitted.

**Step 3: Implement minimal validation and stale-unit reconciliation**

Before mutation require `status === active`, exact source event/order/item, exact fresh `option_uuid`, positive `source_unit_number`, and `source_unit_number <= current quantity`. Add a store operation that marks projected units above current quantity `revoked` with guarded version updates, without deletion or renumbering.

**Step 4: Verify GREEN and commit**

Run all admission, replay, reversal, and projection-preservation assertions; commit production and test changes.

### Task 4: Make configuration and activation atomic

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Config.php`
- Modify: `oras-tickets/includes/Registration_Desk/Admin_Settings.php`
- Create: `scripts/registration-desk-config-concurrency-worker.php`
- Modify: `scripts/registration-desk-integration-checks.php`
- Modify: `scripts/run-registration-desk-integration-checks.sh`

**Step 1: Add failing transactional regressions**

Test stale revision, first configuration, forced postmeta write failure, forced active-option failure, cache refresh after commit/rollback, and unchanged active event after every failure. Launch independent WP-CLI processes for same-event saves and different-event activation; assert exactly one winner and one conflict.

**Step 2: Verify RED**

Run the configuration phase and observe both same-revision writers succeeding or partial active-event changes.

**Step 3: Implement global locking and one transaction**

Use a bounded database advisory lock dedicated to Registration Desk configuration, plus InnoDB row locks for the target event and active option. Read durable postmeta under the lock, compare revision, write the serialized next configuration, then write the active option. Roll back on any failed query and release the advisory lock in `finally`. Invalidate post/option caches after the transaction outcome.

Route the admin form through the combined save-and-activate operation. Make standalone active-event changes use the same global coordination.

**Step 4: Verify GREEN and commit**

Run both independent-process races repeatedly, verify cache-visible committed values, then run station invalidation tests and commit.

### Task 5: Classify audit persistence failures and test token expiry

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Audit_Store.php`
- Modify: `scripts/registration-desk-access-checks.php`
- Modify: `scripts/registration-desk-integration-checks.php`

**Step 1: Add failing audit retry and expiry tests**

Use a nonduplicate audit trigger failure and assert a safe 500-class persistence code, full attendee/attendance rollback, no audit row, and successful retry with the identical request UUID after removing the trigger. Retain same-binding replay and conflicting-binding rejection.

Construct a correctly signed token whose `expires_at` is in the past and assert `oras_desk_station_expired` without sleeping.

**Step 2: Verify RED**

Confirm the audit failure is currently misclassified as `oras_desk_request_exists`; the expiry test may already pass and should document existing behavior.

**Step 3: Implement duplicate confirmation**

After a failed insert, read the request UUID. Return `oras_desk_request_exists` only when a row exists; otherwise return a safe `oras_desk_audit_persist_failed` error with status 500. Leave full replay/conflict binding checks in `Service`.

**Step 4: Verify GREEN and commit**

Run audit rollback, retry, replay, conflict, and expiry checks; commit.

### Task 6: Expand isolation evidence and qualify both order stores

**Files:**
- Modify: `scripts/fixtures/oras-registration-desk-test-guard.php`
- Modify: `scripts/registration-desk-integration-checks.php`
- Modify: `scripts/run-registration-desk-integration-checks.sh`
- Modify: `docs/registration-desk-m1a.md`

**Step 1: Add protected global evidence**

Separate fixture setup, desk operations, and ordinary-checkout control. Around desk operations capture detailed fixture hashes, global Woo order/item/product/refund counts, total WordPress users, memberships/subscriptions, QBO actions, transport logs, and test-only Woo object-save/user-creation/write probes. Explicitly exclude permitted desk/config/session writes.

Assert both unchanged counts and unchanged detailed source objects; document that neither alone proves isolation.

**Step 2: Add safe storage-mode control**

Extend the guarded runner with explicit `--mode=legacy` and `--mode=hpos`. Capture original Woo storage options, change them only after disposable identity verification, require `OrderUtil::custom_orders_table_usage_is_enabled()` to match the requested mode, disable compatibility synchronization for HPOS, and restore the original state on exit.

Run the relevant suite with newly created fixtures in each authoritative mode. If runtime compatibility blocks HPOS, fail with a named evidence gap rather than reporting a pass.

**Step 3: Update the contract documentation**

Document listener-only automatic discovery, signed recovery pagination, coverage states, exact option/unit admission, transactional configuration, AJAX confinement, audit persistence errors, and tested storage modes. Preserve explicit deferred scope and permanent payment boundary.

**Step 4: Run final verification**

Run:

- host domain/source/access/operation checks;
- PHP syntax for changed PHP;
- targeted PHPCS and PHPStan;
- guarded integration in legacy mode;
- guarded integration in authoritative HPOS mode;
- real configuration and attendance concurrency workers;
- core/bootstrap regressions;
- `git diff --check` and final worktree/original-checkout status.

**Step 5: Commit the qualification/documentation changes**

Commit only after reading the complete successful output. Do not merge, push, tag, release, deploy, or access production.
