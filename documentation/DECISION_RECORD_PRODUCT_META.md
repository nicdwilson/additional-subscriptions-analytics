# Decision Record: Do Not Snapshot Mutable Product Meta (SKU, etc.)

- **Status:** Accepted
- **Date:** 2026-07-08
- **Applies to:** `wc_subscription_product_lookup` and any future plugin-owned
  analytics lookup tables that surface product attributes.
- **Related:** [`UPCOMING_RENEWALS_SKU_PLAN.md`](./UPCOMING_RENEWALS_SKU_PLAN.md),
  [`PROJECT_BRIEF.md`](./PROJECT_BRIEF.md)

## Context
The plugin owns denormalized lookup tables and snapshots certain line-item data
(e.g. `product_name`) at sync time so date-windowed aggregation stays fast and
survives product deletion. Adding **SKU** to the Upcoming Renewals report raised
the question of whether SKU should be snapshotted the same way.

SKU differs from the data we already snapshot. `product_name` on a subscription
line item is a **historical transaction fact** — what the line was called at the
time — and is meaningful even after the product is gone. **SKU is current product
identity**: for a forward-looking pick/pack report, the operator needs the SKU the
product has *right now* (barcode, warehouse code), not whatever it was when the
subscription last synced.

Snapshotting a mutable, current-identity attribute introduces two defects:

1. **Staleness.** Editing a product's SKU leaves every previously-synced row
   holding the old value until it is re-synced.
2. **Cross-subscription inconsistency.** The report aggregates by
   `product_id:variation_id:currency`. Subscriptions of the same product sync at
   different times, so one aggregated row can draw from stored rows with
   *different* SKUs. Any "pick one" rule (e.g. `max()`) is nondeterministic. No
   re-sync cadence removes this class of bug.

## Decision
**We do not store SKU (or other mutable, current-identity product meta) in the
lookup tables. We resolve it from the live product at read time**, in the REST
controller, after aggregation.

This is viable and cheap because:
- Aggregation collapses to one row per product/variation (≤ 100 per page), so
  resolution runs over a small, bounded set — not per line item.
- The controller **already loads the live product per visible row**
  (`Controller::prepare_links()` calls `wc_get_product()` for edit/view links).
  Reading `->get_sku()` from that object adds effectively no cost.

The performance rationale for snapshotting — avoiding a join across thousands of
line items during date-windowed aggregation — is untouched: it lives in the query
layer, before resolution.

## Consequences

### Positive
- **Always fresh and internally consistent** by construction. A SKU edit shows on
  the next report load with no re-sync, regeneration, or migration.
- **No schema change, no `DB_VERSION` bump, no `dbDelta` migration, no backfill,
  and no freshness machinery** (no product-save hooks, no
  `synced_at_gmt`-based tie-breaking).
- Lookup tables stay limited to stable facts (IDs, name snapshot, quantities,
  totals), keeping their contract clear.

### Negative / accepted costs
- **CSV export does the heavy lifting.** Interactive report pages are naturally
  bounded (≤ 100 rows), but a full export can span many rows/batches, and each
  prepared row resolves its product live. This is the main cost we are knowingly
  accepting. Mitigations, in order of escalation:
  1. Rely on the per-row `wc_get_product()` load (mirrors interactive behaviour;
     acceptable for typical merchant catalog sizes).
  2. If export profiling shows a problem, resolve SKUs per export batch with a
     single batched query against `wc_product_meta_lookup.sku`
     (`... WHERE product_id IN (...)`, indexed) keyed on the
     variation-id-or-product-id set.
- **Deleted products show a blank SKU.** Correct for this report — a deleted
  product has no current SKU and cannot be picked — and `product_name` still
  shows the historical snapshot. But note: reports that need a SKU for *deleted*
  products (e.g. historical reconciliation) are not served by this approach.
- **SKU is not server-side sortable in v1.** Because SKU is attached after the
  query's PHP sort, sorting by SKU would require resolving SKUs for the full
  candidate set before sorting (a batched `wc_product_meta_lookup` lookup). Left
  out of v1; documented as a v1.1 option.

## Revisit Triggers (signs we may have chosen wrong)
Reconsider snapshotting if any of these hold:
- Export or report render times degrade materially due to per-row product loads
  at a real merchant's catalog scale, **and** batched read-time lookup does not
  fix it.
- A requirement emerges to show SKU (or other meta) for **deleted** products, or
  to preserve the **historical** SKU as it was at renewal/charge time — at which
  point the value genuinely becomes a transaction fact and snapshotting is
  correct.
- We need to **filter or sort** on SKU at query scale in a way that read-time
  resolution cannot serve efficiently.

## Alternative If We Revisit: snapshot with a freshness strategy
If we later decide to store SKU (or similar meta) in the lookup table, the
migration is additive and the freshness defects above **must** be addressed
together, not piecemeal:

- Add `product_sku varchar(100) NOT NULL DEFAULT ''`; bump `Schema::DB_VERSION`
  so `Migrator` → `Installer::install()` → `dbDelta` adds the column. Populate in
  `ProductLookupRowBuilder` (via `$item->get_product()->get_sku()`) and persist
  in `SubscriptionAnalyticsRepository` (keep row keys and format array aligned).
- **Freshness:** on `woocommerce_update_product`, re-sync **all** lookup rows for
  the affected `product_id` atomically so no mixed-age rows survive within one
  product.
- **Backstop:** full regeneration via existing repair tooling; release notes
  instruct operators to regenerate to backfill.
- **Deterministic aggregation:** carry the SKU from the **freshest** row by
  `synced_at_gmt`, not `max()`, to make any residual mixed-age window
  deterministic.

The trade being made in reverse: we would gain deleted-product/historical SKUs
and cheaper exports, at the cost of a staleness window, more moving parts, and a
schema migration. That trade is only worth it if a revisit trigger above fires.
