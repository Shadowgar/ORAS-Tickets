# Architecture Boundaries (Do Not Break)

## Allowed plugin entry points
- plugin/oras-tickets.php
- plugin/includes/Bootstrap.php

## Phase 1 modules (will be added)
- plugin/includes/Admin/*
- plugin/includes/Commerce/Woo/*
- plugin/includes/ET/*
- plugin/includes/Frontend/*
- plugin/includes/Support/*

## Rules
- No code outside plugin/ unless it is docs/ or tools/
- No modifying wp-env installed plugin code
- No copying ET+ code verbatim; implement behaviors using ET/TEC public architecture
- All view rendering should be via ET v2 templates or TEC v2 hooks, not shortcodes
