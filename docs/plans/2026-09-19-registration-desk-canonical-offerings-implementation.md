# Registration Desk Canonical Offerings Implementation Plan

> **For Codex:** Use test-driven development and verification-before-completion. Work as one agent on `main`.

**Goal:** Replace independent Registration Desk ticket options with canonical event offerings and implement accountless RSVP-only admission/waitlisting against shared RSVP capacity.

**Architecture:** Introduce one event-scoped offering resolver shared by the public form and desk REST API. Store only supplemental ticket rules and separate cross-event entitlements in desk configuration. Re-resolve offerings on writes. Extend desk records with snapshot evidence and use a shared RSVP capacity policy under the existing event lock.

**Test environment:** Only `/home/rocco/projects/oras-wp-env` and its verified disposable test services.

---

### Task 1: Lock the offering contract with failing tests

**Files:**
- Create: `scripts/registration-desk-offering-checks.php`
- Modify: `.github/workflows/phase5-verification.yml`
- Modify: `scripts/run-registration-desk-integration-checks.sh`
- Modify: `scripts/registration-desk-integration-checks.php`

1. Add tests for normalized canonical identity, current display fields, resolved pricing phase, attendance mode, sale state, Woo stock, and strict event scoping.
2. Add mutations for add, rename, phase, stock, sale window, removal/unavailability, and unrelated-event changes, comparing public and desk offering projections.
3. Add failing RSVP scenarios for admitted, waitlisted, refused, shared public/desk capacity, no user creation, ticket precedence, and cross-event isolation.
4. Run the focused host check and guarded integration suite to record RED failures before implementation.

### Task 2: Implement the shared canonical resolver

**Files:**
- Create: `oras-tickets/includes/Domain/Event_Offering_Resolver.php`
- Modify: `oras-tickets/includes/Bootstrap.php`
- Modify: `oras-tickets/includes/Frontend/Tickets_Display.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/includes/Registration_Desk/Service.php`

1. Normalize ticket/product/price/sale/stock state once in the resolver.
2. Refactor public ticket rendering to consume resolver output.
3. Add a station-authenticated current-offerings endpoint and refresh it when walk-in begins.
4. Re-resolve submitted canonical identity on the server immediately before mutation.
5. Store immutable offering snapshots in desk source evidence.

### Task 3: Reduce manager configuration to supplemental facts

**Files:**
- Modify: `oras-tickets/includes/Registration_Desk/Config.php`
- Modify: `oras-tickets/includes/Registration_Desk/Admin_Settings.php`
- Modify: `oras-tickets/includes/Registration_Desk/Source_Resolver.php`
- Modify: `oras-tickets/includes/Registration_Desk/Event_Stats_Service.php`

1. Replace manual option labels/prices/availability with canonical-ticket-keyed coverage and validity rules.
2. Separate explicit cross-event entitlements from selectable ticket rules.
3. Automatically resolve same-event website purchases from canonical product identity.
4. Preserve historical labels and canonical facts through stored registration snapshots.
5. Ensure unmatched legacy options are not selectable.

### Task 4: Implement shared RSVP capacity and waitlist outcomes

**Files:**
- Create: `oras-tickets/includes/Registration_Desk/RSVP_Capacity.php`
- Modify: `oras-tickets/includes/Frontend/Event_RSVP.php`
- Modify: `oras-tickets/includes/Registration_Desk/Service.php`
- Modify: `oras-tickets/includes/Registration_Desk/Registration_Store.php`
- Modify: `oras-tickets/includes/Registration_Desk/Rest_Controller.php`
- Modify: `oras-tickets/assets/registration-desk/desk.js`

1. Count approved public RSVPs and active admitted desk RSVPs in one effective capacity calculation.
2. Decide admit/waitlist/refuse while holding the event lock.
3. Persist accountless admitted or waitlisted records without creating users.
4. Check in only admitted RSVP walk-ins and render a clear waitlist success state.
5. Make the public RSVP mutation use the same effective capacity calculation.

### Task 5: Qualify, document, and deliver

**Files:**
- Modify as directly required by tests and CI only.

1. Run PHP/JavaScript syntax, focused checks, differential PHPCS/PHPStan, and `git diff --check`.
2. Run the complete guarded suite in the verified disposable wp-env instance.
3. Exercise the browser flows and capture ticket selection plus RSVP admitted, waitlisted, and refused screenshots.
4. Commit all scoped changes without staging the owner's `.gitignore`, push `main`, and wait for exact-commit CI.
5. Fix directly related CI failures, re-push, and report the final commit, CI result, local URL, and screenshots without deploying or releasing.
