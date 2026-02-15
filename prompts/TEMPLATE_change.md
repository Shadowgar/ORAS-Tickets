TEMPLATE: Change Request

Fill this template for any change you want the assistant to make.

---
Goal:
- (One-sentence goal)

Context:
- Repo: /home/rocco/projects/ORAS-Tickets
- Branch: (branch name)
- Relevant files: (comma-separated list)
- Current static checks: (phpstan/phpcs status)

Constraints:
- Do not modify unrelated files.
- Keep patches minimal.
- No credentials or API keys in repo.

Files to edit:
- `path/to/file.php` — short intent
- `path/to/other.php` — short intent

Description of changes:
- Bullet 1
- Bullet 2

Acceptance criteria:
- `composer phpstan` returns no errors
- PHPCS reports fewer than X new issues (or none)
- New behavior: (short sentence)

Commands to run locally to validate:
```
cd /home/rocco/projects/ORAS-Tickets
composer phpstan
./vendor/bin/phpcs --standard=phpcs.xml plugin/
```

Follow-ups (what to do after merge):
- Run full static analysis in CI
- Tag release

Notes (recent additions):
- For RSVP frontend changes use `admin-post.php?action=oras_rsvp_update` and per-event nonce `oras_rsvp_{EVENT_ID}`.

---
Example usage:
- Fill the template, save as `prompts/sessions/2026-02-14-add-waitlist.md`, then paste the filled MASTER_PROMPT + session file into ChatGPT.
