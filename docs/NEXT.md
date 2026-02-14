# NEXT — Single Focus

Next approved development phase: Phase 4.6 — Speaker Resource Uploads + Historical Archive

Note: Phase 4.2 is deferred (retained below for reference).

Status: Ready for implementation planning.

Concrete next phases:
1) Phase 4.2-A — Reporting hardening
	- Per-speaker assignment/fulfillment export views.
	- Reporting performance pass for larger datasets.
2) Phase 4.2-B — Automation refinements
	- Optional internal notification refinements around fulfillment actions.
	- Audit-friendly activity summaries for speaker obligations.

Verification checklist (current features):
- `wp post meta get <event_id> _oras_agenda_v1`
- `wp post meta list <speaker_id> --keys=_oras_speaker_headshot_id`
- `wp post get <speaker_id> --fields=ID,post_status,post_name`
