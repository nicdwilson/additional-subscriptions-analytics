# Roadmap: Additional Subscriptions Analytics

**Plugin:** Additional Subscriptions Analytics
**Slug / text domain:** `additional-subscriptions-analytics`
**Namespace:** `AdditionalSubscriptionsAnalytics\` (PSR-4)
**Hook / option / transient prefix:** `asa_`
**Working tables:** `{$wpdb->prefix}wc_subscriptions_stats`, `{$wpdb->prefix}wc_subscription_product_lookup`
**Origin:** Investigation ZD-11336952, "Option C", expanded into a standalone
Subscriptions analytics prototype.

> Companion: `PROJECT_BRIEF.md` is the single source of truth for scope. Read it
> before any architectural decision. This roadmap is the build order.

---

## 1. The Thesis

This project is no longer only a thin report over live subscription meta. It is a
prototype for a modern WooCommerce Subscriptions analytics layer that mirrors the
shape of WooCommerce core analytics: maintain purpose-built lookup tables, then
serve reports from those tables through WooCommerce Analytics.

The first report is still **Upcoming Renewals**, but the durable value is the data
model:

- `{$wpdb->prefix}wc_subscriptions_stats`: one row per subscription.
- `{$wpdb->prefix}wc_subscription_product_lookup`: one row per recurring
  subscription line item.

The report query should be a fast grouped lookup-table query. Live subscription
objects and order-item tables are source-of-truth inputs for sync/backfill, not
the request-time reporting path.

### What We Reuse vs. Build

| Layer | Reuse from WooCommerce / Subscriptions | We build |
|-------|----------------------------------------|----------|
| Analytics shell | WooCommerce Analytics routing, report list, date controls, `GenericController`, `GenericQuery`, CSV export conventions | a custom report controller, data store, schema, and React report |
| Source data | WooCommerce Subscriptions APIs, data stores, schedule/status helpers, renewal hooks | extraction into analytics rows |
| Lookup model | Core analytics design patterns from `wc_order_stats` / `wc_order_product_lookup` | subscription stats and subscription product lookup tables |
| Data lifecycle | WordPress options, dbDelta, Action Scheduler, WP-CLI conventions | schema versioning, migrations, backfill, incremental sync, repair/regeneration |
| Authorization | WooCommerce admin capabilities | `manage_woocommerce` gates on reports and regeneration tools |
| Validation | Existing Subscriptions reports can provide comparison points | reconciliation tests and admin sync status |

---

## 2. Locked Decisions

1. **Standalone dependencies only.** The plugin may depend on WooCommerce and
   WooCommerce Subscriptions. It must not require, call, or delegate to AutomateWoo
   or merchant-specific custom plugins.
2. **Custom analytics tables are v1 scope.** The comprehensive route is deliberate:
   table creation, schema versioning, migrations, backfill, sync, and repair are
   part of v1, not a later optimization.
3. **Runtime reports read lookup tables.** Direct grouped SQL over live
   subscription/order-item storage may exist only as validation, repair, or
   migration support.
4. **v1 report is Upcoming Renewals.** The table model should support sibling
   reports later, especially renewal-revenue forecast and churn, but those reports
   are not v1 deliverables.
5. **Private distribution, upstream-quality architecture.** Build as if this could
   inform WooCommerce Subscriptions' future analytics implementation.
6. **HPOS compatibility is mandatory.** Source reads during sync/backfill must use
   WooCommerce/WooCommerce Subscriptions APIs or documented HPOS-aware query
   helpers.

---

## 3. Data Model

### `wc_subscriptions_stats`

One row per subscription. Working columns:

| Column | Purpose |
|--------|---------|
| `subscription_id` | Primary key; source subscription ID |
| `parent_order_id` | Initial/parent order where available |
| `customer_id` | Internal customer/user ID for future cohort analysis |
| `status` | Subscription status without the `wc-` prefix where practical |
| `date_created_gmt` | Subscription creation date |
| `date_updated_gmt` | Source subscription updated date |
| `start_date_gmt` | Subscription start date |
| `trial_end_date_gmt` | Trial end date, nullable |
| `last_payment_date_gmt` | Last successful payment date, nullable |
| `next_payment_date_gmt` | Next scheduled payment date, nullable and indexed |
| `end_date_gmt` | End date, nullable |
| `billing_period` | day/week/month/year |
| `billing_interval` | Numeric billing interval |
| `recurring_total` | Current recurring order total |
| `recurring_tax_total` | Current recurring tax total |
| `recurring_shipping_total` | Current recurring shipping total |
| `currency` | Subscription currency |
| `payment_method` | Payment method ID, nullable |
| `synced_at_gmt` | Last analytics row sync time |

Minimum indexes:

- Primary key: `subscription_id`
- `status_next_payment`: `(status, next_payment_date_gmt, subscription_id)`
- `next_payment`: `(next_payment_date_gmt)`
- `customer`: `(customer_id)`
- `synced`: `(synced_at_gmt)`

### `wc_subscription_product_lookup`

One row per recurring subscription line item.

| Column | Purpose |
|--------|---------|
| `subscription_id` | Source subscription ID |
| `line_item_id` | Subscription line item ID |
| `product_id` | Parent product ID |
| `variation_id` | Variation ID, `0` for simple products |
| `product_name` | Snapshot name for reporting exports |
| `product_qty` | Recurring quantity |
| `line_subtotal` | Recurring line subtotal |
| `line_total` | Recurring line total |
| `line_tax` | Recurring line tax |
| `synced_at_gmt` | Last analytics row sync time |

Minimum indexes:

- Primary key: `(subscription_id, line_item_id)`
- `subscription`: `(subscription_id)`
- `product_variation`: `(product_id, variation_id)`
- `product`: `(product_id)`

### Upcoming Renewals Query Shape

The report should aggregate from lookup tables:

```sql
SELECT
	product_lookup.product_id,
	product_lookup.variation_id,
	product_lookup.product_name,
	SUM(product_lookup.product_qty) AS total_qty,
	COUNT(DISTINCT stats.subscription_id) AS subscription_count,
	SUM(product_lookup.line_total) AS recurring_total
FROM {$wpdb->prefix}wc_subscriptions_stats stats
INNER JOIN {$wpdb->prefix}wc_subscription_product_lookup product_lookup
	ON stats.subscription_id = product_lookup.subscription_id
WHERE stats.status = 'active'
	AND stats.next_payment_date_gmt >= %s
	AND stats.next_payment_date_gmt < %s
GROUP BY product_lookup.product_id, product_lookup.variation_id, product_lookup.product_name
```

The real implementation must use `$wpdb->prepare()`, configurable status filters,
pagination/order handling, and table-name helpers.

---

## 4. Architecture & File Layout

```
additional-subscriptions-analytics/
├── additional-subscriptions-analytics.php
├── uninstall.php
├── readme.txt
├── composer.json
├── package.json
├── webpack.config.js
├── phpcs.xml.dist  phpstan.neon.dist  phpunit.xml.dist  playwright.config.ts
├── .github/workflows/ci.yml
├── src/
│   ├── Plugin.php
│   ├── Admin/
│   │   ├── Assets.php
│   │   ├── Menu.php
│   │   └── SyncStatus.php
│   ├── Analytics/
│   │   ├── ReportRegistry.php
│   │   └── UpcomingRenewals/
│   │       ├── Controller.php
│   │       └── DataStore.php
│   ├── Data/
│   │   ├── DateWindow.php
│   │   ├── SubscriptionAnalyticsRepository.php
│   │   ├── UpcomingRenewalsQuery.php
│   │   └── TableNames.php
│   ├── Database/
│   │   ├── Installer.php
│   │   ├── Migrator.php
│   │   └── Schema.php
│   ├── Sync/
│   │   ├── BackfillScheduler.php
│   │   ├── SubscriptionRowBuilder.php
│   │   ├── ProductLookupRowBuilder.php
│   │   ├── SubscriptionSyncer.php
│   │   ├── SyncHooks.php
│   │   └── RepairCommands.php
│   └── Support/
│       └── Compat.php
├── client/
│   ├── index.js
│   └── reports/upcoming-renewals/
│       ├── index.js
│       └── config.js
├── languages/
└── tests/
    ├── Unit/
    ├── Integration/
    ├── E2E/
    └── bootstrap.php
```

---

## 5. Build Phases

Each phase is independently testable and lands behind passing CI. Estimates are
rough developer-days for one engineer and may shift after inspecting WooCommerce
core and WooCommerce Subscriptions internals.

### Phase 0 - Scaffold & Guardrails  *(~1 day)*

**Status:** Complete.

- Main plugin file: full header; constants (`ASA_VERSION`, `ASA_PATH`, `ASA_URL`,
  `ASA_BASENAME`); Composer PSR-4 autoload; `plugins_loaded` bootstrap.
- Declare HPOS compatibility (`custom_order_tables`) in
  `before_woocommerce_init` via `FeaturesUtil::declare_compatibility()`.
- Hard guards: WooCommerce active; WooCommerce >= 9.3; Subscriptions active;
  Subscriptions >= 6.0; PHP >= 8.0.
- Tooling configs: PHPCS, PHPStan, PHPUnit, Playwright, `@wordpress/scripts`,
  Woo dependency extraction, CI.
- Baseline plugin lifecycle hooks for activation, deactivation, and uninstall.
- **Exit:** plugin activates cleanly with dependencies, no-ops with clear admin
  notices when dependencies are missing, and empty PHP/JS builds pass.

### Phase 1 - Core/Subs Reference Audit  *(~0.5 day)*

**Status:** Complete. See `documentation/REFERENCE_AUDIT.md`.

- Inspect WooCommerce core analytics patterns for lookup-table lifecycle,
  especially order stats/product lookup schema, data stores, report controllers,
  table regeneration, and Action Scheduler usage.
- Inspect WooCommerce Subscriptions for existing reports, cache managers, HPOS
  helpers, subscription data stores, schedule-date APIs, renewal lifecycle hooks,
  status transitions, and line-item mutation paths.
- Produce a hook map for events that must trigger analytics-table resync.
- Confirm final table names, columns, indexes, and sync boundaries before writing
  migrations.
- **Exit:** schema and sync design are grounded in core WooCommerce Analytics and
  WooCommerce Subscriptions internals, with no dependency on non-required plugins.

Audit decisions now locked for implementation:

- Tables remain `wc_subscriptions_stats` and
  `wc_subscription_product_lookup`.
- Backfill enumerates subscriptions through WooCommerce Subscriptions/WooCommerce
  order query APIs, not direct source table SQL.
- Runtime report queries read only plugin-owned lookup tables.
- Incremental product lookup sync replaces all product lookup rows for the
  affected subscription.
- Incremental stats sync is driven by subscription create/update, status, date,
  payment, failed-payment, switch, trash, and delete events.

### Phase 2 - Database Schema, Versioning & Lifecycle  *(~2 days)*

**Status:** Complete.

- `Database/Schema.php`: authoritative schema definitions for both analytics
  tables, including column types, indexes, charset/collation, and schema version.
- `Database/Installer.php`: create tables with `dbDelta()` on activation or first
  load when missing.
- `Database/Migrator.php`: compare stored `asa_db_version` to code version and run
  ordered, idempotent migrations.
- Store lifecycle options: `asa_db_version`, `asa_backfill_status`,
  `asa_backfill_started_at_gmt`, `asa_backfill_completed_at_gmt`,
  `asa_last_sync_at_gmt`.
- `uninstall.php`: remove plugin options, scheduled actions, and plugin-owned
  tables for this private prototype.
- **Tests:** schema SQL snapshot tests; install/upgrade idempotency; indexes exist;
  uninstall cleans plugin-owned state.
- **Exit:** fresh install and version upgrade produce correct tables and options
  without data loss.

### Phase 3 - Source Extraction & Row Builders  *(~2 days)*

**Status:** Complete.

- `Sync/SubscriptionRowBuilder.php`: convert a `WC_Subscription` into one
  `wc_subscriptions_stats` row.
- `Sync/ProductLookupRowBuilder.php`: convert subscription line items into
  `wc_subscription_product_lookup` rows.
- Normalize dates to GMT and keep site-local date math isolated in
  `Data/DateWindow.php`.
- Decide canonical status handling: default v1 includes `active`, excludes
  `pending-cancel` unless explicitly enabled later.
- Use WooCommerce Subscriptions APIs for schedule dates, totals, status, line
  items, and product IDs. Avoid reading order post meta directly.
- **Tests:** unit tests for row shaping, date normalization, decimal precision,
  missing/deleted products, variation products, line item quantities, and nullable
  schedule dates.
- **Exit:** row builders produce stable table rows from seeded subscriptions.

### Phase 4 - Backfill & Regeneration  *(~2.5 days)*

**Status:** Complete. Scheduler, persistence, syncer, repair service, and WP-CLI
command wiring are implemented with unit coverage. Integration coverage now
exercises default backfill against real WooCommerce/WooCommerce Subscriptions
storage. Admin UI entry points remain scoped to Phase 8.

- `Data/TableNames.php`: introduced early so all Phase 4 SQL uses centralized
  plugin-owned table names.
- `Data/SubscriptionAnalyticsRepository.php`: introduced early to own lookup
  table writes, deletes, stale-row detection, truncation, and orphan cleanup.
- `Sync/SubscriptionSyncer.php`: introduced early to make backfill and repair
  paths write the same row shapes as incremental sync will use.
- `Sync/BackfillScheduler.php`: chunk all existing subscriptions through Action
  Scheduler.
- Support resumable backfill with status options and clear failure states.
- Add WP-CLI/admin repair commands for:
  - full regenerate
  - single subscription resync
  - stale row detection
  - orphan product lookup cleanup
- Avoid long-running admin requests; all large work runs in scheduled chunks.
- **Tests:** chunking, resume after failure, duplicate job idempotency, stale row
  repair, missing subscription cleanup, HPOS and legacy storage coverage.
- **Exit:** a large store can build or rebuild both analytics tables without
  blocking admin page loads.

### Phase 5 - Incremental Sync  *(~2 days)*

**Status:** Complete. `SyncHooks` registers the Subscriptions lifecycle hooks,
debounces single-subscription sync actions via Action Scheduler, deletes
analytics rows on subscription deletion, and has unit coverage for idempotent
queueing, item mutation hooks, deletion, and scheduled sync processing.
Integration coverage now exercises real WooCommerce Subscriptions status,
next-payment date, renewal completion, failed renewal, line item update,
deletion, and duplicate-event queueing paths.

- `Sync/SyncHooks.php`: subscribe to WooCommerce Subscriptions lifecycle events
  that can change analytics rows:
  - `woocommerce_new_subscription`
  - `woocommerce_update_subscription`
  - `woocommerce_subscription_status_changed`
  - `woocommerce_subscription_date_updated`
  - `woocommerce_subscription_date_deleted`
  - `woocommerce_subscription_payment_complete`
  - `woocommerce_subscription_renewal_payment_complete`
  - `woocommerce_subscription_payment_failed`
  - `woocommerce_subscription_renewal_payment_failed`
  - `woocommerce_new_order_item`
  - `woocommerce_update_order_item`
  - `woocommerce_before_delete_order_item`
  - `wcs_user_removed_item`
  - `wcs_user_readded_item`
  - `woocommerce_subscriptions_switch_completed`
  - `woocommerce_subscriptions_switched_item`
  - `woocommerce_before_delete_subscription`
  - `woocommerce_delete_subscription`
  - `woocommerce_subscription_deleted`
  - `woocommerce_before_trash_subscription`
  - `woocommerce_trash_subscription`
  - `woocommerce_subscription_trashed`
- Extend `Sync/SubscriptionSyncer.php` from Phase 4 for delayed/debounced
  incremental events.
- Use Action Scheduler for delayed/debounced sync where multiple hooks fire for
  the same subscription.
- **Tests:** event-driven sync for status changes, next-payment changes, renewal
  completion, failed renewal, line item update, deletion, duplicate events, and
  concurrent resync requests.
- **Exit:** analytics rows stay current after normal subscription operations.

### Phase 6 - Report Query Data Layer  *(~1.5 days)*

**Status:** Complete. `UpcomingRenewalsQuery` now converts Analytics date ranges
to GMT lookup-table windows, aggregates only plugin-owned analytics tables,
supports sanitized status filters, whitelisted sorting, pagination, totals, and
export-sized requests. `Analytics\UpcomingRenewals\DataStore` adapts those query
results to the WooCommerce Analytics `GenericController` shape, and the data
store is registered with WooCommerce. Unit and wp-env integration coverage
exercises DST windows, active-only defaults, sorting/pagination,
variation-level granularity, deleted product snapshots, data-store shape, and
backfill-produced source reconciliation.

- Extend `Data/TableNames.php` from Phase 4 as needed for report queries.
- `Data/DateWindow.php`: convert Analytics `after`/`before` site-local range into
  GMT `[start, end)` windows against `next_payment_date_gmt`.
- `Data/UpcomingRenewalsQuery.php`: grouped SQL against lookup tables, with
  sanitized status filters, allowed orderby fields, pagination, totals, and CSV
  export support.
- `Analytics/UpcomingRenewals/DataStore.php`: wraps query results in the shape
  expected by WooCommerce Analytics `GenericController`.
- Register the data store via `woocommerce_data_stores`.
- **Tests:** DST boundaries, future date windows, query sorting/pagination,
  active-only filtering, variation-level granularity, deleted products, and
  reconciliation against seeded source subscriptions.
- **Exit:** `DataStore::get_data()` returns correct aggregates from analytics
  tables only.

### Phase 7 - REST API  *(~1.5 days)*

**Status:** Complete. `Analytics\UpcomingRenewals\Controller` now registers the
WooCommerce Analytics REST report at `/wp-json/wc-analytics/reports/upcoming-renewals`,
requires `manage_woocommerce`, exposes the public report schema, maps response
rows from the lookup-table data store, adds product REST/edit/view links where
available, and defines CSV export columns/item mapping. wp-env integration
coverage exercises controller/report registration, schema-valid payloads,
pagination headers, link generation, CSV mapping, invalid `orderby` rejection,
auth rejection, and empty-table/backfill-needed behavior.

- `Analytics/UpcomingRenewals/Controller.php` extends
  `Automattic\WooCommerce\Admin\API\Reports\GenericController` and implements the
  Analytics export interface used by WooCommerce.
- `$rest_base = 'reports/upcoming-renewals'`, served at
  `/wp-json/wc-analytics/reports/upcoming-renewals`.
- `get_item_schema()` exposes `product_id`, `variation_id`, `product_name`,
  `total_qty`, `subscription_count`, and `recurring_total`.
- `prepare_item_for_response()` adds product edit/view links where available.
- `get_export_columns()` and `prepare_item_for_export()` define CSV output.
- `permission_callback` requires `manage_woocommerce`.
- **Tests:** schema-valid payload, pagination headers, CSV mapping,
  unauthenticated/under-capability rejection, invalid orderby rejection, and
  behavior when tables need backfill.
- **Exit:** endpoint is queryable, exportable, and authorization-gated.

### Phase 8 - Admin Menu, Sync Status & Client Report  *(~3 days)*

**Status:** Complete. The report is registered under WooCommerce Analytics at
`/analytics/upcoming-renewals`, admin/runtime sync status is exposed for missing
tables, running/failed backfills, and stale data, and the WooCommerce Admin
client registers an Upcoming renewals report with Next Friday, Next 7 days, and
Next 30 days presets. The report renders product-level upcoming renewal counts
from the Phase 7 Analytics endpoint, supports sorting/pagination, and exports
CSV from the visible table or the report export endpoint. Integration coverage
exercises menu registration and sync states; Playwright coverage is present for
report navigation, date-window changes, sorting, CSV download, and sync notices,
with a local skip when the mounted WooCommerce checkout has not built its admin
asset registry.

- `Admin/Menu.php`: add the report under WooCommerce -> Analytics
  (`id: asa-upcoming-renewals`, `path: /analytics/upcoming-renewals`).
- `Admin/SyncStatus.php`: show admin notices/status when tables are missing,
  backfill is running, backfill failed, or rows are stale.
- `client/index.js`: register the report with WooCommerce Admin.
- `client/reports/upcoming-renewals/index.js`: render the report table using
  WooCommerce Analytics components and `@woocommerce/data`.
- Add future-oriented presets such as Next Friday, Next 7 days, and Next 30 days.
  Hide or avoid period comparison if it does not make sense for future cohorts.
- **Tests:** Playwright flow for menu navigation, table rendering, future date
  range changes, sorting, CSV download, and sync-status notices.
- **Exit:** a merchant can open the report, pick a future window, see per-product
  counts, sort, and export CSV.

### Phase 9 - Validation & Reconciliation  *(~1 day)*

- Add diagnostics that compare lookup-table aggregates against source
  subscriptions for a selected window.
- Validate BVO's next-Friday counts against the manual subscriptions-list method.
- Document expected differences from legacy *Upcoming Recurring Revenue* and
  existing Subscriptions reports.
- Capture any bugs as data-sync issues first, report-rendering issues second.
- **Exit:** table-backed report reconciles with source subscription data and BVO
  confirms the operational counts.

### Phase 10 - Hardening, Docs & Packaging  *(~1.5 days)*

- Run code health and traceability review before release.
- Run upgrade-safety review because schema and migrations are v1-critical.
- WooCommerce QIT compatibility checks.
- `readme.txt`, PHPDoc, translations/POT, admin docs for regeneration and table
  lifecycle.
- CI green: PHPCS, PHPStan >= level 6, PHPUnit, Playwright.
- **Exit:** tagged, installable build with all checks passing.

---

## 6. Non-Negotiable Standards

- No dependency on AutomateWoo or merchant-specific plugins.
- HPOS-compatible source reads only. Do not use raw `wp_posts` / `wp_postmeta`
  reads for orders/subscriptions unless a specific, documented Subscriptions
  helper requires it and no stable API exists.
- All SQL is prepared. All table names come from `TableNames`.
- Migrations are idempotent and tested.
- Backfill and sync are idempotent. Duplicate events must not create duplicate
  lookup rows.
- Every admin action and REST endpoint is capability-gated with
  `manage_woocommerce`.
- All strings are translatable with the `additional-subscriptions-analytics` text
  domain.
- Tests exist for every feature, including schema lifecycle and sync behavior.

---

## 7. Key Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| **Analytics table drift** | Idempotent syncer, regeneration tools, stale-row detection, reconciliation diagnostics. |
| **Missing Subscriptions hooks for some mutations** | Inspect Subscriptions internals, cover save/status/schedule/renewal/line-item paths, and add fallback resync on subscription save. |
| **Initial backfill load** | Action Scheduler chunking, resumable status, CLI/admin repair, no large admin requests. |
| **Timezone math** | Store dates in GMT, isolate site-local Analytics windows in `DateWindow`, heavily test DST boundaries. |
| **Future-oriented Analytics UX** | Custom presets and defaults; avoid misleading period comparison. |
| **Schema evolution** | Explicit `asa_db_version`, ordered migrations, upgrade tests, upgrade-safety review. |
| **Scale** | Indexed lookup tables and grouped queries over stats/product lookup rows, not live object loading. |
| **Upstream API drift** | Hard version guards and targeted integration tests against supported WooCommerce/WooCommerce Subscriptions versions. |

---

## 8. Merchant-Facing Caveats To Ship In UI / Docs

- **It's an estimate:** subscriptions paused, cancelled, edited, or failed between
  view time and renewal date may change the final charged count.
- Counts active subscriptions by default. `pending-cancel` is excluded in v1 unless
  a future setting says otherwise.
- Add-ons, bundles, and child line items may appear as separate recurring line
  items depending on how Subscriptions stores them.
- The report is read-only. Data regeneration affects analytics lookup tables only;
  it does not edit subscriptions.

---

## 9. Definition Of Done (v1)

- Custom subscription analytics tables are created, versioned, migrated, backfilled,
  incrementally synced, and repairable.
- Native **Upcoming Renewals** report under WooCommerce -> Analytics with future
  date range, sortable table, and CSV export.
- Report runtime reads analytics lookup tables only.
- Correct on HPOS and legacy storage; gated by `manage_woocommerce`.
- Unit, integration, E2E, PHPCS, PHPStan, and QIT checks are green.
- BVO live validation confirms the per-product upcoming renewal counts.

---

## 10. Open Questions (Defaults Chosen)

- Include `pending-cancel` subscriptions? **Default: no.**
- Show recurring revenue column to the merchant, or qty/sub-count only?
  **Default: show it.**
- Store customer IDs in `wc_subscriptions_stats`? **Default: yes**, because it
  unlocks future cohort/churn reports and stores only internal IDs, not names or
  emails.
- Final upstream table names if this informs WooCommerce Subscriptions core?
  **Default for prototype:** `wc_subscriptions_stats` and
  `wc_subscription_product_lookup`; rename only if upstream conventions require it.
