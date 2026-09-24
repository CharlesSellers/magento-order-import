# Source-order metadata preservation — release candidate, not activated

This is a narrow, opt-in correction to native order creation. It is **not a complete historical-order
replication implementation** and is not sufficient on its own to activate Jangro's production sync.

## Behaviour

The new environment-only setting `venuno/order_import/preserve_source_metadata` defaults to false.
It is independent of `venuno/order_import/materialise`, which also remains off on new production.
No environment configuration is changed by installing the code. Staging's existing behaviour remains
unchanged unless its own preservation flag is explicitly enabled.

When preservation is enabled, the Magento source header supplies the destination increment number
(including leading zeroes), created/updated UTC timestamps, state and status. The intake identity,
header and any duplicate payload identities must agree. Missing/malformed dates, mismatched currency
and identity, unsupported states and unmapped destination state/status pairs fail terminally; the
importer does not invent defaults. A number already present in the same store is held for identity
reconciliation, never relinked by guesswork. Magento's unique order-number/store constraint remains
the final concurrent-write protection.

The check runs before order creation inside the existing materialisation transaction. After Magento
saves, the persisted identity/state/status are read back; disagreement rejects the whole creation.
Magento's sales resource deliberately discards ORM `updated_at`, so the exact new order's two dates
are restored and verified within that same transaction. A targeted grid refresh ensures the Admin
grid agrees even with asynchronous indexing and older source dates. No global timestamp plugin is
introduced. The migration/replay fast path for already linked orders is unchanged.

## Release requirements

- This candidate has not been pushed, merged, deployed or enabled.
- Pin the reviewed module revision in the consuming Magento Composer lock; do not edit a deployed
  vendor directory manually. Preserve Jangro's existing required-company Composer patch; its dry-run
  application against this candidate succeeds.
- Rebuild generated DI for the entire candidate release. The NativeOrderGateway constructor changed:
  old compiled 0.4.0 metadata is incompatible. Build before switching the release symlink.
- Run the integration gate against the actual candidate release and custom status mappings, with
  synthetic fixtures and outbound side effects disabled. The local fixture runtime checks below do
  not establish production release compilation or authenticated HTTP behaviour for this candidate.
- Keep native production materialisation and the production flow off until the remaining history
  gaps below are implemented and verified. Do not advertise complete-history capability yet.

## Remaining full-history gaps

The inspected Venuno payload contains header, addresses, line items, totals, payment method and
customer identity. It does **not** carry invoice, shipment or credit-memo collections. The destination
does not create them. Restoring a `complete`/`closed` label is not evidence of preserved fulfilment,
payments or refunds. The local state tests intentionally prove only the header mapping.

Further work needs a versioned source/destination contract containing related records, source line
identities/parent relationships, invoiced/shipped/refunded/cancelled quantities, settlement totals and
base currency/conversion data. Creation must be atomic and must not execute payments, send emails,
reserve/decrement inventory or recalculate old amounts. Missing products/customer identity conflicts
must remain held, not assigned to guessed accounts. Completed, part-invoiced, part-shipped, cancelled
and refunded order fixtures need end-to-end comparison.

Existing linked orders currently return the duplicate fast path without applying later source
updates. That behaviour protects the migrated baseline; a future history-update mechanism must not
silently overwrite intentionally different destination records. No such update path is added here.

## Verification, 24 September 2026

- Existing baseline: 38 unit tests / 131 assertions passed before modification.
- Expanded tests cover metadata parsing, identity/date/currency rejection, separate opt-in flags,
  transaction guards, state mapping, collision disposition and scoped date/grid persistence.
- Local real Magento/PHP 8.3 fixture checks pass for all eight standard states, preserving source
  number/date/status in both sales_order and sales_order_grid; each case also verifies replay and
  same-store number collision handling.
- Unknown SKU, unmapped status and invalid metadata produce terminal failures with no extra orders.
- All synthetic sales, grid, related-record and ledger rows are rolled back; before/after counts match.
- No production/staging/legacy data, credentials, website deployments or sync switches changed.

The local rollback-only harness is recorded in the enclosing Jangro workspace as
`outputs/venuno-metadata-runtime-check-20260924.php`. It refuses any database other than the named
local Docker fixture database. It uses fresh runtime definitions for the candidate module only,
without replacing the shared fixture installation's compiled metadata.
