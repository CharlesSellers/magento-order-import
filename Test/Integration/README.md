# Integration tests (real Magento)

These tests exercise the **Magento-coupled** materialisation code — `NativeOrderGateway` (native order
construction), the resource-connection store/transaction, and the end-to-end staged-row → native-order
path. They require a live Magento and therefore run under Magento's **integration** test framework
(`dev/tests/integration`), not the Magento-free `Unit` suite (`phpunit.xml.dist`).

This is the live validation module [ADR-0003](../../docs/adr/ADR-0003-order-import-intake.md) and
[ADR-0005](../../docs/adr/ADR-0005-order-materialisation.md) require before native order creation is
trusted in production.

## What they cover

- `OrderMaterialiserIntegrationTest` — a staged row materialises into a native order (external reference
  and source totals preserved); replay is idempotent (no second order); an unknown SKU fails terminally
  and leaves **no orphan order** (transactional rollback), recording `failed` + `error_message` for replay.

## Running

From a Magento 2 installation that has this module installed, register the module's integration suite
in `dev/tests/integration/phpunit.xml` (or copy the test under a discovered path) and run, e.g.:

```bash
cd dev/tests/integration
../../../vendor/bin/phpunit --filter OrderMaterialiserIntegrationTest
```

The tests use core fixtures (`Magento/Catalog/_files/product_simple.php`) and
`@magentoDbIsolation enabled`, so they create no lasting data. Run them on **staging** as the gate before
enabling `venuno/order_import/materialise` in production.
