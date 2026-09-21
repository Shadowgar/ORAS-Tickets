# Registration Desk Training Mode Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build and publish v0.4.58 with a manager-controlled, station-specific Training Mode whose synthetic operations are physically isolated from every live Registration Desk, commerce, membership, email, and reporting data path.

**Architecture:** Store each bounded training dataset in one dedicated InnoDB row keyed by training session/station, with the simulated date as mutable state and attendance partitioned by that date. Resolve mode and date from server-authorized station context, route all training writes through separate services, and reject live operational routes whenever the station has an active training row. Bind every session to the selected event and configuration revision so changes fail closed.

**Tech Stack:** WordPress/PHP 8.0+, MySQL/InnoDB, WordPress REST API, The Events Calendar and PMPro read APIs, vanilla JavaScript/CSS, guarded wp-env legacy/HPOS checks, Playwright CLI, GitHub Actions, and `git archive` release packaging.

---

### Task 1: Add the isolated training schema and transactional store

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Training_Store.php`
- Modify: `oras-tickets/includes/Registration_Desk/Schema.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/uninstall.php`
- Create: `scripts/registration-desk-training-checks.php`

**Step 1: Write failing schema/store checks**

Add assertions for a dedicated training table, schema version advancement, the
unique station key, session UUID, event/config/date bindings, JSON state, row
revision, expiry, and InnoDB installation. Assert the table name is absent from
all live stores and Board Reports queries.

**Step 2: Run the new check and confirm RED**

Run: `php scripts/registration-desk-training-checks.php`

Expected: failure because `Training_Store` and the training schema do not exist.

**Step 3: Implement the minimal store**

Add schema version 3 and one table with this contract:

```text
training_uuid, station_uuid UNIQUE, user_id, wp_session_digest,
event_id, config_revision, simulated_local_date, state_json,
record_version, expires_at_utc, created_at_utc, updated_at_utc
```

Implement create/find/mutate/reset/delete/cleanup methods. Mutations must begin
a transaction, lock the row, validate its expected revision and binding, apply
a callable transition to decoded bounded state, update the row revision, and
commit or roll back. Reject malformed/oversized state.

**Step 4: Rerun checks and syntax**

Run:

```bash
php scripts/registration-desk-training-checks.php
php -l oras-tickets/includes/Registration_Desk/Training_Store.php
php -l oras-tickets/includes/Registration_Desk/Schema.php
```

Expected: PASS.

**Step 5: Commit**

```bash
git add oras-tickets/includes/Registration_Desk/Training_Store.php oras-tickets/includes/Registration_Desk/Schema.php oras-tickets/includes/Bootstrap.php oras-tickets/uninstall.php scripts/registration-desk-training-checks.php
git commit -m "Add isolated Registration Desk training store"
```

### Task 2: Bind server-authorized training context and fail closed

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Training_Context.php`
- Modify: `oras-tickets/includes/Registration_Desk/Station_Session.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `scripts/registration-desk-training-checks.php`
- Modify: `scripts/registration-desk-access-checks.php`

**Step 1: Add failing authorization checks**

Cover manager-only start, inclusive event-date validation, server default to the
event start date, independent station behavior, WordPress-session binding,
mutable date on the same training row, configuration revision mismatch,
cross-station rejection, expiry, and live-route rejection when training is
active even if the client sends no training marker.

**Step 2: Confirm RED**

Run:

```bash
php scripts/registration-desk-training-checks.php
php scripts/registration-desk-access-checks.php
```

**Step 3: Implement context resolution**

Add a signed-station reissue helper that can create a new event-bound training
station after manager authorization. `Training_Context` validates the station,
matching training row, user/session/event/config/date/expiry bindings, and
returns the simulated date only from the server row. Add a reusable live guard
that rejects operational live routes for an active training station. Keep live
clock/date code unchanged.

**Step 4: Rerun focused checks and syntax**

Expected: all PASS.

**Step 5: Commit**

```bash
git add oras-tickets/includes/Registration_Desk/Training_Context.php oras-tickets/includes/Registration_Desk/Station_Session.php oras-tickets/includes/Registration_Desk/Rest_Controller.php oras-tickets/includes/Bootstrap.php scripts/registration-desk-training-checks.php scripts/registration-desk-access-checks.php
git commit -m "Bind Training Mode to server station context"
```

### Task 3: Seed deterministic synthetic registrations and training roster

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Training_Service.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `scripts/registration-desk-training-checks.php`
- Modify: `scripts/registration-desk-roster-checks.php`

**Step 1: Add failing domain checks**

Assert deterministic `DEMO` records, reserved example contact data, one seed per
meaningful canonical type, family attendees, Student coverage when available,
optional configuration-only included access, no order lookup, dynamic filters,
search, detail, manager synthetic diagnostics, and state-size limits.

**Step 2: Confirm RED**

Run the training and roster host checks.

**Step 3: Implement seeding and roster reads**

Resolve canonical offerings read-only, derive deterministic UUID-shaped IDs
from the training session and semantic key, build synthetic registrations and
attendees, and provide roster/detail filtering entirely from `state_json`.

**Step 4: Rerun checks and commit**

```bash
git add oras-tickets/includes/Registration_Desk/Training_Service.php oras-tickets/includes/Bootstrap.php scripts/registration-desk-training-checks.php scripts/registration-desk-roster-checks.php
git commit -m "Seed isolated Training Mode registrations"
```

### Task 4: Implement training check-in, walk-ins, and idempotency

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Training_Service.php`
- Modify: `scripts/registration-desk-training-checks.php`
- Modify: `scripts/registration-desk-operation-checks.php`

**Step 1: Add failing operation checks**

Cover seeded individual check-in, selected family attendees, attendance keyed by
simulated date, prior-date history after date change, same-request replay,
refresh persistence, individual/family/one-day walk-ins, Card/Cash/Check/Unpaid
training assertions, canonical offering fingerprints, and failure without live
fallback.

**Step 2: Confirm RED**

Run training and operation checks.

**Step 3: Implement bounded transitions**

Add training-only check-in and walk-in state transitions. Store request UUID,
payload hash, and result in the training row; reject request reuse with a
different payload and return the historical result for an identical retry.
Validate the simulated date and current canonical offering/config revision on
every transition.

**Step 4: Rerun checks and commit**

```bash
git add oras-tickets/includes/Registration_Desk/Training_Service.php scripts/registration-desk-training-checks.php scripts/registration-desk-operation-checks.php
git commit -m "Add idempotent training check-in and walk-ins"
```

### Task 5: Add synthetic membership and training statistics

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Training_Service.php`
- Modify: `scripts/registration-desk-training-checks.php`
- Modify: `scripts/registration-desk-membership-checks.php`
- Modify: `scripts/registration-desk-stats-checks.php`

**Step 1: Add failing checks**

Assert synthetic member lookup, canonical PMPro offering display, simulated
Cash/Check result, no credit/email/user/membership calls, training-only stats,
current-date attendance, historical date counts, walk-in/type breakdowns, and
no live Event Stats or Board Reports dependency.

**Step 2: Confirm RED, implement, and rerun**

Add synthetic member fixtures and membership result records in `state_json`.
Compute stats from that state only.

**Step 3: Commit**

```bash
git add oras-tickets/includes/Registration_Desk/Training_Service.php scripts/registration-desk-training-checks.php scripts/registration-desk-membership-checks.php scripts/registration-desk-stats-checks.php
git commit -m "Add training membership and event statistics"
```

### Task 6: Expose separate training REST routes and manager lifecycle

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/Training_Rest_Controller.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `scripts/registration-desk-training-checks.php`
- Modify: `scripts/registration-desk-access-checks.php`

**Step 1: Add failing route checks**

Cover start/context/offerings/roster/detail/check-in/walk-in/membership/stats,
manager-authorized date/reset/end, confirmation semantics, manager-token
invalidation after start, training-required errors, and no route overlap with
live writes.

**Step 2: Confirm RED**

Run training and access checks.

**Step 3: Implement controller**

Register explicit `/registration-desk/training/*` routes. Route every state
change only to `Training_Service`/`Training_Store`. Start creates the row and
returns the new station payload; date change updates the same row; reset reseeds;
end deletes the row. Add live guards at operational callback boundaries.

**Step 4: Rerun checks and commit**

```bash
git add oras-tickets/includes/Registration_Desk/Training_Rest_Controller.php oras-tickets/includes/Registration_Desk/Rest_Controller.php oras-tickets/includes/Bootstrap.php scripts/registration-desk-training-checks.php scripts/registration-desk-access-checks.php
git commit -m "Expose fail-closed Training Mode API"
```

### Task 7: Build the kiosk Training Mode experience

**Files:**
- Modify: `oras-tickets/assets/registration-desk/desk.js`
- Modify: `oras-tickets/assets/registration-desk/desk.css`
- Modify: `scripts/registration-desk-operation-checks.php`
- Modify: `scripts/registration-desk-roster-checks.php`

**Step 1: Add failing client/source checks**

Cover Start Training Mode, event/date picker, start confirmation, shell banner,
server context restoration, training route selection, seeded roster, check-in,
walk-in practice copy, payment-only labels, membership success, training stats,
date change, reset/end confirmations, training-specific failure copy, and banner
presence on every view.

**Step 2: Confirm RED**

Run operation/roster checks and `node --check`.

**Step 3: Implement client mode**

Hydrate stored stations from the server before rendering. Add manager start
workflow and a shell-level training banner. Reuse presentation components while
selecting separate training routes. Replace AlfaPOS/payment and error text in
training. Clear manager authorization after start and require it for date,
reset, and end actions.

**Step 4: Add responsive styles**

Keep the banner textual, sticky but non-obscuring, and usable at 1024x768,
768x1024, 390x844, and desktop widths.

**Step 5: Rerun checks and commit**

```bash
git add oras-tickets/assets/registration-desk/desk.js oras-tickets/assets/registration-desk/desk.css scripts/registration-desk-operation-checks.php scripts/registration-desk-roster-checks.php
git commit -m "Build Registration Desk Training Mode UI"
```

### Task 8: Prove live-data isolation in guarded WordPress

**Files:**
- Modify: `scripts/registration-desk-integration-checks.php`
- Modify: `scripts/run-registration-desk-integration-checks.sh` only as required by its immutable identity guard
- Modify: `scripts/fixtures/oras-registration-desk-test-guard.php` only if new probes are required

**Step 1: Extend disposable fixtures**

Create generic future multi-day event offerings including individual, family,
and Student examples. Create two independent desk stations without using any
production identifier.

**Step 2: Snapshot every live source**

Capture exact counts/content hashes for live registrations, attendees,
attendance, audit, offline memberships, Woo orders/items/customers/stock,
PMPro memberships/credits, RSVP/waitlist data, users, mail/HTTP/write probes,
live Event Stats, and Board Reports totals.

**Step 3: Exercise the full training matrix**

Start future-event training; validate default and alternate dates; check in
seeded and family attendees; create walk-ins for all payment assertions;
simulate membership; inspect stats; prove a second station stays live; craft
live-endpoint bypass attempts; reset; end; and prove normal wrong-day behavior.

**Step 4: Compare snapshots**

Require exact equality for every live source. Confirm only the dedicated
training table changed during training and is empty for the ended station.

**Step 5: Run both guarded modes**

Run:

```bash
bash scripts/run-registration-desk-integration-checks.sh --mode=legacy
bash scripts/run-registration-desk-integration-checks.sh --mode=hpos
```

Expected: both PASS with verified disposable-environment cleanup.

**Step 6: Commit**

```bash
git add scripts/registration-desk-integration-checks.php scripts/run-registration-desk-integration-checks.sh scripts/fixtures/oras-registration-desk-test-guard.php
git commit -m "Prove Training Mode live-data isolation"
```

### Task 9: Browser qualification and requested screenshots

**Files:**
- Modify only for discovered defects: `oras-tickets/assets/registration-desk/desk.js`
- Modify only for discovered defects: `oras-tickets/assets/registration-desk/desk.css`
- Capture ignored artifacts: `output/playwright/registration-desk-training/`

**Step 1: Start only the verified disposable browser target**

Use the guarded wp-env test instance and deterministic fixtures. Record the LAN
URL and a disposable-only Manager PIN.

**Step 2: Exercise all requested views**

Capture Start Training, date picker, training home/banner, seeded roster,
check-in success, walk-in, payment practice, Training Event Stats, reset
confirmation, and return to live mode.

**Step 3: Qualify four viewports**

Verify iPad landscape, iPad portrait, iPhone portrait, and desktop have no
horizontal overflow, hidden controls, banner overlap, or console errors.

**Step 4: Fix only reproduced defects and rerun focused checks**

Commit browser-driven corrections if any.

### Task 10: Version, full qualification, release, and handoff

**Files:**
- Modify: `oras-tickets/oras-tickets.php`
- Modify: `docs/CHANGELOG.md`
- Modify: `.github/workflows/phase5-verification.yml` if the new focused check is not already exercised

**Step 1: Add release metadata**

Bump the plugin header and `ORAS_TICKETS_VERSION` to `0.4.58`. Add the approved
Training Mode release notes without claiming production validation.

**Step 2: Run complete local qualification**

Run focused training/access/operation/roster/membership/stats checks, all
affected PHP syntax, JavaScript syntax, version consistency, PHPStan,
differential PHPCS against the immutable baseline, guarded legacy and HPOS,
and owned-file `git diff --check` while preserving `.gitignore`.

**Step 3: Commit and push final main**

Stage only owned release files, commit, push normally, and verify `origin/main`
matches the exact final commit.

**Step 4: Wait for exact-commit CI**

Require Phase5 and CodeQL success on the final commit. Fix only directly related
failures and repeat qualification if needed.

**Step 5: Package and verify**

Build `oras-tickets-0.4.58.zip` with `git archive` from the exact commit,
excluding only `tools/`. Verify the exact manifest/content, `oras-tickets/`
root, version, required training assets/classes, forbidden-file absence, PHP
syntax, credential signatures, and SHA-256.

**Step 6: Tag and publish**

Create annotated `v0.4.58` on the exact green commit, push it, publish the
non-prerelease GitHub release with the verified ZIP, download the asset, and
prove byte-for-byte identity, checksum, version, manifest, tag target, and
release metadata.

**Step 7: Final safety check and handoff**

Confirm `.gitignore` retains its original file and diff hashes; production was
not accessed; all screenshots and LAN/disposable credentials are recorded; and
the final handoff contains only the requested summary, evidence, owner steps,
and safety confirmations.
