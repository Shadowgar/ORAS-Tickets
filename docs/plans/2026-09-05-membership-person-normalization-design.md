# Membership Person Normalization Design

## Goal

Make the Board Reports Membership roster report one normalized real person per
row while retaining raw PMPro relationship history and Legacy PayPal
subscription records as read-only detail evidence.

## Boundary

The reporting layer will continue to read PMPro and Legacy PayPal records
without changing either source.  It will resolve a source record to a person
only through explicit account linkage, a WordPress user ID, exact normalized
email, or explicit Legacy-to-website metadata.  Name-only similarity remains a
review indicator and is never a merge key.

Unresolved PMPro relationships will not produce roster people in Current,
Former / Inactive, or All.  They will be retained in the service result for a
read-only membership-manager diagnostic count/list.

## Data Contract

`Membership_Report_Service` will first construct raw source rows, then
aggregate resolvable rows into person rows.  A person row will contain the
selected primary display fields plus `website_membership`,
`membership_history`, and `legacy_paypal_records` arrays.  Templates will use
the aggregate fields for the compact roster and the retained arrays for the
existing native detail dialog.

Current status is person-level: Active wins, then Expiring Soon, then inactive
or expired states.  A current PMPro membership supplies the displayed level
when present; otherwise a current Legacy-only person displays Legacy PayPal
Membership.  PMPro and Legacy source counts remain raw current-record metrics;
Current Unique Members counts current aggregates.

## UI

The default scope is Current Members.  Former / Inactive contains resolved
people with history but no current source, and All contains every resolved
person.  The table remains Member, Source, Level, Status.  The dialog keeps
its current contact, account linkage, and allowlisted signup answers, adding
separate Website Membership, Membership History, and Legacy PayPal sections.

Membership managers will see a collapsed, read-only orphan-PMPro review panel;
view-only board users will not.  No write controls, migrations, or source-data
mutations are added.

## Verification

Focused wp-env checks will prove aggregation, matching, filters, dialog
rendering, manager-only orphan diagnostics, and source-preserving behavior.
Changed PHP files will be linted; targeted PHPStan and a task-scoped diff check
will run.  The historical full suite, packaging, deployment, version changes,
and production-data writes are out of scope.
