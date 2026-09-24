# Order history snapshots — 0.5.0

This release adds **creation-time archival replication**, not payment processing or bidirectional order updates.

## Activation

All flags are local `app/etc/env.php` values and default off:

- `venuno/order_import/materialise`
- `venuno/order_import/preserve_source_metadata`
- `venuno/order_import/require_source_history`

Complete-history mode requires all three. Deploy the module and compile DI before enabling them. Use a dedicated source connection with `settings.orderHistory.required=true`; do not change an established source cursor or staging destination.

## Contract and safety

The source reads all invoices, shipments and credit memos for a bounded order page, verifies collection counts, then re-reads each order to reject a changing snapshot. `history.version=1`, `history.complete=true` and every collection are required. The JSON field allowlist is `etc/order-history-v1.json` (identical to Venuno's connector contract).

The destination validates source identity, original timestamps/state/status, currencies, amounts, item parent graphs, related-record ownership and aggregate invoice/shipment/refund quantities and totals. It remaps order, item, product, customer, address and invoice relationships to destination IDs. Missing products/customers, unknown statuses or conflicting document numbers fail rather than fabricate records. A validated full-history snapshot may preserve a registered legacy status paired with a supported state even when that historical pair is absent from the mapping table; no global mapping or approval policy is changed. Metadata-only imports still require an exact state/status mapping.

Archival inserts share the existing order transaction. No invoice register/pay/capture, refund or shipment service is invoked. No stock deduction or new notification is requested; imported orders/documents have `send_email=0`. Payment data is limited to method, purchase-order/transaction reference and accounting amounts; card data and arbitrary payment additional-information are excluded. Historical status and document comments are preserved. Sales grids are refreshed only for the imported order.

First-write-wins replay semantics are unchanged. An existing imported ledger entry returns the same destination order ID and never recreates documents. In particular, registering earlier database-migrated orders in the ledger protects them from duplication. **This release does not overwrite those existing orders or apply later source edits to an already-imported order.** Any separate historical reconciliation requires its own frozen, backed-up scope.

## Verification

Unit tests cover partial fulfilment, refunds, incomplete/mismatched collections and invalid identities. PHP 8.3 native-runtime tests also cover completed, partial, refunded, unfulfilled, parent-child, virtual and cancelled orders; verify original values, remapped IDs, grids, replay, unchanged stock and full transaction rollback. Before production activation, verify the deployed version and flags, run a small real-order canary, compare destination records to source, and repeat it to prove no duplication.

Rollback: pause the dedicated production flow and set materialise=false before rolling back code. Keep the ledger and legitimate imported sales records; do not delete orders as a code rollback shortcut.
