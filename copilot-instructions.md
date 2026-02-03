# ORAS Events Add-On (repo: ORAS-Tickets) — Copilot Instructions

Authority:

- docs/CURRENT_STATE.md is the single source of truth for phase + scope.
- docs/NEXT.md is the current objective. Do not work outside it.
- docs/ARCHITECTURE_BOUNDARIES.md defines allowed code paths.
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
