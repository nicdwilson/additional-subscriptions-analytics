# Release Checklist

Use this checklist before tagging a release or handing the package to a merchant.

## Metadata

- Plugin header `Version:` matches `ASA_VERSION`.
- `package.json` `version` matches the plugin header.
- `readme.txt` `Stable tag` matches the release tag.
- `readme.txt` contains a changelog and upgrade notice for the release.
- `WC requires at least` and `WC tested up to` match the tested WooCommerce range.

## Local Validation

Run from the repository root:

```bash
composer validate --strict
composer lint
composer phpstan
composer test
npm run lint:js
npm run build
npm run test:integration:wp-env
npm run test:e2e
git diff --check
```

Known local caveat: the Playwright suite skips when the configured local
WooCommerce checkout does not have built Woo Admin assets. Build WooCommerce
admin assets before treating E2E coverage as fully exercised.

## Translation Template

Generate the POT file:

```bash
npm run make-pot
```

The generated file is:

```text
languages/additional-subscriptions-analytics.pot
```

## Installable Package

Create the zip:

```bash
npm run package
```

Verify the archive contains the runtime source and assets:

```bash
unzip -l additional-subscriptions-analytics.zip | grep 'additional-subscriptions-analytics/src/plugin.php'
unzip -l additional-subscriptions-analytics.zip | grep 'additional-subscriptions-analytics/build/index.js'
unzip -l additional-subscriptions-analytics.zip | grep 'additional-subscriptions-analytics/languages/additional-subscriptions-analytics.pot'
```

## QIT Managed Tests

QIT requires the WooCommerce QIT CLI, network access, and an authenticated
WooCommerce.com partner account.

Install or update QIT:

```bash
composer global require "woocommerce/qit-cli:*"
qit connect
```

Run the managed checks against the local build:

```bash
npm run qit:compat
```

The QIT script runs:

- `qit run:security additional-subscriptions-analytics --zip=additional-subscriptions-analytics.zip`
- `qit run:phpcompatibility additional-subscriptions-analytics --zip=additional-subscriptions-analytics.zip`
- `qit run:woo-e2e additional-subscriptions-analytics --zip=additional-subscriptions-analytics.zip`

If QIT cannot find `additional-subscriptions-analytics`, use the extension slug
shown by `qit extensions` and pass the same zip path manually.

## Tagging

After all checks pass and the Phase 10 changes are committed:

```bash
git tag v0.1.0
git push origin main v0.1.0
```

Do not tag an uncommitted tree. The tag should point at the exact source used to
generate the installable package.
