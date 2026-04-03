# ORAS-Tickets Evaluation Controller

## Output Rule
All passes must write results to the specified file.
Chat output must be minimal (confirmation only).
Large outputs in chat are invalid.

## Execution Rule
The AI must execute ONLY the explicitly requested PASS.
If it proceeds beyond that PASS, the output is invalid.

## Global Rules
- Code = runtime truth
- docs/CURRENT_STATE.md = roadmap truth
- If docs/CURRENT_STATE.md conflicts with other roadmap/planning docs, docs/CURRENT_STATE.md wins
- No guessing. If exact evidence cannot be found, say: NO EVIDENCE FOUND
- Do not jump ahead to later phases
- Do not implement code during evaluation
- Do not modify files outside docs/evaluation/2026-03-22/ unless explicitly instructed
- Every finding must include exact evidence:
  - file path
  - class name
  - function/method
  - hook/filter/action
  - REST route
  - CPT/taxonomy/meta key
  - DB table or option key

## Classification Labels
Use only:
- COMPLETE
- PARTIAL
- MISSING
- EXTRA
- BLOCKER
- OUT OF SCOPE

Definitions:
- COMPLETE = exists in code and aligns with docs/CURRENT_STATE.md
- PARTIAL = exists in code but incomplete, or documented but not fully implemented
- MISSING = required by docs/CURRENT_STATE.md but absent in code
- EXTRA = implemented but not called for by current approved scope
- BLOCKER = implementation/design issue that prevents approved next-phase work
- OUT OF SCOPE = not part of the currently approved phase path

## PASS 1 — CURRENT STATE
Map:
- plugin bootstrap path
- modules/services with file paths
- CPTs, taxonomies, meta keys
- WooCommerce hooks and order/item meta
- REST routes
- admin pages
- frontend entry points
- storage surfaces:
  - post meta
  - user meta
  - options
  - custom tables

Rules:
- Evidence required for every item
- No recommendations
- No roadmap analysis
- Stop after this pass

## PASS 2 — ROADMAP GAPS
Compare code against:
- docs/CURRENT_STATE.md
- locked/current phase docs if clearly identifiable

Rules:
- Use classification labels only
- Every classification must cite both code evidence and doc evidence
- docs/CURRENT_STATE.md wins if docs conflict
- No refactor plan yet
- Stop after this pass

## PASS 3 — DATA MODEL
Analyze:
- events
- tickets
- attendees
- RSVP/waitlist
- Woo order linkage
- pricing/capacity data surfaces

Rules:
- Cite meta keys, tables, relationships, and file paths
- No recommendations unless needed to explain a BLOCKER
- Stop after this pass

## PASS 4 — INTEGRATIONS
Analyze:
- WooCommerce integration points
- Stripe assumptions through WooCommerce
- QuickBooks integration boundaries

Rules:
- QuickBooks is downstream reporting/integration only
- QuickBooks must not define the core ticketing domain
- Cite exact files, hooks, and data dependencies
- Stop after this pass

## PASS 5 — REFACTOR PLAN
Based only on prior passes.

Rules:
- minimal diff
- no rewrites
- backward compatible where practical
- phase-aware
- no SaaS
- no telemetry

For each recommendation include:
- problem
- evidence
- affected files
- affected classes/functions
- risk
- required now or later

Stop after this pass

## PASS 6 — NEXT STEPS
Create a strict ordered execution plan.

Each step must include:
1. Step number
2. Goal
3. Why now
4. Exact files to inspect/modify
5. Exact classes/functions/hooks
6. Expected behavior after change
7. Verification:
   - WP-CLI
   - admin UI
   - frontend
   - Woo order checks
8. Rollback/risk note

Rules:
- No vague steps
- No architecture drift
- End after this pass