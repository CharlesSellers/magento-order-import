# Optional read-only source financials (0.5.1)

`GET /rest/V1/venuno/source-financials/{orderId}` requires the existing environment's Venuno token and is disabled unless protected `env.php` configuration explicitly enables it:

```php
'source_financials' => [
    'enabled' => true,
    'database' => 'LEGACY_SCHEMA',
    'base_url' => 'https://legacy.example.test',
],
'suppress_history_export_changelog' => true,
```

Both keys belong under `venuno/order_import`. The reader reuses the destination's database-server credentials but connects to the configured **different** schema. A dedicated PDO connection opens a repeatable-read, **READ ONLY** transaction, issues only two fixed parameterised SELECTs and rolls back. It never modifies the source database, schema, customers, credentials or API. Requests cannot choose a schema. Timeout is five seconds; order items are capped at 1,000. Authentication runs before configuration or database access. No customer, address or payment data is exposed.

The response contains `snapshot_json`: source URL, order identifiers/timestamp/currencies and financial fields plus item identifiers/SKUs, matching values and exact stored row totals. The Venuno source connector must validate identity, timestamp, currencies, totals, item set/quantity/prices/tax/discounts before accepting the stored row totals. Missing or inconsistent data fails closed. No reverse calculations are permitted.

The separate opt-in historical export safeguard removes only the newly imported order's entries from Adobe sales exporter change logs **inside the same order-import transaction**. It does not modify global cron, queues for other orders or normal checkout behaviour. It is not a generic guarantee about arbitrary third-party observers; verify runtime side effects before activation.

No database migration is required. Deploy using the existing atomic release procedure, verify anonymous requests are denied and token-authenticated reads match source SQL, then enable only the dedicated production connection. Existing-import replay remains a no-op; later invoice/status reconciliation is separate. Rollback: pause the dedicated feed, revert the dependency release and remove the two opt-in configuration keys using the protected pre-change backup.
