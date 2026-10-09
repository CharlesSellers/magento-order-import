# Legacy order sync — 0.6.0

Imports are first-write-wins: once a legacy order is imported, later legacy changes (approval, invoices,
shipments, refunds, edits) never reach the destination. 0.6.0 adds an **update path for already-imported
orders**, keyed by the legacy `entity_id` (ledger `source_order_entity_id`). Legacy is the source of truth
and is only ever read.

## What it does

For one imported order it reads a consistent legacy snapshot (REPEATABLE READ, READ ONLY session) and the
destination order, then plans:

| Change | How |
|---|---|
| state / status | legacy wins (incl. `awaiting_approval` → `approved`), with the importer's `pending/pending` → `new/pending` normalisation. The pair must be assigned in `sales_order_status_state`; the data patch adds the legacy pairs `approved/new`, `rejected/new`, `approved/closed`, `complete/processing` (additive only). |
| invoices, shipments (+ tracking), credit memos | **added only** if missing (matched by document number); children remapped to destination order lines, invoices (credit memo `invoice_id`), addresses and product ids. Tracking/comments added to existing documents. Lifecycle fields of existing documents (e.g. invoice state) follow legacy. |
| order / line / payment fields | contract financial fields (`*_invoiced`, `total_paid`, `total_due`, `qty_*`, …), `updated_at` |
| customer snapshot and addresses | `customer_email/firstname/lastname/prefix/…`; address name/prefix/company/street/city/region/postcode/country/telephone/email/vat_id (whitespace-only differences ignored; the importer's required-company fallback is not drift) |
| order status history | missing legacy comments are added; destination-only comments are kept and reported |

Never synced: ids/foreign keys (customer, group, product, quote), currencies (a difference fails),
`created_at` (warned), destination artefacts (`product_options`, `base_cost`, gift-message flags, card
metadata, payment `additional_information`, weee, `applied_rule_ids`, Stripe-only columns).

## Safety

- **Direct SQL, one transaction per order.** No order save → no sales observers, approval requests,
  emails, payment capture/refund, shipment services or stock movements. Inserted documents carry
  `send_email=0, email_sent=0`; the sync note is `is_customer_notified=0, is_visible_on_front=0`.
- **Fail closed** (nothing written) on: destination-only documents or tracks, document content/line
  quantity differences, order line differences (count/SKU/qty/parent), legacy documents that do not add
  up to the order (torn or mid-change), document numbers owned by another order, unknown/unassigned
  status, currency or identity mismatch, payment method mismatch, an address on one side only.
- **Races:** the destination order is locked and must still match its planning fingerprint; legacy
  `updated_at` is re-read outside the snapshot before commit; both are retryable refusals.
- **Read-back:** after writing, the destination is re-read and re-planned against the same legacy
  snapshot inside the transaction; anything left over rolls the order back (`readback_mismatch`).
- `updated_at` is set explicitly (legacy's value) so `ON UPDATE CURRENT_TIMESTAMP` never fires.
- Grids are refreshed for the order; `sales_order_data_exporter_cl` / `sales_order_status_data_exporter_cl`
  rows created by the transaction for that order are removed (same suppression as the importer).
- **Audit + rollback:** `venuno_order_sync_audit` stores every from/to value and inserted row id.
  Rollback restores and deletes them, and refuses if any synced value has changed since.
- **Idempotent:** a synced order re-plans to nothing (`noop`).

## Configuration (`app/etc/env.php`, default off)

```php
'venuno' => ['order_import' => [
    'order_sync' => [
        'enabled'   => true,
        'database'  => 'wwwjangronet',            // read-only legacy schema on the same server
        'base_url'  => 'https://www.jangro.net/', // ledger source_base_url filter
        'api_apply' => false,                     // API plans only until enabled
    ],
]],
```

## CLI

```bash
bin/magento venuno:orders:sync --discover=var/sync-scope.txt              # read-only: drifted legacy ids
bin/magento venuno:orders:sync --orders-file=var/sync-scope.txt --report=var/sync-dry.jsonl   # dry run
bin/magento venuno:orders:sync --orders-file=var/sync-scope.txt --apply --expect=N --batch=sync-YYYYMMDD
bin/magento venuno:orders:sync --rollback-batch=sync-YYYYMMDD               # or --rollback-audit=ID
```

`--apply` always re-plans first and aborts unless `--expect` equals the number of orders with changes
and no order fails closed (`--skip-failed` overrides the latter).

## API — `POST /V1/venuno/orders/sync`

Same body as `/orders/import` (Venuno token). The ledger row for `replay_key` must be imported and its
source identity must match. With `database` configured, the module re-reads legacy (authoritative); else
the payload's complete order-history v1 is validated exactly like the importer and used. Response:
`{accepted, status: noop|planned|applied, magento_order_id, audit_id, categories, message}`. Refusals:
422 (terminal, reason code in the message), 503 (race/legacy unavailable — retry), 404 (not imported),
403 (disabled).

## Tests

- `Test/Unit/Sync` — planner (every change type and every fail-closed reason), payload snapshots, CLI parsing.
- `Test/Integration/Sync` — **real MySQL/MariaDB** copies of the production legacy and destination sales
  schemas (DDL, exporter triggers, the scoped orders' rows): dry run writes nothing; apply on every order;
  exact re-plan (`noop`); idempotent second run; no exporter change-log rows; no notification flags;
  rollback restores every order exactly; legacy checksums unchanged; mid-sync destination/legacy changes
  refused with no writes; rollback refused after later edits; synthetic tracking + credit memo on the real
  schema. Requires `VENUNO_SYNC_TEST_DSN` and an export (`VENUNO_SYNC_FIXTURES`, contains customer data —
  never commit). Not a full Magento runtime: `GridPool` refresh, DI, the webapi route and the data patch
  are exercised only on a Magento install.
