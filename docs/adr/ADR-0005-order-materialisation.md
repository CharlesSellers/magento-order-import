# ADR-0005 — Native order materialisation (0.3)

- **Status:** Accepted
- **Date:** 2026-06-25
- **Applies to:** `venuno/module-order-import` (Magento 2 destination module)
- **Builds on:** [ADR-0002](./ADR-0002-import-domain-contract.md), [ADR-0003](./ADR-0003-order-import-intake.md)

## Context

ADR-0003 shipped an idempotent intake that **stages** imports (`import_status=pending`,
`magento_order_id=0`) and deliberately deferred creating native Magento sales orders, because order
creation (customer/address/SKU mapping, totals, payment) is error-prone and "can only be trusted after
validation against a live Magento". 0.3 closes that gap: turn a staged import into a real Magento order
— without losing the idempotency, auditability and safety the intake established.

## Decision

Materialise **synchronously during the import call**, behind an opt-in flag, with a clear separation
between Magento-free logic and Magento I/O.

1. **Opt-in flag** `venuno/order_import/materialise` in `app/etc/env.php` (default **false** = the 0.2
   staging behaviour). `capabilities.order_materialisation` reflects it, so a client discovers honestly
   whether a store creates native orders. This is the staging-proves-before-production gate.

2. **Architecture (ports & adapters)** so the logic is testable without a Magento runtime:
   - *Pure core* (unit-tested): `OrderDraftBuilder` (staged payload → `OrderDraft`, with validation),
     `OrderMaterialiser` (the idempotency/transaction/partial-failure state machine), and the seams
     `NativeOrderGatewayInterface` / `ImportRowStoreInterface` / `TransactionRunnerInterface`.
   - *Magento glue* (integration-tested): `NativeOrderGateway` (builds + saves the order),
     `MagentoTransactionRunner`, and the repository’s row transitions.

3. **Direct order construction (no quote).** The order is built field-by-field from the source payload
   and saved via `OrderRepository`, so the **source order's totals are reproduced verbatim** (fidelity)
   rather than recomputed from B's catalogue/tax/shipping rules — and materialisation **does not touch
   B's inventory**, matching the order-only replication scope.

4. **Guarantees:**
   - **Idempotent** — a row already carrying a `magento_order_id` returns it with no second order;
     concurrency is handled by an optimistic claim (`pending|failed → materialising` in one atomic
     UPDATE) so exactly one worker materialises.
   - **Transactional** — the native order save and the ledger `markImported` commit in one transaction
     (Magento's nested-transaction counter makes this atomic); either both land or neither does.
   - **Replayable** — a `failed` (or `pending`) row can be re-sent; because a failed attempt is rolled
     back, no orphan order exists, so the replay creates exactly one.
   - **Partial-failure safe** — terminal data errors (bad payload, unknown SKU) map to **HTTP 422** and
     transient errors to **HTTP 5xx**, both recorded on the row (`failed` + `error_message`, `attempts`),
     never leaving a half-built order.
   - **External references preserved** — the source order number is stored as the Magento order's
     `ext_order_id` and in an order status-history comment, so B↔A is always traceable; the
     `venuno_order_import` row continues to map `replay_key ↔ magento_order_id ↔ source identity`.

5. **Ledger columns** (`db_schema.xml`): add `attempts`, `materialised_at`, `error_message`; extend the
   `import_status` lifecycle to `pending | materialising | imported | failed | skipped`.

## Consequences

- **+** A complete Magento → Venuno → Magento flow produces native sales orders, idempotently and
  transactionally, with full B↔A traceability.
- **+** Safe, reversible rollout: default off; prove on staging (integration tests + a real order) before
  flipping production. Honest `capabilities.order_materialisation`.
- **−** Payment is recorded as an **offline** method (default `checkmo`); no funds are captured in B —
  the order is a faithful record, not a new sale (consistent with GOLDEN_CUSTOMER_02).
- **−** **Single-currency** assumption: base amounts are set equal to order amounts. Multi-currency base
  conversion is out of scope for 0.3 (documented; revisit when a customer needs it).
- **−** B's order gets **its own** `increment_id` (the A number lives in `ext_order_id`) — forcing A's
  number risks colliding with B's sequence.
- **−** A genuinely malformed order that Magento rejects on save surfaces as *retryable* and, after the
  client's bounded retries, lands in the dead-letter queue with the failure on the row for diagnosis.

## Alternatives considered

- **Quote → `cartManagement->placeOrder()`** — rejected: recomputes totals from B's pricing/tax/shipping
  (breaks replication fidelity), decrements B inventory, and depends on shipping/payment methods being
  configured for the quote. Direct construction preserves A's order exactly.
- **Asynchronous materialisation** (stage now, materialise via cron/CLI) — rejected for 0.3: synchronous
  materialisation returns the `magento_order_id` in the import response, so the Venuno client records the
  destination id immediately; async would require a separate reconciliation. (Async remains a future
  option for very large orders / throughput.)
- **Force A's `increment_id` onto the B order** — rejected: A's number is per-store (not globally unique)
  and can collide with B's own sequence.

## Rollout

1. Deploy 0.3 with the flag **off** → behaviour is unchanged (staging intake).
2. On **staging**, run the integration tests (`Test/Integration`), then enable
   `venuno/order_import/materialise` and replicate a real order; verify the native order, totals and
   `ext_order_id`.
3. Enable the flag in **production** only after staging passes.
