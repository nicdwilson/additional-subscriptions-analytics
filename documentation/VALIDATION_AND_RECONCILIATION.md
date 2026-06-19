# Validation and Reconciliation

Phase 9 adds a repeatable way to compare the Upcoming renewals lookup-table
report with live WooCommerce Subscriptions source data for the same date window.

## What the Diagnostic Checks

The diagnostic scans source subscriptions through WooCommerce Subscriptions APIs,
filters by status and next payment date, rebuilds product-line aggregates, and
compares those results with the plugin-owned lookup tables used by the report.

For each product, variation, and currency group it compares:

- renewal quantity
- distinct subscription count
- recurring line total

If those values differ, treat the issue as a data-sync problem first. Resync the
affected subscriptions or regenerate the lookup tables before investigating REST
or React report rendering.

## BVO Next-Friday Validation

Use the same date for `--after` and `--before`; report date-only boundaries are
inclusive in the UI and converted internally to a half-open GMT window.

```bash
wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active
```

For machine-readable output:

```bash
wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active --format=json
```

The report UI also has a **Validate data** action for the currently selected
window. It calls:

```text
/wp-json/wc-analytics/reports/upcoming-renewals/reconcile
```

The default scan limit is 5,000 source subscriptions. Increase it for large
stores:

```bash
wp asa reconcile-upcoming-renewals --after=2026-07-03 --before=2026-07-03 --status=active --limit=25000
```

## Expected Differences From Other Reports

This report is intentionally operational and product-line based. It can differ
from legacy *Upcoming Recurring Revenue* or general Subscriptions reports because
those reports may:

- count revenue at subscription/order level instead of product-line level
- include statuses other than active, depending on their filters
- use site-local display dates while this plugin stores lookup dates in GMT
- include shipping, tax, discounts, fees, or retries that are not product-line
  recurring totals
- reflect live subscription data immediately while this report waits for sync or
  backfill rows
- hide deleted products, while this report keeps the subscription line-item
  product name snapshot

When source and lookup diagnostics match but another report differs, compare the
filter scope and revenue definition before treating the difference as a bug.
