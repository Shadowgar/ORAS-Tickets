# Zoom Phone Join Instructions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace raw Zoom one-tap strings in attendee emails with clear mobile call buttons and explicit landline/manual dialing instructions.

**Architecture:** Add a small Zoom email formatter that parses each authoritative one-tap invitation line into a safe display number, location, and complete `tel:` URI. Both approved RSVP and paid virtual-ticket email renderers will use the same formatter so instructions remain consistent.

**Tech Stack:** PHP 8, WordPress email APIs and escaping functions, ORAS HTML email templates, custom CLI integration harness.

---

### Task 1: Define Phone Instruction Behavior

**Files:**
- Modify: `oras-tickets/tools/zoom-integration-checks.php`

**Step 1: Write the failing checks**

Add source and formatter assertions requiring:

- A one-tap line produces a complete `tel:` URI.
- The clean telephone number and location are retained.
- Both email renderers contain `Join by mobile phone`.
- Both email renderers contain the manual steps for Meeting ID, skipped participant ID, and Phone Passcode.

**Step 2: Run the test to verify it fails**

Run:

```bash
php oras-tickets/tools/zoom-integration-checks.php
```

Expected: failure because the formatter and new guidance do not exist.

### Task 2: Add Shared Zoom Phone Email Formatter

**Files:**
- Create: `oras-tickets/src/Integrations/Zoom/Phone_Join_Instructions.php`
- Modify: `oras-tickets/src/Integrations/Zoom/Module.php`

**Step 1: Parse one-tap lines**

Return a sanitized entry containing:

```php
array(
    'display_number' => '+1 301 715 8592',
    'location'       => 'US (Washington DC)',
    'tel_uri'        => 'tel:+13017158592,,89952118766#,,,,*8100654419#',
)
```

Reject values that do not match Zoom's expected telephone and dialing-sequence
shape.

**Step 2: Render safe email HTML**

Render:

- `Join by mobile phone` heading and explanation.
- One call button per valid one-tap line.
- `Calling from a landline or dialing manually` heading.
- Four numbered dialing steps.
- Clean fallback phone-number list.
- Existing local-number link when available.

**Step 3: Load the formatter**

Require the formatter from the Zoom module before dependent lifecycle services.

### Task 3: Integrate Both Email Paths

**Files:**
- Modify: `oras-tickets/includes/Frontend/Event_RSVP.php`
- Modify: `oras-tickets/includes/Commerce/Woo/Virtual_Ticket_Access_Email.php`

**Step 1: Clarify passcode labels**

Change the generic attendee-facing `Passcode` label to `App/Web passcode` when
a separate numeric phone passcode exists.

**Step 2: Remove raw one-tap rows**

Stop placing raw Zoom one-tap strings in the detail table.

**Step 3: Insert the shared phone guidance**

For approved RSVP email templates, add a trusted formatter-generated supplement
between event details and action buttons. For paid virtual-ticket emails, insert
the same supplement after the detail table.

### Task 4: Verify And Release

**Files:**
- Modify: `oras-tickets/oras-tickets.php`
- Modify: `docs/CHANGELOG.md`
- Modify: `docs/ZOOM_INTEGRATION_DEPLOYMENT.md`

**Step 1: Run targeted checks**

```bash
php oras-tickets/tools/zoom-integration-checks.php
```

Expected: `Zoom integration checks passed.`

**Step 2: Bump the release**

Bump ORAS-Tickets from `0.4.49` to `0.4.50` and document the new phone guidance.

**Step 3: Run full verification**

```bash
php -l oras-tickets/src/Integrations/Zoom/Phone_Join_Instructions.php
php -l oras-tickets/includes/Frontend/Event_RSVP.php
php -l oras-tickets/includes/Commerce/Woo/Virtual_Ticket_Access_Email.php
composer version-check
composer phpcs
composer phpstan
php oras-tickets/tools/zoom-integration-checks.php
git diff --check
```

Expected: all commands exit successfully.

**Step 4: Commit and push**

```bash
git add docs oras-tickets
git commit -m "Improve Zoom phone join email instructions"
git push origin main
```

**Step 5: Verify GitHub checks**

Wait for Phase5 Verification and CodeQL to complete successfully.
