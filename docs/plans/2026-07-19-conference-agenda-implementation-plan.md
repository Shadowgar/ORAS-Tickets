# ORAS Conference Agenda Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the current agenda timeline with a responsive multi-day conference program that clearly represents ongoing activities, concurrent sessions, speakers, and resources in light and dark modes.

**Architecture:** Keep `_oras_agenda_v1` and the existing editor unchanged. Refactor `Event_Agenda_Render` into normalization, grouping, and card-rendering helpers; add client-side filtering and accessible day navigation; and move the speaker profile UI into a body-mounted drawer controlled by focused JavaScript and scoped CSS variables.

**Tech Stack:** PHP 8+, WordPress hooks and escaping APIs, vanilla JavaScript, semantic HTML, CSS Grid/Flexbox, WP Dark Mode selectors, existing wp-env integration checks.

---

### Task 1: Establish The Conference Program Markup Contract

**Files:**
- Modify: `oras-tickets/tools/phase4-surface-checks.php`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Step 1: Add a multi-day, overlapping agenda fixture**

Extend `phase4RunSurfaceChecks()` to save `_oras_agenda_v1` for the existing event fixture with:

- Two days.
- A 10:00 AM-6:00 PM registration activity.
- A 10:00 AM-2:00 PM flea market.
- Two 11:00 AM sessions with different locations.
- One 12:30 PM sequential session.
- One titled slot without a valid start time.
- A speaker and a public resource.

Render it through `phase4RenderAgendaForEvent()`.

**Step 2: Add failing markup assertions**

Assert that the rendered output contains:

```php
phase4SurfaceAssert( strpos( $agenda_html, 'oras-agenda__ongoing' ) !== false, 'Long overlapping activities render in the ongoing region' );
phase4SurfaceAssert( substr_count( $agenda_html, 'data-start-group="11:00"' ) === 1, 'Concurrent sessions share one time band' );
phase4SurfaceAssert( strpos( $agenda_html, 'oras-agenda__session-grid--concurrent' ) !== false, 'Concurrent session grid is identified' );
phase4SurfaceAssert( strpos( $agenda_html, 'oras-agenda__unscheduled' ) !== false, 'Untimed sessions remain visible' );
phase4SurfaceAssert( strpos( $agenda_html, 'data-agenda-type=' ) !== false, 'Session cards expose type filter data' );
phase4SurfaceAssert( strpos( $agenda_html, 'data-agenda-location=' ) !== false, 'Session cards expose location filter data' );
phase4SurfaceAssert( strpos( $agenda_html, 'oras-agenda__resource-action' ) !== false, 'Resources render as clear actions' );
```

Update the static CSS checks to require the new program, time-band, session-card, filter, and speaker-drawer selectors instead of the obsolete flat-row constants.

**Step 3: Run checks to verify they fail**

Run:

```bash
php oras-tickets/tools/phase1h-event-questions-checks.php
```

Expected: FAIL because the conference program selectors and markup do not exist.

If wp-env is running, also run:

```bash
wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase4-surface-checks.php
```

Expected: FAIL on the first new conference-program assertion.

**Step 4: Commit the failing contract checks**

```bash
git add oras-tickets/tools/phase4-surface-checks.php oras-tickets/tools/phase1h-event-questions-checks.php
git commit -m "Test conference agenda rendering contract"
```

### Task 2: Normalize And Group Agenda Sessions

**Files:**
- Modify: `oras-tickets/includes/Frontend/Event_Agenda_Render.php`
- Test: `oras-tickets/tools/phase4-surface-checks.php`

**Step 1: Add pure slot normalization helpers**

Add private helpers that preserve the original source index and return sanitized rendering data without changing stored metadata:

```php
private static function normalize_public_slots( array $slots ): array;
private static function partition_day_program( array $slots ): array;
private static function slot_duration_minutes( array $slot ): int;
private static function slots_overlap( array $first, array $second ): bool;
```

Each normalized slot must include `source_index`, `start_24`, `end_24`, `start_minutes`, `end_minutes`, and the existing title, description, type, location, speakers, resources, and visibility values.

Invalid or missing start times remain in an `unscheduled` collection when they have a title.

**Step 2: Implement stable chronological ordering**

Sort valid timed slots by `start_minutes`, then by `source_index`. Preserve source order for equal starts.

**Step 3: Classify ongoing activities**

Classify a slot as ongoing only when:

```php
$duration >= 120 && $slot overlaps at least one later timed slot.
```

This identifies registration, markets, and long activities while avoiding schema changes. Ongoing slots must not be duplicated in timed bands.

**Step 4: Group remaining sessions by normalized start time**

Return this shape:

```php
array(
    'ongoing'     => array( /* normalized slots */ ),
    'time_groups' => array(
        '11:00' => array( /* concurrent slots */ ),
        '12:30' => array( /* sequential slot */ ),
    ),
    'unscheduled' => array( /* titled slots without valid times */ ),
)
```

**Step 5: Add focused Reflection assertions**

Invoke `partition_day_program()` in `phase4-surface-checks.php` and assert:

- Registration and flea market are ongoing.
- Both 11:00 sessions occupy one group.
- The 12:30 session occupies a different group.
- Untimed titled content survives.

**Step 6: Run focused checks**

Run the phase 4 surface check in wp-env.

Expected: helper assertions PASS while markup assertions may still fail.

**Step 7: Commit**

```bash
git add oras-tickets/includes/Frontend/Event_Agenda_Render.php oras-tickets/tools/phase4-surface-checks.php
git commit -m "Group agenda sessions into conference time bands"
```

### Task 3: Render The Conference Program

**Files:**
- Modify: `oras-tickets/includes/Frontend/Event_Agenda_Render.php`
- Test: `oras-tickets/tools/phase4-surface-checks.php`

**Step 1: Extract reusable rendering helpers**

Add helpers with all dynamic values escaped at output:

```php
private static function render_session_card( array $slot, array &$speaker_ids, bool $show_descriptions, bool $show_end_times, string $date, bool $highlight_current ): string;
private static function render_time_group( string $start, array $slots, array &$speaker_ids, bool $show_descriptions, bool $show_end_times, string $date, bool $highlight_current ): string;
private static function render_ongoing_region( array $slots, array &$speaker_ids, bool $show_descriptions, bool $show_end_times, string $date, bool $highlight_current ): string;
private static function render_resource_actions( array $resources ): string;
```

**Step 2: Replace timeline markup**

Render each panel as:

```html
<section class="oras-agenda__panel" role="tabpanel">
  <header class="oras-agenda__day-header">...</header>
  <section class="oras-agenda__ongoing" aria-labelledby="...">...</section>
  <ol class="oras-agenda__program">
    <li class="oras-agenda__time-band" data-start-group="11:00">
      <div class="oras-agenda__band-time">11:00 AM</div>
      <div class="oras-agenda__session-grid oras-agenda__session-grid--concurrent">
        <article class="oras-agenda__session-card">...</article>
      </div>
    </li>
  </ol>
  <section class="oras-agenda__unscheduled">...</section>
</section>
```

Keep `oras-agenda__item` on each session card as a compatibility class for `agenda-now.js`, and retain `data-agenda-date`, `data-start`, and `data-end` on the card.

**Step 3: Improve card hierarchy**

Each card must render in this order:

1. Type and location eyebrow.
2. Title.
3. Full time range.
4. Description.
5. Speaker buttons.
6. Resource action links.
7. Current-session badge when added by JavaScript.

Resource links use `oras-agenda__resource-action` and retain `target="_blank" rel="noopener"`.

**Step 4: Render filter controls only when useful**

Collect unique public session types and locations across rendered days. Render:

```html
<div class="oras-agenda__filters" aria-label="Filter agenda sessions">
  <label>Session type <select data-agenda-filter="type">...</select></label>
  <label>Location <select data-agenda-filter="location">...</select></label>
  <button type="button" data-agenda-filter-reset>Clear filters</button>
  <span class="screen-reader-text" aria-live="polite" data-agenda-filter-status></span>
</div>
```

Omit a select when it would contain only `All` plus one value.

**Step 5: Run runtime checks**

Expected: all new phase 4 markup assertions PASS.

**Step 6: Commit**

```bash
git add oras-tickets/includes/Frontend/Event_Agenda_Render.php oras-tickets/tools/phase4-surface-checks.php
git commit -m "Render multi-day conference agenda program"
```

### Task 4: Add Day Navigation And Session Filtering Behavior

**Files:**
- Modify: `oras-tickets/assets/js/agenda-ui.js`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Step 1: Add failing static behavior checks**

Require the JavaScript source to include handlers for:

- Left and right arrow movement between day tabs.
- Type and location filter changes.
- Empty time-band hiding.
- Filter reset.
- Live result-count status.

Run the targeted PHP check and confirm failure.

**Step 2: Preserve existing day activation**

Keep `activateDay()` and current-session activation. Add `aria-selected`, `tabIndex`, and focus updates so only the selected day tab is in the normal tab order.

**Step 3: Add keyboard tab navigation**

Within the agenda tablist:

- `ArrowRight` and `ArrowLeft` move to the next or previous day.
- `Home` selects the first day.
- `End` selects the last day.

**Step 4: Implement client-side filtering**

For the active agenda:

```javascript
function applyFilters(agenda) {
    const type = agenda.querySelector('[data-agenda-filter="type"]')?.value || '';
    const location = agenda.querySelector('[data-agenda-filter="location"]')?.value || '';
    // Hide only cards that do not match both active filters.
    // Hide a time band or ongoing region only when all contained cards are hidden.
    // Update the aria-live result count.
}
```

Filter comparisons use normalized data attributes generated by PHP. Changing day tabs must preserve active filters.

**Step 5: Run targeted checks**

```bash
php oras-tickets/tools/phase1h-event-questions-checks.php
```

Expected: PASS.

**Step 6: Commit**

```bash
git add oras-tickets/assets/js/agenda-ui.js oras-tickets/tools/phase1h-event-questions-checks.php
git commit -m "Add accessible agenda navigation and filters"
```

### Task 5: Build The Light And Dark Conference Visual System

**Files:**
- Modify: `oras-tickets/assets/css/agenda.css`
- Modify: `oras-tickets/assets/css/oras-agenda-colors.css`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Step 1: Add failing CSS contract checks**

Require:

- Distinct outer, ongoing, card, and elevated surfaces.
- `.oras-agenda__program`.
- `.oras-agenda__time-band`.
- `.oras-agenda__session-grid--concurrent`.
- `.oras-agenda__session-card`.
- `.oras-agenda__filters`.
- Horizontal mobile day navigation.
- Single-column mobile session grids.
- Reduced-motion handling.

Run and confirm failure.

**Step 2: Replace legacy timeline styling**

Remove the flat accent-rail presentation. Define a deliberate conference system:

```css
.oras-agenda {
    --oras-agenda-page: #f7f8fb;
    --oras-agenda-surface: #ffffff;
    --oras-agenda-card: #ffffff;
    --oras-agenda-card-muted: #f1f5f9;
    --oras-agenda-text: #172033;
    --oras-agenda-muted: #5f6b7c;
    --oras-agenda-accent: #1d4f91;
    --oras-agenda-border: #d9e0e8;
}
```

Use clear card borders, modest shadows, 10-14 pixel radii, and restrained spacing. Avoid giant cards, decorative circles, and continuous timeline rails.

**Step 3: Style ongoing activities separately**

Use a soft tinted region with compact cards so all-day activities are visible without competing with featured timed sessions.

**Step 4: Style concurrent bands**

Use:

```css
.oras-agenda__session-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
    gap: 14px;
}
```

Time headings remain fixed-width on desktop and move above cards below the tablet breakpoint.

**Step 5: Add robust WP Dark Mode variables**

Cover all selectors already used elsewhere in ORAS-Tickets:

```css
html[data-wp-dark-mode-active] .oras-agenda,
html.wp-dark-mode-active .oras-agenda,
body.wp-dark-mode-active .oras-agenda,
html[data-wp-dark-mode-loading] .oras-agenda { ... }
```

Dark surfaces must be visibly distinct: outer near-black, agenda navy, cards lighter slate, and controls high contrast.

**Step 6: Add responsive and reduced-motion rules**

- Horizontally scroll day tabs without wrapping.
- Stack time and cards on tablet/mobile.
- Force one card column on mobile.
- Keep interactive controls at least 44 pixels tall.
- Disable nonessential transitions under `prefers-reduced-motion: reduce`.

**Step 7: Run targeted checks and commit**

```bash
php oras-tickets/tools/phase1h-event-questions-checks.php
git diff --check
git add oras-tickets/assets/css/agenda.css oras-tickets/assets/css/oras-agenda-colors.css oras-tickets/tools/phase1h-event-questions-checks.php
git commit -m "Style responsive conference agenda"
```

### Task 6: Replace The Bleeding Speaker Modal With An Isolated Drawer

**Files:**
- Modify: `oras-tickets/includes/Frontend/Event_Agenda_Render.php`
- Modify: `oras-tickets/assets/js/speaker-modal.js`
- Modify: `oras-tickets/assets/css/agenda.css`
- Modify: `oras-tickets/assets/css/oras-agenda-colors.css`
- Modify: `oras-tickets/tools/phase4-surface-checks.php`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php`

**Step 1: Add failing drawer assertions**

Require markup and source behavior for:

- `id="oras-speaker-drawer"`.
- `role="dialog"` and `aria-modal="true"`.
- A labeled `Close speaker profile` control.
- Moving the drawer beneath `document.body`.
- Focusable-element collection and Tab focus containment.
- Restoring focus to the speaker trigger.
- Body scroll locking.
- A fully opaque drawer surface.

Run checks and confirm failure.

**Step 2: Replace modal markup**

Render:

```html
<div class="oras-speaker-drawer" id="oras-speaker-drawer" hidden>
  <div class="oras-speaker-drawer__backdrop" data-speaker-close></div>
  <aside class="oras-speaker-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="oras-speaker-drawer-title">
    <header class="oras-speaker-drawer__header">
      <span>Speaker profile</span>
      <button type="button" data-speaker-close>Close speaker profile</button>
    </header>
    <div class="oras-speaker-drawer__content">...</div>
  </aside>
</div>
```

Keep the existing speaker payload shape and links.

**Step 3: Mount and control the drawer**

At initialization:

```javascript
if ( drawer.parentElement !== document.body ) {
    document.body.appendChild( drawer );
}
```

On open:

- Populate profile content.
- Save the trigger.
- Remove `hidden`.
- Add `oras-speaker-drawer-open` to `body`.
- Focus the close button.

On close:

- Restore `hidden`.
- Remove the body class.
- Restore trigger focus.

Trap Tab and Shift+Tab inside the open drawer. Close on Escape or backdrop activation.

**Step 4: Isolate the visual layer**

Use a very high component z-index, `position: fixed`, `isolation: isolate`, a fully opaque panel `background-color`, and an explicit backdrop. The panel must not use semi-transparent backgrounds.

Desktop uses a right-side drawer no wider than approximately 560 pixels. Mobile uses the full viewport with no rounded outer corners.

**Step 5: Run focused checks and commit**

```bash
php oras-tickets/tools/phase1h-event-questions-checks.php
wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase4-surface-checks.php
git diff --check
git add oras-tickets/includes/Frontend/Event_Agenda_Render.php oras-tickets/assets/js/speaker-modal.js oras-tickets/assets/css/agenda.css oras-tickets/assets/css/oras-agenda-colors.css oras-tickets/tools/phase4-surface-checks.php oras-tickets/tools/phase1h-event-questions-checks.php
git commit -m "Replace speaker modal with accessible profile drawer"
```

### Task 7: Release Verification And Version Bump

**Files:**
- Modify: `oras-tickets/oras-tickets.php`
- Modify: `docs/plans/2026-07-19-conference-agenda-design.md` only if implementation findings require a factual correction

**Step 1: Run PHP syntax checks**

```bash
php -l oras-tickets/includes/Frontend/Event_Agenda_Render.php
php -l oras-tickets/tools/phase4-surface-checks.php
php -l oras-tickets/tools/phase1h-event-questions-checks.php
```

Expected: no syntax errors.

**Step 2: Run targeted and static checks**

```bash
php oras-tickets/tools/phase1h-event-questions-checks.php
git diff --check
```

Expected: PASS and no whitespace errors.

**Step 3: Run project quality gates**

```bash
composer phpcs
composer phpstan
```

Expected: both pass.

**Step 4: Run wp-env integration checks**

Use the existing project environment and run:

```bash
wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/phase4-surface-checks.php
bash scripts/run-phase5-integration-checks.sh
```

Expected: all checks pass without modifying production data.

**Step 5: Perform browser verification**

Verify with a fixture containing at least two days, two concurrent sessions, one long ongoing activity, one speaker, and one resource:

- Desktop light mode.
- Desktop WP Dark Mode.
- Mobile light mode.
- Mobile WP Dark Mode.
- Day tab keyboard navigation.
- Type and location filtering.
- Speaker drawer open, focus containment, close, and focus restoration.
- No agenda or speaker content bleeds through the drawer.
- Existing current-session highlighting remains functional.

**Step 6: Bump the plugin version**

Increment both the plugin header and `ORAS_TICKETS_VERSION` constant from `0.4.31` to the next patch version.

**Step 7: Commit the release**

```bash
git add oras-tickets/oras-tickets.php
git commit -m "Release conference agenda redesign"
```

**Step 8: Final repository check**

```bash
git status --short
git log -8 --oneline
```

Expected: clean worktree and the task commits listed in order.
