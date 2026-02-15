# MASTER HANDOFF — ORAS-Tickets (Deterministic Engineer)

You are continuing an ongoing production WordPress plugin project: ORAS-Tickets. This file is the authoritative handoff to paste into a new ChatGPT session together with the repository ZIP so the assistant can run audits, produce prompts, and propose minimal code changes.

Keep changes small and deterministic. Follow the Hard Rules exactly.

---

Project at-a-glance
- Name: ORAS-Tickets
- Stack: PHP (WordPress), JS, CSS; integrates with The Events Calendar (TEC), Event Tickets, WooCommerce, PMPro
- Local dev: `npx wp-env` (project includes `.wp-env.json`)
- Primary plugin namespace: `ORAS\\Tickets`

Hard Rules (must follow)
1. ORAS-Tickets is an ADD-ON: do NOT edit TEC, Event Tickets, WooCommerce, or PMPro core files.
2. No new DB tables unless explicitly approved.
3. No telemetry, no external SaaS, no license servers.
4. Small, deterministic diffs only — avoid large refactors without approval.
5. Preserve backwards-compatible meta envelopes and keys (v1 meta keys in use).

Key repo paths
- `plugin/` — primary plugin source
  - `plugin/includes/Bootstrap.php` — wiring & module registration
  - `plugin/includes/Frontend/` — frontend controllers (e.g., `Event_RSVP.php`, `Virtual_Access.php`)
  - `plugin/includes/Api/` — REST endpoints (e.g., `Rsvp.php`)
  - `plugin/includes/Admin/` — admin menu, metaboxes, pages
  - `plugin/assets/` — CSS/JS assets
- `docs/` — architecture, project state, and roadmap

Important meta keys & conventions
- `_oras_tickets_v1`, `_oras_tickets_woo_map_v1`
- RSVP: `_oras_rsvp_v1` envelope; per-user RSVP stored in usermeta `_oras_rsvp_event_{EVENT_ID}`
- Virtual access: `_oras_virtual_access_v1`
- Speakers/agenda envelopes: `_oras_speakers_v1`, `_oras_agenda_v1`

Current phase & notable status
- Core ticketing, RSVP, virtual access, Woo mapping, and ticket printing exist.
- Active work is Phase 5 (RSVP + Waitlist + Virtual Access / dashboard UX).
- NOTE: The dedicated Check-In feature (Phase 6.1) has been abandoned and removed from the codebase — the REST check-in route and check-in CLI were removed to stabilize the plugin. Do not attempt to reintroduce check-in without coordinated design and tests.

Quick local setup & verification
1. Start local WP environment:
```bash
npx wp-env start
```
2. Run project static checks (if composer/vendor available):
```bash
composer phpcs
composer phpstan
```
3. WP-CLI checks inside wp-env (example):
```bash
# List event meta for event 60
npx wp-env run cli wp --skip-plugins --skip-themes post meta list 60

# Verify ORAS meta keys on event 60
npx wp-env run cli wp --skip-plugins --skip-themes post meta get 60 _oras_rsvp_v1
npx wp-env run cli wp --skip-plugins --skip-themes post meta get 60 _oras_tickets_v1

# Verify REST routes exist (example RSVP routes)
npx wp-env run cli php -d memory_limit=512M /usr/local/bin/wp eval ' $r = rest_get_server()->get_routes(); echo isset($r["/oras/v1/rsvp/my"]) ? "my:ok\n":"my:missing\n"; echo isset($r["/oras/v1/rsvp/event(?P<id>\\d+)"]) ? "event:ok\n":"event:missing\n";'
```

Audit checklist (first tasks for new session)
1. Confirm `Bootstrap::register()` wiring in `plugin/includes/Bootstrap.php` and which modules are registered.
2. Confirm frontend RSVP (`plugin/includes/Frontend/Event_RSVP.php`) renders and posts correctly when `_oras_rsvp_v1.enabled` is set.
3. Confirm Virtual Access (`plugin/includes/Frontend/Virtual_Access.php`) gating and meta keys.
4. Confirm REST endpoints in `plugin/includes/Api/` (notably `Rsvp.php`) and authentication behavior.
5. Confirm admin metaboxes (`plugin/includes/Admin/Metaboxes/`) render and save properly.

Typical commands you may need (copyable)
```bash
# Start dev environment
npx wp-env start

# Run CLI inside container
npx wp-env run cli wp <args>

# Run PHP eval to inspect routes
npx wp-env run cli php -d memory_limit=512M /usr/local/bin/wp eval '...'
```

Guidance for proposing code changes
- Always produce minimal diffs and a one-line commit message.
- Include WP-CLI verification commands and a short testing checklist in the PR description.
- Run `php -l` or `composer phpcs` locally for changed files before proposing a patch.

What to hand back in a PR or commit
- Small focused commits implementing the requested change.
- Provide `php -l` validation output for edited PHP files.
- Provide WP-CLI verification commands and a short testing checklist.

Session/context notes (copy into new chat if relevant)
- This repo uses versioned meta envelopes (v1). Do not change meta schemas without a migration.
- Check-in feature was removed to stabilize the dashboard; if reintroducing, coordinate design and tests first.

End of handoff — start with the Audit Checklist above.
