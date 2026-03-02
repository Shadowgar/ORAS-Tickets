# ORAS Events Add-On (repo: ORAS-Tickets) — Copilot Instructions

Authority (apply in this order):

- docs/MASTER_EXECUTION_TRACKER.md is authoritative for phase status, completion, and advancement gates.
- docs/CURRENT_STATE.md and docs/PROJECT_STATE.md define current enforcement mode and operational constraints.
- docs/NEXT.md is the immediate ordered queue within the active gate; do not skip order unless explicitly approved.
- docs/ARCHITECTURE_BOUNDARIES.md defines allowed code paths and prohibited implementation areas.
- docs/MASTER_DEVELOPMENT_PLAN.md is strategic baseline scope.
- docs/CHANGELOG.md is append-only; do not rewrite history.

Non-negotiables:

- Add-on only. Do NOT modify TEC, Event Tickets, or WooCommerce plugin code.
- WooCommerce is the only commerce engine.
- No external services, license servers, update engines, or SaaS calls.
- Follow WordPress Coding Standards.
- Use existing project namespaces and patterns (ORAS\Tickets\...).

Output requirements (for every implementation):

- List files to change before writing code.
- Prefer small, auditable changes.
- Include WP-CLI verification commands.
- If requirements are unclear, ask ONE question, then wait.

Token discipline:

- Do not paste large files.
- Quote only the minimum relevant snippets.
- Summarize before proposing code.
