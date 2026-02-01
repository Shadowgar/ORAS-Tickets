# Event Tickets (free) — Engine Architecture Guide (Code-Derived)

Source reviewed:
- `event-tickets/` (ET 5.27.4), primarily `event-tickets.php`, `src/Tribe/Main.php`, `src/Tribe/*`, `src/Tickets/*`, `src/views/*`, `src/template-tags/*`

Goal of this document:
- Explain how the Event Tickets plugin is structured so another AI/dev can build an add-on that behaves like ET+.
- Identify the key extension points (providers, views, service providers, template system, data flow).

---

## 1) Boot process (high-level)

Entry file:
- `event-tickets.php`

Key steps:
1. Defines constants (`EVENT_TICKETS_DIR`, `EVENT_TICKETS_MAIN_PLUGIN_FILE`)
2. Loads PHP min version guard (`src/functions/php-min-version.php`)
3. Loads Composer autoload (`vendor/autoload.php`)
4. Registers a deprecated autoloader (`TEC\Tickets\Deprecated_Autoloader`)
5. Requires `src/Tribe/Main.php`
6. Boots: `Tribe__Tickets__Main::instance()->do_hooks()`

The “real” initialization happens after WordPress loads plugins:
- `Tribe__Tickets__Main::do_hooks()` wires `plugins_loaded` callbacks, activation hooks, and later calls `plugins_loaded()` which eventually triggers `bind_implementations()`.

---

## 2) Container + Service Provider model (Tribe Common / StellarWP)

Event Tickets uses the Tribe container functions:
- `tribe_singleton( key, class|instance, [methods] )`
- `tribe_register_provider( ServiceProvider::class )`
- `tribe( key )` to fetch from container

Central place: `Tribe__Tickets__Main::bind_implementations()`

This method:
- Registers the plugin singleton: `tribe_singleton('tickets.main', $this)`
- Registers core Tickets service providers and feature providers, e.g.
  - `Tribe__Tickets__Service_Provider`
  - `TEC\Tickets\Provider` (Tickets Commerce providers)
  - ORM provider, REST providers, Blocks controller
  - Views V2 provider for events tickets views
  - Admin home/settings/manager providers
  - Promoter provider
  - Admin provider

Important “extension point”:
- Action: `tec_tickets_bound_implementations`
  - This is where an add-on can bind/override implementations in the container.

---

## 3) Providers concept (ticketing backends)

Event Tickets defines the notion of “ticket providers”:
- RSVP provider
- Commerce providers (Tickets Commerce, Woo, EDD, etc. — depending on installed add-ons)

Providers implement a common surface:
- Create tickets
- Get tickets for a post/event
- Handle stock/inventory rules
- Issue attendees on purchase
- Check-in state management

Core base(s) and related:
- Legacy provider support exists (see `legacy_provider_support` references in `Tribe__Tickets__Main`)
- Modern code is split between:
  - `src/Tribe/*` (legacy-ish integration layer)
  - `src/Tickets/*` (newer modular code under `TEC\Tickets\...` namespaces)

**Add-on strategy:**
- Implement a provider that can supply tickets from your own storage (e.g., `_oras_tickets` + Woo product IDs), but do NOT force provider selection globally for non-event posts.

---

## 4) Data model (conceptual)

ET operates on three main record types:

### 4.1 Ticket definitions
Stored per post/event.
- In Woo providers, tickets map to Woo products with extra meta.

### 4.2 Attendee records
Created per ticket quantity purchased.
Contains:
- event/post ID
- order reference
- purchaser/attendee details
- ticket type/product reference
- check-in status and timestamps

### 4.3 Order linkage
Provider-specific:
- Woo: order + order item meta is the source of truth for issuance.
- ET uses repository/query layers to render attendees lists and exports.

---

## 5) Views / Template system (critical for “show on event page”)

ET (and TEC) use a views system with template overrides.
Namespace for templates:
- ET sets `template_namespace = 'tickets'` (in `Tribe__Tickets__Main`)

Theme override path pattern:
- `your-theme/tribe/tickets/...`

ET view loading is managed via Tribe Common template utilities; a lot of rendering is done through:
- `src/views/*` (v2 templates live here)
- `src/template-tags/*` (helpers used by themes/templates)

Key idea:
- ET renders its “tickets module” on TEC single pages through its own views and template tags, not through `the_content`.

**Add-on strategy:**
- Override specific ET template(s) using Tribe template filters (e.g., `tribe_template_file`) or register your own views under `tribe/tickets/...` namespace.
- Avoid “guessing hooks” and instead hook where ET already includes its tickets views.

---

## 6) Blocks and editor integration

ET includes editor UI and blocks:
- `src/Tickets/Blocks/`
- `src/modules/blocks/`
- `src/admin-views/editor/`

Add-on strategy:
- If matching ET+ feature parity, implement enhancements via service providers and/or block extensions rather than hacking post content.

---

## 7) REST APIs and admin pages

ET registers:
- REST v1 and editor REST service providers
- Admin manager/home/settings providers

Add-on strategy:
- Add your own service provider(s) and register routes or admin screens under the same pattern:
  - create provider class
  - `tribe_register_provider(...)` during `tec_tickets_bound_implementations` or after `tec_tickets_fully_loaded`

---

## 8) Relevant hooks/actions for add-ons

From ET core:
- `tec_tickets_bound_implementations` — bind/override container services
- `tec_tickets_fully_loaded` — safe point once plugin is fully loaded

From Tribe Common template layer:
- `tribe_template_before_include:{slug}`
- `tribe_template_after_include:{slug}`
- `tribe_template_file` filter (used to override resolved template files)

---

## 9) Practical roadmap for “ORAS Tickets Pro” (behavioral parity, not code copying)

Recommended decomposition:

1. Keep your existing ticket definition storage and Woo product sync (already built)
2. Build a thin ET integration layer that:
   - declares a provider (or a provider adapter)
   - places the tickets UI using ET/TEC view system
3. Add Phase 2 features:
   - attendee screen, export, filters
   - derived from Woo orders + your ticket mappings
4. Add Phase 3:
   - per-attendee registration fields
   - order item meta schema + admin UI
5. Add Phase 4:
   - check-in state, QR generation, check-in UI
6. Add Phase 5:
   - email enhancements (hook Woo emails, add event context)

---

## 10) Notes about the ET+ code you provided

The `event-tickets-plus.php` file in the provided zip contains injected code that:
- writes PUE install keys via `update_option(...)`
- intercepts license validation HTTP calls via `pre_http_request`

That injected block is not part of the Event Tickets “engine architecture” and should not be used as an implementation reference.

