# ORAS-Tickets Release Process

## Versioning

Every production release must update both the plugin `Version` header and
`ORAS_TICKETS_VERSION`. Run `composer version-check`; CI rejects mismatches.

## Required Verification

1. Run `composer version-check`, `composer role-check`, and `composer phpstan`.
2. Run `bash scripts/qbo-phpcs-diff-check-tests.sh`.
3. Run `bash scripts/qbo-phpcs-diff-check.sh --baseline 1440cab86c1b7cb3de311764f0c28886f2da4a4b`.
4. Run `git diff --check`.
5. Run the Board Reports, Phase 5, reports, and QBO integration wrappers against `/home/rocco/projects/oras-wp-env`.
6. For communication changes, run `scripts/communication-queue-tests.php` inside wp-env.
7. Push only after local checks pass, then require green Phase5 Verification and CodeQL runs.

## Immutable PHPCS release baseline

The Phase5 release gate uses
`1440cab86c1b7cb3de311764f0c28886f2da4a4b` as its immutable baseline. That
commit is the complete approved 0.4.55 release, including the Observer Pass and
Membership work, and is the direct parent of the first integrated QuickBooks
runtime commit. It is therefore the last committed tree before the QuickBooks
release change set. The workflow must pass this full object ID explicitly; it
must not derive the baseline from current `main`, current `HEAD`, a merge base,
or the working tree.

The checker requires the baseline to resolve exactly as a commit, differ from
`HEAD`, and be an ancestor of committed `HEAD`. It rejects dirty PHP inputs and
compares Git blobs from the baseline and `HEAD`, so a clean CI checkout cannot
collapse the release comparison to an empty working-tree diff.

All added, copied, deleted, modified, and renamed PHP paths are discovered.
Production plugin PHP uses `config/phpcs.xml`. Repository test, fixture, and
tooling paths (`scripts`, `dev-tools`, `tests`, `test`, `fixtures`, and
`oras-tickets/tools`) use `config/phpcs-tests.xml`. The test ruleset inherits
the production WordPress standard without adding any test-specific security or
correctness exclusions.

Modified and renamed files are scanned from both committed trees. Diagnostics
are compared by exact identity and diff-aligned line occurrence, which retains
pre-existing debt across harmless edits while detecting new diagnostics emitted
on changed or neighboring lines. Added and copied PHP files receive full-file
PHPCS, and every diagnostic in those files is introduced. Deleted files have no
current diagnostics to gate.

The complete committed `oras-tickets` plugin is also scanned and its inherited
error, warning, and affected-file totals are reported separately. Those totals
are informational; they neither suppress rules nor weaken the blocking rule of
zero introduced diagnostics. Missing tools or rulesets, invalid baselines,
unsupported Git changes, PHPCS execution failures, unexpected stderr, malformed
JSON, inconsistent totals, and unsupported report schemas all fail closed.

Do not advance this baseline as part of the release being evaluated. A future
baseline change requires its own reviewed commit after an intervening release
has passed this gate, with the new full release commit ID and rationale recorded
here and in the workflow. This prevents a candidate release from accepting its
own violations merely by moving the comparison point.

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
