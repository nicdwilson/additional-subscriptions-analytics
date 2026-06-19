=== Additional Subscriptions Analytics ===
Contributors: woocommerce
Requires at least: 6.4
Requires PHP: 8.0
WC requires at least: 9.3
WC tested up to: 10.8
Stable tag: 0.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Native WooCommerce Analytics reports backed by subscription analytics lookup tables.

== Description ==

Additional Subscriptions Analytics adds native WooCommerce Analytics reports
backed by subscription analytics lookup tables.

The first report, Upcoming renewals, shows future WooCommerce Subscriptions
renewals grouped by product, variation, and currency. Merchants can use it to
prepare operational product counts before renewal orders are charged.

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

= 0.9.1 =
Adds Analytics Settings controls for subscription analytics backfill and
replacement rebuilds. Activation now queues the initial non-destructive backfill.

= 0.9-beta =
Initial private prerelease. The plugin creates two lookup tables and performs a
non-destructive initial backfill. No manual migration steps are required.

== Changelog ==

= 0.9.1 =
* Added WooCommerce > Analytics > Settings controls for subscription analytics backfill status.
* Added Backfill missing data and Delete and rebuild data actions backed by Action Scheduler.
* Added automatic initial backfill scheduling after activation or first migration.
* Kept cache clearing out of WooCommerce > Status > Tools because this plugin has no separate cache layer.

= 0.9-beta =
* Added plugin-owned subscription analytics lookup tables with versioned schema lifecycle.
* Added Action Scheduler backfill, regeneration, stale repair, and orphan cleanup tools.
* Added event-driven subscription and subscription line-item sync.
* Added Upcoming renewals report under WooCommerce > Analytics.
* Added CSV export, sync status notices, and data validation diagnostics.
* Added WP-CLI reconciliation command for source-vs-lookup validation.
