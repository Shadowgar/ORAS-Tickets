Sessions README

Purpose

Keep short, dated session snapshots for each assistant interaction. These are intentionally human-readable and committed so you can reproduce the exact instructions that generated any change.

Filename convention

- `YYYYMMDD-HHMM-summary.md` (e.g. `20260214-1430-waitlist-add.md`)

Required sections for each session file

- Date: ISO datetime
- Author: who prepared the prompt
- Master prompt snapshot: either a link to `prompts/MASTER_PROMPT.md` or the filled sections
- Top-level intent: 1-2 line summary
- Files referenced: list of file paths
- Commands run locally before/after (e.g., `composer phpstan`)
- Assistant reply summary: 1-3 lines
- Resulting commit/PR link or hash (after merge)

Why this helps

- Traceability: know exactly what instruction produced a code change.
- Reproducibility: rerun the same prompt against the repository state.
- Auditing: reviewers can see the instruction set used.

Procedure

1. Fill `prompts/TEMPLATE_change.md` and save as `prompts/sessions/YYYYMMDD-HHMM-summary.md`.
2. Append this session file to the master prompt when uploading to ChatGPT UI.
3. After the assistant finishes, paste the assistant response into the same session file and commit.
