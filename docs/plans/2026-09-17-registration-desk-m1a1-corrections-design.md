# Registration Desk M1A.1 Corrections Design

## Scope

M1A.1 corrects the five findings from the focused M1A implementation review. It does not add walk-ins, payment collection, family or one-day attendance, cross-event access, final UI, offline behavior, or any later milestone.

The Registration Desk may continue to write only desk registrations, attendees, attendance, audit/request results, desk configuration and coverage state, role definitions, and permitted staff-session state. WooCommerce orders and items, payments, refunds, products, capacity, QuickBooks, WordPress attendee accounts, memberships, subscriptions, and mail remain outside the write boundary.

## Account confinement

Restricted Registration Desk accounts will be rejected before WordPress admin-AJAX or WooCommerce WC-AJAX dispatch. The desk uses REST and has no AJAX allowlist. The early denial applies only to the dedicated restricted role, leaving ordinary users and administrators unchanged. Desk REST, WordPress authentication, and logout continue to operate normally.

Qualification will install disposable authenticated probe handlers which would mutate a test marker if dispatched. Real HTTP requests must receive the desk-specific denial before either probe runs and before protected commerce state changes.

## Discovery and recovery

Online discovery has one automatic mechanism: a narrowly scoped listener on WooCommerce order status changes. After Woo has persisted a status transition, the listener reads the canonical order and line items, selects only items explicitly tied to the current active event, and writes only desk projection and coverage records. It never saves a Woo object and contains projection errors so other order handlers and checkout outcomes are unaffected.

Administrator recovery scans all relevant Woo orders in ascending order-ID order. Each page reports source orders examined separately from matching event items. An opaque signed cursor binds the next page, page size, active event, configuration revision, and source snapshot identity. The snapshot identity includes the source count and highest source order ID. If the source set changes between pages, the cursor is rejected and recovery restarts rather than silently skipping records.

Coverage state is persisted per event and configuration revision. Its states are:

- `not_started`: no complete recovery scan is known.
- `in_progress`: a bound recovery scan has more source pages.
- `complete`: every source order in the recorded snapshot was examined successfully under the recorded event/configuration revision.
- `failed`: a listener or recovery operation failed and the unresolved failure remains visible.

A listener success does not make an incomplete scan complete and does not erase an unrelated unresolved failure. A failed recovery keeps the same cursor eligible for retry. Completion is published only after the final source page succeeds and no unresolved failure remains. Search returns the persisted coverage state; an empty result is not presented as definitive while coverage is incomplete or failed.

Repeated listener transitions and recovery pages converge on the existing `(event_id, source_key)` registration identity. Refresh preserves confirmed attendee names and UUIDs, attendance, audit, and source-null rows.

## Exact admission binding

Before creating attendance, the service requires the stored registration to be active, sourced by the expected Woo order/item, scoped to the active event, and bound to the exact freshly resolved option UUID. Its `source_unit_number` must be positive and no greater than the current source quantity. Existing classification, validity, refund, revocation, date, and configuration checks remain in force.

Projection uses a conservative quantity-shrink policy: previously projected units above current quantity are retained for history and marked `revoked`. Their attendee identity, attendance, and audit rows are never deleted or renumbered. Option remapping leaves the historical option binding intact and marks the registration `needs_review`. Explicit unpaid admission cannot bypass either state.

## Configuration transaction

Configuration save and active-event publication form one database transaction. The transaction obtains a global Registration Desk configuration lock, then locks the target event row and current active-event option row. The global lock serializes same-event saves, first-time configuration, and competing active-event changes involving different events.

Inside the transaction the service reads configuration directly from durable storage, compares the expected revision, writes the next revision, and only then updates the active-event option. Any stale revision or write failure rolls back both changes. WordPress caches are invalidated only after commit or rollback so subsequent requests observe committed state.

Standalone active-event changes used by administrative operations acquire the same global lock. Storage failures return safe errors and are never reported as success.

## Audit failure classification

An audit insert failure is treated as a duplicate only when the request UUID can be read back from the audit table. The service then applies the existing full binding comparison before replay or conflict. Other insert failures return a safe server-side persistence error, roll back attendee and attendance mutations, and leave the original request UUID reusable after the fault is removed.

## Qualification

Every correction begins with a failing regression and is implemented minimally. Qualification includes actual HTTP AJAX dispatch, listener transitions, recovery pagination and interruption, unit shrink and option remap, same- and different-event configuration races using independent processes, rollback injection and retry, an explicitly signed expired station token, expanded protected-store hashes plus global counts/write probes, and the existing attendance concurrency and checkout controls.

The guarded disposable suite will run in legacy order storage and, if the installed WooCommerce runtime supports it, authoritative HPOS with compatibility synchronization disabled. Each run records the authoritative mode. External HTTP and mail remain intercepted; QuickBooks remains disabled and dry-run. Production and the original checkout are never accessed or modified.
