# ET_CODEMAP.md — Event Tickets (Free) Code Map (for ORAS-Tickets add-on)

Purpose: give an AI (or new dev) a fast, practical map of **where to look** in Event Tickets (free) to understand:
- boot/registration lifecycle
- provider/module architecture
- view/template plumbing
- where tickets are rendered on single events
- attendee/order/check-in data flow surfaces
- where to hook/extend safely

Scope: Event Tickets (free) as shipped (your `event-tickets.zip`), not The Events Calendar.

---

## 0) Read-first sequence (recommended)

1) `event-tickets.php` — plugin entry, constants, autoload, main class instantiation
2) `src/Tribe/Main.php` — main singleton, binding providers, hook points
3) `src/Tribe/Tickets.php` — “ticket facade” methods used everywhere (`get_all_event_tickets`, provider selection, etc.)
4) `src/template-tags/tickets.php` — functions themes/templates call (`tribe_events_has_tickets`, etc.)
5) `src/views/v2/` — current ticket UI templates (what actually renders on pages)
6) `src/Tribe/JSON_LD/Type.php` — where tickets presence impacts JSON-LD output
7) `src/Tickets/` + `src/modules/` — blocks and modern module code (useful later)

---

## 1) Boot and lifecycle

### 1.1 Entry
- **`event-tickets.php`**
  - Defines constants and loads Composer autoload.
  - Requires `src/Tribe/Main.php`.
  - Calls `Tribe__Tickets__Main::instance()->do_hooks()`.

### 1.2 Main singleton
- **`src/Tribe/Main.php`**
  - Central coordinator. Key responsibilities:
    - `do_hooks()` → wires WP hooks.
    - `bind_implementations()` → binds services/providers into Tribe container.
    - defines template namespace `tickets` for view resolution.
  - Important actions for add-ons:
    - `tec_tickets_bound_implementations`
    - `tec_tickets_fully_loaded`

**Add-on integration guidance**
- Prefer registering your add-on at/after `plugins_loaded` and then hooking into:
  - `tec_tickets_bound_implementations` (bind/override container services)
  - `tec_tickets_fully_loaded` (safe point to register your providers/views)

---

## 2) Ticket provider/module architecture

### 2.1 Provider concept
Event Tickets treats ticket backends as “providers” (modules).
Providers must conform to expected interface patterns used by `Tribe__Tickets__Tickets`.

**Hotspot**
- **`src/Tribe/Tickets.php`**
  - `get_all_event_tickets( $post_id )` iterates providers and calls `::get_instance()->get_tickets()`
  - `get_event_ticket_provider( $post_id )` decides provider selection.

**Critical requirement**
- A provider class must implement:
  - `public static function get_instance()`
  - `public function get_tickets( $post_id, $context = null )`

Failing this breaks JSON-LD and sitewide checks.

### 2.2 Where providers are registered
Providers get bound/registered via service providers and container bindings in:
- **`src/Tribe/Main.php`** (`bind_implementations()`)
- plus various provider classes under `src/Tickets/` and `src/modules/`

**Add-on target**
- Register your provider in the same pattern (after ET is fully loaded) and keep it safe on non-event pages.

---

## 3) Views/templates plumbing

### 3.1 Template namespace
ET uses a template namespace `tickets`:
- Templates resolve under:
  - plugin: `event-tickets/src/views/...`
  - theme override: `your-theme/tribe/tickets/...`

### 3.2 V2 ticket UI
- **`src/views/v2/`**
  - Current rendering surface for tickets UI.
  - Key file:
    - `src/views/v2/tickets.php` (name may vary slightly by version) — main tickets module template.

### 3.3 Template tags (bridges)
- **`src/template-tags/tickets.php`**
  - Functions called by TEC templates and themes:
    - `tribe_events_has_tickets( $post )`
    - other helpers used to decide rendering.

**Engineering target for ORAS-Tickets add-on**
- Implement an ET-compatible provider that returns tickets from ORAS storage
- Ensure TEC templates “see” tickets as present
- Ensure the ET v2 tickets view renders *your* markup via template override or hook

---

## 4) JSON-LD and sitewide ticket checks

- **`src/Tribe/JSON_LD/Type.php`**
  - Called on many pages (`wp_head`) and triggers ticket checks.
  - Provider architecture must be safe for all pages.

---

## 5) Attendees, orders, and check-in surfaces (Phase 2–4)

Useful directories to examine:
- `src/Tribe/Attendees/` (if present)
- `src/Tribe/REST/`
- `src/modules/` (block-related attendee UI, if any)

**For ORAS**
- Use WooCommerce as source-of-truth:
  - order → order items → quantities → derive attendee rows
- Keep deterministic mapping:
  - event_id + ticket_key → product_id
  - order_id + item_id + attendee_index → attendee_id (or derived hash)

---

## 6) Practical extension points for ORAS-Tickets

Preferred safe points:
- Action: `tec_tickets_fully_loaded` — register ORAS provider
- Filter: Tribe template resolution (to override v2 tickets view file)
- Avoid broad logging/hook dumping.

