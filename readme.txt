=== Additional Subscriptions Analytics ===
Contributors: woocommerce
Requires at least: 6.4
Requires PHP: 8.0
WC requires at least: 9.3
WC tested up to: 10.8
Stable tag: 0.9.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Native WooCommerce Analytics reports backed by subscription analytics lookup tables.

== Description ==

Additional Subscriptions Analytics adds native WooCommerce Analytics reports
backed by subscription analytics lookup tables.

The Upcoming renewal products report shows future WooCommerce
Subscriptions renewals grouped by product, variation, and currency. Merchants
can use it to prepare operational product counts before renewal orders are
charged.

The Upcoming renewal revenue report shows forecast recurring revenue over the
selected future window. Its graph reports revenue by date, while its table can
be grouped by day, week, month, or year.

The plugin is admin-only. It does not change the storefront, cart, checkout, My
Account, payment processing, or subscription editing flows.

Lookup data is stored in plugin-owned tables:

* `{$wpdb->prefix}wc_subscriptions_stats`
* `{$wpdb->prefix}wc_subscription_product_lookup`

Tables are created and updated with idempotent migrations, populated with Action
Scheduler backfill jobs, and maintained by subscription sync hooks. Initial
activation queues a non-destructive backfill automatically.

== Installation ==

1. Install WooCommerce and WooCommerce Subscriptions.
2. Upload the installable plugin zip or clone this repository for development.
3. For source checkouts, run Composer and npm install steps before building.
4. Activate the plugin from WordPress admin.
5. Use WooCommerce > Analytics > Settings to monitor backfill status or rebuild
   subscription analytics data.

== Frequently Asked Questions ==

= How do I regenerate analytics tables? =

Use WooCommerce > Analytics > Settings to refresh missing or incomplete lookup
data without truncating tables, or delete and rebuild all subscription analytics
lookup rows. Operators can also use `wp asa regenerate` to run a full table
regeneration through Action Scheduler.
Use `wp asa repair-stale` for stale-row repair and `wp asa cleanup-orphans` to
remove product lookup rows whose subscription stats row no longer exists.

= How do I validate next-Friday counts? =

Run:

`wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active`

Use the same date for `--after` and `--before`; the report treats date-only
boundaries as an inclusive local report day and converts them to a half-open GMT
window internally.

= Why can this differ from legacy Subscriptions reports? =

This report is product-line based, active-status by default, and uses GMT-backed
lookup tables. Legacy revenue reports may count at subscription/order level or
include shipping, tax, fees, discounts, retries, or different statuses.

== Upgrade Notice ==

= 0.9.6 =
Adds a SKU column to the Upcoming renewal products report and CSV export. SKUs
are read live from each product, so they always reflect the current value with
no resync required.

= 0.9.5 =
Adds the Upcoming renewal revenue report with daily charting and selectable
table grouping.

= 0.9.4 =
Counts recurring renewal occurrences within the selected future report window,
including billing interval and subscription end-date handling.

= 0.9.3 =
Adds the forward-looking Upcoming renewal products date picker and fixes report
loading with WooCommerce Analytics date query compatibility.

= 0.9.1 =
Adds Analytics Settings controls for subscription analytics backfill and
replacement rebuilds. Activation now queues the initial non-destructive backfill.

= 0.9-beta =
Initial private prerelease. The plugin creates two lookup tables and performs a
non-destructive initial backfill. No manual migration steps are required.

== Changelog ==

= 0.9.6 =
* Added a SKU column to the Upcoming renewal products report table and CSV export.
* Resolved the SKU live from each product at read time so it never goes stale; no lookup-table schema change or resync is required.

= 0.9.5 =
* Added Upcoming renewal revenue under WooCommerce > Analytics.
* Added recurring revenue REST endpoints, CSV export, and daily chart data.
* Added daily, weekly, monthly, and annual grouping for the revenue report table.

= 0.9.4 =
* Count recurring renewal occurrences within longer future report windows.
* Use billing period, billing interval, report end date, and subscription end date when expanding future renewals.
* Keep reconciliation diagnostics aligned with the occurrence-aware report totals.

= 0.9.3 =
* Added a forward-looking date range picker for Upcoming renewal products.
* Removed visible comparison controls and comparison summary deltas from the forward-looking report.
* Fixed report rendering by preserving WooCommerce Analytics date query compatibility internally.

= 0.9.1 =
* Added WooCommerce > Analytics > Settings controls for subscription analytics backfill status.
* Added Backfill missing data and Delete and rebuild data actions backed by Action Scheduler.
* Added automatic initial backfill scheduling after activation or first migration.
* Kept cache clearing out of WooCommerce > Status > Tools because this plugin has no separate cache layer.

= 0.9-beta =
* Added plugin-owned subscription analytics lookup tables with versioned schema lifecycle.
* Added Action Scheduler backfill, regeneration, stale repair, and orphan cleanup tools.
* Added event-driven subscription and subscription line-item sync.
* Added Upcoming renewal products report under WooCommerce > Analytics.
* Added CSV export, sync status notices, and data validation diagnostics.
* Added WP-CLI reconciliation command for source-vs-lookup validation.
