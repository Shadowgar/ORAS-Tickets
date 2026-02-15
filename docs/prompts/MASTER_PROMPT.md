# ✅ MASTER HANDOFF — ORAS-Tickets (TEC Integrated)

You are continuing a long-running **production WordPress plugin engineering project**.

This is NOT a greenfield build.

You are acting as a **Senior Architect / Deterministic Phase Controller**.

You must operate carefully, minimally, and phase-by-phase.

---

# ✅ MASTER HANDOFF — ORAS-Tickets (updated)

This is the authoritative handoff prompt for the ORAS-Tickets codebase. Paste this file into a new ChatGPT session together with the repository ZIP so the model can perform audits, create prompts, and propose code changes.

Keep instructions deterministic and phase-driven: small, minimal diffs only; follow the Hard Rules below.

---

## Project At-a-Glance

- Name: ORAS-Tickets
- Organization: Oil Region Astronomical Society (ORAS)
- Primary purpose: Event ticketing + RSVP + speaker and agenda enhancements integrated with The Events Calendar (TEC)
- Language/stack: PHP (WordPress), JS, CSS; integrates with TEC, Event Tickets, WooCommerce, Stripe, PMPro
- Local dev: `npx wp-env` (project includes `.wp-env.json`)
- Primary plugin namespace: `ORAS\\Tickets`

---

## Hard Rules (must follow)

1. ORAS-Tickets is an ADD-ON: do NOT modify TEC, Event Tickets, WooCommerce, or PMPro core files.
2. No new DB tables unless explicitly approved.
3. No telemetry, no license servers, no external SaaS required.
4. Small, deterministic diffs only. Avoid large structural refactors without explicit approval.
5. Preserve backwards-compatible meta envelopes and existing meta keys.

---

## Key Repo Paths (open these first)

- `plugin/` — primary plugin source
  - `plugin/includes/Bootstrap.php` — wiring & module registration
  - `plugin/includes/Frontend/` — frontend controllers (e.g., `Event_RSVP.php`, `Virtual_Access.php`)
  - `plugin/includes/Api/` — REST endpoints (e.g., `Rsvp.php`)
  - `plugin/includes/Admin/Metaboxes/` — admin metaboxes (tickets, RSVP, agenda, speakers)
  - `plugin/assets/` — CSS/JS assets
- `docs/` — architecture, project state, next steps
- `docs/prompts/` — prompt templates and session snapshots
- `scripts/`, `tools/` — developer helper scripts
- `config/` — phpstan/phpcs configs

---

## Important Meta Keys & Conventions

- Event ticketing: `_oras_tickets_v1`, `_oras_tickets_woo_map_v1`
- RSVP: `_oras_rsvp_v1` (envelope), per-user RSVP state stored in usermeta `_oras_rsvp_event_{EVENT_ID}`
- Virtual access: `_oras_virtual_access_v1`
- Speakers: `oras_speaker` CPT and `_oras_speakers_v1` envelope
- Agenda: `_oras_agenda_v1`

All meta is versioned (v1) and saved as arrays/envelopes; avoid changing schemas without coordinated migration.

---

## Current Phase / Status (short)

- Core ticketing and Woo mapping implemented. Ticket print and Woo mapping exist.
- Speaker CPT and Agenda features implemented.
- Phase 5 (RSVP + Waitlist + Virtual Access) is active: frontend RSVP, REST endpoints, virtual access gating, admin helpers exist; work remains on master metabox and dashboard UX.

---

## Quick Local Setup & Verification

1. Start local WP environment:
```bash
npx wp-env start
```

2. Run static checks locally (if you have composer/vendor installed):
```bash
composer phpcs
composer phpstan
```

3. Common WP-CLI checks (inside repo root with `wp-env` running):
```bash
# List event meta for event 60
npx wp-env run cli wp --skip-plugins --skip-themes post meta list 60

# Check ORAS meta for an event
npx wp-env run cli wp --skip-plugins --skip-themes post meta get 60 _oras_rsvp_v1
npx wp-env run cli wp --skip-plugins --skip-themes post meta get 60 _oras_tickets_v1

# Verify REST routes exist
npx wp-env run cli php -d memory_limit=512M /usr/local/bin/wp eval ' $r = rest_get_server()->get_routes(); echo isset($r["/oras/v1/rsvp/my"]) ? "my:ok\n":"my:missing\n"; echo isset($r["/oras/v1/rsvp/event/(?P<id>\\d+)"]) ? "event:ok\n":"event:missing\n";'
```

---

## Audit Checklist (what to confirm during an initial audit)

1. Bootstrap wiring: confirm `Bootstrap::register()` and that modules are registered in `plugin/includes/Bootstrap.php`.
2. Frontend RSVP (`plugin/includes/Frontend/Event_RSVP.php`): renders on single event when `_oras_rsvp_v1.enabled` is true and posts data to handlers.
3. Virtual access (`plugin/includes/Frontend/Virtual_Access.php`): ensures TEC virtual metabox can be augmented and saves `_oras_virtual_access_v1`.
4. REST endpoints: `plugin/includes/Api/Rsvp.php` provides `/oras/v1/rsvp/*` routes and returns proper JSON with authentication.
5. Admin metaboxes: RSVP attendees, tickets, agenda, speakers render and their save handlers work.
6. Scripts and CI: `config/phpstan.neon`, `config/phpcs.xml` in repo root; `composer` scripts should run without path errors.

---

## Typical Commands You May Need

- Start dev environment: `npx wp-env start`
- Run WP-CLI inside container: `npx wp-env run cli wp ...`
- Run PHP evals: `npx wp-env run cli php -d memory_limit=512M /usr/local/bin/wp eval '...'
- Run static checks: `composer phpstan` and `composer phpcs`

---

## Known Operational Notes

- The repository previously had a GitHub Actions workflow that auto-updated `docs/prompts/MASTER_PROMPT.md` from auto-generated session files; this workflow has been removed (no bot commits should occur anymore).
- Developer helper scripts live in `scripts/` and `tools/`. They are manual and should not be executed automatically by CI.

---

## First Tasks for a New Chat (what to ask the model to do first)

1. Run a deterministic audit following the Audit Checklist above. Report files changed, missing behaviors, and a short remediation plan.
2. Confirm Phase 5 wiring and list gaps required to complete Phase 5.3 (master metabox) and Phase 5.4 (dashboard).
3. Provide exact, minimal diffs to implement Phase 5.3 (one PR) — include file list, code snippets, and WP-CLI verification steps.

When you request code changes, require that each proposed patch:
- includes a one-line commit message
- includes WP-CLI verification commands
- is as small and targeted as possible

---

## Example Starter Prompt (paste into new chat along with the repo zip)

You are a senior WordPress plugin engineer. I will upload the ORAS-Tickets repository as a zip. First, perform a deterministic audit of the repository. Use the Audit Checklist in the MASTER_PROMPT to guide the audit. Produce:

1. A short summary of the repo (3-6 bullets).
2. A list of 3 high-priority issues to fix now (safety, broken hooks, missing wiring).
3. One minimal PR implementing the highest-priority fix with a diff, commit message, and WP-CLI verification steps.

Do not proceed beyond those steps until I confirm.

---

## Contact / Context

This repository is actively developed. Keep changes minimal, discuss large refactors first, and strictly follow the Hard Rules.

---

End of MASTER_PROMPT

 - `plugin/includes/Bootstrap.php` (require and register the new metabox; unregister old metaboxes only after new one is live)
 - `plugin/assets/css/tickets-frontend.css` (minor admin tab styling only)

9) If you run into memory/boot issues during WP-CLI checks
 - Increase PHP memory for the WP-CLI command with `php -d memory_limit=512M /usr/local/bin/wp ...` as shown above.

10) What to hand back in a PR or commit
 - Small focused commits implementing the UI container and wiring; include `php -l` validation output, and a short testing checklist in the PR description with the commands used.

Use this handoff text exactly when you upload the repo to the next assistant. The assistant should not guess repository layout beyond these paths — open and read the files above first.


Proceed carefully.

## Recent Sessions

### Session Snapshot
- Date: 2026-02-15T06:31:43.451262Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 39ab0ba
- Commit message: updated commit values
- Goal: updated commit values
- Checks:
  - phpstan: FAIL (exit 127)
  - phpcs: FAIL (exit 127)
- Key files:
  - .githooks/post-commit
  - .githooks/pre-commit

### Session Snapshot
- Date: 2026-02-15T06:29:39.033280Z
- Author: github-actions[bot] <41898282+github-actions[bot]@users.noreply.github.com>
- Commit: 2fbd2a0
- Commit message: chore: auto-update MASTER_PROMPT.md (action)
- Goal: chore: auto-update MASTER_PROMPT.md (action)
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - docs/prompts/MASTER_PROMPT.md
  - docs/prompts/sessions/auto-20260215-051140-e4a73f5.md

### Session Snapshot
- Date: 2026-02-14T11:04:47.985446Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 2f147bd
- Commit message: Phase 4.7 Completed.
- Goal: Phase 4.7 Completed.
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - .wp-env.json
  - .wp-env/php/php.ini
  - plugin/includes/Admin/Tickets_Metabox.php
  - docs/prompts/MASTER_PROMPT.md
  - docs/prompts/sessions/auto-20260214-105713-0b5fc35.md

### Session Snapshot
- Date: 2026-02-14T10:57:13.920595Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 0b5fc35
- Commit message: Change Speaker Contributes
- Goal: Change Speaker Contributes
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - plugin/templates/single-oras_speaker.php
  - docs/prompts/MASTER_PROMPT.md
  - docs/prompts/sessions/auto-20260214-100446-65af058.md

### Session Snapshot
- Date: 2026-02-14T10:04:46.686905Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 65af058
- Commit message: Update Prompt
- Goal: Update Prompt
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - docs/prompts/MASTER_PROMPT.md
  - docs/prompts/sessions/auto-20260214-093648-88d1631.md

### Session Snapshot
- Date: 2026-02-14T09:36:48.747270Z
- Author: ShadowGar <rocco.paul@gmail.com>
- Commit: 88d1631
- Commit message: Agenda Updates
- Goal: Agenda Updates
- Checks:
  - phpstan: FAIL (exit 1)
  - phpcs: FAIL (exit 2)
- Key files:
  - plugin/includes/Frontend/Event_Agenda_Render.php
  - docs/prompts/MASTER_PROMPT.md
  - docs/prompts/sessions/auto-20260214-093129-4501a00.md

