# Upgrade Safety Report

## Plugin

Additional Subscriptions Analytics `0.1.0-dev` source checkout -> `0.1.0`
private release.

## Date

2026-06-20

## Upgrade Risk Level

**HIGH for first production deployment** because v0.1.0 introduces custom
database tables, schema versioning, Action Scheduler jobs, and WooCommerce
feature compatibility declarations.

**LOW for existing merchant data loss** because there is no prior stable release
with production data to migrate, the table creation path is non-destructive, and
the plugin stores derived analytics data only.

## Diff Summary

- Version metadata moves from `0.1.0-dev` to `0.1.0`.
- No minimum PHP, WordPress, WooCommerce, or WooCommerce Subscriptions version
  increase during Phase 10.
- No payment gateway, payment token, checkout, cart, My Account, webhook, or
  order-payment flow changes.
- v0.1.0 creates two derived analytics lookup tables and lifecycle options.
- v0.1.0 adds Action Scheduler jobs for backfill, regeneration, stale repair, and
  subscription sync.

## Database Migrations

| Migration | Idempotent | Batched | Reversible | Status |
| --- | --- | --- | --- | --- |
| Create `wc_subscriptions_stats` with `dbDelta()` | Yes | N/A | Dropped only on uninstall | PASS |
| Create `wc_subscription_product_lookup` with `dbDelta()` | Yes | N/A | Dropped only on uninstall | PASS |
| Store `asa_db_version` | Yes | N/A | Option removed only on uninstall | PASS |
| Initial backfill of derived subscription rows | Yes | Yes, paged Action Scheduler batches | Regeneratable | PASS |

The migrator runs behind a schema-version and table-existence gate. Missing
tables are recreated, stale schema versions call `dbDelta()`, and forward schema
versions do not downgrade or delete data.

## Payment Continuity

| Check | Status | Notes |
| --- | --- | --- |
| Saved tokens preserved | N/A | The plugin does not read, write, migrate, or tokenize payment methods. |
| Active subscription payments safe | PASS | The plugin reads subscription data and does not alter renewal payment hooks, gateway IDs, mandates, or payment profiles. |
| Pending transactions safe | N/A | The plugin does not process orders or pending transactions. |
| Webhook backward compatibility | N/A | The plugin exposes no webhooks. |

## Hook Compatibility

| Hook/Filter | Change | Deprecated? | Replacement | Status |
| --- | --- | --- | --- | --- |
| `before_woocommerce_init` | Adds HPOS compatibility declaration | N/A | N/A | PASS |
| WooCommerce Admin report filters | Adds Upcoming renewals report registration | N/A | N/A | PASS |
| Subscriptions mutation hooks | Adds listeners for derived lookup sync | N/A | N/A | PASS |
| WP-CLI `asa *` commands | Adds repair/reconciliation commands | N/A | N/A | PASS |

No public hooks or filters are removed or renamed in v0.1.0.

## Rollback Assessment

- Downgrade safe: **Partial**. There is no previous stable version to downgrade
  to. If a merchant removes v0.1.0 and reinstalls an earlier development checkout,
  extra lookup tables/options should not break WooCommerce or WooCommerce
  Subscriptions.
- Auto-update safe: **Yes for v0.1.0**. No manual data migration is required.
- Manual steps required: **None** for the schema. Merchants should wait for the
  initial backfill to complete before relying on report counts.

## Changelog Review

- Breaking changes documented: N/A for first private release.
- Upgrade notice present: Yes, in `readme.txt`.
- Version metadata current: Yes, plugin header, `ASA_VERSION`, `package.json`,
  and `readme.txt` target `0.1.0`.

## Prioritized Upgrade Issues

No critical or high upgrade-safety issues are open for v0.1.0.

## Release Communication

Merchant-facing release notes should state:

- The plugin creates two derived analytics lookup tables.
- The first backfill may take time on stores with many subscriptions.
- Regeneration and repair commands affect analytics lookup tables only and do
  not edit subscriptions, orders, products, or payment data.
- Upcoming renewal counts are estimates until the actual renewal charge occurs.

## Phase 11 Addendum - 0.9.1

### Scope

Version `0.9.1` adds merchant-accessible controls for subscription analytics
backfill and replacement rebuilds under **WooCommerce > Analytics > Settings**.
It also queues the initial non-destructive backfill on activation or first
migration while the lifecycle state is still `not_started`.

### Upgrade Risk Level

**MEDIUM for operational load** because activation can enqueue Action Scheduler
work automatically on stores with existing subscriptions.

**LOW for merchant data loss** because both manual actions mutate only
plugin-owned derived lookup tables. The replacement action truncates and rebuilds
analytics rows, but does not edit subscriptions, orders, products, customers, or
payment data.

### Safety Notes

- No new database tables or schema migrations are introduced in `0.9.1`.
- Backfill and replacement actions require `manage_woocommerce` through a REST
  permission callback.
- Concurrent manual requests are blocked while a backfill or regeneration is
  queued or running.
- No **WooCommerce > Status > Tools** cache-clearing action is included because
  the plugin does not maintain a separate analytics cache layer.
- Rollback remains safe for source data. Derived lookup rows can be regenerated
  after reinstalling or upgrading again.
