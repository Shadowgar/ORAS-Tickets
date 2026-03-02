# Board Dashboard Design Pack (Phase 9.5 / 10.4)

Date: 2026-03-02
Owner: ORAS-Tickets (design-only artifact)
Status: Draft complete (implementation remains gated by Phase 5 closure)

## Scope
Define the board-only dashboard contract without adding Phase 6+ runtime behavior.

This pack is limited to:
- KPI contract
- Members Hub-aligned information architecture
- capability model and export permissions

This pack does not include:
- production rollout changes
- new background jobs
- non-board UI surfaces

## KPI Contract (Initial)

### Membership
- Active paid members (count)
- 30-day new members (count)
- 30-day expirations/churn (count)
- Renewal pressure (next 30 days expiring)

### Ticketing
- Tickets sold (period total)
- RSVP yes / waitlist counts
- Waitlist conversion rate
- Sellout pressure (events near capacity)

### Financial
- Ticket revenue (gross)
- Membership revenue (gross)
- Combined gross revenue
- Refund count + refund impact amount

### Operations / Risk
- Failed payment anomalies (count)
- Queue pressure indicator (waitlist backlog)
- Capacity risk indicator (near-full events)
- Data freshness timestamp per metric group

## Information Architecture (Members Hub aligned)
1. Header row: date range + freshness + export actions.
2. Executive KPI cards: membership, ticketing, financial, operations (4-card top row).
3. Trend/context row: period deltas and notable changes.
4. Top events block: attendance + gross/net snapshot.
5. Alerts/watchlist block: capacity, waitlist, payment anomalies.
6. Export section: board packet slices (CSV now; PDF-ready schema later).

## Capability and Access Model
- View capability: board-only capability gate (to be finalized in implementation phase).
- Export capability: separate board-export capability gate.
- All dashboard routes must enforce capability checks before rendering or exporting.
- No public exposure and no member-level fallback rendering for this surface.

## Data Contract Shape (Implementation Target)
- Deterministic response envelope versioned under ORAS conventions.
- Sectioned payload:
  - `membership`
  - `ticketing`
  - `financial`
  - `operations`
  - `top_events`
  - `alerts`
  - `meta` (range, generated_at, source freshness)

## Guardrails
- Respect add-on boundaries in `docs/ARCHITECTURE_BOUNDARIES.md`.
- Use ORAS-Tickets for ticketing/finance logic; Member Hub remains presentation-first.
- Avoid external SaaS dependencies.
- Keep implementation blocked until Phase 5 closure criteria are satisfied.

## Implementation Readiness Checklist
- [x] KPI domains defined.
- [x] Information hierarchy defined.
- [x] Capability/export model defined.
- [ ] API contract finalized against existing report aggregators.
- [ ] UI wireframes mapped to current Members Hub components.
- [ ] Build task opened only after Phase 5 gate release.
