# ADR-0003 — Order-import intake (idempotent staging)

- **Status:** Accepted
- **Date:** 2026-06-23
- **Applies to:** `venuno/module-order-import` (Magento 2 destination module)
- **Builds on:** [ADR-0001](./ADR-0001-destination-verification-contract.md), [ADR-0002](./ADR-0002-import-domain-contract.md)

## Context

ADR-0002 fixed the import-domain contract (store-aware identity + replay protection) and advertised it
on `capabilities` while no endpoint existed. The next step is to **accept** replicated orders. Two
things make this endpoint sensitive:

1. **Idempotency must be guaranteed at the destination.** The source re-surfaces orders (the
   `updated_at` cursor re-emits post-creation edits) and may retry on transient failures, so the same
   order arrives more than once. Duplicates must be impossible.
2. **Materialising a payload into a native Magento sales order is large and risky.** Magento order
   creation (customer resolution, address/region mapping, SKU→product, quote vs. direct order, totals
   reconciliation, tax/shipping) is error-prone and can only be trusted after validation against a
   **live** Magento. Shipping it blind — and advertising it as working — would be dishonest and unsafe.

## Decision

Ship the endpoint as an **idempotent intake that stages** the import; defer native order creation.

`POST /V1/venuno/orders/import` (Venuno token; `anonymous` to Magento ACL, validated in the service):

1. **Authenticate** with the Venuno per-environment token (as the other endpoints).
2. **Validate** the contract — `replay_key`, `source_platform`, `source_base_url`,
   `source_order_entity_id` are required. Missing fields → **HTTP 422** (non-retryable).
3. **Enforce first-write-wins idempotency on `replay_key`** (a `UNIQUE` column in
   `venuno_order_import`): if the key is already recorded, return the existing record as a no-op
   (`duplicate=true`); otherwise record a new staged row (`import_status=pending`).
4. **Persist the full store-aware identity** plus `payload_hash` and the normalised order payload (for
   audit/replay). `magento_order_id` stays null/0 — nothing is created in Magento yet.
5. **Respond** with a typed DTO: `{ accepted, duplicate, replay_key, import_status, magento_order_id,
   message }`. HTTP is 200 for both new and duplicate; the client reads `duplicate` to tell them apart.

`capabilities` is updated honestly: `order_import: true` (the store accepts + stages imports) and a new
`order_materialisation: false` (staged imports are not yet turned into native sales orders).
`contract_version` and the module version bump to **0.2** (per ADR-0001, the module version is the
contract version).

## Consequences

- **+** Duplicates are impossible by construction (`replay_key` UNIQUE + first-write-wins), so the
  source can poll/retry freely.
- **+** Every accepted order is persisted with its full composite identity and payload — auditable and
  replayable, and ready to materialise later without re-fetching from source.
- **+** The contract is now *exercised*, not just advertised; Venuno can integrate end-to-end against a
  real acceptance path.
- **+** Honest capability signalling: clients can see import is accepted but not yet materialised.
- **−** Staged imports do **not** appear as Magento sales orders yet; a follow-up release (and live
  validation) is required before `order_materialisation` flips to true.
- **−** No automated test runs in this repo (no Magento runtime here); correctness is enforced by
  `php -l`, `composer validate`, well-formed XML, and adherence to service-contract conventions —
  live-Magento integration validation is required before production use.

## Alternatives considered

- **Materialise the native order now** — rejected: cannot be validated without a live Magento; shipping
  it blind risks creating malformed orders and would falsely advertise `order_materialisation`.
- **In-memory / no persistence idempotency** — rejected: idempotency must survive restarts and be
  auditable; a `UNIQUE replay_key` row is the durable authority.
- **Return 201/200 to distinguish created/duplicate** — Magento webapi returns 200 for a successful
  service call; rather than fight the framework, the `duplicate` flag in the body carries that signal.

## Roadmap (non-binding)

- **0.3+** — materialise staged imports into native Magento sales orders behind a config flag, validated
  against a live store; flip `order_materialisation` → true; a superseding ADR for the creation/mapping
  shape (customer resolution, address/SKU mapping, totals).
