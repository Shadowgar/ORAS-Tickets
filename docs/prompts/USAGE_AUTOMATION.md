Auto-updating `MASTER_PROMPT.md` — usage

Goal

Allow quick reproduction of the exact prompt you used with the assistant by keeping `docs/prompts/MASTER_PROMPT.md` up-to-date with short session summaries.

Files added

- `scripts/update_master_prompt.py` — merge a session file into `docs/prompts/MASTER_PROMPT.md` (adds a compact session snapshot into a `## Recent Sessions` section).
- `scripts/assemble_prompt.py` — assemble a ready-to-send prompt by concatenating `MASTER_PROMPT.md` and a session file.

Quick workflow

1. Save your change intention into a session file under `docs/prompts/sessions/`, using `docs/prompts/TEMPLATE_change.md` as a base.
2. Run the update script to add a quick snapshot into the master prompt:

```bash
python scripts/update_master_prompt.py docs/prompts/sessions/20260214-1430-waitlist.md
```

This prepends a compact session snapshot into `docs/prompts/MASTER_PROMPT.md` under `## Recent Sessions`.

3. Produce a ready-to-send prompt for ChatGPT by assembling master + session:

```bash
python scripts/assemble_prompt.py --session docs/prompts/sessions/20260214-1430-waitlist.md > ready_prompt.txt
```

4. Copy `ready_prompt.txt` into ChatGPT or your model UI. After the assistant responds, save the assistant reply into the same session file and commit both session + any code changes.

Notes: recent additions (RSVP & Virtual Access)
- RSVP frontend posts to `admin-post.php?action=oras_rsvp_update` and uses a per-event nonce `oras_rsvp_{EVENT_ID}`; verify via WP-CLI:

```bash
wp post meta get <event_id> _oras_rsvp_v1
wp user meta get <user_id> _oras_rsvp_event_<event_id>
```

- RSVP REST endpoints: GET `/oras/v1/rsvp/my` returns user's RSVPs; GET `/oras/v1/rsvp/event/{event_id}` returns event stats; implemented in `plugin/includes/Api/Rsvp.php`.

No `make` installed? Use direct commands

```bash
cd /home/rocco/projects/ORAS-Tickets
git config core.hooksPath .githooks
python3 scripts/generate_auto_session.py
python3 scripts/update_master_prompt.py latest-auto
python3 scripts/assemble_prompt.py --session docs/prompts/sessions/<your-session>.md > ready_prompt.txt
```

Notes and best practices

- Keep session files short and focused; the update script extracts Date, Author, Goal and up to 6 list items to create the snapshot.
- The master prompt remains the authoritative, human-curated top-level description of the project. Only use `update_master_prompt.py` for short session summaries.
- Do NOT include secrets or API keys in session files.

If you'd like, I can also:
- Add an executable Makefile entry (e.g., `make prompt-assemble SESSION=...`).
- Wire this into a small GitHub Action that updates `MASTER_PROMPT.md` when a session file is added to a PR.
