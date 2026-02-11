# NEXT — Single Focus

Next approved development phase: Phase 4.1 — Speaker Management (MVP, internal only)

Status: Planning only. No coding begins until explicit approval after docs are updated.

Implementation Plan (4.1):
1) Register `oras_speaker` CPT with admin metaboxes for the locked meta fields.
2) Add Event edit metabox/panel to manage speaker assignments stored in `_oras_speakers_v1`.
3) Treasurer view for unfulfilled obligations (fee unpaid or membership not granted), querying event meta directly (indexing deferred to 4.2).
4) PMPro fulfillment action:
	- If speaker has `_oras_speaker_wp_user_id`, use it.
	- Else try to find a WP user by email; if none exists, create a Subscriber with a random password.
	- Link WP user ID to speaker meta.
	- Grant PMPro membership level (`pmpro_level_id`) and mark fulfilled.

Deferred to 4.1-B / 4.2:
- Public speaker profiles and event page speaker display (Phase 4.1-B).
- Automated emails and fulfillment automation (Phase 4.2).
- Indexing or caching layer for reporting/export (Phase 4.2).
