
# NEXT — Single Focus

Next approved development phases (short-term):

1) ~~Phase 5.3 — Unified ORAS Event Metabox (UI container refactor)~~ ✅ **COMPLETED**
	- Create `ORAS EVENTS ADDON` master metabox containing vertical tabs: Tickets, Agenda, RSVP, Speakers.
	- Virtual Access remains integrated in The Events Calendar's virtual metabox via filters.
	- Reuse existing per-feature render + save handlers; no meta schema changes.

2) ~~Phase 5.4 — RSVP Management Dashboard (operational UI)~~ ✅ **COMPLETED**
	- Move attendee lists, CSV export, and waitlist promotion into the plugin Dashboard with an event selector and AJAX-driven lists.
	- Keep Event Edit focused on per-event configuration only.

3) Phase 5.5 — Settings Page Expansion (global defaults)
	- Consolidate global behavior toggles under `ORAS-Tickets → Settings` (default capacity, waitlist behavior, virtual access defaults, ticket auto-complete).

4) Phase 6 — Attendees Management (operational)
	- Phase 6.2 → 6.5 implemented: attendees dashboard, attendee operations, messaging, and notes (admin-only).
	- Verification: use `admin_post` CSV export and AJAX handlers for `oras_attendees_dashboard_data`, `oras_attendees_send_email`, and `oras_attendees_save_note` (WP-CLI simulations were used during development).

Notes:
- Phase 5 (RSVP + Waitlist) frontend and REST work is implemented; these NEXT items focus on admin UX and settings consolidation.
- Phase 4.2 remains deferred and will be revisited after the Phase 5 admin refactors.

Verification checklist (current features):
 - `wp post meta get <event_id> _oras_agenda_v1`
 - `wp post meta list <speaker_id> --keys=_oras_speaker_headshot_id`
 - `wp post get <speaker_id> --fields=ID,post_status,post_name`
