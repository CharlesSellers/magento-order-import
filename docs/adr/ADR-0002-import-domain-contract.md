# ADR-0002 — Import-Domain Contract (identity & replay)

- **Status:** Accepted
- **Date:** 2026-06-23
- **Applies to:** `venuno/module-order-import` (Magento 2 destination module)
- **Builds on:** [ADR-0001](./ADR-0001-destination-verification-contract.md)

## Context

[ADR-0001](./ADR-0001-destination-verification-contract.md) established a verification-only contract
(`health` / `version` / `capabilities`) and deliberately deferred order ingestion. Before that
ingestion is designed we must fix the **shape of an imported order's identity and its idempotency**,
because those choices are the hardest to change later (they determine how duplicates are detected and
how records are addressed forever after).

Read-only archaeology against the first real source store (a Magento 2 instance, 140,052 orders) made
two facts authoritative (see the source connector's
`docs/verified-behaviour/magento-source-admin-jwt-and-stores.md`):

1. **The source hosts many storefronts** (35), each with its **own `increment_id` sequence**. So the
   human order number `increment_id` is **not globally unique** — the same value recurs across stores.
2. **An order row carries `store_id` (store-view) but not `website_id`**; the global unique key is the
   Magento `entity_id`. Website/store-code must be resolved from the store catalogue.

A naïve `source + increment_id` idempotency key (the historical assumption) would therefore **collapse
distinct orders from different storefronts into one** — silent data loss. The destination contract must
make that impossible by construction, and must do so for **multiple sources** (more than one store, and
eventually more than one platform) at once.

## Decision

Define an **import-domain contract** now — as discoverable metadata on `capabilities` and as this ADR —
even though no import endpoint exists yet. A future `order_import` request MUST carry:

### Identity (store-aware, never `increment_id` alone)

| Field | Meaning |
|---|---|
| `source_connection_id` | Which Venuno connection pulled the order (multi-source). |
| `source_platform` | Source system family, e.g. `magento`. |
| `source_base_url` | Which source instance. |
| `source_store_id` | The source store-view id. |
| `source_store_code` | Resolved store-view code, e.g. `ace_en`. |
| `source_website_id` | Resolved website id. |
| `source_order_entity_id` | **Globally-unique** source order PK. |
| `source_order_increment_id` | Per-store sequence number (NOT globally unique). |
| `source_order_display_number` | Human-facing order number. |
| `original_created_at` | Source creation timestamp (store-local). |

### Replay protection (idempotency)

| Field | Meaning |
|---|---|
| `replay_key` | Stable idempotency anchor derived from identity (`platform \| base_url \| entity_id`) — identical across repeated pulls of the same order, independent of content. |
| `payload_hash` | Content hash — same content hashes identically; a genuine edit changes it. |
| `import_status` | Destination lifecycle: `pending` / `imported` / `skipped` / `failed`. |
| `imported_at` | When the destination imported the order. |
| `last_seen_at` | When a poll last observed the order. |

Specific decisions:

1. **The idempotency key is `replay_key`, never `source + increment_id`.** `replay_key` is built from
   the globally-unique `entity_id` (plus platform + instance), so two storefronts sharing an
   `increment_id` produce different keys, and a re-pull of the same order (even after an edit) produces
   the same key → **first-write-wins, no duplicates**.

2. **Identity is always composite and store-aware.** The destination persists the full identity so an
   imported order can always be traced back to its exact source store and order, and so multi-store /
   multi-source imports never collide.

3. **`payload_hash` separates "seen again" from "changed".** Equal hash ⇒ a benign re-pull (skip);
   different hash on the same `replay_key` ⇒ the source order changed (the destination decides whether
   to update, per its first-write-wins policy).

4. **The contract is discoverable before the endpoint exists.** `GET /V1/venuno/capabilities`
   advertises `contract_version`, `import_identity_fields[]` and `import_replay_fields[]` while
   `order_import` stays `false`. A client can therefore validate it can satisfy the contract today.

5. **Append-only evolution.** Contract fields are only ever added; existing fields never change meaning
   (mirrors ADR-0001's capability rule). `contract_version` is bumped when the contract changes.

This ADR defines the **contract only**. No import endpoint, persistence schema or write path is built in
this release (consistent with ADR-0001 and the "verify before ingest" stance).

## Consequences

- **+** Duplicate detection and order addressing are correct for multi-store and multi-source from day
  one; the per-store `increment_id` collision is impossible by construction.
- **+** Venuno clients can discover and validate the contract now, decoupled from endpoint delivery.
- **+** The destination identity/replay model is symmetric with the source connector's, so the two
  evolve together.
- **−** The contract is carried as metadata only until an import endpoint consumes it; it is a promise,
  not yet an enforced wire schema.
- **−** Resolving `source_store_code` / `source_website_id` requires the source to read its store
  catalogue — handled on the source side, but a dependency the contract assumes is populated.

## Alternatives considered

- **`source + increment_id` idempotency** — rejected: `increment_id` is per-store and collides across
  the 35 storefronts; it would silently merge distinct orders.
- **`entity_id` alone as the key** — rejected for cross-instance use: unique within one Magento, but not
  across instances/platforms; `replay_key` folds in platform + base URL so multi-source is safe.
- **Defining the contract only when the endpoint is built** — rejected: identity/idempotency are the
  costliest things to change later; fixing them now de-risks the ingestion design.

## Roadmap (non-binding)

- **0.2+** — an `order_import` endpoint that enforces this contract on the wire, persists the composite
  identity, and uses `replay_key` for first-write-wins idempotency; `capabilities.order_import` → `true`;
  a superseding ADR for the ingestion/persistence shape.
