# ORAS Conference Agenda Design

## Purpose

Replace the current decorative timeline with a professional conference program that remains easy to scan for multi-day events containing dozens of sessions, overlapping activities, multiple locations, speakers, and downloadable resources.

The design must work for small events as well as AstroBlast-scale schedules. It must remain readable in both native light mode and WP Dark Mode.

## Primary Layout

The agenda uses a time-banded program layout.

Each event day has:

1. A day selector for quickly moving among event dates.
2. An optional ongoing-activities region for registration windows, contests, markets, observing, or other activities spanning multiple scheduled sessions.
3. Chronological time bands containing one or more session cards.

Sessions sharing a start time are grouped in the same time band. On wide screens, concurrent sessions render beside each other. On narrow screens, they stack below the shared time heading.

The agenda does not use a continuous vertical timeline or a border connecting every session. Time bands, spacing, card surfaces, and headings provide the visual structure.

## Information Hierarchy

The agenda header contains:

- Agenda title.
- Timezone note when enabled.
- Sticky day navigation when more than one day exists.
- Optional filters for session type and location when the agenda contains multiple values.

Each day contains:

- Day label and formatted date.
- Ongoing activities, displayed as compact information cards above the timed schedule.
- Timed session bands ordered by start time.

Each session card displays:

- Session title.
- Start and end time.
- Session type.
- Location when provided.
- Short description when provided.
- Speaker buttons when speakers are assigned.
- Clearly labeled resource actions when resources exist.
- A current-session indicator when current-session highlighting is enabled.

Long descriptions and extensive metadata should not force every card to become oversized. The initial release will use restrained typography and content spacing; future expansion controls may be added only if real production content demonstrates a need.

## Concurrent Sessions

The renderer groups adjacent sessions with the same normalized start time into one time band. The band owns the time label, and its session area uses a responsive card grid.

- One concurrent session uses the full available width.
- Two concurrent sessions use two columns when space permits.
- Three or more concurrent sessions use responsive columns with a practical minimum card width.
- Mobile always uses one column.

Sessions without a valid start time remain visible in an unscheduled section after timed sessions rather than being discarded.

## Ongoing Activities

The existing agenda data model does not contain a dedicated ongoing-activity field. The first implementation will infer ongoing presentation from session duration and explicit session data without changing stored agenda records.

Long-running activities still remain in chronological order, but they receive an ongoing visual treatment when they overlap later sessions. A later editor enhancement may introduce an explicit `ongoing` setting after the frontend design is proven.

This preserves all existing agenda data and avoids a schema migration for a visual redesign.

## Speaker Experience

Speaker names render as accessible buttons inside session cards. Activating a speaker opens a dedicated profile drawer.

Desktop behavior:

- Fixed drawer attached to the right side of the viewport.
- Fully opaque surface.
- Separate fixed backdrop.
- Maximum readable width with internal scrolling.

Mobile behavior:

- Full-screen profile sheet.
- Fixed header containing speaker name and a labeled close control.
- Scrollable profile content.

The drawer is rendered at document-body level so it cannot be clipped by the event content container or inherit transparency from agenda cards. Opening the drawer locks background scrolling, moves keyboard focus into the drawer, and records the trigger so focus can be restored when closed. Escape and backdrop activation close the drawer.

The profile displays the speaker headshot, name, role or affiliation, biography, website, and full-profile link when available.

## Resource Experience

Resources render as compact labeled actions instead of a plain nested list. Labels should describe the destination, such as `Slides`, `Handout`, `Recording`, or `Download`, while retaining the resource's configured name.

Links remain normal accessible anchors and open according to the existing resource behavior. No resource storage changes are required.

## Light And Dark Modes

The agenda uses scoped CSS custom properties for all surfaces, text, borders, accents, overlays, and focus states.

Light mode uses:

- Warm white outer surface.
- White session cards.
- Dark navy typography.
- Soft gray-blue borders.
- Restrained ORAS blue accents.

Dark mode uses:

- Near-black page-compatible outer surface.
- Dark navy agenda surface.
- Lighter slate session cards.
- High-contrast off-white typography.
- Cyan-blue accents for times, links, and active states.

WP Dark Mode selectors override the variables, not individual component rules. The speaker drawer explicitly defines opaque backgrounds and opts out of inherited transparency effects.

## Responsive Behavior

Desktop:

- Time label occupies a narrow left column.
- Concurrent cards occupy a flexible grid to the right.
- Day navigation remains visible while scrolling when the theme permits sticky positioning.

Tablet:

- Time remains above or beside the card grid depending on available width.
- Concurrent cards reduce to one or two columns.

Mobile:

- Day navigation scrolls horizontally without wrapping into several rows.
- Time appears as a strong heading above its sessions.
- All session cards use one column.
- Speaker drawer becomes a full-screen sheet.
- Touch targets remain at least 44 pixels where controls are interactive.

## Accessibility

- Day navigation retains tab semantics and keyboard operation.
- Session groups use meaningful headings and list structure.
- Speaker buttons expose the speaker name in their accessible label.
- The drawer uses dialog semantics, an accessible name, focus containment, Escape handling, and focus restoration.
- Color is never the only indicator for session type, location, or current state.
- Text and controls meet WCAG AA contrast in both themes.
- Reduced-motion preferences disable nonessential drawer and card transitions.

## Backward Compatibility

- Preserve `_oras_agenda_v1` storage.
- Preserve existing agenda editor behavior.
- Preserve day tabs, descriptions, locations, speakers, resources, current-session highlighting, and autoscroll settings.
- Preserve events containing one day, one session, missing dates, missing end times, or no speakers.
- Do not modify ORAS-Member-Hub.

## Testing

Targeted checks must cover:

- Multi-day navigation rendering.
- Concurrent sessions grouped into one time band.
- Sequential sessions rendered in separate bands.
- Single-session and missing-time fallbacks.
- Speaker drawer markup and body-level mounting behavior.
- Light and WP Dark Mode variable definitions.
- Mobile single-column layout.
- Existing speaker, resource, date, and current-session behavior.

Run PHP syntax checks, targeted agenda checks, `git diff --check`, PHPCS, PHPStan, and browser verification in the ORAS wp-env when available.

## Non-Goals

- No new ticketing or RSVP functionality.
- No agenda database migration.
- No Member Hub changes.
- No new drag-and-drop agenda editor behavior.
- No separate track-grid view in this release.
