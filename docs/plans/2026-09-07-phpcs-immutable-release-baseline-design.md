# PHPCS Immutable Release Baseline Design

## Goal

Make the Phase5 PHPCS gate compare the committed ORAS Tickets 0.4.56 release
candidate with the immutable commit immediately before its QuickBooks changes,
while reporting rather than accepting or suppressing inherited whole-plugin
debt.

## Immutable baseline

The baseline is
`1440cab86c1b7cb3de311764f0c28886f2da4a4b`. It is the complete ORAS Tickets
0.4.55 Board Reports release and the direct parent of the integrated QuickBooks
runtime commit. The workflow passes the full commit object ID; the checker does
not derive a baseline from the current branch, `main`, or `HEAD`.

The checker rejects a missing baseline, a symbolic or abbreviated revision, an
unknown object, a non-commit object, a baseline equal to `HEAD`, and a commit
that is not an ancestor of `HEAD`.

## Comparison and reporting

The checker examines every PHP file changed between the committed baseline and
committed `HEAD`, including production code, tests, and fixtures. Git rename and
copy detection is enabled. Modified and renamed files are scanned in both Git
trees; diagnostics on the current tree are matched to equivalent baseline
diagnostics through diff-aligned line mapping. Equal lines map directly, while
the common positional portion of a replacement block maps only when the exact
diagnostic identity and occurrence already existed at the aligned baseline
line. A diagnostic with no baseline match is introduced even when PHPCS reports
it on an unchanged neighboring line.

Added and copied PHP files receive a full-file scan, and any diagnostic blocks
the gate. Deleted PHP files have no current diagnostics to gate.

Separately, the checker scans the complete current `oras-tickets` plugin with
the production ruleset and reports its error, warning, and affected-file totals.
Those totals describe inherited debt and do not suppress rules or alter the
zero-introduction decision. Test and fixture paths use the repository ruleset;
no test-specific security or correctness exclusions are added.

## Failure behavior

PHPCS diagnostic exit codes are parsed as results. Missing tools or files,
unsupported process exits, malformed JSON, unexpected report schemas, unsafe
paths, dirty committed inputs, and Git/baseline failures terminate the checker
with a fail-closed tooling error.

GitHub Actions checks out full history, invokes the checker with the pinned
baseline, and keeps PHPStan and every existing integration step blocking.

## Verification

An isolated shell regression suite creates temporary Git repositories and a
deterministic PHPCS fixture executable. It proves inherited debt reporting,
introduced modified-file and neighboring-line failures, rename handling,
full-file added/copied-file failures, and fail-closed baseline, execution, and
output-validation behavior. The real repository is then checked with the real
PHPCS installation against the pinned baseline.
