# Finalization Tasks for Additional Subscriptions Analytics

## Audit Date

2026-06-20

## Scope

Reviewed the v0.1.0 source tree after Phase 9:

- Runtime PHP under `additional-subscriptions-analytics.php`, `src/`, and
  `uninstall.php`.
- React/WooCommerce Admin report code under `client/`.
- PHPUnit integration/unit suites and Playwright E2E suite.
- Database lifecycle, sync, report, reconciliation, CLI repair, and packaging
  paths.

## Critical Priority

No open critical finalization tasks.

## High Priority

No open high-priority traceability or code-health tasks.

## Medium Priority

### TASK-OPT-001: Revisit large single-purpose services after v0.1.0 adoption
- **File:** `src/diagnostics/upcoming-renewals-reconciler.php`
- **Lines:** Whole class
- **Issue:** The reconciler is intentionally self-contained for v0.1.0 but is
  larger than the preferred finalization threshold. Future diagnostic expansion
  could make source scanning, lookup snapshotting, and mismatch formatting harder
  to maintain in one class.
- **Fix:** If more diagnostics are added, extract lookup snapshot, source
  snapshot, and mismatch formatter collaborators with focused unit coverage.
- **Status:** [ ] Deferred; not release-blocking for v0.1.0.

### TASK-OPT-002: Revisit Action Scheduler orchestration split after live load data
- **File:** `src/sync/backfill-scheduler.php`
- **Lines:** Whole class
- **Issue:** Backfill and regeneration are cohesive but dense. Production load
  data may reveal whether queue state management and batch execution should be
  separated.
- **Fix:** Keep current v0.1.0 structure. Reassess after live BVO regeneration
  and repair runs.
- **Status:** [ ] Deferred; not release-blocking for v0.1.0.

## Traceability Review

Verified runtime paths:

- **Report UI:** `client/reports/upcoming-renewals/index.js` calls WooCommerce
  Analytics data APIs with `REPORT_SLUG`, then renders `TableCard` rows and CSV
  export.
- **Report REST/data path:** WooCommerce Admin registers
  `Analytics\UpcomingRenewals\Controller`, which delegates to
  `Analytics\UpcomingRenewals\DataStore`, which reads `Data\UpcomingRenewalsQuery`
  against plugin-owned lookup tables.
- **Validation UI path:** The report's **Validate data** action calls
  `/wc-analytics/reports/upcoming-renewals/reconcile`, which delegates to
  `Diagnostics\UpcomingRenewalsReconciler`.
- **Backfill path:** Activation/migration creates tables, `BackfillScheduler`
  queues Action Scheduler work, `SubscriptionSource` pages source subscriptions,
  row builders normalize stats/product rows, and
  `SubscriptionAnalyticsRepository` upserts lookup rows.
- **Incremental sync path:** `SyncHooks` listens to subscription, status,
  schedule, and line-item mutation hooks, then queues `SubscriptionSyncer`.
- **Repair path:** `RepairCommands` exposes regenerate, resync, stale repair,
  orphan cleanup, and reconciliation through WP-CLI.

No broken layer boundaries or signature mismatches were found in the reviewed
paths. Existing unit and integration tests cover the report query, REST
permissions, sync idempotency, schema lifecycle, repair commands, and
reconciliation diagnostics.

## Packaging Finding Resolved During Phase 10

Before Phase 10 packaging work, `wp-scripts plugin-zip` discovered only the
bootstrap/readme/build files and omitted `src/`, which would have produced a
non-installable package. Phase 10 adds an explicit `package.json` `files`
allowlist so the zip includes runtime PHP source, built assets, language files,
and release/admin docs.
