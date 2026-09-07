# PHPCS Immutable Release Baseline Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the non-actionable whole-plugin PHPCS release failure with a fail-closed committed-tree comparison that permits inherited debt and rejects every newly introduced diagnostic.

**Architecture:** Extend the existing Python-backed shell checker with a mandatory immutable baseline argument, dual Git-tree scans, and a separate whole-plugin debt report. Exercise it in temporary Git repositories with a deterministic PHPCS fixture, then wire the pinned baseline into the unchanged Phase5 job.

**Tech Stack:** Bash, Python 3 standard library, Git, PHP_CodeSniffer JSON output, GitHub Actions.

---

### Task 1: Add checker contract tests

**Files:**
- Create: `scripts/qbo-phpcs-diff-check-tests.sh`

**Step 1: Write the failing test**

Create an isolated test runner that copies the checker into a temporary Git
repository and supplies a fixture `vendor/bin/phpcs`. Add cases for:

- inherited whole-plugin and changed-file diagnostics reporting without failure;
- introduced modified-file and neighboring-line diagnostics;
- renamed-file comparison;
- full-file added and copied-file diagnostics;
- missing, abbreviated, unknown, equal-to-HEAD, and non-ancestor baselines;
- malformed JSON and PHPCS execution failures.

Each case must assert both exit status and a stable output fragment.

**Step 2: Run test to verify it fails**

Run: `bash scripts/qbo-phpcs-diff-check-tests.sh`

Expected: FAIL because the current checker does not accept a committed baseline
or provide whole-plugin reporting.

**Step 3: Commit the red test**

```bash
git add scripts/qbo-phpcs-diff-check-tests.sh
git commit -m "Test committed PHPCS baseline gate"
```

### Task 2: Implement the committed-tree checker

**Files:**
- Modify: `scripts/qbo-phpcs-diff-check.sh`

**Step 1: Implement mandatory baseline validation**

Accept exactly `--baseline <full-object-id>`. Require a lowercase 40- or
64-character hexadecimal ID, resolve it as a commit without substitution, and
require it to be a proper ancestor of committed `HEAD`.

**Step 2: Implement dual-tree scanning**

Use `git diff --name-status -z --find-renames --find-copies-harder
<baseline>..HEAD -- '*.php'`. Materialize baseline and current blobs in separate
temporary roots. Compare modified and renamed diagnostics through unchanged-line
mapping; treat all diagnostics in added and copied files as introduced.

**Step 3: Add whole-plugin debt reporting**

Run PHPCS JSON against `oras-tickets`, validate its schema and totals, and print
errors, warnings, and affected files without changing the differential exit
decision.

**Step 4: Fail closed**

Reject dirty tracked PHP inputs, missing tools/rulesets, unsafe paths,
unsupported process exits, stderr/tool failures, malformed JSON, inconsistent
totals, and unexpected PHPCS paths or fields.

**Step 5: Run tests to verify they pass**

Run: `bash scripts/qbo-phpcs-diff-check-tests.sh`

Expected: all positive and negative cases pass.

### Task 3: Wire and document the release gate

**Files:**
- Modify: `.github/workflows/phase5-verification.yml`
- Modify: `docs/RELEASE_PROCESS.md`

**Step 1: Update the workflow**

Configure `actions/checkout` with `fetch-depth: 0`. Replace only the PHPCS step
command with:

```bash
bash scripts/qbo-phpcs-diff-check.sh --baseline 1440cab86c1b7cb3de311764f0c28886f2da4a4b
```

Keep PHPStan and every integration step blocking and unchanged.

**Step 2: Document the baseline**

Record the immutable baseline, why it is the correct pre-QuickBooks commit, the
repository-wide PHP scope, added/copied full-file policy, neighboring-line
behavior, and the rule for deliberately advancing the baseline in a later
release.

**Step 3: Verify the real release comparison**

Run:

```bash
bash scripts/qbo-phpcs-diff-check-tests.sh
bash scripts/qbo-phpcs-diff-check.sh --baseline 1440cab86c1b7cb3de311764f0c28886f2da4a4b
composer phpstan
composer version-check
composer role-check
git diff --check
```

Expected: the fixture suite passes, inherited whole-plugin debt is reported,
the release comparison reports zero introduced diagnostics, and all other
commands pass.

### Task 4: Publish through protected review

**Files:**
- Review all files changed since `26e4837bf4178cfa404d65027d1233007ddf4f67`.

**Step 1: Confirm runtime isolation**

Verify no file under `oras-tickets/src/Integrations/QuickBooks/` changed and the
separate Board Reports worktree remains untouched.

**Step 2: Commit and push**

Stage only the checker, checker tests, workflow, and release documentation.
Push `ci/0.4.56-phpcs-baseline` without force.

**Step 3: Open a pull request and wait for checks**

Create a PR to `main`, wait for Phase5 and CodeQL, and merge through GitHub only
after required checks pass. Re-fetch and verify remote `main` at the resulting
merge commit.

**Step 4: Resume release publication**

Confirm tag/release availability, build a new ZIP from final `main`, publish the
annotated tag and GitHub release, download the asset, and compare SHA-256.
