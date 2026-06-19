# Admin Operations

This plugin is an admin-only analytics extension. It maintains local lookup
tables for WooCommerce Subscriptions data and reads those tables from the
WooCommerce Analytics report runtime.

## Table Lifecycle

The plugin owns two tables:

- `{$wpdb->prefix}wc_subscriptions_stats`
- `{$wpdb->prefix}wc_subscription_product_lookup`

Schema state is tracked in the `asa_db_version` option. Activation runs the
migrator once, and normal admin traffic calls the migrator behind a version/table
existence gate so missing tables can be recreated without running schema work on
every request.

Lifecycle options:

- `asa_db_version`: installed schema version.
- `asa_backfill_status`: `not_started`, `queued`, `running`, `completed`, or
  `failed`.
- `asa_backfill_started_at_gmt`: GMT timestamp for the current or last backfill.
- `asa_backfill_completed_at_gmt`: GMT timestamp for the last successful
  backfill.
- `asa_last_sync_at_gmt`: GMT timestamp for the last successful incremental
  subscription sync.
- `asa_backfill_last_page`: last processed source-subscription page for resumable
  backfills.
- `asa_backfill_failure`: last backfill failure message.

Deleting the plugin through WordPress runs `uninstall.php`, clears queued plugin
actions, removes `asa_` options, and drops both lookup tables.

## Backfill And Regeneration

Initial activation creates tables, then queues a non-destructive backfill through
Action Scheduler. The report surfaces sync status notices when data is not
ready, is running, or has failed.

Merchants can manage subscription analytics import state from
**WooCommerce > Analytics > Settings**:

- **Backfill missing data** preserves existing lookup rows and imports
  subscriptions that are not already present.
- **Delete and rebuild data** queues a full replacement regeneration. The
  scheduled job truncates plugin-owned lookup tables, then repopulates them from
  current WooCommerce Subscriptions source data.

This plugin does not currently maintain a separate report cache. There is
therefore no **WooCommerce > Status > Tools** cache-clearing entry for
subscription analytics. If cached aggregates are introduced later, cache clearing
should be added there through WooCommerce's debug-tools surface.

Use full regeneration when table data looks broadly stale, when schema lifecycle
work has run after a deployment, or when validation reports many mismatches:

```bash
wp asa regenerate
```

Regeneration truncates and repopulates plugin-owned lookup tables only. It does
not edit source subscriptions, orders, products, payment methods, or customer
data.

## Targeted Repair Commands

Resync one subscription:

```bash
wp asa resync-subscription <subscription-id>
```

Repair stale rows:

```bash
wp asa repair-stale
```

Clean product lookup rows whose subscription stats row no longer exists:

```bash
wp asa cleanup-orphans
```

## Reconciliation

Reconcile report lookup aggregates against live WooCommerce Subscriptions source
data for a selected Analytics window:

```bash
wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active
```

For BVO next-Friday validation, use the same date for `--after` and `--before`.
Date-only report boundaries are interpreted as inclusive local dates and
converted to a half-open GMT query window internally.

Large stores can increase the source scan limit:

```bash
wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active --limit=25000
```

JSON output is available for issue capture:

```bash
wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active --format=json
```

Treat reconciliation mismatches as data-sync issues first. Regenerate or resync
the affected subscriptions before investigating REST or React rendering.

## Access Control

All admin and diagnostic surfaces require `manage_woocommerce`:

- WooCommerce Analytics report registration.
- Upcoming renewals REST report.
- Reconciliation REST endpoint.
- Admin sync status notices.
- WP-CLI repair and reconciliation commands through server shell access.

## Expected Report Caveats

- Counts active subscriptions by default.
- Product totals are subscription line-item totals, not order totals.
- Shipping, tax, fees, discounts, retries, and renewal-order payment outcomes are
  outside the Upcoming renewals report definition.
- Deleted products can still appear by stored subscription line-item product name.
- Renewals edited, paused, cancelled, or failed after report view time can change
  final charged counts.
