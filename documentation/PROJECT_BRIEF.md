# Project Brief: Additional Subscriptions Analytics

## Overview
Additional Subscriptions Analytics is a standalone WooCommerce plugin that
prototypes a modern WooCommerce Subscriptions analytics layer. It adds native
reports to **WooCommerce -> Analytics** for subscription data the current
Subscriptions reports do not surface well. The first report, **Upcoming
Renewals**, answers a concrete operating question: for subscriptions due to renew
in a chosen future window, **how many of each product/variation** will be
charged. The initial deployment target is Bega Valley Organics, where synced
Friday renewals require a per-product pick/pack list on the Wednesday/Thursday
before renewal.

## Target Market
- **Distribution:** Private. Deployed first to begavalleyorganics.com.au
  (Pressable), then reusable across other WooCommerce Subscriptions clients.
- **Longer-term positioning:** Treat this as a prototype for a future
  WooCommerce Subscriptions analytics replacement, not only a one-off merchant
  report.
- **Quality bar:** WooCommerce Marketplace standards apply even though there is
  no marketplace review -- HPOS compatibility, coding standards, security,
  schema migrations, data repair tooling, tests, and CI.
- **Competitive landscape:** No upstream per-product *upcoming* renewals report
  currently exists. Core's *Subscriptions by Product* counts the whole active
  base, not a date-windowed renewal cohort, and the legacy Subscriptions reports
  do not expose a reusable analytics data model equivalent to WooCommerce's
  `wc_order_stats` and `wc_order_product_lookup` tables.

## Core Functionality
Build and maintain subscription analytics lookup tables, then surface reports
from those tables through the native WooCommerce Analytics stack. The v1 data
model uses:

- `{$wpdb->prefix}wc_subscriptions_stats`: one row per subscription, including
  subscription ID, status, schedule dates, next payment date, recurring totals,
  currency, and update timestamps.
- `{$wpdb->prefix}wc_subscription_product_lookup`: one row per recurring
  subscription line item, including subscription ID, line item ID, product ID,
  variation ID, product name, quantity, and recurring line totals.

The **Upcoming Renewals** report queries this lookup model, not live
subscription/order-item tables, so date-windowed product counts remain fast and
predictable at scale. The plugin owns its table schema, schema version option,
installation/upgrade routines, Action Scheduler backfill jobs, event-driven sync,
and repair/regeneration tools.

## Customer-Facing Features
- None. This is an admin-only reporting plugin; no storefront, cart, checkout, or
  My Account changes.

## Admin Features
- New **Upcoming Renewals** report under WooCommerce -> Analytics.
- Future-oriented date-range selection interpreted against the subscription next
  payment date.
- Sortable table: Product, Total qty, # subscriptions, Recurring total.
- CSV export via the Analytics export flow.
- Admin-visible table sync state, including last backfill/update time and
  actionable notices if analytics tables need regeneration.
- Extensible scaffold for sibling reports, such as renewal-revenue forecast and
  churn, using the same subscription analytics tables.

## Technical Requirements
- Minimum WordPress version: 6.4
- Minimum WooCommerce version: 9.3 (first stable `GenericController` /
  `GenericQuery`)
- Minimum WooCommerce Subscriptions version: 6.0+ (HPOS-aware subscription
  helpers and modern renewal hooks)
- Minimum PHP version: 8.0
- Required plugin dependencies: WooCommerce and WooCommerce Subscriptions only.
  No dependency on AutomateWoo or merchant-specific custom plugins.
- HPOS compatible: **Yes (mandatory)** -- declare `custom_order_tables` and read
  subscription/order data through WooCommerce/WooCommerce Subscriptions APIs or
  documented HPOS-aware query helpers during sync/backfill.
- Cart & Checkout Blocks compatible: N/A (no cart/checkout surface).
- Product Block Editor compatible: N/A.
- Site Editor compatible: N/A.
- Store API extensions needed: No.
- Custom database tables: Yes --
  `{$wpdb->prefix}wc_subscriptions_stats` and
  `{$wpdb->prefix}wc_subscription_product_lookup`, with schema versioning,
  migrations, indexes, backfill, incremental sync, and repair/regeneration
  commands.
- External API integrations: None.
- Background processing: Yes -- Action Scheduler jobs for initial backfill,
  chunked regeneration, stale-row repair, and optional off-peak refreshes for
  high-volume stores.

## Data & Compliance
- Stores derived subscription analytics data already present in WooCommerce
  Subscriptions: internal subscription IDs, product/variation IDs, line item IDs,
  schedule dates, statuses, quantities, totals, and currency.
- Does not store customer names, email addresses, shipping addresses, payment
  tokens, or card data in the analytics tables.
- Collects and transmits no new data externally. Report output is aggregate
  product counts and recurring totals.
- All report access and regeneration actions are gated by `manage_woocommerce`
  through REST `permission_callback` and admin capability checks.
- No new GDPR/PCI surface is introduced beyond derived operational analytics
  stored locally in WordPress.

## Out of Scope
- AutomateWoo integration or delegation. The plugin may be validated against
  other implementations, but it must not require or call AutomateWoo or
  merchant-specific plugins.
- Storefront/customer UX, cart/checkout behavior, My Account changes, and
  subscription editing/cancellation flows.
- Reporting on past realized renewal orders as the main value proposition; stock
  WooCommerce Analytics already covers order history via order lookup tables.
- Sibling reports beyond **Upcoming Renewals** in v1, although the table model and
  registry should be designed so renewal forecast and churn reports can be added
  without replacing the data layer.
