# Reference Audit Notes

This file records Phase 1 findings from the local WooCommerce core and
WooCommerce Subscriptions checkouts. Both repositories were inspected as
read-only references.

## Audit Sources

| Project | Path | Ref |
|---------|------|-----|
| WooCommerce core | `/Users/nicw/Code/GitHub/woocommerce` | `trunk` at `b84b21d2cc5` |
| WooCommerce Subscriptions | `/Users/nicw/Code/GitHub/woocommerce-subscriptions` | `develop` at `9a292aeb1` |

The local Subscriptions checkout reports version `8.8.1` and currently declares
WordPress `6.9`, WooCommerce `10.7`, and PHP `7.4` requirements. The prototype
minimums in `PROJECT_BRIEF.md` remain provisional until implementation is tested
against the intended tagged Subscriptions release range.

## WooCommerce Core Analytics Findings

### REST And Report Registration

- Analytics REST controllers live in the `wc-analytics` namespace.
- `Automattic\WooCommerce\Admin\API\Reports\GenericController` loads its data
  store with `WC_Data_Store::load( $this->rest_base )`.
- A `GenericController` data-store response must include `data`, `page_no`,
  `pages`, and `total`.
- `Automattic\WooCommerce\Admin\API\Reports\GenericQuery` loads data stores with
  `WC_Data_Store::load( "report-{$name}" )` and exposes query filters named from
  the dash-to-snake report name.
- Core registers Analytics data stores through `woocommerce_data_stores` and
  REST controllers through `woocommerce_admin_rest_controllers`.
- The report index is extended through `woocommerce_admin_reports`.

**Design implication:** the prototype should register a report controller under
`reports/upcoming-renewals`, register the matching data store key, and add the
report to WooCommerce Analytics through WooCommerce's report filters instead of
creating a separate admin screen.

### Lookup Tables And Lifecycle

- Core table schema is centralized in `WC_Install::get_schema()` and installed
  through `dbDelta()`.
- `wc_order_stats` uses one row per order, with date/status/customer indexes.
- `wc_order_product_lookup` uses one row per order line item, with a compound
  primary key and order/product/customer/date indexes.
- `Automattic\WooCommerce\Admin\ReportsSync` coordinates full regeneration and
  exposes import/delete/status REST operations.
- `Automattic\WooCommerce\Internal\Admin\Schedulers\ImportScheduler` provides the
  core chunking shape: init actions, batch actions, import/delete counts stored
  in an option, Action Scheduler batches, and resumable progress.
- `OrdersScheduler` imports orders through `wc_get_order()`, handles both HPOS
  and CPT storage, and invalidates Analytics report caches after imports.

**Design implication:** the plugin should own its schema and migration code, but
reuse the same lifecycle shape: `dbDelta()` table creation, explicit schema
version option, chunked Action Scheduler backfill, idempotent single-object sync,
and progress/status options.

## WooCommerce Subscriptions Findings

### Existing Reports

- Existing Subscriptions reports are legacy WooCommerce Reports classes under
  `includes/admin/reports/`.
- `WCS_Report_Upcoming_Recurring_Revenue` reads directly from live subscription
  storage and branches between HPOS tables and posts/postmeta.
- `WCS_Report_Cache_Manager` schedules cache refreshes for legacy reports on
  subscription payment, status, switch, and item update hooks.

**Design implication:** legacy reports are behavioral references only. This
plugin must not call them or depend on their transients/cache manager.

### Subscription Data Stores

- `wcs_get_subscriptions()` builds a WooCommerce order query with
  `type => shop_subscription` and returns `WC_Subscription` objects keyed by ID.
- Both the CPT and HPOS subscription data stores expose `get_orders()` wrappers
  around `wc_get_orders()` for `shop_subscription`.
- HPOS storage keeps core order columns in WooCommerce order tables and stores
  subscription-specific schedule fields as order meta.
- Both data stores emit `woocommerce_new_subscription` on create.
- Both data stores emit `woocommerce_update_order` and
  `woocommerce_update_subscription` on update.
- HPOS emits `woocommerce_before_delete_subscription`,
  `woocommerce_delete_subscription`, `woocommerce_before_trash_subscription`, and
  `woocommerce_trash_subscription`.
- On CPT storage, Subscriptions attaches to WordPress post deletion/trash hooks
  and fires compatibility hooks:
  - `woocommerce_subscription_deleted`
  - `woocommerce_subscription_trashed`

**Design implication:** backfill should enumerate subscriptions with
`wcs_get_subscriptions()` or `wc_get_orders()` using `type => shop_subscription`,
not direct `wp_posts`, `wp_postmeta`, `wc_orders`, or `wc_orders_meta` queries.
Incremental sync can use `woocommerce_update_subscription` as a debounced
fallback, but narrower hooks should trigger most updates.

### Dates, Statuses, Payments, And Renewals

- `WC_Subscription::get_date()` and `get_time()` are the source APIs for schedule
  dates. They normalize date-type aliases and can return GMT values.
- `WC_Subscription::update_dates()` and `delete_date()` persist through
  `save_dates()` and then fire:
  - `woocommerce_subscription_date_updated`
  - `woocommerce_subscription_date_deleted`
- `WC_Subscription::status_transition()` fires:
  - `woocommerce_subscription_status_{$to}`
  - `woocommerce_subscription_status_{$from}_to_{$to}`
  - `woocommerce_subscription_status_updated`
  - `woocommerce_subscription_status_changed`
- `WC_Subscription::payment_complete_for_order()` fires:
  - `woocommerce_subscription_payment_complete`
  - `woocommerce_subscription_renewal_payment_complete` for renewal orders
- Failed renewal flows fire:
  - `woocommerce_subscription_payment_failed`
  - `woocommerce_subscription_renewal_payment_failed`
- `WCS_Action_Scheduler` maps the `next_payment` date to
  `woocommerce_scheduled_subscription_payment`, scheduled with
  `subscription_id` args.
- `wcs_create_renewal_order()` exposes the `wcs_renewal_order_created` filter
  after the renewal order is created and related to the subscription.

**Design implication:** sync should run after subscription state changes, not
before scheduled payment processing. `woocommerce_subscription_payment_complete`,
`woocommerce_subscription_renewal_payment_complete`, status hooks, and date hooks
are stronger table-sync triggers than the scheduled payment hook itself.

### Line Item Mutation Paths

- WooCommerce order-item APIs fire:
  - `woocommerce_new_order_item`
  - `woocommerce_update_order_item`
  - `woocommerce_before_delete_order_item`
  - `woocommerce_delete_order_item`
- Subscriptions' My Account item removal path changes order item type through
  `wcs_update_order_item_type()` and fires:
  - `wcs_user_removed_item`
  - `wcs_user_readded_item`
- Subscription switching changes order item types directly, then recalculates and
  saves subscriptions. It fires:
  - `woocommerce_subscriptions_switch_completed`
  - `woocommerce_subscriptions_switched_item`
  - `woocommerce_subscription_item_switched`

**Design implication:** product lookup sync must replace all product lookup rows
for a subscription after each sync. Hooking only item create/update is not
enough; switch and remove/re-add paths need explicit coverage, and item-delete
hooks must resolve the owning subscription before the item disappears.

## Phase 1 Design Decisions

### Table Names

The prototype table names are confirmed:

- `{$wpdb->prefix}wc_subscriptions_stats`
- `{$wpdb->prefix}wc_subscription_product_lookup`

The plural stats table mirrors `wc_order_stats`; the singular product lookup
table mirrors `wc_order_product_lookup`.

### Sync Boundaries

- Backfill enumerates subscription IDs through Subscriptions/WooCommerce query
  APIs.
- Single-subscription sync loads the source subscription object with
  `wcs_get_subscription()` or `wc_get_order()` and reads all source fields through
  object methods.
- Stats rows are upserted one row per subscription.
- Product lookup rows are replaced wholesale for the subscription being synced:
  delete existing lookup rows for the subscription, then insert current recurring
  line items.
- Deleted subscriptions remove rows from both plugin tables.
- Runtime report queries read only plugin-owned lookup tables.
- Direct source-data SQL is reserved for diagnostics or reconciliation commands,
  not normal report rendering.

### Hook Map

| Event | Action |
|-------|--------|
| `woocommerce_new_subscription` | Queue stats/product lookup sync for the new subscription. |
| `woocommerce_update_subscription` | Queue debounced fallback sync after general subscription saves. |
| `woocommerce_subscription_status_changed` | Queue stats sync; status affects report inclusion. |
| `woocommerce_subscription_date_updated` | Queue stats sync; next payment/end/trial dates may change. |
| `woocommerce_subscription_date_deleted` | Queue stats sync; nullable schedule dates may change. |
| `woocommerce_subscription_payment_complete` | Queue stats sync after successful payment state changes. |
| `woocommerce_subscription_renewal_payment_complete` | Queue stats sync after renewal payment updates next payment/last payment. |
| `woocommerce_subscription_payment_failed` | Queue stats sync; status and retry/next dates may change. |
| `woocommerce_subscription_renewal_payment_failed` | Queue stats sync for failed renewal edge cases. |
| `woocommerce_new_order_item` | If the order is a subscription, queue product lookup replacement. |
| `woocommerce_update_order_item` | Resolve owning subscription and queue product lookup replacement. |
| `woocommerce_before_delete_order_item` | Resolve owning subscription before deletion and queue product lookup replacement. |
| `wcs_user_removed_item` | Queue product lookup replacement after My Account removal. |
| `wcs_user_readded_item` | Queue product lookup replacement after My Account undo. |
| `woocommerce_subscriptions_switch_completed` | Queue sync for each subscription related to the switch order, after switch completion. |
| `woocommerce_subscriptions_switched_item` | Queue product lookup replacement for the affected subscription. |
| `woocommerce_before_delete_subscription` | HPOS: delete analytics rows or mark them for deletion before source removal. |
| `woocommerce_delete_subscription` | HPOS: ensure analytics rows are removed. |
| `woocommerce_subscription_deleted` | CPT compatibility: ensure analytics rows are removed by subscription ID. |
| `woocommerce_before_trash_subscription` | HPOS: queue stats sync or removal depending on v1 trash policy. |
| `woocommerce_trash_subscription` | HPOS: queue stats sync/removal after trash status is applied. |
| `woocommerce_subscription_trashed` | CPT compatibility: queue stats sync/removal by subscription ID. |

### Minimum Version Note

The current project minimums remain unchanged for now:

- WordPress `6.4`
- WooCommerce `9.3`
- WooCommerce Subscriptions `6.0+`
- PHP `8.0`

The local Subscriptions `develop` checkout requires newer WooCommerce and
WordPress versions. Before implementation is declared release-ready, the test
matrix should confirm whether the intended Subscriptions release range actually
supports the APIs used here. If not, raise the documented minimums and plugin
guards together.
