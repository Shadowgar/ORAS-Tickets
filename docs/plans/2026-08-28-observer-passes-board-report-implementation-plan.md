# Observer Passes Board Report Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a read-only operational Observer Passes tab to Board Reports that accurately reports Annual and Daily pass validity, attendance, refunded pass quantities, and a clean printable list for today's observers.

**Architecture:** Add a dedicated `Observer_Pass_Report_Service` that reads HPOS-compatible WooCommerce order and line-item data and returns one normalized report snapshot. `Board_Reports` will render the snapshot using its existing server-rendered shell, capability model, filters, pagination, CSS language, and expandable-detail pattern; a nonce-protected admin-post handler will render a standalone print document.

**Tech Stack:** PHP 8, WordPress shortcode/admin-post/capability/nonce APIs, WooCommerce CRUD and HPOS APIs, Booking & Appointment order-item metadata, server-rendered HTML/CSS, small vanilla-JavaScript progressive enhancement, wp-env integration checks, PHPCS, and PHPStan.

**Approved design:** `docs/plans/2026-08-28-observer-passes-board-report-design.md`

**Execution rules:** Start in an isolated worktree using `@superpowers:using-git-worktrees`. Use `@superpowers:test-driven-development` for every behavior task, `@playwright` for the browser acceptance pass, and `@superpowers:verification-before-completion` before claiming completion. Do not write to production, push, deploy, or modify the pre-existing `.playwright-cli/` directory.

---

### Task 1: Define And Normalize Observer Pass Rows

**Files:**
- Create: `oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php`
- Modify: `oras-tickets/includes/Bootstrap.php:50-53`
- Modify: `scripts/board-reports-integration-checks.php:9-13,59-104,106-236`

**Step 1: Add observer-specific fixture helpers**

Extend `scripts/board-reports-integration-checks.php` with a service import and
helpers that create exact Annual/Daily products and dated line items without
depending on production IDs:

```php
use ORAS\Tickets\Reporting\Observer_Pass_Report_Service;

function oras_board_reports_create_observer_product( string $name, string $price, string $slug ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_virtual( true );
	$product->set_regular_price( $price );
	$product->set_price( $price );
	$product_id = $product->save();

	if ( ! is_int( $product_id ) || $product_id <= 0 ) {
		oras_board_reports_fail( 'Unable to create Observer Pass product: ' . $name );
	}

	return $product_id;
}

function oras_board_reports_set_order_date( WC_Order $order, DateTimeImmutable $date ): void {
	$order->set_date_created( $date );
	$order->save();
}

function oras_board_reports_set_daily_dates(
	WC_Order $order,
	string $start,
	string $checkout,
	string $booking_status = 'confirmed'
): void {
	$item = current( $order->get_items( 'line_item' ) );
	if ( ! $item instanceof WC_Order_Item_Product ) {
		oras_board_reports_fail( 'Daily Observer Pass line item missing' );
	}

	$item->update_meta_data( '_wapbk_booking_date', $start );
	$item->update_meta_data( '_wapbk_checkout_date', $checkout );
	$item->update_meta_data( '_wapbk_booking_status', $booking_status );
	$item->save();
}
```

Record every fixture order ID separately from product/post IDs. Clean orders
with `$order->delete( true )` in `finally`; do not rely on `wp_delete_post()` so
the checks work with HPOS enabled.

**Step 2: Write failing row-contract checks**

Create an Annual product, a Daily product, one completed order for each, and a
fixed service clock:

```php
$today = new DateTimeImmutable( '2026-08-28 00:00:00', wp_timezone() );
$annual_product_id = oras_board_reports_create_observer_product(
	'Annual Observer Pass ' . $suffix,
	'60.00',
	'annual-observer-pass-' . strtolower( $suffix )
);
$daily_product_id = oras_board_reports_create_observer_product(
	'Daily Observer Pass ' . $suffix,
	'16.00',
	'daily-observer-pass-' . strtolower( $suffix )
);

add_filter(
	'oras_tickets_observer_annual_product_ids',
	static fn( array $ids ): array => array( $annual_product_id )
);
add_filter(
	'oras_tickets_observer_daily_product_ids',
	static fn( array $ids ): array => array( $daily_product_id )
);
```

Create the Annual order on `2026-08-01` and a two-night Daily order with start
`2026-08-28` and exclusive checkout `2026-08-30`. Require the new service file,
instantiate `new Observer_Pass_Report_Service( $today )`, and assert:

```php
$report = $observer_service->get_report();

oras_board_reports_assert( true === $report['available'], 'Observer Pass report is available' );
oras_board_reports_assert_same( count( $report['all_rows'] ), 2, 'Only configured Observer Pass products are normalized' );
oras_board_reports_assert_same( $annual_row['pass_type'], Observer_Pass_Report_Service::PASS_ANNUAL, 'Annual product is identified' );
oras_board_reports_assert_same( $annual_row['expiration_date'], '2027-08-01', 'Annual expiration is one calendar year after purchase' );
oras_board_reports_assert_same( $annual_row['holder_label'], 'Purchaser/Passholder', 'Purchaser is explicitly labeled as holder fallback' );
oras_board_reports_assert_same( $daily_row['valid_start'], '2026-08-28', 'Daily start date is normalized' );
oras_board_reports_assert_same( $daily_row['valid_checkout'], '2026-08-30', 'Daily checkout stays exclusive' );
oras_board_reports_assert_same( $daily_row['last_valid_date'], '2026-08-29', 'Daily visible range ends on the last observing night' );
oras_board_reports_assert_same( $daily_row['order_number'], (string) $daily_order->get_order_number(), 'Displayed order number is preserved' );
```

Also JSON-encode the normalized rows and assert the forbidden transaction and
Stripe fixture metadata are absent.

**Step 3: Run the focused check and verify failure**

Run:

```bash
bash scripts/run-board-reports-integration-checks.sh
```

Expected: FAIL because `Observer_Pass_Report_Service` does not exist.

**Step 4: Implement the minimal service and bootstrap include**

Create `Observer_Pass_Report_Service` with these public constants and API:

```php
final class Observer_Pass_Report_Service {
	public const PASS_ALL    = 'all';
	public const PASS_ANNUAL = 'annual';
	public const PASS_DAILY  = 'daily';

	public const STATUS_ACTIVE        = 'active';
	public const STATUS_EXPIRING_SOON = 'expiring_soon';
	public const STATUS_EXPIRED       = 'expired';
	public const STATUS_TODAY         = 'today';
	public const STATUS_UPCOMING      = 'upcoming';
	public const STATUS_PAST          = 'past';
	public const STATUS_REFUNDED      = 'refunded';
	public const STATUS_CANCELLED     = 'cancelled';
	public const STATUS_FAILED        = 'failed';
	public const STATUS_UNPAID        = 'unpaid';
	public const STATUS_DATE_MISSING  = 'date_missing';

	private const ORDER_STATUSES = array(
		'completed',
		'processing',
		'on-hold',
		'pending',
		'refunded',
		'cancelled',
		'failed',
	);

	private DateTimeImmutable $today;

	public function __construct( ?DateTimeImmutable $today = null ) {
		$this->today = ( $today ?? current_datetime() )->setTime( 0, 0, 0 );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	public function get_report( array $filters = array() ): array;
}
```

The unfiltered report contract is:

```php
array(
	'available'          => true,
	'error'              => '',
	'all_rows'           => array(),
	'rows'               => array(),
	'summary'            => array(
		'active_annual_count'     => 0,
		'daily_today_count'       => 0,
		'daily_next_7_days_count' => 0,
		'daily_this_month_count'  => 0,
	),
	'today_rows'         => array(),
	'active_annual_rows' => array(),
)
```

Page through `wc_get_orders()` in batches of 50 with `orderby => 'date ID'` so
orders sharing a creation timestamp remain stable across page boundaries. Use
only `WC_Order` and `WC_Order_Item_Product` APIs. Product matching order is:

1. Filtered Annual/Daily IDs from
   `oras_tickets_observer_annual_product_ids` and
   `oras_tickets_observer_daily_product_ids`, defaulting to `3219` and `2494`.
2. Filtered exact slugs from
   `oras_tickets_observer_annual_product_slugs` and
   `oras_tickets_observer_daily_product_slugs`, defaulting to
   `annual-observer-pass` and `daily-observer-pass`.

Use `Contact_Normalizer::from_order()` for board-safe contact fields. Annual
expiration must be derived from the local order-created date. February 29
purchases expire explicitly at the start of March 1 in the next non-leap year;
do not rely on implicit PHP rollover. Parse Daily metadata strictly as
`!Y-m-d` in `wp_timezone()` and reject parse warnings or errors. Do not copy
arbitrary order metadata into a row.

If WooCommerce order APIs are unavailable, return `available => false` with a
generic board-safe error. Wrap the paged scan in `try/catch ( Throwable $e )`,
log only through the existing debug-safe mechanism, and return the same failure
contract rather than partial totals.

Add this include before `Board_Report_Service.php` in `Bootstrap.php`:

```php
require_once ORAS_TICKETS_DIR . 'includes/Reporting/Observer_Pass_Report_Service.php'; // NOSONAR include: Observer Pass reporting
```

**Step 5: Run focused checks**

Run:

```bash
php -l oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php
bash scripts/run-board-reports-integration-checks.sh
```

Expected: PHP syntax PASS and all new normalization assertions PASS. Summary,
filter, and UI assertions are not added yet.

**Step 6: Commit**

```bash
git add oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php oras-tickets/includes/Bootstrap.php scripts/board-reports-integration-checks.php
git commit -m "Add Observer Pass report normalization"
```

### Task 2: Calculate Validity, Quantity Refunds, And Operational Summaries

**Files:**
- Modify: `oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php`
- Modify: `scripts/board-reports-integration-checks.php`

**Step 1: Add failing status-boundary fixtures**

For the fixed `2026-08-28` clock, add isolated fixtures for:

- Active Annual: expires `2026-10-01`.
- Expiring Soon boundary: expires `2026-09-27` (30 days away).
- Expired Annual: expires `2026-08-28`.
- February 29 Annual: purchased `2024-02-29`, valid through `2025-02-28`, and
  expired beginning `2025-03-01`.
- Daily Today: start on or before `2026-08-28`, checkout after it.
- Daily Upcoming: starts between `2026-08-29` and `2026-09-04`.
- Daily Past: checkout on `2026-08-28`.
- Daily missing and malformed dates as separate fixtures.
- Daily unknown booking status on an otherwise current date range; retain its
  Today date classification but require `is_valid=false` and exclusion from
  `today_rows` and every operational count.
- Daily booking status `cancelled` on an otherwise completed order.
- WooCommerce orders in `cancelled`, `failed`, and `refunded` states.
- An unpaid `pending` order that remains auditable but is never valid.
- A missing purchaser name that normalizes to an empty `holder_names` array.
- A local-midnight boundary fixture where the UTC timestamp falls on an
  adjacent date.
- More than 50 configured Observer orders to prove the complete paged scan.

Assert exact `operational_status`, `is_valid`, `valid_quantity`, and date values.
In particular:

```php
oras_board_reports_assert_same( $expiring_row['operational_status'], 'expiring_soon', 'Annual pass enters Expiring Soon at 30 days' );
oras_board_reports_assert_same( $expired_row['operational_status'], 'expired', 'Annual pass expires on its anniversary' );
oras_board_reports_assert_same( $today_row['operational_status'], 'today', 'Inclusive Daily start and exclusive checkout contain today' );
oras_board_reports_assert_same( $past_row['operational_status'], 'past', 'Daily checkout date is no longer valid' );
oras_board_reports_assert_same( $missing_row['operational_status'], 'date_missing', 'Missing Daily dates stay auditable but invalid' );
```

**Step 2: Add failing quantity-refund and multi-line fixtures**

Create:

- A completed Annual line with quantity 2 and line total `$120`.
- An attributable refund for quantity 1 against that item.
- A dollar-only partial refund without line or quantity attribution; it must
  not change valid quantity or pass validity.
- A completed Observer line plus an unrelated merchandise line in the same
  order.
- One order containing Annual and Daily Observer lines.
- One order containing multiple Daily Observer lines.
- A fully refunded Observer order.

Use WooCommerce refund APIs and associate the refund line with the original
line item. Assert:

```php
oras_board_reports_assert_same( $partial_row['refunded_quantity'], 1, 'Attributable refunded quantity is recorded' );
oras_board_reports_assert_same( $partial_row['valid_quantity'], 1, 'Partial item refund reduces valid quantity' );
oras_board_reports_assert_same( $fully_refunded_row['operational_status'], 'refunded', 'Fully refunded pass stays visible as refunded' );
oras_board_reports_assert_same( $fully_refunded_row['valid_quantity'], 0, 'Fully refunded pass has no valid quantity' );
```

Assert that unrelated merchandise and dollar-only refunds do not alter Observer
Pass classification or valid quantity.

**Step 3: Add failing summary assertions**

Assert summaries are calculated from all normalized rows before filters:

```php
oras_board_reports_assert_same( $report['summary']['active_annual_count'], $expected_active_qty, 'Active Annual summary uses valid quantity' );
oras_board_reports_assert_same( $report['summary']['daily_today_count'], $expected_today_qty, 'Daily Today summary uses valid quantity' );
oras_board_reports_assert_same( $report['summary']['daily_next_7_days_count'], $expected_upcoming_qty, 'Next-seven-days summary excludes Today' );
oras_board_reports_assert_same( $report['summary']['daily_this_month_count'], $expected_month_qty, 'This-month summary counts future Daily visits' );
```

Run the integration check and expect the first new status assertion to fail.

**Step 4: Implement exact status precedence**

Implement status calculation in this order:

```php
if ( 'refunded' === $order_status || $valid_quantity <= 0 ) {
	return self::STATUS_REFUNDED;
}
if ( 'cancelled' === $order_status || 'cancelled' === $booking_status || 'canceled' === $booking_status ) {
	return self::STATUS_CANCELLED;
}
if ( 'failed' === $order_status ) {
	return self::STATUS_FAILED;
}
if ( ! $order->is_paid() ) {
	return self::STATUS_UNPAID;
}
```

For a paid Daily row, only `confirmed` and `paid` booking states may become
valid. Missing/unrecognized states remain visible and invalid. Then apply the
approved Annual or Daily date rules.

Use WooCommerce attribution methods only:

```php
$refunded_quantity = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
$valid_quantity    = max( 0, $quantity - $refunded_quantity );
```

Only an explicit Observer line-item quantity refund changes operational valid
quantity. Other refund records leave valid quantity unchanged.

**Step 5: Run focused checks**

```bash
bash scripts/run-board-reports-integration-checks.sh
```

Expected: all validity, quantity-refund, multi-line, and operational-summary
assertions PASS.

**Step 6: Commit**

```bash
git add oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php scripts/board-reports-integration-checks.php
git commit -m "Simplify Observer Pass operational reporting"
```

### Task 3: Filter And Sort The Observer Pass Snapshot

**Files:**
- Modify: `oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php`
- Modify: `scripts/board-reports-integration-checks.php`

**Step 1: Write failing filter tests**

Call `get_report()` with these filter shapes and assert only expected order/item
rows remain:

```php
array( 'pass_type' => 'annual' )
array( 'pass_type' => 'daily' )
array( 'status' => 'expiring_soon' )
array( 'status' => 'refunded_cancelled' )
array( 'search' => 'board buyer' )
array( 'search' => 'board.buyer@example.org' )
array( 'search' => (string) $daily_order->get_order_number() )
array( 'date_preset' => 'today' )
array( 'date_preset' => 'next_7' )
array( 'date_preset' => 'this_month' )
array( 'date_preset' => 'this_year' )
array( 'date_preset' => 'custom', 'after' => '2026-09-01', 'before' => '2026-09-30' )
```

Daily rows match when any valid observing night overlaps the selected inclusive
range. Annual rows match when expiration is inside the inclusive range. Invalid
date inputs are ignored rather than passed into WooCommerce queries.

Assert the four summary values remain identical between the unfiltered report
and a filtered report.

**Step 2: Write failing order tests**

Assert Daily order is Today, Upcoming by start ascending, then Past/history.
Assert Annual order is Active, Expiring Soon by expiration ascending, then
Expired. Assert All Passes places valid current rows before future and
historical rows and uses order ID/item ID as stable tie-breakers.

Run the focused integration script and expect failure on pass-type filtering.

**Step 3: Implement filter normalization**

Normalize filters to this internal contract:

```php
array(
	'pass_type'   => 'all',
	'status'      => 'all',
	'search'      => '',
	'date_preset' => 'all',
	'after'       => '',
	'before'      => '',
)
```

Allow only known pass types, statuses, and presets. Search case-insensitively
across `purchaser_name`, `holder_names`, `email`, and `order_number`. Do not
search arbitrary serialized metadata.

Calculate preset boundaries from `$this->today` in `wp_timezone()`. `next_7`
means tomorrow through seven dates after today; it must not duplicate Today.

**Step 4: Implement stable operational sorting**

Use a private status-rank map and `usort()`. For equal status rank:

- Daily Upcoming: `valid_start` ascending.
- Annual Active/Expiring Soon: `expiration_date` ascending.
- Historical rows: most recent operational/purchase date first.
- Final tie-breakers: order ID, then line-item ID.

**Step 5: Run focused checks**

```bash
bash scripts/run-board-reports-integration-checks.sh
```

Expected: every filter, invariant-summary, and sorting assertion PASS.

**Step 6: Commit**

```bash
git add oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php scripts/board-reports-integration-checks.php
git commit -m "Filter and sort Observer Pass reports"
```

### Task 4: Add The Top-Level Board Reports Tab And Global Shell

**Files:**
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php:5-12,20-46,61-69,848-871,2118-2205`
- Modify: `scripts/board-reports-integration-checks.php`
- Modify: `oras-tickets/tools/phase1h-event-questions-checks.php:299-321`

**Step 1: Add failing navigation and shell assertions**

Set the authorized admin user, temporarily set:

```php
$_GET['oras_board_tab'] = 'observer_passes';
```

Render `[oras_board_reports]` and assert:

```php
oras_board_reports_assert( false !== strpos( $observer_html, '>Observer Passes<' ), 'Observer Passes is a top-level Board Reports tab' );
oras_board_reports_assert( false !== strpos( $observer_html, 'oras-board-reports__observer'), 'Observer Passes dashboard renders' );
oras_board_reports_assert( false === strpos( $observer_html, 'Load Event Dashboard' ), 'Observer Passes does not render the event selector' );
oras_board_reports_assert( false === strpos( $observer_html, 'Open Attention Items' ), 'Observer Passes omits event overview metrics' );
```

Render the default tab again and assert `Load Event Dashboard` and existing
overview content remain present. Add a static phase 1H assertion for the new
tab label and render helper without changing existing tab assertions.

Run the focused script and expect the Observer tab assertion to fail.

**Step 2: Add the tab and route**

Import `Observer_Pass_Report_Service`, add:

```php
private const TAB_OBSERVER_PASSES = 'observer_passes';
```

Add it to `get_dashboard_tabs()` after Roster:

```php
self::TAB_OBSERVER_PASSES => __( 'Observer Passes', 'oras-tickets' ),
```

In `render_shortcode()`, do not load events or select a default event when the
active tab is Observer Passes. Render RSVP/waitlist/attention notices, the event
selector, event overview cards, and the attention center only for event tabs.
Always render the dashboard title, privacy notice, and tab navigation.

Add an Observer branch that calls:

```php
self::render_observer_passes_tab( $page_id );
```

Start with a minimal section heading and report-unavailable/empty state so this
task changes routing only.

**Step 3: Preserve tab URL isolation**

Update `build_tab_url()` so changing tabs drops Observer-only parameters and
event-only parameters that do not apply to the target tab. This prevents stale
Observer status/date filters from leaking into Sales and avoids carrying an
event ID into the global report.

**Step 4: Run routing regression checks**

```bash
php oras-tickets/tools/phase1h-event-questions-checks.php
bash scripts/run-board-reports-integration-checks.sh
```

Expected: the Observer tab and shell assertions PASS, and all existing Board
Reports assertions remain green.

**Step 5: Commit**

```bash
git add oras-tickets/includes/Frontend/Board_Reports.php scripts/board-reports-integration-checks.php oras-tickets/tools/phase1h-event-questions-checks.php
git commit -m "Add Observer Passes Board Reports tab"
```

### Task 5: Render Cards, Operational Lists, Filters, Table, And Details

**Files:**
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php:73-845,977-1049,1499-1776,2531-2628,2663-2685`
- Modify: `scripts/board-reports-integration-checks.php`

**Step 1: Add failing complete-dashboard assertions**

With Observer fixtures active, assert the rendered HTML contains:

```php
foreach ( array(
	'Active Annual Passes',
	'Daily Passes Today',
	'Upcoming Daily Passes — Next 7 Days',
	'Upcoming Daily Passes — This Month',
	"Today's Daily Observers",
	'Active Annual Observer Passes',
	'Purchaser/Passholder',
	'Valid Date / Expiration',
) as $required_text ) {
	oras_board_reports_assert( false !== strpos( $observer_html, $required_text ), 'Observer dashboard contains ' . $required_text );
}
```

Also assert:

- Summary values use all rows even when a table filter is present.
- The main table displays the fixture order number and the expected Annual and
  Daily dates.
- `<details>` contains email, phone, order status, and booking status.
- Refunded/cancelled rows have explicit status text.
- No WooCommerce admin order URL, edit/refund/delete button, forbidden
  transaction metadata, or payment method text is present.
- The Annual verification list includes only Active/Expiring Soon rows.
- The Annual search input and its result-status live region are present.

Run the integration check and expect failure on the first missing card.

**Step 2: Add Observer-specific request filters**

Do not overload event Sales filters. Add a dedicated parser returning:

```php
array(
	'pass_type'     => sanitize_key( /* oras_observer_pass_type */ ),
	'status'        => sanitize_key( /* oras_observer_status */ ),
	'date_preset'   => sanitize_key( /* oras_observer_date_preset */ ),
	'after'         => validated Y-m-d or '',
	'before'        => validated Y-m-d or '',
	'search'        => sanitize_text_field( /* oras_observer_search */ ),
	'page'          => max( 1, absint( /* oras_observer_page */ ) ),
	'per_page'      => one of 25, 50, 100,
)
```

Render type, status, preset, after, before, search, and page-size controls as a
GET form that keeps the page ID and Observer tab but no event ID. Custom dates
remain visible and become effective only for the Custom Range preset.

**Step 3: Render the unfiltered summary cards**

Use the four operational counts from the service-provided `summary` before
paginating `rows`. Otherwise render a clear unavailable state; never convert a
query failure into four zeroes.

**Step 4: Render Today's Daily Observers**

Render `today_rows` before the historical table. Each card/list item includes
purchaser/passholder, valid quantity, known holders, and displayed order
number. Empty state text must say no Daily observers are scheduled for the
current local date, not that no orders exist.

**Step 5: Render the Active Annual verification list**

Render `active_annual_rows` in a compact list with name, email when present,
status, and expiration. Add:

```html
<input type="search" data-oras-observer-annual-search aria-controls="oras-active-annual-list">
<span class="screen-reader-text" aria-live="polite" data-oras-observer-annual-status></span>
```

Add a small inline progressive-enhancement script scoped to the Observer
section. It lowercases trimmed input, compares only each list item's existing
`data-search` value, toggles `hidden`, and updates the visible-count live region.
All functionality remains usable without JavaScript.

**Step 6: Render the responsive main table**

Use a semantic table inside the existing overflow wrapper with columns:

```text
Purchaser | Pass Type | Pass Holder(s) | Purchase Date |
Valid Date / Expiration | Qty | Order | Status | Details
```

Render one `<details>` element in Details. Use `wp_date()` for display dates and
escape every field. Use `Not available` for missing contact values and `Date
unavailable` for malformed Daily dates. Order numbers are plain text, never
admin links.

Paginate only filtered main-table rows using the existing `paginate_rows()`
contract, with Observer parameter names preserved in Previous/Next URLs.

**Step 7: Extend scoped CSS and mobile behavior**

Add Observer selectors inside the existing inline stylesheet for:

- summary card grid and unavailable state;
- Today and Active Annual stacked lists;
- status badges, including refunded/cancelled warning colors;
- table details;
- a 390px-friendly Annual verification row;
- dark-mode selectors matching the dashboard's current three dark-mode roots.

At `max-width: 700px`, keep the main table horizontally scrollable and make the
two operational lists single-column with large tap/read targets. Do not alter
styles outside `.oras-board-reports`.

**Step 8: Run focused checks**

```bash
bash scripts/run-board-reports-integration-checks.sh
php oras-tickets/tools/phase1h-event-questions-checks.php
```

Expected: all dashboard markup, data-safety, and existing tab assertions PASS.

**Step 9: Commit**

```bash
git add oras-tickets/includes/Frontend/Board_Reports.php scripts/board-reports-integration-checks.php
git commit -m "Render Observer Passes dashboard"
```

### Task 6: Add The Secure Standalone Print View

**Files:**
- Modify: `oras-tickets/includes/Frontend/Board_Reports.php:20-46,2003-2057,2630-2661`
- Modify: `scripts/board-reports-integration-checks.php:117-122,222-229`

**Step 1: Add failing action and document assertions**

Require registration of:

```php
has_action(
	'admin_post_oras_board_reports_print_observers_today',
	array( Board_Reports::class, 'handle_print_observers_today' )
)
```

Assert the dashboard print URL targets `admin-post.php`, carries the print
action, and contains a WordPress nonce.

Use Reflection to invoke a pure private
`build_observer_print_document( array $rows, DateTimeImmutable $today ): string`
helper. Assert its result contains the local date, fixture name, quantity, and
order number, while excluding:

- `wpadminbar` and WordPress navigation;
- Board Reports filters and tabs;
- financial data and unrelated customer details;
- transaction/payment/accounting metadata.

**Step 2: Add failing authorization checks**

Install a temporary `wp_die_handler` that throws an exception. Call the print
handler as the unauthorized subscriber with a syntactically valid nonce and
assert it is rejected. Call as the authorized administrator with an invalid
nonce and assert it is rejected. Restore the original handler and request
globals in `finally`.

Run the integration check and expect failure because the action is not
registered.

**Step 3: Register the print action and URL**

Add dedicated constants:

```php
private const OBSERVER_PRINT_ACTION = 'oras_board_reports_print_observers_today';
private const OBSERVER_PRINT_NONCE  = 'oras_board_reports_print_observers_today';
```

Register only the authenticated `admin_post_` action. Build the URL with
`wp_nonce_url()` and do not register a `nopriv` route.

**Step 4: Implement the handler**

The handler must execute checks in this order:

```php
if ( ! is_user_logged_in() || ! current_user_can( 'oras_tickets_view_board_dashboard' ) ) {
	wp_die(
		esc_html__( 'You do not have permission to print this report.', 'oras-tickets' ),
		'',
		array( 'response' => 403 )
	);
}

check_admin_referer( self::OBSERVER_PRINT_NONCE );
```

Then build a fresh unfiltered service snapshot, fail closed if unavailable,
render only `today_rows`, send `Content-Type: text/html; charset=UTF-8` and
no-cache headers, echo the trusted escaped document, and exit.

The standalone document contains embedded print-safe CSS, an `<h1>Today's Daily
Observers</h1>`, the formatted local date, and a simple table/list of name,
quantity, holder label, and order number. It must not load the WordPress theme
or expose email/phone unless the approved design is revised.

**Step 5: Run focused security checks**

```bash
bash scripts/run-board-reports-integration-checks.sh
```

Expected: action registration, permission denial, nonce denial, and clean
document assertions all PASS.

**Step 6: Commit**

```bash
git add oras-tickets/includes/Frontend/Board_Reports.php scripts/board-reports-integration-checks.php
git commit -m "Add secure Daily observer print view"
```

### Task 7: Add CI Coverage, Release Notes, And Deployment Guidance

**Files:**
- Modify: `.github/workflows/phase5-verification.yml`
- Modify: `oras-tickets/oras-tickets.php:7,26`
- Modify: `docs/CHANGELOG.md:1-3`
- Modify: `docs/BOARD_REPORTS_EVENT_MANAGEMENT_DASHBOARD_DEPLOYMENT.md`

**Step 1: Run the new integration suite before changing CI**

```bash
bash scripts/run-board-reports-integration-checks.sh
```

Expected: `Board reports integration checks passed.`

**Step 2: Add Board Reports integration to CI**

After the Reports integration step, add:

```yaml
- name: Run Board Reports integration checks
  env:
    ORAS_WP_ENV_DIR: .
    ORAS_WP_ENV_CMD: npx --yes @wordpress/env
  run: bash scripts/run-board-reports-integration-checks.sh
```

This makes the new behavior a required Phase5 Verification gate instead of a
local-only harness.

**Step 3: Bump the plugin patch version**

Change both the plugin header and `ORAS_TICKETS_VERSION` from `0.4.50` to
`0.4.51`. Do not change `scripts/phpstan-bootstrap.php`; it is a static-analysis
fallback, not release metadata.

**Step 4: Add append-only release documentation**

Add a new top entry to `docs/CHANGELOG.md` describing:

- the read-only Observer Passes tab;
- Annual anniversary/30-day expiry rules;
- Daily booking-date and Today/Upcoming behavior;
- explicit refunded quantity and operational valid quantity;
- explicit February 29 expiration at the start of March 1 in non-leap years;
- secure clean printing and unchanged Board capabilities.

Update the existing Board Reports deployment document with a short `0.4.51`
verification section: activate/update the plugin, load Observer Passes as a
Board user, verify summary cards against known orders, verify a multi-night
Daily pass, verify refunded rows are historical only, and test print output.
State explicitly that rollback is plugin-code rollback only because this
feature adds no schema and writes no order data.

**Step 5: Run metadata and workflow checks**

```bash
composer version-check
git diff --check
```

Expected: version metadata matches `0.4.51` and no whitespace errors exist.

**Step 6: Commit**

```bash
git add .github/workflows/phase5-verification.yml oras-tickets/oras-tickets.php docs/CHANGELOG.md docs/BOARD_REPORTS_EVENT_MANAGEMENT_DASHBOARD_DEPLOYMENT.md
git commit -m "Prepare Observer Passes report release"
```

### Task 8: Perform Full Automated And Browser Verification

**Files:**
- Verify only; make no production changes.

**Step 1: Run syntax checks on every changed PHP file**

```bash
php -l oras-tickets/includes/Reporting/Observer_Pass_Report_Service.php
php -l oras-tickets/includes/Frontend/Board_Reports.php
php -l oras-tickets/includes/Bootstrap.php
php -l scripts/board-reports-integration-checks.php
```

Expected: `No syntax errors detected` for each file.

**Step 2: Run targeted behavior checks**

```bash
bash scripts/run-board-reports-integration-checks.sh
php oras-tickets/tools/phase1h-event-questions-checks.php
```

Expected: `Board reports integration checks passed.` The phase 1H harness may
print the pre-existing unrelated `FAIL: Board communications emails use HTML
content type` and exit `1`; compare it with the original checkout and do not
treat the unchanged baseline as an Observer Pass regression.

**Step 3: Run repository quality gates**

```bash
composer version-check
composer role-check
composer phpcs
composer phpstan
bash scripts/run-phase5-integration-checks.sh
bash scripts/run-reports-integration-checks.sh
git diff --check
```

Expected: every command exits zero. If an existing unrelated gate fails, capture
the exact command and output; do not weaken or bypass it.

**Step 4: Create an ephemeral local QA page**

In wp-env, create or locate a published page containing `[oras_board_reports]`:

```bash
npx --yes @wordpress/env run cli wp post create --post_type=page --post_status=publish --post_title='Observer Passes QA' --post_content='[oras_board_reports]' --porcelain
```

Record the returned page ID. This changes only the disposable local wp-env
database.

**Step 5: Validate the UI with Playwright**

Using `@playwright`, sign into the local wp-env administrator account and check
the Observer Passes tab at desktop width and at approximately 390px width:

- no event selector or event metrics appear;
- all four summary cards render;
- Today and Active Annual lists are readable;
- Annual instant search hides non-matching entries and updates its result count;
- type/status/date/search filters preserve the Observer tab;
- the main table can be scrolled on mobile;
- details expand without an admin-order link;
- refunded/cancelled rows are clearly invalid;
- the print action opens a document with no WordPress chrome.

Capture screenshots in a temporary directory outside the repository or in the
tool-managed artifact directory. Do not add browser artifacts to Git.

**Step 6: Inspect the final change boundary**

```bash
git status --short
git diff --stat 19b0803..HEAD
git log --oneline 19b0803..HEAD
```

Expected: only the Observer service, Board Reports integration/tests, approved
release/docs/workflow files, and the already committed design/plan are in scope.
The pre-existing untracked `.playwright-cli/` directory remains untouched.

**Step 7: Request code review before integration**

Use `@superpowers:requesting-code-review` against the full range from `19b0803`
to the implementation HEAD. Address only evidence-backed findings using
`@superpowers:receiving-code-review`, rerun affected checks, and make focused
follow-up commits.

**Step 8: Stop before external changes**

Report the final commit range, exact test results, known limitations, and local
QA evidence. Do not push, deploy, modify production, or reuse the temporary
production administrator credentials without separate explicit authorization.
