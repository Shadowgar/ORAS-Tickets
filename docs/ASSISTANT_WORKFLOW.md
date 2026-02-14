Assistant Workflow — How to prepare prompts and operate with the assistant

Overview

This doc describes the minimal in-repo workflow to create reproducible prompts, run an assistant-driven change, and record outcomes.

Quick Steps

1. Prepare a change using `prompts/TEMPLATE_change.md`.
2. Save a session snapshot in `prompts/sessions/YYYYMMDD-HHMM-summary.md`.
3. Copy `prompts/MASTER_PROMPT.md` into the ChatGPT prompt area and append your session file.
4. Run the assistant and follow the patch instructions returned.
5. After changes are committed, attach the session file to the PR/issue and commit both.
6. Run local checks: `composer phpstan` and PHPCS. Note results in the session file.

Detailed guidance

- Minimal prompt hygiene:
  - Goal first, followed by context, constraints, file list, acceptance criteria.
  - Keep the context short (3-6 bullets) and include only essential files and commands.

- Recording sessions:
  - Each session file must include the resulting assistant reply and the commit/PR hash.
  - Keep session files small; include diffs only when needed.

- CI and verification:
  - After merging assistant-driven change, run the standard CI checks (PHPStan, PHPCS).
  - If any check fails, create a follow-up session describing the remediation.

- Security:
  - Never store API keys in the repo. Use environment variables or CI secrets.

Optional helper script

- If you'd like, we can add `tools/assemble_prompt.py` to automatically render the `MASTER_PROMPT.md` with session variables.

Tips

- Use session files as the single source of truth for what was asked.
- Commit session files before you call the assistant so the instruction history exists in the repo.

If you want, I can now:
- Add `tools/assemble_prompt.py` to render prompts automatically.
- Create a simple Issue template that includes a `Prompt for assistant` section.
