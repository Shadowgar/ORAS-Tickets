# Registration Desk M1A Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the inactive-by-default, nonfinancial Registration Desk backend for supported direct individual online registrations, attendee confirmation, daily check-in, idempotent replay, and administrator reversal.

**Architecture:** Four prefixed, additive InnoDB tables own operational registrations, attendee slots, daily attendance, and append-only request/audit results. Versioned administrator configuration maps immutable Woo order-item evidence to stable desk option IDs; REST operations require a restricted desk capability, active-event match, per-device station token, current configuration revision, and object-level event scope. Woo, Stripe, QuickBooks, users, memberships, products, capacity, and legacy check-in remain read-only or inaccessible.

**Tech Stack:** PHP 8.0+, WordPress REST API and roles, WooCommerce read APIs, The Events Calendar event posts, `$wpdb`/`dbDelta`, MySQL InnoDB transactions, repository PHP scripts, wp-env disposable integration runtime.

---

### Task 1: Schema, stores, and source-independent identity

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Schema.php`
- Create: `oras-tickets/includes/Registration_Desk/Store.php`
- Create: `oras-tickets/includes/Registration_Desk/Registration_Store.php`
- Create: `oras-tickets/includes/Registration_Desk/Attendee_Store.php`
- Create: `oras-tickets/includes/Registration_Desk/Attendance_Store.php`
- Create: `oras-tickets/includes/Registration_Desk/Audit_Store.php`
- Create: `scripts/registration-desk-domain-checks.php`
- Modify: `oras-tickets/oras-tickets.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

1. Write domain checks asserting exactly four table definitions, nullable source fields, stable UUIDs, source and attendance unique keys, record versions, UTC audit fields, and no order requirement.
2. Run `php scripts/registration-desk-domain-checks.php`; verify RED because classes do not exist.
3. Implement repeat-safe schema versioning and narrow stores. Use InnoDB and expose an engine verification method; never drop tables.
4. Add transaction begin/commit/rollback helpers which fail closed when transaction setup fails.
5. Wire repeat-safe upgrades through activation/bootstrap without enabling the feature.
6. Re-run domain checks and PHP syntax; verify GREEN.
7. Commit as `Registration Desk: add nonfinancial stores`.

### Task 2: Configuration, role, account guard, and station sessions

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Config.php`
- Create: `oras-tickets/includes/Registration_Desk/Access.php`
- Create: `oras-tickets/includes/Registration_Desk/Station_Session.php`
- Create: `oras-tickets/includes/Registration_Desk/Admin_Settings.php`
- Create: `oras-tickets/includes/Registration_Desk/Landing_Page.php`
- Create: `scripts/registration-desk-access-checks.php`
- Modify: `oras-tickets/includes/Capabilities.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

1. Write failing checks for feature-disabled default, immutable option IDs, separate existing-access and new-registration flags, administrator-only active-event/config changes, restricted role caps, absence of legacy/broad caps, and two independent signed station tokens for one user.
2. Run the checks and verify RED.
3. Implement versioned config validation, explicit revision increments, active-event setting, role reconciliation, per-device signed station tokens, and a minimal protected landing placeholder.
4. Implement a restricted-account guard for legacy check-in, Member Hub, RSVP mutations, Woo account/checkout, reports, exports, membership, user management, and accounting entry points without changing ordinary-user/admin behavior.
5. Re-run checks, syntax, and existing capability/bootstrap tests; verify GREEN.
6. Commit as `Registration Desk: add restricted access controls`.

### Task 3: Read-only source resolution, projection, and eligibility

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Source_Adapter.php`
- Create: `oras-tickets/includes/Registration_Desk/Source_Resolver.php`
- Create: `oras-tickets/includes/Registration_Desk/Eligibility.php`
- Create: `oras-tickets/includes/Registration_Desk/Projection_Service.php`
- Create: `scripts/registration-desk-source-checks.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

1. Write failing synthetic checks for direct immutable event evidence, exact option mappings, individual/family/unclassified handling, processing/completed eligibility, explicit on-hold unpaid path, cancelled/refunded revocation, partial-refund ambiguity, custom-status review, and unsupported one-day/cross-event records.
2. Add source spies that fail if an order, item, product, payment, refund, capacity, or integration save/dispatch method is invoked.
3. Run and verify RED.
4. Implement bounded active-event source queries and a read-only adapter. Never resolve old numeric indexes or names against current ticket arrays.
5. Implement repeat-safe projection which updates only source-owned fields and preserves attendee UUIDs, desk names, order-free registrations, attendance, and audit.
6. Re-run checks and syntax; verify GREEN.
7. Commit as `Registration Desk: resolve online sources read only`.

### Task 4: REST search/detail, attendee confirmation, attendance, replay, and reversal

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Service.php`
- Create: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Create: `scripts/registration-desk-operation-checks.php`
- Modify: `oras-tickets/includes/Bootstrap.php`

1. Write failing checks for station bootstrap, scoped search/detail, individual attendee confirmation, immediate source revalidation, event-local date enforcement, request binding/hash conflict, safe recent responses, daily uniqueness, stale versions, replay after reversal, and administrator-only reasoned reversal.
2. Run and verify RED.
3. Implement transactional operations whose audit/result row commits atomically with the business mutation. Replay must reauthorize and return both historical result and current attendance state.
4. Register minimal REST routes using operational registration UUIDs, never order IDs as route identity.
5. Re-run checks, syntax, PHPStan, and focused PHPCS on changed PHP files; verify GREEN.
6. Commit as `Registration Desk: add attendance operations`.

### Task 5: Disposable integration and isolation qualification

**Files:**
- Create: `scripts/fixtures/oras-registration-desk-test-guard.php`
- Create: `scripts/registration-desk-integration-checks.php`
- Create: `scripts/registration-desk-concurrency-worker.php`
- Create: `scripts/run-registration-desk-integration-checks.sh`
- Create: `docs/registration-desk-m1a.md`
- Modify: `.wp-env.json` only if a dedicated test-only mapping is necessary.

1. Write the guarded runner first. It must verify canonical repository/runtime identity, `tests-wordpress`/`tests-mysql`, test URL, an explicit disposable marker, outbound HTTP/mail interception, and plugin mount before mutation.
2. Verify the runner rejects a missing/wrong marker and active/runtime database identities.
3. In the verified tests runtime, create synthetic event, product, order, restricted staff user, administrator, config, and mappings. These fixtures are not desk operations.
4. Verify repeat-safe schema and InnoDB engines; source-null registration support; active-event isolation; operator-session independence; supported and unsupported classifications; on-hold unpaid admission; cancellation/refund race; projection preservation; disabled-option versus revoked-access behavior; local-date boundary behavior; and route/capability bypass rejection.
5. Exercise true concurrency with independent WP-CLI processes/connections against the same attendee/date and verify one attendance row and deterministic request results.
6. Snapshot Woo orders/items/meta/status/notes, products/prices/stock/capacity, users/usermeta, membership/subscription data, mail, Action Scheduler/QuickBooks queues, and intercepted HTTP calls before and after desk operations; assert no prohibited changes.
7. Run a normal synthetic checkout control with internal commerce hooks enabled and outbound transports intercepted; prove the existing integration hooks remain registered/executed.
8. Run the full guarded integration command, all focused host checks, PHP syntax, PHPStan, targeted PHPCS, `git diff --check`, and existing core/capability/bootstrap regressions.
9. Document routes, permissions, request/response/error contracts, schema, permitted writes, deferred scope, and qualification limitations.
10. Commit as `Registration Desk: qualify nonfinancial isolation`.

