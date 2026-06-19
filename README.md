# Additional Subscriptions Analytics

Additional Subscriptions Analytics adds native WooCommerce Analytics reports
backed by subscription analytics lookup tables. The first report, **Upcoming
renewals**, shows future subscription renewals grouped by product, variation, and
currency so merchants can prepare operational pick/pack counts before renewal
orders are charged.

This is an admin-only WooCommerce Subscriptions extension. It does not change the
storefront, cart, checkout, My Account, payment processing, or subscription
editing flows.

## Requirements

- WordPress 6.4 or newer
- WooCommerce 9.3 or newer
- WooCommerce Subscriptions 6.0 or newer
- PHP 8.0 or newer
- Node 20.11.x and npm 10.x for development builds

## Data Model

The plugin creates and owns two lookup tables:

- `{$wpdb->prefix}wc_subscriptions_stats`
- `{$wpdb->prefix}wc_subscription_product_lookup`

The schema is versioned through the `asa_db_version` option and maintained with
idempotent `dbDelta()` migrations. Lookup rows are populated by a chunked Action
Scheduler backfill and kept current by subscription save/status/date/line-item
sync hooks.

## Admin Operations

Initial activation queues a non-destructive Action Scheduler backfill
automatically. Merchants can manage subscription analytics data from
**WooCommerce > Analytics > Settings**:

- **Backfill missing data** refreshes derived lookup rows without truncating the
  tables, repairing missing or incomplete subscription analytics rows.
- **Delete and rebuild data** removes plugin-owned lookup rows and performs a
  complete backfill from current WooCommerce Subscriptions source data.

Common maintenance commands:

```bash
wp asa regenerate
wp asa resync-subscription <subscription-id>
wp asa repair-stale
wp asa cleanup-orphans
wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active
```

See `documentation/ADMIN_OPERATIONS.md` and
`documentation/VALIDATION_AND_RECONCILIATION.md` for the full operational
workflow.

## Development

Install dependencies:

```bash
composer install
npm ci
```

Run validation:

```bash
composer validate --strict
composer lint
composer phpstan
composer test
npm run lint:js
npm run build
npm run test:integration:wp-env
npm run test:e2e
```

Build an installable package:

```bash
npm run package
```

The package is written to `additional-subscriptions-analytics.zip`.

## Release

Release preparation lives in:

- `documentation/RELEASE_CHECKLIST.md`
- `documentation/FINALIZATION_TASKS.md`
- `documentation/UPGRADE_SAFETY_REPORT.md`

WooCommerce QIT managed tests require an authenticated QIT CLI session:

```bash
qit connect
npm run qit:compat
```
