# Registration Desk M1A.2 Qualification Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Qualify the corrected Registration Desk backend in the actual `/home/rocco/projects/oras-wp-env` disposable test instance without modifying its ordinary development environment.

**Architecture:** Resolve wp-env ownership and generated Compose state from the designated source project, then layer a temporary test-service-only Compose mount override. Fail closed before WordPress mutation unless project, database, marker, mounts, guards, and mounted code identity all match; restore test mounts, storage options, and prior service state on every exit.

**Tech Stack:** Bash, Docker Compose, wp-env 10.39.0, WordPress/WP-CLI, WooCommerce legacy storage and HPOS, PHP_CodeSniffer.

---

### Task 1: Capture identities and add a failing project-selection guard

**Files:**
- Create: `scripts/registration-desk-runner-guard-tests.sh`
- Modify: `scripts/run-registration-desk-integration-checks.sh`

1. Add a host guard test that requires the runner to resolve configuration from `/home/rocco/projects/oras-wp-env`, rejects `wp_env start`, requires a test-only Compose overlay, and rejects hard-coded Compose hashes or ports.
2. Run the guard test against `f8c322d` and confirm it fails because `wp_env()` changes directory to the feature worktree and hashes that worktree's `.wp-env.json`.
3. Commit the failing guard independently.

### Task 2: Select the designated project and isolate test services

**Files:**
- Modify: `scripts/run-registration-desk-integration-checks.sh`
- Test: `scripts/registration-desk-runner-guard-tests.sh`

1. Resolve the designated source config and install path through wp-env 10.39.0 with the designated project as process cwd.
2. Generate a temporary Compose overlay containing only `tests-wordpress` and `tests-cli` feature/test mounts.
3. Snapshot ordinary development services and start only designated test services using the base Compose file plus the overlay.
4. Resolve containers by exact Compose file/project labels rather than fixed hashes or ports.
5. Verify the test database volume, guards, exact mounts, and a mounted-code digest/commit identity before marker handling or mutable setup; permit marker creation only through an explicit one-time mode after those checks pass.
6. Run the host guard and environment-only verification until both pass.
7. Commit the runner correction and guard.

### Task 3: Restore test state without touching development state

**Files:**
- Modify: `scripts/run-registration-desk-integration-checks.sh`
- Test: `scripts/registration-desk-runner-guard-tests.sh`

1. Extend cleanup to restore captured Woo storage, ORAS settings, and plugin activation options when captured.
2. Recreate only test application/CLI services from the designated base Compose configuration, then return all three test services to their original running/stopped states.
3. Verify ordinary development container identity, state, mounts, and database volume are unchanged.
4. Remove only runner-created temporary files.
5. Run a deliberately stopped environment-only probe and verify restoration on both success and forced failure.
6. Commit cleanup behavior.

### Task 4: Correct introduced PHPCS diagnostics

**Files:**
- Modify only files reported as introduced between `a6062bc703394193ef73fbce60742bffd9843679` and the current branch.

1. Run the repository differential PHPCS algorithm and retain the exact introduced diagnostic list.
2. Apply formatting-only corrections and a non-conflicting host-test WordPress error stub; do not change assertions or backend behavior.
3. Rerun differential PHPCS and require zero introduced diagnostics while reporting inherited debt separately.
4. Commit formatting-only changes.

### Task 5: Qualify and document

**Files:**
- Modify: `docs/registration-desk-m1a.md` only if execution instructions require correction.

1. Run host domain, source, access, operation, and runner-guard checks.
2. Run changed-PHP syntax, Bash syntax, differential PHPCS, and `git diff --check`.
3. Run the complete guarded suite with `--mode=legacy` in the designated test instance.
4. Verify restoration, then run the complete guarded suite with `--mode=hpos` and compatibility synchronization disabled.
5. Verify final storage settings, mounts, service states, ordinary development identity, feature worktree cleanliness, original-checkout status, and the untouched separate M1A.1 runtime.
6. Commit accurate qualification documentation if changed and provide the bounded handoff without pushing or deploying.
