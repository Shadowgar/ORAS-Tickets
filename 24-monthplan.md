ORAS-Tickets — 24‑Month Delivery Plan

Scope: a pragmatic, deterministic roadmap that implements Tier‑1 and Tier‑2 priorities first, preserves TEC Pro integration and recurrence guardrails, and delivers treasurer-grade reporting and operational tooling over 24 months.

Principles
- One phase at a time. Minimal diffs. Backwards-compatible meta envelopes.
- Build on TEC Pro (no duplication of recurrence/views). Enforce recurrence guardrail early.
- Ship high‑value ops features first (speaker archive, RSVP/waitlist, reporting, QR check‑in).
- Prefer small, testable milestones with clear acceptance criteria.

Assumptions
- Core team: 1 senior engineer (part‑time), 1 mid engineer, plus volunteer reviewers.
- Integration points: WooCommerce, TEC Pro, PMPro are available and configured.
- CI runs: `composer phpstan`, PHPCS available.

High‑Level Phases (mapped to months)
- Months 0–3 (Feb–Apr 2026): Stabilize + Finish Phase 4.6
  - Tasks: finalize Speaker Resource Archive, compute `_oras_speaker_history_v1`, finish frontend single-speaker view and admin editor.
  - Deliverables: speaker CPT resources persisted, UI to attach resources per slot, tests for data integrity.
  - Acceptance: Speaker archive editable, renderable; phpstan passes for modified files.

- Months 3–6 (May–Jul 2026): Recurrence Guardrail + RSVP foundations
  - Tasks: implement recurrence guardrail (disable ORAS ticketing when TEC recurrence enabled); implement RSVP storage (DB table) and basic UI injections.
  - Deliverables: guardrail enforcement in admin save handlers; RSVP record store + simple RSVP form and confirmation emails.
  - Acceptance: Recurring events cannot save ORAS ticket meta; RSVP entries written to DB, basic phpstan/PHPCS green for new code.

- Months 6–9 (Aug–Oct 2026): Waitlist MVP (Ticketed + RSVP)
  - Tasks: post‑sellout waitlist form per event/ticket, store structured waitlist CPT/DB record, email verification toggle, confirmation email with cancel link, admin bulk actions (verify/confirm/reject/export CSV).
  - Deliverables: waitlist enroll UI, admin list, CSV export.
  - Acceptance: waitlist entries created, verification flow optional, exports functional.

- Months 9–12 (Nov 2026–Jan 2027): Promotion + Reservation Window + Capacity Dashboard
  - Tasks: priority‑based promotion, optional reservation window (lock inventory), extend `Reports_Aggregator` for capacity tab, admin manual promote next.
  - Deliverables: promotion job/endpoint, reservation timer persisted, capacity dashboard UI.
  - Acceptance: promotion moves entry to reservation state; reservation expiry releases stock and promotes next.

- Months 12–15 (Feb–Apr 2027): Treasurer Reporting (Phase 10 start)
  - Tasks: advanced reporting suite (revenue per event, per speaker, member vs non‑member), CSV/XLSX export, basic invoice engine design.
  - Deliverables: reporting UI, export endpoints, report export templates.
  - Acceptance: reports match Woo data, exports include custom fields.

- Months 15–18 (May–Jul 2027): QR Code & Check‑In System
  - Tasks: generate ticket QR tokens, secure `/oras-ticket/verify` route, check‑in controller + mobile admin, prevent double check‑ins, export attendance.
  - Deliverables: QR generation integrated with print view, check‑in UI, logs persisted.
  - Acceptance: QR verification completes, check‑in logs show timestamps and no duplicates.

- Months 18–21 (Aug–Oct 2027): Member Hub & UX Improvements
  - Tasks: `My Tickets`, `My RSVPs`, `My Speaker History`, invoice downloads, zoom gated display integration, dashboard polish.
  - Deliverables: member-facing hub pages, authenticated endpoints.
  - Acceptance: members can view/download tickets and RSVPs; gated content validated server-side.

- Months 21–24 (Nov 2027–Jan 2028): Polish, Automation, and Contingency
  - Tasks: invoice engine completion, refund intelligence, optional hybrid features, harden CI, docs, accessibility.
  - Deliverables: invoice PDFs, refund reports, documentation for operators.
  - Acceptance: CI stable, documentation updated, release candidate tag.

Deliverable Sizing & Risk
- Small (S): single controller, admin page, UI injection (1–2 dev weeks).
- Medium (M): new DB table + admin UI + exports (3–6 dev weeks).
- Large (L): reservation window + commerce stock integration; treasurer reporting + invoices (8–12 dev weeks).

Dependencies
- Early: recurrence guardrail must be implemented before mass ticketing on recurring events.
- Commerce: reservation window requires careful Woo stock integration and testing.
- Reporting: access to accurate Woo order metadata and sanitized mapping (map must be reliable).

Acceptance Criteria (project-level)
- Every merged change: include a `prompts/sessions/*.md` session file and an acceptance checklist.
- No change breaks `composer phpstan` on target branch; PHPCS issues limited and tracked.
- Each milestone has a PR with UI screenshots, migration notes, and a small operator doc.

Governance & Release
- Release cadence: 1–2 minor releases per quarter (features gated behind flags where appropriate).
- Use session-driven prompts for deterministic assistant work; commit `prompts/sessions/*.md` with each PR.

If you want I can now: (pick)
- Convert this into a quarter-by-quarter Gantt (CSV) for import into planning tools.
- Split the 24‑month plan into an actionable sprint backlog (12–16 two-week sprints) with JIRA/Ticket cues.
- Produce a Core vs Premium feature split for potential commercialization.

