# Implementation Plan: Add SKU to the Upcoming Renewals Report

## Goal
Add a **SKU** column to the **Upcoming Renewals** product report so operators can
match each upcoming renewal line to the product identifier they pick/pack and
reconcile against (barcode, warehouse SKU, supplier code). Today the report
surfaces Product title, Product ID, Variation ID, Renewal quantity,
Subscriptions, Recurring total, and first/last renewal dates.

## Design Decision: resolve the current SKU at read time (do **not** snapshot it)

> The rationale, accepted costs (notably export heavy-lifting), revisit triggers,
> and the fallback snapshot design are recorded in
> [`DECISION_RECORD_PRODUCT_META.md`](./DECISION_RECORD_PRODUCT_META.md). The
> summary below is scoped to this feature.

The report is **forward-looking**. The SKU an operator needs is *the product's
current SKU* — what's on the shelf / in the barcode system today — not a
historical value captured when the subscription was last synced. This makes SKU
fundamentally different from `product_name`, which the lookup table snapshots
precisely because it is a historical transaction fact that must survive product
deletion.

Storing the SKU in `wc_subscription_product_lookup` would introduce two freshness
defects:

1. **Staleness** — if a merchant edits a product's SKU, every previously-synced
   row keeps the old value until re-synced.
2. **Cross-subscription inconsistency (worse)** — the report aggregates by
   `product_id:variation_id:currency`. Different subscriptions of the *same*
   product are synced at different times, so a single aggregated row could draw
   from stored rows carrying *different* SKUs. Any "pick one" rule (e.g. `max()`)
   is then nondeterministic. No re-sync cadence fully removes this.

Both defects disappear if the SKU is resolved from the live product at read time,
because SKU then becomes a pure function of the current `product_id` /
`variation_id`, computed once per aggregated row.

### Why read-time resolution is cheap here (not a "live join")
The snapshot architecture exists to keep **date-windowed aggregation over
thousands of line items** fast — that concern lives entirely in the query layer
and is untouched by this plan. SKU resolution happens **after** aggregation, on a
bounded set (one row per product/variation, ≤ `MAX_PER_PAGE` = 100 per page).

Crucially, the REST controller **already loads the live product for every visible
row**: `Controller::prepare_links()` calls `wc_get_product( $product_id )` per row
to build the edit/view links. Reading `$product->get_sku()` from that
already-loaded object adds effectively zero cost. So this is not a new live
dependency — it reuses one that already exists at the controller layer.

### Consequences
- **Always fresh, always internally consistent** — no freshness machinery.
- **No schema change, no `DB_VERSION` bump, no `dbDelta` migration, no backfill.**
- **Deleted products show a blank SKU** — correct: a deleted product has no
  current SKU and cannot be picked; `product_name` still shows what it was.

## Affected Files

### 1. REST controller — `src/analytics/upcoming-renewals/controller.php`
This is where the SKU is attached. Refactor so the product is loaded once per row
and shared between link-building and SKU resolution:
- Extract the existing `wc_get_product()` load in `prepare_links()` into a shared
  helper (e.g. `get_product_for_item( array $item ): ?object`) so
  `prepare_item_for_response()` can reuse the same object for both links and SKU.
- `prepare_report_item()` (or `prepare_item_for_response()`): set
  `'product_sku' => $product ? (string) $product->get_sku() : ''`. For a
  variation this returns the variation SKU with WooCommerce's parent fallback,
  since `get_linked_product_id()` already prefers `variation_id`.
- `get_item_schema()`: add a `product_sku` string property (`readonly`,
  `view`/`edit`).
- `get_collection_params()`: add `'product_sku'` to the `orderby` enum **only if**
  server-side SKU sorting is implemented (see note below); otherwise omit it and
  keep SKU sortable client-side is not possible — see "Sorting" below.
- `get_export_columns()`: add `'product_sku' => __( 'SKU', ... )` (recommended
  position: right after "Product title").
- `prepare_item_for_export()`: resolve and add `'product_sku'`. Export rows are
  prepared per item; load the product per row here too (bounded per export
  batch). Reuse the same `get_product_for_item()` helper.

### 2. Client table — `client/reports/upcoming-renewals/`
- `config.js` `getHeaders()`: add a SKU header right after "Product title":
  `{ label: __( 'SKU', ... ), key: 'product_sku', isLeftAligned: true }`.
  Mark `isSortable` **only** if server-side sorting is added (see below).
- `index.js` `getRows()`: add the SKU cell in the same position,
  `{ display: decodeEntities( item.product_sku || '' ), value: item.product_sku }`.
- Rebuild assets: `npm run build`.

### 3. Query layer — `src/data/upcoming-renewals-query.php`
**No change required** for read-time resolution, with one exception:

- **Sorting note.** Sorting is done in PHP inside `sort_aggregate_rows()` over
  fields present on the aggregate row. Because the SKU is attached later (in the
  controller), the query cannot sort by it. Two options:
  - **v1 (recommended): SKU is a display/export column, not sortable.** Simplest;
    no query change. Document that SKU is not a sort key.
  - **v1.1 (optional): make SKU sortable.** Requires the query to know the SKU at
    sort time. Rather than re-introduce the snapshot, resolve current SKUs for the
    full candidate product set via one batched lookup against
    `wc_product_meta_lookup.sku` (indexed, one `IN (...)` query keyed on the
    variation_id-or-product_id set) and attach before `sort_aggregate_rows()`.
    This keeps freshness while enabling sort. Defer unless operators ask for it.

### 4. Ancillary
- Update `documentation/PROJECT_BRIEF.md` report-column list to mention SKU.
- `readme.txt` changelog entry. **No migration/regeneration note needed** — this
  approach adds no stored data.
- Plugin version bump per `RELEASE_CHECKLIST.md` (release step).

## Freshness Guarantee (the answer to the staleness question)
Because the SKU is read from the live product on each request, a SKU edit is
reflected on the very next report load. There is **no lookup data to keep fresh**
for the SKU — the lookup table continues to store only stable, historical facts
(IDs, name snapshot, quantities, totals). This is the primary reason to prefer
this approach over snapshotting.

## Unit / Integration Tests

### `tests/Integration/UpcomingRenewalsControllerIntegrationTest.php` (primary coverage)
- `test_item_exposes_current_product_sku()`: create a product with SKU
  `VEG-BOX-01`, seed a subscription + lookup rows, hit the report, assert the
  REST row's `product_sku` is `VEG-BOX-01`.
- `test_sku_reflects_updated_product_sku()`: **the freshness test.** After the
  first assertion, change the product's SKU to `VEG-BOX-02` **without re-syncing
  the lookup table**, re-request the report, assert the row now returns
  `VEG-BOX-02`. This proves reads are not stale.
- `test_variation_returns_variation_sku()`: variation with its own SKU resolves to
  the variation SKU; variation without one falls back to the parent SKU.
- `test_deleted_product_returns_blank_sku()`: delete the product, assert
  `product_sku === ''` while `product_name` still shows the stored snapshot.
- `test_schema_advertises_product_sku()`: `get_item_schema()` includes the
  `product_sku` property.
- `test_export_includes_sku()`: `get_export_columns()` contains the SKU header and
  `prepare_item_for_export()` returns the current SKU.

### `tests/Unit/Analytics/UpcomingRenewalsControllerTest.php` (or extend existing)
- Unit-test the `get_product_for_item()` / SKU-attach helper with a fake product
  object exposing `get_sku()`, and with `wc_get_product()` returning
  `false`/`null` → asserts `''`. Confirms the null-guarding without a DB.

### `tests/Unit/Database/SchemaTest.php`
- **No change** — schema is untouched. (Add an explicit note in the PR that the
  absence of a schema change is intentional.)

### E2E (optional, `tests/E2E/`)
- Assert the "SKU" column header renders and shows the seeded product's SKU.
  `tests/E2E/fixtures/seed-phase8.php` products may need a SKU set.

## Verification Checklist
1. `composer test` green, including the new controller integration tests
   (especially the freshness test).
2. `composer phpcs` / `phpstan` clean on the touched controller file.
3. `npm run build` succeeds; client lint passes.
4. Manual smoke (`wp-env`): report shows current SKUs; edit a product SKU in
   wp-admin and reload the report — the new SKU appears **without** any
   regenerate/resync step.
5. Deleted-product row renders a blank SKU with the name snapshot intact.

## Risks & Mitigations
- **Per-row product load cost on export.** Bounded per export batch and mirrors
  the load `prepare_links()` already performs interactively. If profiling shows a
  problem on very large exports, switch the export path to a single batched
  `wc_product_meta_lookup.sku` `IN (...)` query per batch.
- **SKU not sortable in v1.** Called out above; acceptable for a pick/pack list
  (operators sort by product name or renewal date). The v1.1 batched-lookup path
  is documented if sorting is requested.

## Alternative Considered — snapshot SKU in the lookup table (rejected)
Storing `product_sku` in the lookup table was the original plan and is
**rejected** for this feature because it reintroduces the staleness and
cross-subscription-inconsistency defects described above. The full alternative
design (schema change, freshness hooks, regeneration backstop,
freshest-`synced_at_gmt` aggregation) and the conditions under which we would
adopt it are recorded in
[`DECISION_RECORD_PRODUCT_META.md`](./DECISION_RECORD_PRODUCT_META.md#alternative-if-we-revisit-snapshot-with-a-freshness-strategy).