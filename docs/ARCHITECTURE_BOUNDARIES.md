# Architecture Boundaries (Do Not Break)

## Allowed plugin entry points
- plugin/oras-tickets.php
- plugin/includes/Bootstrap.php

## Active modules (current implementation)
- plugin/includes/Admin/*
- plugin/includes/Commerce/Woo/*
- plugin/includes/Frontend/*
- plugin/includes/Domain/*

## Current frontend rendering model (IMPORTANT)
- Tickets are rendered via `the_content` filter in:
  - `Frontend/Tickets_Display.php`
- Form submission handled via:
  - POST gatekeeping on `template_redirect`
  - Cart + checkout revalidation via Woo hooks
- This is intentional for Phase 2.x and not yet migrated to ET v2 views.

## Commerce rules (locked)
- WooCommerce is the sole commerce engine.
- Each ticket maps to a hidden, private, virtual Woo product.
- Capacity rules:
  - capacity > 0 → Woo manages stock
  - capacity = 0 → unlimited (no stock management)

## Hard rules
- No code outside plugin/ unless docs/ or tools/
- No modifying wp-env installed plugin code
- No ET+ provider or Ticket_Object patterns
- No external services or license checks

## Deferred architecture (future phases)
- ET provider registration
- ET v2 template/view integration
- Attendees, check-in, exports
