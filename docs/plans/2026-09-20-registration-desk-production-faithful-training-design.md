# Production-Faithful Registration Desk Training Design

## Goal

Training Mode must present the same volunteer workflow and current AstroBlast roster that volunteers will use on event day, while every mutation remains confined to the station's isolated training session.

## Snapshot boundary

Starting Training Mode reads the selected event's complete current Registration Desk roster and detail projections and copies them into the existing training-session state. Reset discards all training mutations and takes a fresh snapshot. The snapshot contains the volunteer-facing registration, attendee, admission, ticket, and contact fields needed by the normal kiosk UI. It does not contain credentials or authorize any live write.

After creation, training roster searches, details, check-ins, walk-ins, memberships, and statistics read and write only the training row. Live Registration Desk, attendance, WooCommerce, PMPro, email, QuickBooks, Stripe, and Board Reports remain untouched. The captured event configuration revision remains mandatory; a revision change fails closed.

## Presentation

Operational screens reuse the live UI renderer and live wording. Registration detail, attendee selection, AlfaPOS handoff, payment statement, completion, membership, and Event Stats screens must not substitute training-specific instructions. The only volunteer-visible difference is a compact `TRAINING` marker in the top shell showing the simulated date. Manager-only start, date, reset, and end controls retain explicit training language.

## Lifecycle

- Start snapshots the full current event roster and initializes empty training attendance.
- Reset resnapshots the current event roster and clears training activity.
- Change Date preserves the snapshot, walk-ins, and attendance history; the selected date becomes `TODAY` in the normal UI.
- End deletes the isolated training session.

## Qualification

Focused checks must prove that snapshot records preserve real roster/detail fields, the live renderer is shared, training check-ins and walk-ins mutate only copied state, reset refreshes the snapshot, date changes preserve history, operational copy matches live mode, and the compact marker remains visible.
