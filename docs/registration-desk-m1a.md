# Registration Desk M1A Backend Contract

M1A is an inactive-by-default, backend-only foundation for admitting an existing direct individual online registration. It contains no event-specific configuration, no production event names, no final volunteer UI, and no walk-in creation.

## Storage and migration

`Registration_Desk\Schema::maybe_upgrade()` installs additive schema version 1 through WordPress `dbDelta()`. Activation also calls the repeat-safe installer. Existing tables are never dropped or recreated, and successful installation records `oras_registration_desk_schema_version` only after all four prefixed tables exist and report the InnoDB engine.

The four desk-owned tables are:

- `{$wpdb->prefix}oras_event_registrations`: stable registration UUID and desk option UUID; event, classification, current eligibility/provenance projection, configuration revision, guarded record version, and nullable source key/order/item/unit fields. A unique `(event_id, source_key)` identity makes online projection repeat-safe while allowing source-null future complimentary or walk-in records.
- `{$wpdb->prefix}oras_event_attendees`: stable attendee UUID and `(registration_id, slot_key)` identity. M1A uses `individual-1`; confirmed attendee identity remains separate from purchaser contact data.
- `{$wpdb->prefix}oras_event_attendance`: site-local attendance date and UTC action timestamps, original actor/station/operator attribution, reversal fields, and guarded record version. A unique `(event_id, attendee_id, attendance_local_date)` key prevents simultaneous duplicate attendance.
- `{$wpdb->prefix}oras_event_audit`: one append-only row per request UUID, binding operation, event, actor, station, normalized payload hash, configuration revision, result references/JSON, meaningful changes, and UTC timestamp.

Mutating attendance operations use a database transaction that covers attendee/attendance mutation and its audit result. A callback error or failed commit rolls back the unit. Replays compare the complete request binding and return both the historical result and current attendance state.

## Configuration and access

The feature is disabled when event metadata is absent. An administrator explicitly selects one active TEC event and saves versioned `_oras_registration_desk_v1` configuration. Configuration publication and active-event selection share a global database lock; the combined administrator save performs durable revision comparison, configuration write, and active-option write in one transaction, then invalidates caches after commit or rollback. Each option has a stable UUID, source product mappings, classification, validity type, `available_for_new`, and independent `existing_access_valid` state. The code contains no AstroBlast, Pro-Am, or other production-event rules.

The `oras_registration_desk` role receives only:

- `read`
- `oras_tickets_use_registration_desk`
- `oras_tickets_admit_registration_desk`

Administrators also receive `oras_tickets_manage_registration_desk`. The shared role does not receive legacy check-in, event, attendee, report, export, membership, user, commerce, or settings capabilities. Restricted accounts are confined to the protected desk landing route and `/oras-tickets/v1/registration-desk/*`; other REST, wp-admin, front-end, authenticated admin-AJAX, and WC-AJAX entry points are rejected or redirected server-side before their dispatchers execute.

A station token is signed with the WordPress auth salt and binds a unique station UUID, shared user ID, WordPress login session, active event, configuration revision, operator label, issuance, and expiry. Tokens are device-local inputs; changing one device's label does not mutate another. Logout/session change, event change, configuration change, expiry, or capability revocation prevents protected use.

## Source and eligibility contract

`Source_Adapter` uses WooCommerce getters only. It loads a direct order/item association and derives immutable item event ID, product ID, quantity/refunded quantity, order status, and contact/search evidence. It never saves an order, item, product, payment, or refund.

Resolution requires the item event to equal the target event and exactly one explicit current configuration mapping for the immutable product ID. Historical ticket index and label are retained only as evidence and never classify a purchase.

- Individual + full-event + processing/completed: supported and normally eligible.
- On-hold: supported only through explicit unpaid admission; this does not mark the order paid.
- Pending/failed: inactive.
- Cancelled/fully refunded: revoked.
- Partial refund with ambiguous covered unit: review required.
- Family, one-day, cross-event, conflicting mapping, unclassified, and unknown/custom status: unsupported or review required in M1A.
- `available_for_new=false` does not revoke an existing registration. `existing_access_valid=false` does.

Automatic discovery is listener-only: persisted Woo order-status transitions project direct items for the active event without a request-time broad scan. Administrator recovery traverses all relevant Woo orders in ascending ID pages, including pages with no event matches. Its opaque continuation is signed and binds event, configuration revision, page size, source count, and highest order ID. Persistent `not_started`, `in_progress`, `complete`, and `failed` coverage states retain unresolved failure identity; unrelated listener success cannot erase a failure, and a changed source snapshot invalidates the old cursor.

Refresh updates only source-owned registration projection fields. It preserves registration UUIDs, attendee identity, attendance, audit, and source-null records. Admission requires an active stored row plus the exact current event/order/item, exact current option UUID, and a positive source unit still within the current quantity. Quantity reductions revoke excess rows without deleting or renumbering them; remaps become review-required.

## REST contract

All successful responses use `Cache-Control: no-store, private`. Search/detail expose operational registration UUIDs, masked contact data, and explicit coverage limitations; order IDs are not route identities.

| Route | Permission | Inputs | Success output |
|---|---|---|---|
| `POST /oras-tickets/v1/registration-desk/station` | `oras_tickets_use_registration_desk` | `operator_label` | station token, event ID/title, configuration revision, label |
| `POST /oras-tickets/v1/registration-desk/project` | administrator desk-management capability + station | opaque `continuation`, `limit` | scanned orders, matching items, continuation, projections, and coverage state |
| `GET /oras-tickets/v1/registration-desk/registrations` | desk-use capability + station | `q` (minimum two characters) | event-scoped masked matches and current coverage state |
| `GET /oras-tickets/v1/registration-desk/registrations/{registration_uuid}` | desk-use capability + station | operational UUID | masked registration, attendee slots, coverage limitations |
| `POST /oras-tickets/v1/registration-desk/registrations/{registration_uuid}/confirm-and-check-in` | desk-admit capability + station | request UUID header/body, actual first/last name, site-local date, explicit-unpaid boolean | replay flag, historical result, current attendance |
| `GET /oras-tickets/v1/registration-desk/attendance/recent` | desk-use capability + station | optional bounded `limit` | active-event attendance records |
| `POST /oras-tickets/v1/registration-desk/registrations/{registration_uuid}/attendees/{attendee_uuid}/reverse` | administrator desk-management capability + station | request UUID, local date, expected record version, reason | replay flag, audited reversal result, current attendance |

Protected routes require `X-ORAS-Desk-Station`. Mutations also require a UUID in `X-ORAS-Desk-Request` (or `request_uuid`). Representative safe errors include inactive desk, invalid/expired/stale station, changed event/config/date, missing or inactive registration, changed option or missing source unit, unavailable/contradictory source, review-required or ineligible source, explicit-unpaid confirmation required, attendee conflict, request binding conflict, audit persistence failure, reversed attendance, and stale reversal version.

Immediately before admission, the service verifies the current active event/configuration revision, submitted site-local date, inclusive event date range, direct source association, fresh Woo status/refund evidence, supported individual/full-event classification, current option validity, and actual attendee name. There is no overnight grace period.

## Exact write boundary

Desk operations may write only the four desk tables, the active-event/configuration settings, role/capability reconciliation, and stateless station response data. Configuration and synthetic staff users are administrator/test setup, not attendee creation.

Desk operations do not create or modify Woo orders/items, status, billing, notes, metadata, payments, refunds, products, prices, stock, capacity, Stripe state, QuickBooks payloads/queues/retries/ledger calls, WordPress attendee/customer users, memberships, subscriptions, RSVP records, or purchase/account mail. Existing checkout hooks remain registered; the qualification harness exercises normal item snapshot and paid-order capacity behavior with QuickBooks disabled/dry-run and all delivery/remote transports intercepted.

## Qualification harness

`scripts/run-registration-desk-integration-checks.sh` resolves wp-env configuration and Compose ownership from `/home/rocco/projects/oras-wp-env`, then applies a temporary Compose overlay only to `tests-wordpress` and `tests-cli`. It refuses unknown repository/toolchain identities, wrong project/database/volume/mount/URL identities, mismatched disposable markers, dirty feature code, or missing transport guards. The one-time `--initialize-disposable-marker` mode may establish a missing marker only after the designated project, isolated test database volume, loopback URL, exact feature mounts, guards, and mounted-code digest have all been verified.

Before mutation, the runner snapshots ordinary development container identity, state, mounts, environment, and database volume. It also captures test-service state, active plugins, ORAS settings, and WooCommerce storage options. Cleanup restores the exact plugin/settings state, recreates only the test application and CLI containers from the designated base Compose file, returns all three test services to their original state, and verifies the ordinary development snapshot is unchanged.

The test-only must-use plugin blocks all external HTTP except explicit WordPress.org package download setup, intercepts all mail, and records bounded secret-free observations. The existing Intuit blocker remains active. Tests use synthetic WP/WooCommerce/TEC data only.

Qualification covers schema repetition/engines/source-null storage, listener and signed recovery discovery, projection and active-event identity, supported/unsupported classification, exact option/unit admission, actual attendee confirmation, two station sessions, capability/endpoint bypasses, on-hold admission, cancellation after projection, partial refund ambiguity, date/config/event changes, transactional configuration rollback, projection preservation, disabled versus revoked access, audit fault retry, replay/conflict/reversal, and protected-surface fingerprints. Two simultaneous `docker exec` WP-CLI workers use separate PHP processes and database connections against the same attendee/date; the database must contain one attendee, one daily attendance row, and two request audit results. The guarded runner uses the pinned `/home/rocco/projects/oras-wp-env` toolchain and supports explicit `--mode=legacy` and `--mode=hpos`, verifies the requested authoritative Woo order store, disables compatibility synchronization for the HPOS run, and restores the prior test option state on exit.

## M1A.2 qualification evidence

Qualification on 2026-09-17 used wp-env 10.39.0 from `/home/rocco/projects/oras-wp-env`, configuration `/home/rocco/projects/oras-wp-env/.wp-env.json`, generated install `/home/rocco/wp-env/a3544f17121d4efebaa9174fe1458a62`, Compose project `a3544f17121d4efebaa9174fe1458a62`, URL `http://localhost:8889`, database `tests-wordpress@tests-mysql`, and volume `a3544f17121d4efebaa9174fe1458a62_mysql-test`. The feature mount was the isolated worktree's `oras-tickets/` at tested commit `283e3e4e424a4fa00a3a1066b3211e4aaf70ecce`, with mounted tree digest `c19c7e10e6ca8829c971e0c2a5cfe4f84879efed5d32326e097fba3ff82ed5a9`.

The designated database initially lacked the Registration Desk marker. `--initialize-disposable-marker --verify-environment-only` created `oras-registration-desk-m1a-a3544f17121d4efe` only after the runner verified the distinct development/test volumes, loopback URL, exact project/container labels, both outbound guards, feature mounts, and code digest. Subsequent runs only verified that marker.

Both acceptance commands passed:

```text
scripts/run-registration-desk-integration-checks.sh --mode=legacy
scripts/run-registration-desk-integration-checks.sh --mode=hpos
```

The legacy run explicitly reported legacy storage active. The HPOS run explicitly reported authoritative HPOS active after setting compatibility synchronization off. Both passed the complete WordPress/WooCommerce/TEC suite, authenticated admin-AJAX and WC-AJAX probes, configuration and attendance races, nonfinancial/no-account side-effect snapshots, checkout controls, core regressions, and bootstrap regressions.

Two fail-closed attempts preceded the passing commands and were recorded rather than concealed: the designated test database contained an active older ORAS Tickets copy that loaded before the feature mount, and the integration harness retained the old worktree URL. The runner now captures/restores `active_plugins` while temporarily disabling conflicting ORAS Tickets copies, and the harness receives the runner-verified dynamic URL.

Differential PHPCS against `a6062bc703394193ef73fbce60742bffd9843679` reported 0 introduced diagnostics after correcting the 34 reviewed diagnostics. The same run reported 2,481 legacy differential diagnostics and whole-plugin inherited debt of 41,922 errors plus 762 warnings across 91 files; repository-wide style cleanliness is not claimed.

After each acceptance run, the runner restored the test plugin list, ORAS settings snapshot, `woocommerce_custom_orders_table_enabled=no`, missing `woocommerce_custom_orders_table_data_sync_enabled`, base plugin mounts, and the original stopped state of `tests-mysql`, `tests-wordpress`, and `tests-cli`. The ordinary `mysql`, `wordpress`, and `cli` containers remained stopped with identical identities, mounts, environment, and development database volume. The separate worktree-derived project `e3ae9621ee9cf4297eb317ed90aded38` retained all six of its containers running and was not used, stopped, recreated, or deleted.

## Deferred scope and limitations

M1A deliberately does not implement walk-ins, complimentary creation, family members, one-day admission, cross-event access, joint capacity, payment collection/labels for new registrations, final volunteer screens, manager PINs, legacy imports, offline synchronization, exports, printing, or production event configuration. Search coverage is explicitly incomplete until the deferred source types are implemented. Operator labels are informational attribution, not separately authenticated people. Production access and production-data validation remain deferred.
