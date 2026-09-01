# Board Reports Operations Design

## Goal

Extend the frontend `[oras_board_reports]` dashboard into the board's operational source for website and manual Annual Observer Passes plus PMPro and Legacy PayPal memberships. Keep accounts, memberships, and Observer Passes as separate concepts and do not require board users to enter wp-admin.

## Authority and access

- `oras_tickets_view_board_dashboard` continues to authorize every read-only Board Reports tab and row.
- `oras_tickets_manage_observer_passes` authorizes manual Annual pass add, edit, and import actions.
- `oras_tickets_manage_memberships` authorizes legacy membership add, edit, transition/link, import-preview, import-commit, and import-cancel actions.
- Administrators receive both management capabilities. General Board roles retain view access but do not receive management capabilities automatically.
- Frontend forms post to authenticated `admin-post.php` actions with a dedicated nonce, capability check, sanitized input, and a fixed safe redirect back to the appropriate Board Reports tab.
- No action grants or depends on WooCommerce, PMPro administration, order editing, or general WordPress administration capabilities.

## Persistence

Use two plugin-owned hidden post types:

- `oras_manual_pass`
- `oras_legacy_member`

Both are registered with `public`, `publicly_queryable`, `show_ui`, `show_in_menu`, and `show_in_rest` disabled; `exclude_from_search` enabled; and no archive, rewrite, or query variable. Management is available only through the frontend handlers.

Each store uses simple namespaced post metadata plus a schema-version field. Frequently filtered fields such as email, dates, source, status, linked user, and PayPal reference remain independently queryable. WordPress post IDs and post timestamps provide stable internal IDs and baseline created/updated metadata; actor user IDs are stored separately.

Manual Annual records store holder names as an array, an explicit quantity, optional email, start date, centrally calculated expiration date, source, optional linked user, notes, record state, schema version, and audit actor IDs. Holder count does not implicitly determine quantity.

Legacy records store name, optional email, optional start date, expiration or next-renewal date, status, optional PayPal reference, optional linked user, transitioned state, notes, fixed Legacy PayPal source, schema version, and audit actor IDs. They never require a WordPress user, PMPro record, WooCommerce order, or gateway payload.

## Annual Observer Pass flow

Extract the exact 0.4.51 anniversary calculation and active/expiring/expired policy into `Annual_Pass_Validity`. WooCommerce and manual normalization both call it.

`Manual_Observer_Pass_Store` returns normalized manual records. `Observer_Pass_Report_Service` converts those records into its existing row contract, marks their source as Manual/Offline, merges them with WooCommerce rows before summary/search/filter/sort/pagination processing, and keeps Daily-pass behavior unchanged. The existing Active Annual summary and verification list therefore cover both sources.

Authorized managers receive Add and Edit controls inside the existing Observer Passes tab. Manual records remain visibly distinct through Source and details fields; there is no second dashboard.

## Membership flow

Paid Memberships Pro remains the read-only website-membership authority. `Membership_Report_Service` reads current/historical PMPro membership-user rows, level names, and WordPress identity without changing PMPro. It normalizes user ID, username, name, email, level, source status, start date, end date, operational status, and source.

The service normalizes Legacy PayPal records separately and then combines both sources into one roster. Operational status is derived consistently for Active, Expiring Soon, and Expired while retaining each source's original status. Exact normalized email matches add a review/link indicator only; they never merge or mutate either record. Name-only possible matches remain advisory.

The global Memberships tab provides summary cards, source/status/account-link filters, name/email/username search, pagination, and read-only `<details>`. Authorized managers can add/edit legacy records and mark linkage or transition state from the frontend.

## CSV import

Legacy PayPal import accepts CSV only. Preview parses a bounded upload into sanitized membership fields and stores only normalized preview rows in a short-lived transient keyed to the current user and a random token. Raw uploaded contents are not retained.

Preview classifies valid new rows, exact-email website matches, existing legacy duplicates, possible name matches, and rows needing review because of missing or invalid required values. Commit revalidates the token, nonce, capability, and normalized rows; creates only approved valid records; then deletes the transient. Cancel also deletes it. Natural transient expiration provides the final cleanup path.

The parser and preview result contract are reusable for a later Manual Annual CSV import without hard-coding the secretary's historical list or adding a pasted-text format.

## Errors and notices

Store methods return `WP_Error` for invalid input or failed writes. Handlers use post/redirect/get notices without exposing raw exceptions or request values. Import commit is fail-safe: invalid rows are not silently accepted, duplicate rows are not overwritten, and successful rows are reported by count.

## Development verification

Implementation follows red-green-refactor with focused domain and WordPress integration scripts. During normal development run only changed-file `php -l`, the new focused checks, relevant Board Reports integration checks when UI integration changes, targeted PHPStan/PHPCS where practical, and `git diff --check`. Version, packaging, release notes, tags, push, deployment, production access, and the full historical matrix remain out of scope.
