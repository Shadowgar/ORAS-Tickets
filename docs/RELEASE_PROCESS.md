# ORAS-Tickets Release Process

## Versioning

Every production release must update both the plugin `Version` header and
`ORAS_TICKETS_VERSION`. Run `composer version-check`; CI rejects mismatches.

## Required Verification

1. Run `composer version-check`, `composer role-check`, `composer phpcs`, and `composer phpstan`.
2. Run `git diff --check`.
3. Run the Board Reports, Phase 5, reports, and QBO integration wrappers against `/home/rocco/projects/oras-wp-env`.
4. For communication changes, run `scripts/communication-queue-tests.php` inside wp-env.
5. Push only after local checks pass, then require green Phase5 Verification and CodeQL runs.

## Deployment

1. Back up the production database and current plugin directory.
2. Deploy the tagged ORAS-Tickets plugin artifact, not an unversioned working tree.
3. Activate the plugin and load one authenticated page to run schema upgrades.
4. Verify the communication table schema, role mappings, `/board-reports/`, RSVP approval, exports, and one controlled queued communication.
5. Confirm Action Scheduler processes the `oras-tickets` group without failed actions.

## Rollback

Restore the prior plugin artifact and clear application/CDN caches. Do not drop
ORAS-Tickets tables during a code rollback. Database columns added by newer
versions are backward-compatible and retain audit evidence.

## Privacy

Completed communication-log retention is configured under ORAS Tickets Settings.
`0` retains audit records indefinitely. Any non-zero retention period must be
approved by ORAS policy owners before production use. Recipient delivery payloads
are cleared as soon as queued delivery completes.
