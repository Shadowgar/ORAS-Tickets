# ORAS-Tickets — Copilot Instructions (MUST FOLLOW)

You are working in the ORAS-Tickets WordPress plugin repo.

## Source of truth docs (read first)
- docs/CURRENT_STATE.md
- docs/ARCHITECTURE_BOUNDARIES.md
- docs/COPILOT_CONTEXT.md

## Non-negotiable rules
- ORAS-Tickets is an add-on. Do NOT modify TEC/Event Tickets/WooCommerce.
- No external services, license servers, or update engines.
- Follow WordPress Coding Standards, PHP 8.x.
- Do NOT introduce ET provider / Ticket_Object patterns unless explicitly instructed.
- Current frontend rendering uses `the_content` + `template_redirect` + Woo cart/checkout hooks (see CURRENT_STATE.md).
- Keep changes scoped to the requested phase and file list only.

## Behavior
- When generating code, cite which doc section you are following (briefly).
- If a request conflicts with the docs, stop and propose the smallest compliant alternative.
