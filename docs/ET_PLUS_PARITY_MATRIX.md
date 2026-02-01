# ET_PLUS_PARITY_MATRIX.md — ORAS-Tickets vs Event Tickets Plus Feature Parity

Purpose: map ET+ feature areas to ORAS-Tickets implementation phases, with acceptance criteria.
Designed to keep AI/dev work aligned and prevent “project drift”.

---

## Legend

- **ET** = Event Tickets (free)
- **ET+** = Event Tickets Plus
- **ORAS** = ORAS-Tickets (your add-on/plugin)
- Status:
  - ✅ Done
  - 🟡 Partial
  - ❌ Not started
  - 🚫 Out-of-scope (for now)

---

## Phase 0 — Baselines & constraints

| Area | ET | ET+ | ORAS Target | Status |
|------|----|-----|-------------|--------|
| Events source | TEC events (`tribe_events`) | same | Use TEC events | ✅ |
| Payments | Basic providers | Adds Woo/EDD + advanced | WooCommerce + Stripe gateway | ✅ |
| External services | none required | license/update infra | no external calls | ✅ |

Acceptance criteria:
- ORAS runs without external service calls.
- No theme edits required.

---

## Phase 1 — Ticketing MVP (sell tickets)

### 1.1 Ticket definition UI (event editor)
| Feature | ET | ET+ | ORAS Target | Status |
|--------|----|-----|-------------|--------|
| Add tickets UI | limited/basic | advanced | Repeatable ticket rows on event editor | ✅ Done |
| Ticket fields | name/price/capacity | start/end, description, SKU, etc. | name, price, capacity, sale start/end, description, hide_sold_out | ✅ Done (metabox) |
| Product linkage | provider-specific | Woo maps to products | event_id + ticket_key → product_id | ❌ |

Acceptance criteria:
- Editing an event shows ticket rows.
- Saving event persists mapping.
- Updates do not create duplicates.
- Price is decimal-safe.

Note: Phase 1.2 (Admin Ticket Metabox UI) is complete — the metabox implements the fields `name`, `price`, `capacity`, `sale_start`, `sale_end`, `description`, and `hide_sold_out` and persists to the plugin's versioned postmeta envelope. Frontend rendering, provider registration, and commerce/product sync are NOT started and are explicitly out-of-scope for Phase 1.2.

### 1.2 Front-end ticket module placement
| Feature | ET | ET+ | ORAS Target | Status |
|--------|----|-----|-------------|--------|
| Tickets appear on event page | yes | yes | tickets appear below event description globally | ❌ |
| No shortcode per-event | yes | yes | no manual shortcode | ❌ |
| Respect sale window | basic | advanced | show “sales start/ended” states | ❌ |
| Inventory prevents oversell | yes | yes | Woo stock enforced | ❌ |

Acceptance criteria:
- Any event with tickets shows tickets module automatically.
- Works with TEC v2 templates.
- No `the_content()` reliance.

### 1.3 Cart/checkout
| Feature | ET | ET+ | ORAS Target | Status |
|--------|----|-----|-------------|--------|
| Quantity selection | yes | yes | quantity per ticket + add to cart | ❌ |
| Woo checkout | via providers | strong | use Woo cart/checkout | ❌ |
| Hidden products | n/a | yes | ticket products hidden | ❌ |

Acceptance criteria:
- Adds correct product IDs to cart.
- Woo checkout completes.
- Stock decrements properly.

---

## Phase 2 — Attendees and reporting

| Feature | ET | ET+ | ORAS Target | Status |
|--------|----|-----|-------------|--------|
| Attendee list per event | limited | full | per-event attendee admin screen | ❌ |
| Export CSV | limited | full | export per event and per ticket type | ❌ |
| Filters | basic | advanced | ticket type, order status, date range | ❌ |
| Permissions | WP caps | adds roles | admin + shop_manager | ❌ |

Acceptance criteria:
- Admin can view attendees for an event.
- Export CSV matches displayed rows.
- Filtering is performant.

---

## Phase 3 — Per-attendee fields (registration)

| Feature | ET | ET+ | ORAS Target | Status |
|--------|----|-----|-------------|--------|
| Collect attendee info | minimal | full | per-ticket toggle + per-attendee fields | ❌ |
| Field types | basic | many | start with fname/lname/email + extensible | ❌ |
| Storage | provider-specific | robust | order item meta + attendee meta | ❌ |

Acceptance criteria:
- Buying N tickets collects N attendee datasets.
- Stored data appears in admin + export.

---

## Phase 4 — Check-in

| Feature | ET | ET+ | ORAS Target | Status |
|--------|----|-----|-------------|--------|
| Manual check-in | limited | full | check-in list per event | ❌ |
| Timestamp + checked-in-by | partial | yes | store both | ❌ |
| QR code scan | limited | yes | optional later | 🚫 |

Acceptance criteria:
- Admin can check-in/uncheck-in attendees.
- Audit info stored.

---

## Phase 5 — Emails / ticket delivery

| Feature | ET | ET+ | ORAS Target | Status |
|--------|----|-----|-------------|--------|
| Order emails include event info | partial | yes | inject event info into Woo emails | ❌ |
| Ticket attachment | optional | yes | later (PDF/ICS) | 🚫 |

Acceptance criteria:
- Completed order email includes event title/date/location/link.

---

## Optional modules (post Phase 5)

- Seating
- Waitlist
- Purchase rules
- Ticket presets
- Flexible/shared capacity

---

## Immediate priorities (new repo)

1) Establish local dev environment + tooling
2) Implement Phase 1 end-to-end:
   - admin ticket definition + Woo product sync
   - provider registration
   - automatic tickets display via ET/TEC views
   - checkout

